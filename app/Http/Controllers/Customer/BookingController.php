<?php

namespace App\Http\Controllers\Customer;

use App\Events\BookingStatusUpdated;
use App\Exceptions\Booking\ActiveQuotationException;
use App\Exceptions\Booking\BlacklistedCustomerException;
use App\Exceptions\Booking\DuplicateActiveRouteException;
use App\Exceptions\Booking\NoTruckTypeAvailableException;
use App\Exceptions\Booking\ScheduledCapacityException;
use App\Exceptions\Booking\SpamCooldownException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingRequest;
use App\Http\Requests\LandingBookingRequest;
use App\Mail\BookingAcceptedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Services\BookingService;
use App\Services\DocumentGenerationService;
use App\Services\QuotationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class BookingController extends Controller
{
    public function __construct(
        protected BookingService $bookingService,
        protected DocumentGenerationService $documentGenerationService,
        protected QuotationService $quotationService,
    ) {}

    public function store(BookingRequest $request)
    {
        $data = $request->validatedData();

        if ($request->hasFile('vehicle_images')) {
            $data['vehicle_image_path'] = $this->bookingService->storeVehicleImages($request->file('vehicle_images'));
        }

        try {
            $quotation = $this->bookingService->createCustomerQuotation(
                $data,
                Auth::user(),
                $this->resolveAuthenticatedCustomer()
            );
        } catch (BlacklistedCustomerException | ActiveQuotationException $e) {
            return back()->withInput()->withErrors(['phone' => $e->getMessage()]);
        }

        return redirect()->route('customer.dashboard')
            ->with('success', 'Quotation request submitted! Reference: ' . $quotation->quotation_number . '. We will send you a quotation shortly.');
    }

    public function landingStore(LandingBookingRequest $request)
    {
        $data = $request->validatedData();

        if ($request->has('eta_minutes')) {
            $data['eta_minutes'] = $request->input('eta_minutes');
        } elseif ($request->has('duration_min')) {
            $data['eta_minutes'] = $request->input('duration_min');
        }

        if ($request->hasFile('vehicle_images')) {
            $data['vehicle_image_path'] = $this->bookingService->storeVehicleImages($request->file('vehicle_images'));
        }

        try {
            $result = $this->bookingService->createLandingQuotation($data);
        } catch (BlacklistedCustomerException | ActiveQuotationException | SpamCooldownException $e) {
            return back()->withInput()->withErrors(['phone' => $e->getMessage()]);
        } catch (ScheduledCapacityException $e) {
            return back()->withInput()->withErrors(['scheduled_date' => $e->getMessage()]);
        } catch (NoTruckTypeAvailableException $e) {
            return back()->withErrors(['truck_type_id' => $e->getMessage()]);
        }

        $quotation = $result['quotation'];
        $data = $result['data'];
        $submittedPrice = $result['submitted_price'];

        $serviceLabel = ($data['service_type'] ?? 'book_now') === 'schedule' ? 'Scheduled' : 'Book Now';

        session(['booking_confirmation' => [
            'reference'       => $quotation->quotation_number,
            'submitted_at'    => now()->format('F j, Y g:i A'),
            'service_type'    => $serviceLabel,
            'scheduled_date'  => $data['scheduled_date'] ?? null,
            'name'            => trim(($data['first_name'] ?? '') . ' ' . ($data['middle_name'] ? $data['middle_name'] . ' ' : '') . ($data['last_name'] ?? '')),
            'phone'           => $data['phone'],
            'email'           => $data['email'] ?? null,
            'pickup'          => $data['pickup_address'],
            'dropoff'         => $data['dropoff_address'],
            'distance_km'     => $data['distance_km'] ?? null,
            'eta_minutes'     => $data['eta_minutes'] ?? null,
            'estimated_price' => $submittedPrice,
            'truck_type'      => $quotation->truckType->name ?? null,
            'vehicle_make'    => $data['vehicle_make'] ?? null,
            'vehicle_model'   => $data['vehicle_model'] ?? null,
            'vehicle_year'    => $data['vehicle_year'] ?? null,
            'vehicle_color'   => $data['vehicle_color'] ?? null,
            'vehicle_plate'   => $data['vehicle_plate_number'] ?? null,
            'notes'           => $data['notes'] ?? null,
        ]]);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['redirect' => route('booking.confirmed')]);
        }

        return redirect()->route('booking.confirmed');
    }

    public function update(Request $request, Booking $booking)
    {
        $customer = $this->resolveAuthenticatedCustomer();

        abort_unless($customer && (int) $booking->customer_id === (int) $customer->id, 403);

        if (! in_array((string) $booking->status, ['requested', 'reviewed', 'quoted', 'quotation_sent'], true)) {
            return redirect()->route('customer.track', $booking)
                ->with('error', 'This booking can no longer be edited because dispatch is already preparing the tow unit.');
        }

        $validated = $request->validate([
            'truck_type_id'  => 'required|exists:truck_types,id',
            'pickup_address' => 'required|string|max:255',
            'dropoff_address' => 'required|string|max:255|different:pickup_address',
            'pickup_notes'   => 'nullable|string|max:1000',
            'distance_km'    => 'required|numeric|min:0.1|max:1000',
        ]);

        try {
            $this->bookingService->checkDuplicateActiveRoute(
                $customer,
                [...$validated, 'service_type' => $booking->service_type],
                (int) $booking->id
            );
        } catch (DuplicateActiveRouteException $e) {
            return back()->withInput()->withErrors(['phone' => $e->getMessage()]);
        }

        $updatedBooking = $this->bookingService->refreshBookingForCustomerChange($booking, $validated);

        if ($updatedBooking->quotation_generated) {
            $initialQuotePath = $this->documentGenerationService->generateQuotation($updatedBooking);

            $updatedBooking->update($this->bookingService->filterPayloadForTable('bookings', [
                'initial_quote_path' => $initialQuotePath,
            ]));

            $updatedBooking->refresh()->loadMissing(['customer', 'truckType']);

            if (filled($updatedBooking->customer?->email)) {
                Mail::to($updatedBooking->customer->email)->send(new BookingAcceptedMail($updatedBooking));
            }
        }

        BookingStatusUpdated::safeFire($updatedBooking);

        return redirect()->route('customer.track', $updatedBooking)
            ->with('success', 'Booking details updated and the quotation record was refreshed automatically.');
    }

    public function showQuotationReview(Request $request, Booking $booking)
    {
        abort_unless($request->hasValidSignature(), 403);

        $booking->syncQuotationLifecycle();
        $booking->refresh()->loadMissing(['customer', 'truckType']);

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

    protected function resolveAuthoritativeQuotation(Booking $booking): ?Quotation
    {
        $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();

        if ($quotation || ! $booking->group_code) {
            return $quotation;
        }

        $anchorId = Booking::where('group_code', $booking->group_code)
            ->orderBy('id')
            ->value('id');

        return $anchorId
            ? Quotation::where('source_booking_id', $anchorId)->current()->first()
            : null;
    }

    protected function processQuotationResponse(Request $request, Booking $booking, bool $fromEmail = false)
    {
        $redirectUrl = $this->quotationRedirectUrl($request, $booking, $fromEmail);

        $quotation = $this->resolveAuthoritativeQuotation($booking);

        if (! $quotation || (int) $quotation->customer_id !== (int) $booking->customer_id) {
            return redirect()->to($redirectUrl)
                ->with('error', 'This booking is not waiting for quotation approval right now.');
        }

        if ($quotation->status !== 'sent') {
            return redirect()->to($redirectUrl)
                ->with('error', 'This booking is not waiting for quotation approval right now.');
        }

        if ($quotation->isExpired()) {
            return redirect()->to($redirectUrl)
                ->with('error', 'This quotation has already expired. Dispatch will send an updated quotation soon.');
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
            try {
                $booking = $this->quotationService->acceptQuotation($quotation);
            } catch (\Exception $e) {
                return redirect()->to($redirectUrl)
                    ->with('error', 'Dispatch is still reviewing the latest adjustment for this booking.');
            }

            $booking->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
            $finalQuotePath = $this->documentGenerationService->generateQuotation($booking, true);
            $booking->update(['final_quote_path' => $finalQuotePath]);

            return redirect()->to($this->quotationRedirectUrl($request, $booking, $fromEmail))
                ->with('success', 'Quotation accepted. Your final quotation is confirmed.');
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

        $this->quotationService->negotiateQuotation(
            $quotation,
            $counterOffer > 0 ? $counterOffer : null,
            $customerNote !== '' ? $customerNote : 'Customer requested a quotation adjustment.',
        );

        return redirect()->to($redirectUrl)
            ->with('success', 'Your negotiation request was sent to dispatch for review.');
    }

    protected function quotationRedirectUrl(Request $request, Booking $booking, bool $fromEmail = false, ?string $routeName = null): string
    {
        if ($fromEmail) {
            return route($routeName ?? 'quotation.review', [
                'booking'   => $booking,
                'expires'   => $request->query('expires'),
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
            ->when(Schema::hasColumn('customers', 'user_id'), fn ($q) => $q->where('user_id', $user->id))
            ->when(filled($user->email ?? null), fn ($q) => $q->orWhere('email', $user->email))
            ->first();
    }
}
