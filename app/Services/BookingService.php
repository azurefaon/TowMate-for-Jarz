<?php

namespace App\Services;

use App\Exceptions\Booking\ActiveQuotationException;
use App\Exceptions\Booking\BlacklistedCustomerException;
use App\Exceptions\Booking\DuplicateActiveRouteException;
use App\Exceptions\Booking\NoTruckTypeAvailableException;
use App\Exceptions\Booking\ScheduledCapacityException;
use App\Exceptions\Booking\SpamCooldownException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class BookingService
{
    protected TeamLeaderAvailabilityService $teamLeaderAvailability;
    protected QuotationService $quotationService;

    public function __construct(
        TeamLeaderAvailabilityService $teamLeaderAvailability,
        QuotationService $quotationService,
    ) {
        $this->teamLeaderAvailability = $teamLeaderAvailability;
        $this->quotationService = $quotationService;
    }

    // ──────────────────────────────────────────────────────────────
    // Main orchestration methods (reusable by web + API controllers)
    // ──────────────────────────────────────────────────────────────

    public function createCustomerQuotation(array $data, ?Authenticatable $user, ?Customer $existingCustomer = null): Quotation
    {
        $data = $this->applyDispatchAvailabilityRules($data);

        $customer = $this->findExistingCustomer($data, $existingCustomer);
        $this->checkCustomerEligibility($customer);

        $customer = $customer ?? $this->resolveCustomer($data, $user);
        $pricing = $this->calculatePricing($data);

        $submittedPrice = isset($data['price']) && is_numeric($data['price']) && (float) $data['price'] > 0
            ? (float) $data['price']
            : $pricing['final_total'];

        return $this->quotationService->createQuotation([
            'customer_id'          => $customer->id,
            'truck_type_id'        => $data['truck_type_id'],
            'pickup_address'       => $data['pickup_address'],
            'dropoff_address'      => $data['dropoff_address'],
            'pickup_notes'         => $data['pickup_notes'] ?? $data['pickup_landmark'] ?? null,
            'distance_km'          => $data['distance_km'] ?? $pricing['distance_km'],
            'vehicle_make'         => $data['vehicle_make'] ?? null,
            'vehicle_model'        => $data['vehicle_model'] ?? null,
            'vehicle_year'         => $data['vehicle_year'] ?? null,
            'vehicle_color'        => $data['vehicle_color'] ?? null,
            'vehicle_plate_number' => $data['vehicle_plate_number'] ?? null,
            'vehicle_image_path'   => $data['vehicle_image_path'] ?? null,
            'estimated_price'      => $submittedPrice,
            'additional_fee'       => 0,
            'eta_minutes'          => $data['eta_minutes'] ?? null,
        ]);
    }

    public function createLandingQuotation(array $data): array
    {
        $data = $this->applyDispatchAvailabilityRules($data);

        $existingCustomer = $this->findExistingCustomer($data);
        $this->checkCustomerEligibility($existingCustomer, checkSpam: true);

        $truckType = $this->resolveTruckTypeFromInput($data['truck_type_id']);
        $data['truck_type_id'] = $truckType->id;

        if (($data['service_type'] ?? 'book_now') === 'schedule' && ! empty($data['scheduled_date'])) {
            $this->checkScheduledCapacity($data['scheduled_date']);
        }

        $data = $this->applySecondVehicleDispatchRules($data);

        $customer = $existingCustomer ?? $this->resolveCustomer($data);
        $pricing = $this->calculatePricing($data);

        $submittedPrice = isset($data['price']) && is_numeric($data['price']) && (float) $data['price'] > 0
            ? (float) $data['price']
            : $pricing['final_total'];

        $quotation = $this->quotationService->createQuotation([
            'customer_id'          => $customer->id,
            'truck_type_id'        => $data['truck_type_id'],
            'pickup_address'       => $data['pickup_address'],
            'dropoff_address'      => $data['dropoff_address'],
            'pickup_notes'         => $data['pickup_notes'] ?? $data['pickup_landmark'] ?? null,
            'distance_km'          => $data['distance_km'] ?? $pricing['distance_km'],
            'vehicle_make'         => $data['vehicle_make'] ?? null,
            'vehicle_model'        => $data['vehicle_model'] ?? null,
            'vehicle_year'         => $data['vehicle_year'] ?? null,
            'vehicle_color'        => $data['vehicle_color'] ?? null,
            'vehicle_plate_number' => $data['vehicle_plate_number'] ?? null,
            'vehicle_image_path'   => $data['vehicle_image_path'] ?? null,
            'estimated_price'      => $submittedPrice,
            'additional_fee'       => 0,
            'eta_minutes'          => $data['eta_minutes'] ?? null,
            'service_type'         => $data['service_type'] ?? 'book_now',
            'scheduled_date'       => $data['scheduled_date'] ?? null,
            'scheduled_time'       => $data['scheduled_time'] ?? null,
        ]);

        $quotation->load(['customer', 'truckType']);

        $secondQuotation = $this->createSecondVehicleQuotation($data, $customer, $pricing);

        return [
            'quotation'        => $quotation,
            'second_quotation' => $secondQuotation,
            'submitted_price'  => $submittedPrice,
            'data'             => $data,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // Customer eligibility guards (throw domain exceptions)
    // ──────────────────────────────────────────────────────────────

    public function findExistingCustomer(array $data, ?Customer $preferredCustomer = null): ?Customer
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

    public function checkCustomerEligibility(?Customer $customer, bool $checkSpam = false): void
    {
        if (! $customer) {
            return;
        }

        if ($customer->is_blacklisted) {
            throw new BlacklistedCustomerException(
                'This customer account is currently restricted from booking. Please contact dispatch for assistance.'
            );
        }

        if ($this->quotationService->hasActiveQuotation($customer->id)) {
            $active = $this->quotationService->getActiveQuotation($customer->id);
            $timeRemaining = $active->getTimeRemaining();

            if (! $timeRemaining || ! $timeRemaining['expired']) {
                throw new ActiveQuotationException(
                    $active->quotation_number,
                    $timeRemaining['message'] ?? 'expired'
                );
            }
        }

        if ($checkSpam) {
            $recent = Booking::where('customer_id', $customer->id)
                ->whereIn('status', ['cancelled', 'rejected'])
                ->latest('created_at')
                ->first();

            if ($recent && $recent->created_at?->gt(now()->subMinutes(5))) {
                throw new SpamCooldownException('Please wait a few minutes before requesting again.');
            }
        }
    }

    public function checkDuplicateActiveRoute(?Customer $customer, array $data, ?int $ignoreBookingId = null): void
    {
        if (! $customer) {
            return;
        }

        $pickup = $this->normalizeAddress($data['pickup_address'] ?? null);
        $dropoff = $this->normalizeAddress($data['dropoff_address'] ?? null);

        if ($pickup === '' || $dropoff === '') {
            return;
        }

        $activeStatuses = [
            'requested', 'reviewed', 'quoted', 'quotation_sent', 'confirmed',
            'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job',
        ];

        $duplicate = Booking::query()
            ->where('customer_id', $customer->id)
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->whereIn('status', $activeStatuses)
            ->get()
            ->first(fn (Booking $b) =>
                $this->normalizeAddress($b->pickup_address) === $pickup &&
                $this->normalizeAddress($b->dropoff_address) === $dropoff
            );

        if ($duplicate) {
            throw new DuplicateActiveRouteException(
                'You already have an active booking for this same pickup and drop-off route.'
            );
        }
    }

    // ──────────────────────────────────────────────────────────────
    // Dispatch / availability helpers
    // ──────────────────────────────────────────────────────────────

    public function applyDispatchAvailabilityRules(array $data): array
    {
        if (($data['service_type'] ?? 'book_now') === 'schedule') {
            return $data;
        }

        $availability = $this->dispatchAvailability();

        if ($availability['book_now_enabled'] ?? false) {
            return $data;
        }

        $scheduledFor = Carbon::now()->addHour()->second(0);
        $data['service_type'] = 'schedule';
        $data['scheduled_date'] = $data['scheduled_date'] ?? $scheduledFor->toDateString();
        $data['scheduled_time'] = $data['scheduled_time'] ?? $scheduledFor->format('H:i');

        return $data;
    }

    // ──────────────────────────────────────────────────────────────
    // Truck type / capacity helpers
    // ──────────────────────────────────────────────────────────────

    public function resolveTruckTypeFromInput(mixed $input): TruckType
    {
        $truckType = is_numeric($input)
            ? TruckType::find($input)
            : TruckType::where('name', 'like', '%' . $input . '%')->first();

        $truckType ??= TruckType::first();

        if (! $truckType) {
            throw new NoTruckTypeAvailableException('No vehicle types available. Please contact support.');
        }

        return $truckType;
    }

    public function checkScheduledCapacity(string $date): void
    {
        $cap = DB::table('booking_capacity')->where('booking_date', $date)->first();
        $slotsUsed = $cap ? $cap->slots_used : 0;
        $slotsMax = $cap ? $cap->slots_max : 2;

        if ($slotsUsed >= $slotsMax) {
            throw new ScheduledCapacityException(
                "Sorry, this date is fully booked (max {$slotsMax} scheduled tows per day). Please choose another date."
            );
        }
    }

    // ──────────────────────────────────────────────────────────────
    // File handling
    // ──────────────────────────────────────────────────────────────

    public function storeVehicleImages(array $files): string
    {
        $paths = array_map(fn ($file) => $this->storeVehicleImage($file), $files);

        return json_encode(array_values($paths));
    }

    protected function storeVehicleImage(UploadedFile $file): string
    {
        // Magic-bytes check — reads actual file headers, not just extension/MIME
        $imageInfo = @getimagesize($file->getRealPath());
        abort_unless($imageInfo !== false, 422, 'Uploaded file is not a valid image.');

        // Strict allow-list: IMAGETYPE_JPEG (2) and IMAGETYPE_PNG (3) only
        abort_unless(in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true), 422, 'Only JPG and PNG images are accepted.');

        // Re-encode via GD to strip polyglot payloads and EXIF metadata
        $source = imagecreatefromstring(file_get_contents($file->getRealPath()));
        abort_unless($source !== false, 422, 'Could not process the uploaded image.');

        $tmpPath = tempnam(sys_get_temp_dir(), 'towmate_img_') . '.jpg';
        imagejpeg($source, $tmpPath, 85);
        imagedestroy($source);

        $storedPath = Storage::disk('public')->putFile('vehicle_images', new File($tmpPath));

        @unlink($tmpPath);

        return $storedPath;
    }

    // ──────────────────────────────────────────────────────────────
    // Address helpers
    // ──────────────────────────────────────────────────────────────

    public function normalizeAddress(?string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));
    }

    // ──────────────────────────────────────────────────────────────
    // Private helpers for createLandingQuotation
    // ──────────────────────────────────────────────────────────────

    protected function applySecondVehicleDispatchRules(array $data): array
    {
        $wantSecond = (string) ($data['add_second_vehicle'] ?? '0') === '1'
            && filled($data['vehicle_2_truck_type_id'] ?? null);

        if (! $wantSecond) {
            $data['_want_second_vehicle'] = false;
            return $data;
        }

        $availability = $this->dispatchAvailability();
        $readyUnits = (int) ($availability['ready_units_count'] ?? 0);

        if (($data['service_type'] ?? 'book_now') === 'book_now' && $readyUnits < 2) {
            $at = Carbon::now()->addHour()->second(0);
            $data['vehicle_2_service_type'] = 'schedule';
            $data['vehicle_2_scheduled_date'] = $at->toDateString();
            $data['vehicle_2_scheduled_time'] = $at->format('H:i');
            $data['vehicle_2_schedule_reason'] = $readyUnits === 1
                ? 'Only 1 unit is available right now — your second vehicle is auto-scheduled for ' . $at->format('g:i A') . '.'
                : 'No units online right now — your second vehicle is auto-scheduled for ' . $at->format('g:i A') . '.';
        } else {
            $data['vehicle_2_service_type'] = $data['service_type'] ?? 'book_now';
            $data['vehicle_2_scheduled_date'] = $data['scheduled_date'] ?? null;
            $data['vehicle_2_scheduled_time'] = $data['scheduled_time'] ?? null;
            $data['vehicle_2_schedule_reason'] = null;
        }

        $data['_want_second_vehicle'] = true;

        return $data;
    }

    protected function createSecondVehicleQuotation(array $data, Customer $customer, array $primaryPricing): ?Quotation
    {
        if (! ($data['_want_second_vehicle'] ?? false)) {
            return null;
        }

        $v2TruckType = is_numeric($data['vehicle_2_truck_type_id'])
            ? TruckType::find($data['vehicle_2_truck_type_id'])
            : TruckType::where('name', 'like', '%' . $data['vehicle_2_truck_type_id'] . '%')->first();

        if (! $v2TruckType) {
            return null;
        }

        $useOverride = (string) ($data['vehicle_2_route_override'] ?? '0') === '1'
            && filled($data['vehicle_2_pickup_address'] ?? null)
            && filled($data['vehicle_2_dropoff_address'] ?? null);

        $v2Pickup = $useOverride ? $data['vehicle_2_pickup_address'] : $data['pickup_address'];
        $v2Dropoff = $useOverride ? $data['vehicle_2_dropoff_address'] : $data['dropoff_address'];
        $v2Distance = $useOverride
            ? ($data['vehicle_2_distance_km'] ?? $data['distance_km'] ?? 0)
            : ($data['distance_km'] ?? 0);
        $v2Eta = $useOverride ? ($data['vehicle_2_eta_minutes'] ?? null) : ($data['eta_minutes'] ?? null);

        $v2Pricing = $this->calculatePricing([
            'truck_type_id' => $v2TruckType->id,
            'distance_km'   => $v2Distance,
            'customer_type' => $data['customer_type'] ?? 'regular',
        ]);

        $v2Price = isset($data['vehicle_2_price']) && is_numeric($data['vehicle_2_price']) && (float) $data['vehicle_2_price'] > 0
            ? (float) $data['vehicle_2_price']
            : $v2Pricing['final_total'];

        $quotation = $this->quotationService->createQuotation([
            'customer_id'        => $customer->id,
            'truck_type_id'      => $v2TruckType->id,
            'pickup_address'     => $v2Pickup,
            'dropoff_address'    => $v2Dropoff,
            'pickup_notes'       => $data['pickup_notes'] ?? null,
            'distance_km'        => $v2Distance,
            'vehicle_image_path' => $data['vehicle_image_path'] ?? null,
            'estimated_price'    => $v2Price,
            'additional_fee'     => 0,
            'eta_minutes'        => $v2Eta,
            'service_type'       => $data['vehicle_2_service_type'] ?? 'book_now',
            'scheduled_date'     => $data['vehicle_2_scheduled_date'] ?? null,
            'scheduled_time'     => $data['vehicle_2_scheduled_time'] ?? null,
        ]);

        $quotation->load(['customer', 'truckType']);

        return $quotation;
    }

    // ──────────────────────────────────────────────────────────────
    // Existing public methods (unchanged)
    // ──────────────────────────────────────────────────────────────

    public function createBooking(array $data, ?Authenticatable $user = null): Booking
    {
        $customer = $this->resolveCustomer($data, $user);
        $pricing = $this->calculatePricing($data);
        $serviceType = $this->resolveServiceType($data);
        $scheduledFor = $this->resolveScheduledFor($data, $serviceType);

        $etaMinutes = null;
        if (isset($data['eta_minutes'])) {
            $etaMinutes = $data['eta_minutes'];
        } elseif (isset($data['duration_min'])) {
            $etaMinutes = $data['duration_min'];
        } elseif (isset($data['route']) && isset($data['route']['duration_min'])) {
            $etaMinutes = $data['route']['duration_min'];
        }

        $payload = $this->filterPayloadForTable('bookings', [
            'customer_id' => $customer->id,
            'truck_type_id' => $data['truck_type_id'],
            'created_by_admin_id' => $data['created_by_admin_id'] ?? $user?->getAuthIdentifier(),
            'age' => $data['age'] ?? $customer->age,
            'pickup_address' => $data['pickup_address'],
            'pickup_notes' => $this->sanitizeText($data['pickup_notes'] ?? $data['pickup_landmark'] ?? null),
            'pickup_lat' => $data['pickup_lat'] ?? null,
            'pickup_lng' => $data['pickup_lng'] ?? null,
            'dropoff_address' => $data['dropoff_address'],
            'dropoff_lat' => $data['drop_lat'] ?? $data['dropoff_lat'] ?? null,
            'dropoff_lng' => $data['drop_lng'] ?? $data['dropoff_lng'] ?? null,
            'distance_km' => $pricing['distance_km'],
            'eta_minutes' => $etaMinutes ?? null,
            'base_rate' => $pricing['base_rate'],
            'per_km_rate' => $pricing['per_km_rate'],
            'computed_total' => $pricing['computed_total'],
            'discount_percentage' => $pricing['discount_percentage'],
            'discount_reason' => $pricing['discount_reason'],
            'additional_fee' => $pricing['additional_fee'],
            'final_total' => $pricing['final_total'],
            'vat_amount' => $pricing['vat_amount'],
            'vat_exclusive_total' => $pricing['vat_exclusive_total'],
            'customer_type' => $pricing['customer_type'],
            'service_type' => $serviceType,
            'scheduled_date' => $scheduledFor?->toDateString(),
            'scheduled_time' => $scheduledFor?->format('H:i'),
            'scheduled_for' => $scheduledFor,
            'confirmation_type' => $data['confirmation_type'] ?? 'system',
            'vehicle_image_path' => $data['vehicle_image_path'] ?? null,
            'notes' => $this->composeNotes($this->composeLocationNotes($data), $serviceType, $scheduledFor),
            'status' => 'requested',
            'quotation_generated' => false,
            'quotation_status' => 'active',
        ]);

        return Booking::create($payload);
    }

    public function resolveCustomer(array $data, ?Authenticatable $user = null): Customer
    {
        $nameParts = $this->resolveNameParts($data);
        $phone = normalize_ph_phone($data['phone'] ?? null) ?? ($data['phone'] ?? null);
        $email = filled($data['email'] ?? null) ? strtolower(trim((string) $data['email'])) : ($user?->email ? strtolower(trim((string) $user->email)) : null);
        $customerType = $this->resolveCustomerType($data);

        if (
            $user
            && Schema::hasTable('customers')
            && Schema::hasColumn('customers', 'user_id')
            && method_exists($user, 'customer')
            && $user->customer
        ) {
            $user->customer->update($this->filterPayloadForTable('customers', [
                'first_name' => $nameParts['first_name'],
                'middle_name' => $nameParts['middle_name'],
                'last_name' => $nameParts['last_name'],
                'full_name' => build_full_name($nameParts['first_name'], $nameParts['middle_name'], $nameParts['last_name']),
                'age' => $data['age'] ?? $user->customer->age,
                'phone' => $phone,
                'email' => $email,
                'customer_type' => $customerType,
                'is_pwd' => $customerType === 'pwd',
                'is_senior' => $customerType === 'senior',
            ]));

            return $user->customer->fresh();
        }

        $existingCustomer = null;

        if ($user || $phone || $email) {
            $existingCustomer = Customer::query()
                ->where(function ($query) use ($user, $phone, $email) {
                    if ($user && Schema::hasColumn('customers', 'user_id')) {
                        $query->orWhere('user_id', $user->getAuthIdentifier());
                    }

                    if ($phone) {
                        $query->orWhere('phone', $phone);
                    }

                    if ($email) {
                        $query->orWhere('email', $email);
                    }
                })
                ->first();
        }

        if ($existingCustomer) {
            $payload = [
                'first_name' => $nameParts['first_name'],
                'middle_name' => $nameParts['middle_name'],
                'last_name' => $nameParts['last_name'],
                'full_name' => build_full_name($nameParts['first_name'], $nameParts['middle_name'], $nameParts['last_name']),
                'age' => $data['age'] ?? $existingCustomer->age,
                'phone' => $phone,
                'email' => $email,
                'customer_type' => $customerType,
                'is_pwd' => $customerType === 'pwd',
                'is_senior' => $customerType === 'senior',
            ];

            if (Schema::hasColumn('customers', 'user_id') && ! $existingCustomer->user_id) {
                $payload['user_id'] = $user?->getAuthIdentifier();
            }

            $existingCustomer->update($this->filterPayloadForTable('customers', $payload));

            return $existingCustomer->fresh();
        }

        return Customer::create($this->filterPayloadForTable('customers', [
            ...(Schema::hasColumn('customers', 'user_id') ? ['user_id' => $user?->getAuthIdentifier()] : []),
            'first_name' => $nameParts['first_name'],
            'middle_name' => $nameParts['middle_name'],
            'last_name' => $nameParts['last_name'],
            'full_name' => build_full_name($nameParts['first_name'], $nameParts['middle_name'], $nameParts['last_name']),
            'age' => $data['age'] ?? null,
            'phone' => $phone,
            'email' => $email,
            'customer_type' => $customerType,
            'is_pwd' => $customerType === 'pwd',
            'is_senior' => $customerType === 'senior',
        ]));
    }

    public function calculatePricing(array $data): array
    {
        $truckType  = TruckType::query()->findOrFail($data['truck_type_id']);
        $distanceKm = $this->resolveDistanceKm($data);
        $baseRate   = (float) $truckType->base_rate;

        // Sum base rates of any extra vehicles
        $extraBaseRates = 0.0;
        $extraVehicles  = is_array($data['extra_vehicles'] ?? null)
            ? $data['extra_vehicles']
            : (is_string($data['extra_vehicles'] ?? null) ? json_decode($data['extra_vehicles'], true) ?? [] : []);
        foreach ($extraVehicles as $ev) {
            $evTruck = TruckType::find($ev['truck_type_id'] ?? null);
            if ($evTruck) {
                $extraBaseRates += (float) $evTruck->base_rate;
            }
        }

        $kmIncrements  = (int) floor($distanceKm / 4);
        $kmCharge      = round($kmIncrements * 200.0, 2);
        $customerType  = $this->resolveCustomerType($data);
        $discount      = $this->resolveBookingDiscount($data, $kmCharge, $customerType);
        $additionalFee = $this->parsePrice($data['additional_fee'] ?? null);

        $subtotal   = round($baseRate + $extraBaseRates + $kmCharge + $additionalFee, 2);
        $finalTotal = max(round($subtotal - $discount['discount_amount'], 2), 0);

        // VAT extraction — final_total is VAT-inclusive at 12%
        $vatExclusive = round($finalTotal / 1.12, 2);
        $vatAmount    = round($finalTotal - $vatExclusive, 2);

        return [
            'distance_km'         => $distanceKm,
            'base_rate'           => $baseRate,
            'per_km_rate'         => (float) $truckType->per_km_rate,
            'distance_fee'        => $kmCharge,
            'km_increments'       => $kmIncrements,
            'excess_km_threshold' => 0.0,
            'excess_km_rate'      => 200.0,
            'excess_km'           => 0.0,
            'excess_fee'          => 0.0,
            'computed_total'      => round($baseRate + $extraBaseRates + $kmCharge, 2),
            'discount_percentage' => $discount['discount_percentage'],
            'discount_reason'     => $discount['discount_reason'],
            'discount_amount'     => $discount['discount_amount'],
            'additional_fee'      => $additionalFee,
            'final_total'         => $finalTotal,
            'vat_amount'          => $vatAmount,
            'vat_exclusive_total' => $vatExclusive,
            'customer_type'       => $customerType,
        ];
    }

    public function calculateQuotationTotals(
        Booking $booking,
        ?string $additionalFee = null,
        ?string $quotedTotal = null,
        ?float $distanceKm = null,
        ?float $discountPercentage = null,
        ?float $baseRate = null,
    ): array {
        $resolvedDistanceKm = max(round((float) ($distanceKm ?? ($booking->distance_km ?? 0)), 2), 0);

        $kmIncrements = (int) floor($resolvedDistanceKm / 4);
        $kmCharge = round($kmIncrements * 200.0, 2);

        // Use the booking's stored base_rate as fallback so quotation re-calculations
        // stay consistent with what was shown to the customer at booking time.
        $resolvedBaseRate = round((float) ($baseRate ?? $booking->base_rate ?? 0), 2);
        $computedTotal = round($resolvedBaseRate + $kmCharge, 2);

        $customerType = strtolower((string) ($booking->customer_type ?: $booking->customer?->customer_type ?: 'regular'));
        $resolvedDiscountPercentage = in_array($customerType, ['pwd', 'senior'], true)
            ? max(round((float) ($discountPercentage ?? ($booking->discount_percentage ?? 0)), 2), 0)
            : 0.0;

        $discountAmount = round($computedTotal * ($resolvedDiscountPercentage / 100), 2);
        $resolvedAdditionalFee = $this->parsePrice($additionalFee);

        if ($resolvedAdditionalFee <= 0 && filled($quotedTotal)) {
            $quotedAmount = $this->parsePrice($quotedTotal);
            $resolvedAdditionalFee = max(round($quotedAmount - ($computedTotal - $discountAmount), 2), 0);
        }

        $finalTotal    = max(round($computedTotal + $resolvedAdditionalFee - $discountAmount, 2), 0);
        $vatExclusive  = round($finalTotal / 1.12, 2);
        $vatAmount     = round($finalTotal - $vatExclusive, 2);

        return [
            'distance_km'         => $resolvedDistanceKm,
            'base_rate'           => $resolvedBaseRate,
            'km_increments'       => $kmIncrements,
            'distance_fee'        => $kmCharge,
            'excess_km_threshold' => 0.0,
            'excess_km_rate'      => 200.0,
            'excess_km'           => 0.0,
            'excess_fee'          => 0.0,
            'computed_total'      => $computedTotal,
            'discount_percentage' => $resolvedDiscountPercentage,
            'additional_fee'      => $resolvedAdditionalFee,
            'discount_amount'     => $discountAmount,
            'final_total'         => $finalTotal,
            'vat_amount'          => $vatAmount,
            'vat_exclusive_total' => $vatExclusive,
        ];
    }

    public function refreshBookingForCustomerChange(Booking $booking, array $data): Booking
    {
        $pickupNotes = $this->sanitizeText($data['pickup_notes'] ?? $booking->pickup_notes);
        $truckTypeId = (int) ($data['truck_type_id'] ?? $booking->truck_type_id);
        $hadQuotation = $booking->quotation_generated
            || in_array((string) $booking->status, ['reviewed', 'quoted', 'quotation_sent', 'confirmed'], true);

        $pricing = $this->calculatePricing([
            'truck_type_id' => $truckTypeId,
            'distance_km' => $data['distance_km'] ?? $booking->distance_km,
            'customer_type' => $booking->customer_type ?: $booking->customer?->customer_type ?: 'regular',
        ]);

        $eta = $data['eta_minutes'] ?? $booking->eta_minutes ?? ($booking->quotation ? $booking->quotation->eta_minutes : null);

        $payload = [
            'truck_type_id' => $truckTypeId,
            'pickup_address' => trim((string) ($data['pickup_address'] ?? $booking->pickup_address)),
            'dropoff_address' => trim((string) ($data['dropoff_address'] ?? $booking->dropoff_address)),
            'pickup_notes' => $pickupNotes,
            'distance_km' => $pricing['distance_km'],
            'base_rate' => $pricing['base_rate'],
            'per_km_rate' => $pricing['per_km_rate'],
            'computed_total' => $pricing['computed_total'],
            'discount_percentage' => $pricing['discount_percentage'],
            'discount_reason' => $pricing['discount_reason'],
            'additional_fee' => $pricing['additional_fee'],
            'final_total' => $pricing['final_total'],
            'customer_type' => $pricing['customer_type'],
            'notes' => $this->composeNotes(
                $this->composeLocationNotes(['pickup_notes' => $pickupNotes]),
                $booking->service_mode,
                $booking->scheduled_for,
            ),
            'customer_approved_at' => null,
            'price_locked_at' => null,
            'negotiation_requested_at' => null,
            'counter_offer_amount' => null,
            'final_quote_path' => null,
            'eta_minutes' => $eta,
        ];

        if ($hadQuotation) {
            $payload = array_merge($payload, [
                'status' => 'quotation_sent',
                'quotation_status' => 'active',
                'quotation_generated' => true,
                'quotation_number' => $booking->quotation_number ?: $this->generateQuotationNumber($booking),
                'quoted_at' => now(),
                'quotation_sent_at' => now(),
                'quotation_expires_at' => now()->addDays(7),
                'quotation_follow_up_sent_at' => null,
                'customer_response_note' => 'Booking details were updated and the quotation record was refreshed automatically.',
            ]);
        }

        $booking->update($this->filterPayloadForTable('bookings', $payload));

        return $booking->fresh(['customer', 'truckType']);
    }

    public function generateQuotationNumber(Booking $booking): string
    {
        $prefix = trim((string) SystemSetting::getValue('quote_prefix', 'Q'));
        $prefix = $prefix !== '' ? strtoupper($prefix) : 'Q';

        return sprintf('%s-%s-%04d', $prefix, now()->format('Ymd'), $booking->id);
    }

    public function dispatchAvailability(): array
    {
        $busyTeamLeaderIds = $this->teamLeaderAvailability->busyTeamLeaderIds();
        $teamLeaderRoleIds = Role::query()
            ->whereIn('name', ['Team Leader', 'team leader'])
            ->pluck('id');

        $teamLeadersQuery = User::visibleToOperations()->with(['unit', 'unit.driver']);

        if ($teamLeaderRoleIds->isNotEmpty()) {
            $teamLeadersQuery->whereIn('role_id', $teamLeaderRoleIds);
        }

        $teamLeaderStatuses = $this->teamLeaderAvailability
            ->summarize(
                $teamLeadersQuery->get(),
                $busyTeamLeaderIds,
            )['leaders']
            ->keyBy('id');

        $readyUnits = Unit::query()
            ->where('status', 'available')
            ->whereNotNull('team_leader_id')
            ->with('truckType')
            ->get()
            ->filter(function (Unit $unit) use ($busyTeamLeaderIds, $teamLeaderStatuses) {
                $teamLeaderId = (int) ($unit->team_leader_id ?? 0);
                $leaderStatus = $teamLeaderStatuses->get($teamLeaderId, []);
                $isOnline = ($leaderStatus['presence'] ?? 'offline') === 'online';

                return $teamLeaderId > 0 && $isOnline && ! $busyTeamLeaderIds->contains($teamLeaderId);
            });

        $readyUnitsCount = $readyUnits->count();

        $readyByClass = $readyUnits
            ->filter(fn(Unit $unit) => filled($unit->truckType?->class))
            ->groupBy(fn(Unit $unit) => strtolower($unit->truckType->class))
            ->map->count()
            ->toArray();

        // Also count online, non-busy TLs who may not yet have a unit assigned.
        // This covers TLs created after the last deploy (unit auto-creation race)
        // so the customer sees availability as long as any TL is ready.
        $onlineFreeTlCount = $teamLeaderStatuses->filter(function ($leader) use ($busyTeamLeaderIds) {
            return ($leader['presence'] ?? 'offline') === 'online'
                && ! $busyTeamLeaderIds->contains((int) ($leader['id'] ?? 0));
        })->count();

        $bookNowEnabled = $readyUnitsCount > 0 || $onlineFreeTlCount > 0;

        return [
            'book_now_enabled'         => $bookNowEnabled,
            'ready_units_count'        => $readyUnitsCount,
            'recommended_service_type' => $bookNowEnabled ? 'book_now' : 'schedule',
            'message'                  => $bookNowEnabled
                ? 'A dispatch-ready unit is available right now.'
                : 'Immediate dispatch is currently unavailable. You can still proceed with your booking, and we\'ll assign your service as soon as possible.',
            'ready_by_class'           => $readyByClass,
        ];
    }

    public function estimateDirectDistanceKm(?float $pickupLat, ?float $pickupLng, ?float $dropLat, ?float $dropLng): float
    {
        if (! is_numeric($pickupLat) || ! is_numeric($pickupLng) || ! is_numeric($dropLat) || ! is_numeric($dropLng)) {
            return 0.0;
        }

        return round($this->haversineDistanceKm((float) $pickupLat, (float) $pickupLng, (float) $dropLat, (float) $dropLng), 2);
    }

    public function estimateFallbackDurationMinutes(?float $distanceKm, float $averageSpeedKph = 30.0): float
    {
        if (! is_numeric($distanceKm) || (float) $distanceKm <= 0) {
            return 0.0;
        }

        $safeSpeedKph = max($averageSpeedKph, 10.0);

        return max(round((((float) $distanceKm) / $safeSpeedKph) * 60, 1), 1.0);
    }

    public function parsePrice(?string $price): float
    {
        $normalized = preg_replace('/[^\d.]/', '', (string) $price);

        return $normalized === '' ? 0.0 : (float) $normalized;
    }

    public function filterPayloadForTable(string $table, array $payload): array
    {
        if (! Schema::hasTable($table)) {
            return $payload;
        }

        $columns = array_flip(Schema::getColumnListing($table));

        return array_filter(
            $payload,
            fn ($value, $key) => array_key_exists($key, $columns),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Protected helpers
    // ──────────────────────────────────────────────────────────────

    protected function resolveNameParts(array $data): array
    {
        if (filled($data['first_name'] ?? null) && filled($data['last_name'] ?? null)) {
            return [
                'first_name' => trim((string) $data['first_name']),
                'middle_name' => filled($data['middle_name'] ?? null) ? trim((string) $data['middle_name']) : null,
                'last_name' => trim((string) $data['last_name']),
            ];
        }

        return split_full_name($data['full_name'] ?? '');
    }

    protected function resolveCustomerType(array $data): string
    {
        if (($data['customer_type'] ?? null) === 'pwd' || ! empty($data['is_pwd'])) {
            return 'pwd';
        }

        if (($data['customer_type'] ?? null) === 'senior' || ! empty($data['is_senior'])) {
            return 'senior';
        }

        return 'regular';
    }

    protected function resolveServiceType(array $data): string
    {
        return ($data['service_type'] ?? 'book_now') === 'schedule' ? 'schedule' : 'book_now';
    }

    protected function resolveScheduledFor(array $data, string $serviceType): ?Carbon
    {
        if ($serviceType !== 'schedule') {
            return null;
        }

        $scheduledDate = trim((string) ($data['scheduled_date'] ?? ''));
        $scheduledTime = trim((string) ($data['scheduled_time'] ?? '')) ?: '00:00';

        if ($scheduledDate === '') {
            return null;
        }

        return Carbon::parse(trim($scheduledDate . ' ' . $scheduledTime));
    }

    protected function resolveDistanceKm(array $data): float
    {
        if (is_numeric($data['distance_km'] ?? null)) {
            return max(round((float) $data['distance_km'], 2), 0);
        }

        $resolvedDistance = round($this->parseDistance((string) ($data['distance'] ?? '0')), 2);
        if ($resolvedDistance > 0) {
            return $resolvedDistance;
        }

        $pickupLat = $data['pickup_lat'] ?? null;
        $pickupLng = $data['pickup_lng'] ?? null;
        $dropLat = $data['drop_lat'] ?? $data['dropoff_lat'] ?? null;
        $dropLng = $data['drop_lng'] ?? $data['dropoff_lng'] ?? null;

        if (is_numeric($pickupLat) && is_numeric($pickupLng) && is_numeric($dropLat) && is_numeric($dropLng)) {
            return round($this->haversineDistanceKm((float) $pickupLat, (float) $pickupLng, (float) $dropLat, (float) $dropLng), 2);
        }

        return 0.0;
    }

    protected function haversineDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($deltaLng / 2) ** 2;

        return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    protected function resolveBaseRate(TruckType $truckType): float
    {
        $truckBaseRate = round((float) ($truckType->base_rate ?? 0), 2);

        if ($truckBaseRate > 0) {
            return $truckBaseRate;
        }

        return max(round((float) SystemSetting::getValue('booking_base_rate', 0), 2), 0);
    }

    protected function resolvePerKmRate(TruckType $truckType, ?string $vehicleCategory = null): float
    {
        $truckPerKmRate = round((float) ($truckType->per_km_rate ?? 0), 2);
        $globalPerKmRate = max(round((float) SystemSetting::getValue('booking_per_km_rate', 0), 2), 0);
        $resolvedPerKmRate = $truckPerKmRate > 0 ? $truckPerKmRate : $globalPerKmRate;
        $categoryMultiplier = $this->resolveCategoryRateMultiplier($vehicleCategory);

        return round(max($resolvedPerKmRate, 0) * $categoryMultiplier, 2);
    }

    protected function resolveCategoryRateMultiplier(?string $vehicleCategory = null): float
    {
        $normalizedCategory = $this->normalizeVehicleCategory($vehicleCategory);

        if ($normalizedCategory === null) {
            return 1.0;
        }

        $settingKey = 'booking_category_multiplier_' . $normalizedCategory;
        $configuredMultiplier = (float) SystemSetting::getValue($settingKey, setting($settingKey, 1));

        return $configuredMultiplier > 0 ? round($configuredMultiplier, 2) : 1.0;
    }

    protected function normalizeVehicleCategory(?string $vehicleCategory = null): ?string
    {
        $value = strtolower(trim((string) $vehicleCategory));

        if ($value === '') {
            return null;
        }

        return match ($value) {
            '2_wheels', '2_wheeler', '2 wheeler', '2 wheels' => '2_wheeler',
            '3_wheels', '3_wheeler', '3 wheeler', '3 wheels' => '3_wheeler',
            '4_wheels', '4_wheeler', '4 wheeler', '4 wheels' => '4_wheeler',
            '6_wheeler', '6 wheels', '6_wheels', '10_wheeler', '10 wheels', '10_wheels', 'heavy_vehicle', 'heavy vehicle', 'heavy_vehicle_6_plus' => 'heavy_vehicle',
            'other' => 'other',
            default => str($value)->replace(' ', '_')->value(),
        };
    }

    protected function resolveBookingDiscount(array $data, float $computedTotal, string $customerType): array
    {
        $automaticDiscountPercentage = in_array($customerType, ['pwd', 'senior'], true)
            ? max(round((float) SystemSetting::getValue('discount_percentage', setting('discount_percentage', 0)), 2), 0)
            : 0.0;

        $automaticReason = $automaticDiscountPercentage > 0
            ? trim((string) SystemSetting::getValue('discount_reason', setting('discount_reason', strtoupper($customerType) . ' discount')))
            : null;

        $submittedDiscountCode = strtoupper(trim((string) ($data['discount_code'] ?? $data['promo_code'] ?? '')));
        $configuredDiscountCode = strtoupper(trim((string) SystemSetting::getValue('booking_discount_code', setting('booking_discount_code', ''))));
        $promoDiscountPercentage = 0.0;
        $promoReason = null;

        if ($submittedDiscountCode !== '' && $configuredDiscountCode !== '' && hash_equals($configuredDiscountCode, $submittedDiscountCode)) {
            $promoDiscountPercentage = max(round((float) SystemSetting::getValue('booking_discount_percentage', setting('booking_discount_percentage', 0)), 2), 0);
            $promoReason = trim((string) SystemSetting::getValue('booking_discount_reason', setting('booking_discount_reason', 'Validated booking discount')));
        }

        $discountPercentage = max($automaticDiscountPercentage, $promoDiscountPercentage);
        $maxDiscountPercentage = max(round((float) SystemSetting::getValue('max_booking_discount_percentage', setting('max_booking_discount_percentage', 100)), 2), 0);

        if ($maxDiscountPercentage > 0) {
            $discountPercentage = min($discountPercentage, $maxDiscountPercentage);
        }

        $discountReason = $discountPercentage === $promoDiscountPercentage && $promoDiscountPercentage > 0
            ? $promoReason
            : $automaticReason;

        return [
            'discount_percentage' => $discountPercentage,
            'discount_reason' => $discountReason,
            'discount_amount' => round($computedTotal * ($discountPercentage / 100), 2),
        ];
    }

    protected function resolveExcessKmThreshold(bool $usePreviewFallback = false): float
    {
        $defaultThreshold = $usePreviewFallback
            ? (float) setting('excess_km_threshold', 10)
            : 0.0;

        $configuredThreshold = (float) SystemSetting::getValue('excess_km_threshold', $defaultThreshold);

        if ($usePreviewFallback && $configuredThreshold <= 0) {
            $configuredThreshold = (float) setting('excess_km_threshold', 10);
        }

        return max(round($configuredThreshold, 2), 0);
    }

    protected function resolveExcessKmRate(bool $usePreviewFallback = false): float
    {
        $defaultRate = $usePreviewFallback
            ? (float) setting('excess_km_rate', 20)
            : 0.0;

        $configuredRate = (float) SystemSetting::getValue('excess_km_rate', $defaultRate);

        if ($usePreviewFallback && $configuredRate <= 0) {
            $configuredRate = (float) setting('excess_km_rate', 20);
        }

        return max(round($configuredRate, 2), 0);
    }

    protected function sanitizeText(?string $value): ?string
    {
        $cleaned = trim(strip_tags((string) $value));

        return $cleaned === '' ? null : $cleaned;
    }

    protected function composeLocationNotes(array $data): ?string
    {
        $segments = [];

        if (filled($data['pickup_notes'] ?? null)) {
            $segments[] = 'Pickup notes: ' . trim((string) $data['pickup_notes']);
        } elseif (filled($data['pickup_landmark'] ?? null)) {
            $segments[] = 'Pickup landmark: ' . trim((string) $data['pickup_landmark']);
        }

        if (filled($data['dropoff_landmark'] ?? null)) {
            $segments[] = 'Dropoff landmark: ' . trim((string) $data['dropoff_landmark']);
        }

        if (filled($data['additional_directions'] ?? null)) {
            $segments[] = 'Directions: ' . trim((string) $data['additional_directions']);
        }

        if (filled($data['vehicle_category'] ?? null)) {
            $segments[] = 'Customer vehicle: ' . str((string) $data['vehicle_category'])->replace('_', ' ')->title();
        }

        if (filled($data['notes'] ?? null)) {
            $segments[] = trim((string) $data['notes']);
        }

        return trim(implode(PHP_EOL, array_filter($segments)));
    }

    protected function composeNotes(?string $value, string $serviceType, ?Carbon $scheduledFor): ?string
    {
        $segments = [];
        $cleaned = $this->sanitizeText($value);

        if ($serviceType === 'schedule') {
            $segments[] = 'Requested schedule: ' . ($scheduledFor ? $scheduledFor->format('Y-m-d H:i') : 'Date pending');
        }

        if ($cleaned) {
            $segments[] = $cleaned;
        }

        return trim(implode(PHP_EOL, array_filter($segments)));
    }

    protected function parseDistance(?string $distance): float
    {
        $normalized = preg_replace('/[^\d.]/', '', (string) $distance);

        return $normalized === '' ? 0.0 : (float) $normalized;
    }
}
