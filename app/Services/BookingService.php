<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\SystemSetting;
use App\Models\TruckType;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;

class BookingService
{
    public function createBooking(array $data, ?Authenticatable $user = null): Booking
    {
        $customer = $this->resolveCustomer($data, $user);
        $pricing = $this->calculatePricing($data);

        $payload = $this->filterPayloadForTable('bookings', [
            'customer_id' => $customer->id,
            'truck_type_id' => $data['truck_type_id'],
            'created_by_admin_id' => $data['created_by_admin_id'] ?? $user?->getAuthIdentifier(),
            'age' => $data['age'] ?? $customer->age,
            'pickup_address' => $data['pickup_address'],
            'pickup_lat' => $data['pickup_lat'] ?? null,
            'pickup_lng' => $data['pickup_lng'] ?? null,
            'dropoff_address' => $data['dropoff_address'],
            'dropoff_lat' => $data['drop_lat'] ?? $data['dropoff_lat'] ?? null,
            'dropoff_lng' => $data['drop_lng'] ?? $data['dropoff_lng'] ?? null,
            'distance_km' => $pricing['distance_km'],
            'base_rate' => $pricing['base_rate'],
            'per_km_rate' => $pricing['per_km_rate'],
            'computed_total' => $pricing['computed_total'],
            'discount_percentage' => $pricing['discount_percentage'],
            'discount_reason' => $pricing['discount_reason'],
            'additional_fee' => $pricing['additional_fee'],
            'final_total' => $pricing['final_total'],
            'customer_type' => $pricing['customer_type'],
            'confirmation_type' => $data['confirmation_type'] ?? 'system',
            'vehicle_image_path' => $data['vehicle_image_path'] ?? null,
            'notes' => $this->sanitizeText($data['notes'] ?? null),
            'status' => 'requested',
            'quotation_generated' => false,
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
        $truckType = TruckType::query()->findOrFail($data['truck_type_id']);
        $distanceKm = $this->resolveDistanceKm($data);
        $baseRate = round((float) $truckType->base_rate, 2);
        $perKmRate = round((float) $truckType->per_km_rate, 2);
        $distanceFee = round($distanceKm * $perKmRate, 2);
        $computedTotal = round($baseRate + $distanceFee, 2);
        $customerType = $this->resolveCustomerType($data);
        $discountPercentage = in_array($customerType, ['pwd', 'senior'], true)
            ? round((float) SystemSetting::getValue('discount_percentage', setting('discount_percentage', 0)), 2)
            : 0.0;
        $discountReason = $discountPercentage > 0
            ? trim((string) SystemSetting::getValue('discount_reason', setting('discount_reason', strtoupper($customerType) . ' discount')))
            : null;
        $discountAmount = round($computedTotal * ($discountPercentage / 100), 2);
        $additionalFee = $this->parsePrice($data['additional_fee'] ?? null);
        $finalTotal = max(round($computedTotal + $additionalFee - $discountAmount, 2), 0);

        return [
            'distance_km' => $distanceKm,
            'base_rate' => $baseRate,
            'per_km_rate' => $perKmRate,
            'computed_total' => $computedTotal,
            'discount_percentage' => $discountPercentage,
            'discount_reason' => $discountReason,
            'discount_amount' => $discountAmount,
            'additional_fee' => $additionalFee,
            'final_total' => $finalTotal,
            'customer_type' => $customerType,
        ];
    }

    public function calculateQuotationTotals(
        Booking $booking,
        ?string $additionalFee = null,
        ?string $quotedTotal = null,
        ?float $distanceKm = null,
        ?float $discountPercentage = null,
    ): array {
        $baseRate = round((float) ($booking->base_rate ?? 0), 2);
        $perKmRate = round((float) ($booking->per_km_rate ?? 0), 2);
        $resolvedDistanceKm = max(round((float) ($distanceKm ?? ($booking->distance_km ?? 0)), 2), 0);
        $distanceFee = round($resolvedDistanceKm * $perKmRate, 2);
        $computedTotal = round($baseRate + $distanceFee, 2);
        $resolvedDiscountPercentage = max(round((float) ($discountPercentage ?? ($booking->discount_percentage ?? 0)), 2), 0);
        $discountAmount = round($computedTotal * ($resolvedDiscountPercentage / 100), 2);
        $resolvedAdditionalFee = $this->parsePrice($additionalFee);

        if ($resolvedAdditionalFee <= 0 && filled($quotedTotal)) {
            $quotedAmount = $this->parsePrice($quotedTotal);
            $resolvedAdditionalFee = max(round($quotedAmount - ($computedTotal - $discountAmount), 2), 0);
        }

        return [
            'distance_km' => $resolvedDistanceKm,
            'distance_fee' => $distanceFee,
            'computed_total' => $computedTotal,
            'discount_percentage' => $resolvedDiscountPercentage,
            'additional_fee' => $resolvedAdditionalFee,
            'discount_amount' => $discountAmount,
            'final_total' => max(round($computedTotal + $resolvedAdditionalFee - $discountAmount, 2), 0),
        ];
    }

    public function generateQuotationNumber(Booking $booking): string
    {
        $prefix = trim((string) SystemSetting::getValue('quote_prefix', 'Q'));
        $prefix = $prefix !== '' ? strtoupper($prefix) : 'Q';

        return sprintf('%s-%s-%04d', $prefix, now()->format('Ymd'), $booking->id);
    }

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

    protected function resolveDistanceKm(array $data): float
    {
        $pickupLat = $data['pickup_lat'] ?? null;
        $pickupLng = $data['pickup_lng'] ?? null;
        $dropLat = $data['drop_lat'] ?? $data['dropoff_lat'] ?? null;
        $dropLng = $data['drop_lng'] ?? $data['dropoff_lng'] ?? null;

        if (is_numeric($pickupLat) && is_numeric($pickupLng) && is_numeric($dropLat) && is_numeric($dropLng)) {
            return round($this->haversineDistanceKm((float) $pickupLat, (float) $pickupLng, (float) $dropLat, (float) $dropLng), 2);
        }

        return round($this->parseDistance((string) ($data['distance'] ?? '0')), 2);
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

    protected function sanitizeText(?string $value): ?string
    {
        $cleaned = trim(strip_tags((string) $value));

        return $cleaned === '' ? null : $cleaned;
    }

    protected function parseDistance(?string $distance): float
    {
        $normalized = preg_replace('/[^\d.]/', '', (string) $distance);

        return $normalized === '' ? 0.0 : (float) $normalized;
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
            fn($value, $key) => array_key_exists($key, $columns),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
