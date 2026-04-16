<?php

namespace App\Http\Controllers\Customer;

use App\Events\NewBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingRequest;
use App\Http\Requests\LandingBookingRequest;
use App\Mail\BookingReceiptMail;
use App\Mail\BookingRequestReceivedMail;
use App\Mail\FinalQuotationConfirmedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Services\BookingService;
use App\Services\DocumentGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class BookingController extends Controller
{
    protected BookingService $bookingService;
    protected DocumentGenerationService $documentGenerationService;

    public function __construct(BookingService $bookingService, DocumentGenerationService $documentGenerationService)
    {
        $this->bookingService = $bookingService;
        $this->documentGenerationService = $documentGenerationService;
    }

    public function store(BookingRequest $request)
    {
        $data = $request->validatedData();

        if ($request->hasFile('vehicle_image')) {
            $data['vehicle_image_path'] = $request->file('vehicle_image')->store('vehicle_images', 'public');
        }

        $booking = $this->bookingService->createBooking($data, Auth::user());

        broadcast(new NewBooking($booking));

        return redirect()->route('landing')
            ->with('success', 'Booking created successfully! Reference: ' . $booking->job_code . '. We will contact you shortly.');
    }

    public function landingStore(LandingBookingRequest $request)
    {
        $data = $request->validatedData();

        $existingCustomer = Customer::query()
            ->where(function ($query) use ($data) {
                $query->where('phone', $data['phone']);

                if (filled($data['email'] ?? null)) {
                    $query->orWhere('email', $data['email']);
                }
            })
            ->first();

        if ($existingCustomer) {
            $activeStatuses = ['requested', 'reviewed', 'quoted', 'quotation_sent', 'confirmed', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job'];
            $cooldownStatuses = ['cancelled', 'rejected'];

            $activeBooking = Booking::where('customer_id', $existingCustomer->id)
                ->whereIn('status', $activeStatuses)
                ->exists();

            if ($activeBooking) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'phone' => 'You already have an ongoing booking. Please wait until it is completed.',
                    ]);
            }

            $recentSpamBooking = Booking::where('customer_id', $existingCustomer->id)
                ->whereIn('status', $cooldownStatuses)
                ->latest('created_at')
                ->first();

            if ($recentSpamBooking && $recentSpamBooking->created_at?->gt(now()->subMinutes(5))) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'phone' => 'Please wait a few minutes before booking again.',
                    ]);
            }
        }

        $truckType = is_numeric($data['truck_type_id'])
            ? TruckType::find($data['truck_type_id'])
            : TruckType::where('name', 'like', '%' . $data['truck_type_id'] . '%')->first();

        if (! $truckType) {
            $truckType = TruckType::first();
        }

        if (! $truckType) {
            return back()->withErrors([
                'truck_type_id' => 'No vehicle types available. Please contact support.',
            ]);
        }

        $data['truck_type_id'] = $truckType->id;

        if ($request->hasFile('vehicle_image')) {
            $data['vehicle_image_path'] = $request->file('vehicle_image')->store('vehicle_images', 'public');
        }

        $booking = $this->bookingService->createBooking($data);
        $booking->loadMissing(['customer', 'truckType']);

        if ($booking->customer?->email) {
            try {
                Mail::to($booking->customer->email)->send(new BookingRequestReceivedMail($booking));
            } catch (\Exception $e) {
                Log::error('Failed to send booking request email', [
                    'booking_id' => $booking->id,
                    'customer_email' => $booking->customer->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        broadcast(new NewBooking($booking));

        return redirect()->route('landing')
            ->with(
                'success',
                'Booking created successfully! Reference: ' . $booking->job_code . '. We will contact you shortly.' .
                    ($booking->customer?->email ? ' A confirmation email has been sent to ' . $booking->customer->email . '.' : '')
            );
    }

    public function showQuotationReview(Request $request, Booking $booking)
    {
        abort_unless($request->hasValidSignature(), 403);

        $booking->loadMissing(['customer', 'truckType']);

        return view('customer.pages.quotation-review-email', [
            'booking' => $booking,
            'signedActionUrl' => $this->quotationRedirectUrl($request, $booking, true, 'quotation.review.submit'),
        ]);
    }

    public function respondToQuotation(Request $request, Booking $booking)
    {
        $customer = $this->resolveAuthenticatedCustomer();

        abort_unless($customer && (int) $booking->customer_id === (int) $customer->id, 403);

        return $this->processQuotationResponse($request, $booking, false);
    }

    public function respondToQuotationFromEmail(Request $request, Booking $booking)
    {
        abort_unless($request->hasValidSignature(), 403);

        return $this->processQuotationResponse($request, $booking, true);
    }

    protected function processQuotationResponse(Request $request, Booking $booking, bool $fromEmail = false)
    {
        $redirectUrl = $this->quotationRedirectUrl($request, $booking, $fromEmail);

        if (! in_array($booking->status, ['quoted', 'quotation_sent', 'reviewed', 'confirmed'], true)) {
            return redirect()->to($redirectUrl)
                ->with('error', 'This booking is not waiting for quotation approval right now.');
        }

        $validated = $request->validate([
            'action' => 'required|in:accept,negotiate',
            'counter_offer_amount' => [
                'nullable',
                'max:50',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if ($this->bookingService->parsePrice((string) $value) <= 0) {
                        $fail('Enter a valid counter-offer amount.');
                    }
                },
            ],
            'customer_response_note' => 'nullable|string|max:1000',
        ]);

        if ($validated['action'] === 'accept') {
            if (! in_array($booking->status, ['quoted', 'quotation_sent'], true)) {
                return redirect()->to($redirectUrl)
                    ->with('error', 'Dispatch is still reviewing the latest adjustment for this booking.');
            }

            $booking->update([
                'status' => 'confirmed',
                'customer_approved_at' => now(),
                'price_locked_at' => now(),
                'negotiation_requested_at' => null,
                'counter_offer_amount' => null,
            ]);

            $booking->refresh()->loadMissing(['customer', 'truckType']);
            $finalQuotePath = $this->documentGenerationService->generateQuotation($booking, true);
            $booking->update(['final_quote_path' => $finalQuotePath]);
            $booking->refresh()->loadMissing(['customer', 'truckType']);

            if (filled($booking->customer?->email)) {
                Mail::to($booking->customer->email)->send(new FinalQuotationConfirmedMail($booking));
            }

            return redirect()->to($redirectUrl)
                ->with('success', 'Quotation accepted. The final quotation was confirmed and emailed to you.');
        }

        $counterOffer = $this->bookingService->parsePrice($validated['counter_offer_amount'] ?? null);
        $customerNote = trim(strip_tags((string) ($validated['customer_response_note'] ?? '')));

        if ($counterOffer <= 0 && $customerNote === '') {
            return back()
                ->withInput()
                ->withErrors([
                    'customer_response_note' => 'Add a short note or a counter-offer so dispatch can review your request.',
                ]);
        }

        $booking->update([
            'status' => 'reviewed',
            'negotiation_requested_at' => now(),
            'counter_offer_amount' => $counterOffer > 0 ? $counterOffer : null,
            'customer_response_note' => $customerNote !== ''
                ? $customerNote
                : 'Customer requested a quotation adjustment.',
            'customer_approved_at' => null,
            'price_locked_at' => null,
        ]);

        return redirect()->to($redirectUrl)
            ->with('success', 'Your negotiation request was sent to dispatch for review.');
    }

    protected function quotationRedirectUrl(Request $request, Booking $booking, bool $fromEmail = false, ?string $routeName = null): string
    {
        if ($fromEmail) {
            return route($routeName ?? 'quotation.review', [
                'booking' => $booking,
                'expires' => $request->query('expires'),
                'signature' => $request->query('signature'),
            ]);
        }

        return route('customer.track', $booking);
    }

    protected function resolveAuthenticatedCustomer(): ?Customer
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        if (Schema::hasColumn('customers', 'user_id') && $user->customer) {
            return $user->customer;
        }

        return Customer::query()
            ->when(Schema::hasColumn('customers', 'user_id'), function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->when(filled($user->email ?? null), function ($query) use ($user) {
                $query->orWhere('email', $user->email);
            })
            ->first();
    }
}
