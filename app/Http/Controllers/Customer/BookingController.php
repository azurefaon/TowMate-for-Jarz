<?php

namespace App\Http\Controllers\Customer;

use App\Events\BookingStatusUpdated;
use App\Events\NewBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingRequest;
use App\Http\Requests\LandingBookingRequest;
use App\Mail\BookingAcceptedMail;
use App\Mail\BookingReceiptMail;
use App\Mail\BookingRequestReceivedMail;
use App\Mail\FinalQuotationConfirmedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Services\BookingService;
use App\Services\DocumentGenerationService;
use App\Services\QuotationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class BookingController extends Controller
{
    protected BookingService $bookingService;
    protected DocumentGenerationService $documentGenerationService;
    protected QuotationService $quotationService;

    public function __construct(
        BookingService $bookingService,
        DocumentGenerationService $documentGenerationService,
        QuotationService $quotationService
    ) {
        $this->bookingService = $bookingService;
        $this->documentGenerationService = $documentGenerationService;
        $this->quotationService = $quotationService;
    }

    public function store(BookingRequest $request)
    {
        $data = $this->applyDispatchAvailabilityRules($request->validatedData(), $request);

        $existingCustomer = $this->findExistingCustomerForBooking($data, $this->resolveAuthenticatedCustomer());

        if ($restrictedResponse = $this->rejectRestrictedCustomer($existingCustomer)) {
            return $restrictedResponse;
        }

        // Check for active quotation
        if ($existingCustomer && $this->quotationService->hasActiveQuotation($existingCustomer->id)) {
            $activeQuotation = $this->quotationService->getActiveQuotation($existingCustomer->id);
            $timeRemaining = $activeQuotation->getTimeRemaining();

            // If expired, allow new quotation
            if (!$timeRemaining || !$timeRemaining['expired']) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'phone' => 'You have a pending quotation (Ref: ' . $activeQuotation->quotation_number . '). ' .
                            'Please accept, reject, or wait for it to expire (' . ($timeRemaining['message'] ?? 'expired') . ') before requesting a new quotation.',
                    ]);
            }
        }

        if ($request->hasFile('vehicle_images')) {
            $paths = array_map(
                fn($file) => $this->processAndStoreVehicleImage($file),
                $request->file('vehicle_images')
            );
            $data['vehicle_image_path'] = json_encode(array_values($paths));
        }

        // Create or find customer
        $customer = $existingCustomer ?? $this->bookingService->resolveCustomer($data, Auth::user());

        $pricing = $this->bookingService->calculatePricing($data);

        $submittedPrice = isset($data['price']) && is_numeric($data['price']) && (float)$data['price'] > 0
            ? (float) $data['price']
            : $pricing['final_total'];

        $quotation = $this->quotationService->createQuotation([
            'customer_id'        => $customer->id,
            'truck_type_id'      => $data['truck_type_id'],
            'pickup_address'     => $data['pickup_address'],
            'dropoff_address'    => $data['dropoff_address'],
            'pickup_notes'       => $data['pickup_notes'] ?? $data['pickup_landmark'] ?? null,
            'distance_km'        => $data['distance_km'] ?? $pricing['distance_km'],
            'vehicle_make'       => $data['vehicle_make'] ?? null,
            'vehicle_model'      => $data['vehicle_model'] ?? null,
            'vehicle_year'       => $data['vehicle_year'] ?? null,
            'vehicle_color'      => $data['vehicle_color'] ?? null,
            'vehicle_plate_number' => $data['vehicle_plate_number'] ?? null,
            'vehicle_image_path' => $data['vehicle_image_path'] ?? null,
            'estimated_price'    => $submittedPrice, // ✅ exact amount customer saw and confirmed
            'additional_fee'     => 0,
            'eta_minutes'        => $data['eta_minutes'] ?? null,
        ]);

        return redirect()->route('customer.dashboard')
            ->with('success', 'Quotation request submitted! Reference: ' . $quotation->quotation_number . '. We will send you a quotation shortly.');
    }

    public function landingStore(LandingBookingRequest $request)
    {
        $data = $this->applyDispatchAvailabilityRules($request->validatedData(), $request);

        $existingCustomer = $this->findExistingCustomerForBooking($data);

        if ($existingCustomer) {
            $cooldownStatuses = ['cancelled', 'rejected'];

            if ($restrictedResponse = $this->rejectRestrictedCustomer($existingCustomer)) {
                return $restrictedResponse;
            }

            if ($this->quotationService->hasActiveQuotation($existingCustomer->id)) {
                $activeQuotation = $this->quotationService->getActiveQuotation($existingCustomer->id);
                $timeRemaining = $activeQuotation->getTimeRemaining();

                if (!$timeRemaining || !$timeRemaining['expired']) {
                    return back()
                        ->withInput()
                        ->withErrors([
                            'phone' => 'You have a pending quotation (Ref: ' . $activeQuotation->quotation_number . '). ' .
                                'Please wait for it to expire (' . ($timeRemaining['message'] ?? 'expired') . ') before requesting a new quotation.',
                        ]);
                }
            }

            $recentSpamBooking = Booking::where('customer_id', $existingCustomer->id)
                ->whereIn('status', $cooldownStatuses)
                ->latest('created_at')
                ->first();

            if ($recentSpamBooking && $recentSpamBooking->created_at?->gt(now()->subMinutes(5))) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'phone' => 'Please wait a few minutes before requesting again.',
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

        if (($data['service_type'] ?? 'book_now') === 'schedule' && ! empty($data['scheduled_date'])) {
            $cap = \DB::table('booking_capacity')
                ->where('booking_date', $data['scheduled_date'])
                ->first();

            $slotsUsed = $cap ? $cap->slots_used : 0;
            $slotsMax  = $cap ? $cap->slots_max  : 2;

            if ($slotsUsed >= $slotsMax) {
                return back()->withInput()->withErrors([
                    'scheduled_date' => 'Sorry, this date is fully booked (max ' . $slotsMax . ' scheduled tows per day). Please choose another date.',
                ]);
            }
        }

        if ($request->hasFile('vehicle_images')) {
            $paths = array_map(
                fn($file) => $this->processAndStoreVehicleImage($file),
                $request->file('vehicle_images')
            );
            $data['vehicle_image_path'] = json_encode(array_values($paths));
        }

        if ($request->has('eta_minutes')) {
            $data['eta_minutes'] = $request->input('eta_minutes');
        } elseif ($request->has('duration_min')) {
            $data['eta_minutes'] = $request->input('duration_min');
        }

        $availability = $this->bookingService->dispatchAvailability();
        $readyUnits   = (int) ($availability['ready_units_count'] ?? 0);

        $extraVehicles = [];
        $totalExtraPrice = 0;

        foreach ([2, 3, 4] as $vn) {
            $addKey    = $vn === 2 ? 'add_second_vehicle' : 'add_vehicle_' . $vn;
            $ttIdKey   = 'vehicle_' . $vn . '_truck_type_id';
            $priceKey  = 'vehicle_' . $vn . '_price';
            $schedKey  = 'vehicle_' . $vn . '_is_scheduled';
            $dateKey   = 'vehicle_' . $vn . '_scheduled_date';
            $timeKey   = 'vehicle_' . $vn . '_scheduled_time';
            $distKey   = 'vehicle_' . $vn . '_distance_km';

            $wantThis = (string) ($data[$addKey] ?? '0') === '1'
                && filled($data[$ttIdKey] ?? null);

            if (! $wantThis) {
                continue;
            }

            $evTruckTypeInput = $data[$ttIdKey];
            $evTruckType = is_numeric($evTruckTypeInput)
                ? TruckType::find($evTruckTypeInput)
                : TruckType::where('name', 'like', '%' . $evTruckTypeInput . '%')->first();

            if (! $evTruckType) {
                continue;
            }

            $evIsScheduled = (string) ($data[$schedKey] ?? '0') === '1';
            $evDate = $data[$dateKey] ?? null;
            $evTime = $data[$timeKey] ?? null;

            if (! $evIsScheduled && $vn === 2 && ($data['service_type'] ?? 'book_now') === 'book_now' && $readyUnits < 2) {
                $evIsScheduled = true;
                $autoAt = Carbon::now()->addHour()->second(0);
                $evDate = $autoAt->toDateString();
                $evTime = $autoAt->format('H:i');
            }

            $evDistance = (float) ($data[$distKey] ?? $data['distance_km'] ?? 0);

            $evPricing = $this->bookingService->calculatePricing([
                'truck_type_id' => $evTruckType->id,
                'distance_km'   => $evDistance,
                'customer_type' => $data['customer_type'] ?? 'regular',
            ]);

            $evPrice = isset($data[$priceKey]) && is_numeric($data[$priceKey]) && (float) $data[$priceKey] > 0
                ? (float) $data[$priceKey]
                : $evPricing['final_total'];

            $totalExtraPrice += $evIsScheduled ? 0 : $evPrice;

            $extraVehicles[] = [
                'vehicle_index'  => $vn,
                'truck_type_id'  => $evTruckType->id,
                'truck_type_name' => $evTruckType->name,
                'truck_class'    => $evTruckType->class ?? '',
                'distance_km'    => $evDistance,
                'estimated_price' => $evPrice,
                'service_type'   => $evIsScheduled ? 'schedule' : ($data['service_type'] ?? 'book_now'),
                'scheduled_date' => $evDate,
                'scheduled_time' => $evTime,
            ];
        }

        $customer = $existingCustomer ?? $this->bookingService->resolveCustomer($data);

        $pricing = $this->bookingService->calculatePricing($data);

        $submittedPrice = isset($data['price']) && is_numeric($data['price']) && (float)$data['price'] > 0
            ? (float) $data['price']
            : $pricing['final_total'];

        $quotation = $this->quotationService->createQuotation([
            'customer_id'      => $customer->id,
            'truck_type_id'    => $data['truck_type_id'],
            'pickup_address'   => $data['pickup_address'],
            'dropoff_address'  => $data['dropoff_address'],
            'pickup_notes'     => $data['pickup_notes'] ?? $data['pickup_landmark'] ?? null,
            'distance_km'      => $data['distance_km'] ?? $pricing['distance_km'],
            'vehicle_make'     => $data['vehicle_make'] ?? null,
            'vehicle_model'    => $data['vehicle_model'] ?? null,
            'vehicle_year'     => $data['vehicle_year'] ?? null,
            'vehicle_color'    => $data['vehicle_color'] ?? null,
            'vehicle_plate_number' => $data['vehicle_plate_number'] ?? null,
            'vehicle_image_path'   => $data['vehicle_image_path'] ?? null,
            'extra_vehicles'   => count($extraVehicles) ? $extraVehicles : null,
            'estimated_price'  => $submittedPrice,
            'additional_fee'   => 0,
            'eta_minutes'      => $data['eta_minutes'] ?? null,
            'service_type'     => $data['service_type'] ?? 'book_now',
            'scheduled_date'   => $data['scheduled_date'] ?? null,
            'scheduled_time'   => $data['scheduled_time'] ?? null,
        ]);

        $quotation->load(['customer', 'truckType']);

        $serviceLabel = ($data['service_type'] ?? 'book_now') === 'schedule' ? 'Scheduled' : 'Book Now';
        $hasScheduledExtra = collect($extraVehicles)->contains(fn($ev) => ($ev['service_type'] ?? '') === 'schedule');
        $grandTotal = $submittedPrice + $totalExtraPrice;

        $extraSummary = collect($extraVehicles)->map(fn($ev) => [
            'vehicle_index'  => $ev['vehicle_index'],
            'truck_type'     => $ev['truck_type_name'],
            'service_type'   => $ev['service_type'] === 'schedule' ? 'Scheduled' : 'Book Now',
            'estimated_price' => $ev['estimated_price'],
            'scheduled_date' => $ev['scheduled_date'] ?? null,
        ])->values()->all();

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
            'estimated_price' => $submittedPrice,
            'grand_total'     => $grandTotal,
            'has_scheduled_extra' => $hasScheduledExtra,
            'truck_type'      => $quotation->truckType->name ?? null,
            'vehicle_make'    => $data['vehicle_make'] ?? null,
            'vehicle_model'   => $data['vehicle_model'] ?? null,
            'vehicle_year'    => $data['vehicle_year'] ?? null,
            'vehicle_color'   => $data['vehicle_color'] ?? null,
            'vehicle_plate'   => $data['vehicle_plate_number'] ?? null,
            'notes'           => $data['notes'] ?? null,
            'extra_vehicles'  => $extraSummary,
        ]]);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['redirect' => route('booking.confirmed')]);
        }

        return redirect()->route('booking.confirmed');
    }

    protected function findExistingCustomerForBooking(array $data, ?Customer $preferredCustomer = null): ?Customer
    {
        if ($preferredCustomer) {
            return $preferredCustomer;
        }

        return Customer::query()
            ->where(function ($query) use ($data) {
                $query->where('phone', $data['phone'] ?? null);

                if (filled($data['email'] ?? null)) {
                    $query->orWhere('email', $data['email']);
                }
            })
            ->first();
    }

    protected function rejectRestrictedCustomer(?Customer $customer): ?\Illuminate\Http\RedirectResponse
    {
        if (! $customer || ! $customer->is_blacklisted) {
            return null;
        }

        return back()
            ->withInput()
            ->withErrors([
                'phone' => 'This customer account is currently restricted from booking. Please contact dispatch for assistance.',
            ]);
    }

    protected function rejectDuplicateActiveRouteBooking(?Customer $customer, array $data, ?int $ignoreBookingId = null): ?\Illuminate\Http\RedirectResponse
    {
        if (! $customer) {
            return null;
        }

        $pickupAddress = $this->normalizeRouteAddress($data['pickup_address'] ?? null);
        $dropoffAddress = $this->normalizeRouteAddress($data['dropoff_address'] ?? null);

        if ($pickupAddress === '' || $dropoffAddress === '') {
            return null;
        }

        $duplicateActiveBooking = Booking::query()
            ->where('customer_id', $customer->id)
            ->when($ignoreBookingId, function ($query) use ($ignoreBookingId) {
                $query->where('id', '!=', $ignoreBookingId);
            })
            ->whereIn('status', ['requested', 'reviewed', 'quoted', 'quotation_sent', 'confirmed', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job'])
            ->get()
            ->first(function (Booking $booking) use ($pickupAddress, $dropoffAddress) {
                return $this->normalizeRouteAddress($booking->pickup_address) === $pickupAddress
                    && $this->normalizeRouteAddress($booking->dropoff_address) === $dropoffAddress;
            });

        if (! $duplicateActiveBooking) {
            return null;
        }

        return back()
            ->withInput()
            ->withErrors([
                'phone' => 'You already have an active booking for this same pickup and drop-off route.',
            ]);
    }

    protected function normalizeRouteAddress(?string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));
    }

    protected function applyDispatchAvailabilityRules(array $data, Request $request): array
    {
        $serviceType = ($data['service_type'] ?? 'book_now') === 'schedule' ? 'schedule' : 'book_now';

        if ($serviceType !== 'book_now') {
            return $data;
        }

        $availability = $this->bookingService->dispatchAvailability();

        if ($availability['book_now_enabled'] ?? false) {
            return $data;
        }

        $scheduledFor = Carbon::now()->addHour()->second(0);
        $data['service_type'] = 'schedule';
        $data['scheduled_date'] = $data['scheduled_date'] ?? $scheduledFor->toDateString();
        $data['scheduled_time'] = $data['scheduled_time'] ?? $scheduledFor->format('H:i');

        return $data;
    }

    public function update(Request $request, Booking $booking)
    {
        $customer = $this->resolveAuthenticatedCustomer();

        abort_unless($customer && (int) $booking->customer_id === (int) $customer->id, 403);

        if (! in_array((string) $booking->status, ['requested', 'reviewed', 'quoted', 'quotation_sent', 'confirmed'], true)) {
            return redirect()->route('customer.track', $booking)
                ->with('error', 'This booking can no longer be edited because dispatch is already preparing the tow unit.');
        }

        $validated = $request->validate([
            'truck_type_id' => 'required|exists:truck_types,id',
            'pickup_address' => 'required|string|max:255',
            'dropoff_address' => 'required|string|max:255|different:pickup_address',
            'pickup_notes' => 'nullable|string|max:1000',
            'distance_km' => 'required|numeric|min:0.1|max:1000',
        ]);

        if ($duplicateResponse = $this->rejectDuplicateActiveRouteBooking($customer, $validated, (int) $booking->id)) {
            return $duplicateResponse;
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

        event(new BookingStatusUpdated($updatedBooking));

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

    protected function processQuotationResponse(Request $request, Booking $booking, bool $fromEmail = false)
    {
        $redirectUrl = $this->quotationRedirectUrl($request, $booking, $fromEmail);

        $booking->syncQuotationLifecycle();
        $booking->refresh();

        if ((string) $booking->quotation_status === 'expired') {
            return redirect()->to($redirectUrl)
                ->with('error', 'This quotation has already expired. Dispatch will send an updated quotation soon.');
        }

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
                'quotation_status' => 'accepted',
                'customer_approved_at' => now(),
                'price_locked_at' => now(),
                'negotiation_requested_at' => null,
                'counter_offer_amount' => null,
            ]);

            $booking->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
            $finalQuotePath = $this->documentGenerationService->generateQuotation($booking, true);
            $booking->update(['final_quote_path' => $finalQuotePath]);
            $booking->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
            event(new BookingStatusUpdated($booking));

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
            'quotation_status' => 'active',
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

    private function processAndStoreVehicleImage(\Illuminate\Http\UploadedFile $file): string
    {
        // Magic-bytes check — reads actual file headers, not just extension/MIME
        $imageInfo = @getimagesize($file->getRealPath());
        abort_unless($imageInfo !== false, 422, 'Uploaded file is not a valid image.');

        // Strict allow-list: IMAGETYPE_JPEG (2) and IMAGETYPE_PNG (3) only — GIF (1) and all others blocked
        abort_unless(in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true), 422, 'Only JPG and PNG images are accepted.');

        // Re-encode via GD to strip embedded polyglot payloads and EXIF metadata
        $source = imagecreatefromstring(file_get_contents($file->getRealPath()));
        abort_unless($source !== false, 422, 'Could not process the uploaded image.');

        $tmpPath = tempnam(sys_get_temp_dir(), 'towmate_img_') . '.jpg';
        imagejpeg($source, $tmpPath, 85);
        imagedestroy($source);

        $storedPath = \Illuminate\Support\Facades\Storage::disk('public')
            ->putFile('vehicle_images', new \Illuminate\Http\File($tmpPath));

        @unlink($tmpPath);

        return $storedPath;
    }
}
