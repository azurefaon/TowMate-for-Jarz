<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $nameParts = split_full_name($this->input('full_name') ?: optional($this->user())->name);

        $firstName = trim((string) ($this->input('first_name') ?: $nameParts['first_name']));
        $middleName = trim((string) ($this->input('middle_name') ?: $nameParts['middle_name']));
        $lastName = trim((string) ($this->input('last_name') ?: $nameParts['last_name']));
        $customerType = $this->input('customer_type', 'regular');
        $serviceType = $this->input('service_type', 'book_now');
        $scheduledDate = trim((string) $this->input('scheduled_date'));
        $scheduledTime = trim((string) $this->input('scheduled_time'));
        $notes = trim((string) $this->input('notes'));
        $pickupNotes = trim((string) ($this->input('pickup_notes') ?: $this->input('pickup_landmark')));
        $pickupLandmark = trim((string) $this->input('pickup_landmark'));
        $dropoffLandmark = trim((string) $this->input('dropoff_landmark'));
        $additionalDirections = trim((string) $this->input('additional_directions'));
        $vehicleCategory = $this->normalizeVehicleCategory($this->input('vehicle_category'));
        $discountCode = strtoupper(trim((string) $this->input('discount_code')));

        if (! in_array($customerType, ['regular', 'pwd', 'senior'], true)) {
            $customerType = 'regular';
        }

        if (! in_array($serviceType, ['book_now', 'schedule'], true)) {
            $serviceType = '';
        }

        if ($serviceType === 'schedule') {
            $scheduleLine = 'Requested schedule: ' . ($scheduledDate !== '' ? $scheduledDate : 'Date pending') . ' ' . ($scheduledTime !== '' ? $scheduledTime : 'Time pending');
            $notes = trim($scheduleLine . ($notes !== '' ? PHP_EOL . $notes : ''));
        }

        $this->merge([
            'first_name' => $firstName !== '' ? strip_tags($firstName) : null,
            'middle_name' => $middleName !== '' ? strip_tags($middleName) : null,
            'last_name' => $lastName !== '' ? strip_tags($lastName) : null,
            'full_name' => build_full_name($firstName, $middleName, $lastName),
            'phone' => normalize_ph_phone($this->input('phone')) ?? $this->input('phone'),
            'email' => filled($this->input('email')) ? strtolower(trim((string) $this->input('email'))) : optional($this->user())->email,
            'customer_type' => $customerType,
            'service_type' => $serviceType,
            'scheduled_date' => $scheduledDate !== '' ? $scheduledDate : null,
            'scheduled_time' => $scheduledTime !== '' ? $scheduledTime : null,
            'notes' => $notes !== '' ? strip_tags($notes) : null,
            'pickup_notes' => $pickupNotes !== '' ? strip_tags($pickupNotes) : null,
            'pickup_landmark' => $pickupLandmark !== '' ? strip_tags($pickupLandmark) : null,
            'dropoff_landmark' => $dropoffLandmark !== '' ? strip_tags($dropoffLandmark) : null,
            'additional_directions' => $additionalDirections !== '' ? strip_tags($additionalDirections) : null,
            'vehicle_category' => $vehicleCategory !== '' ? $vehicleCategory : null,
            'discount_code' => $discountCode !== '' ? $discountCode : null,
            'confirmation_type' => $this->input('confirmation_type', 'system'),
            'pickup_address' => strip_tags(trim((string) $this->input('pickup_address'))),
            'dropoff_address' => strip_tags(trim((string) $this->input('dropoff_address'))),
        ]);
    }

    public function rules()
    {
        return [
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => ['required', 'regex:/^\+639\d{9}$/'],
            'email' => [
                'nullable',
                'email:rfc',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value && ! is_public_email((string) $value)) {
                        $fail('Email must be valid and able to receive system notifications and receipts.');
                    }
                },
            ],
            'truck_type_id' => [
                'required',
                Rule::exists('truck_types', 'id')->where(fn($query) => $query->where('status', 'active')),
            ],
            'pickup_address' => 'required|string|max:1000',
            'pickup_lat' => 'required|numeric|between:-90,90',
            'pickup_lng' => 'required|numeric|between:-180,180',
            'dropoff_address' => 'required|string|max:1000',
            'drop_lat' => 'required|numeric|between:-90,90',
            'drop_lng' => 'required|numeric|between:-180,180',
            'distance_km' => 'nullable|numeric|min:0|max:10000',
            'distance' => 'nullable|string|max:50',
            'price' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:1000',
            'pickup_notes' => 'nullable|string|max:1000',
            'pickup_landmark' => 'nullable|string|max:255',
            'dropoff_landmark' => 'nullable|string|max:255',
            'additional_directions' => 'nullable|string|max:1000',
            'vehicle_category' => 'required|in:2_wheeler,3_wheeler,4_wheeler,heavy_vehicle,other',
            'discount_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-\s]+$/'],
            'vehicle_images'   => ['nullable', 'array', 'max:5'],
            'vehicle_images.*' => [
                'file',
                'mimes:jpg,jpeg,png',
                'max:2048',
                'dimensions:max_width=2048,max_height=1536',
            ],
            'service_type' => 'required|in:book_now,schedule',
            'scheduled_date' => 'nullable|required_if:service_type,schedule|date|after_or_equal:today',
            'scheduled_time' => 'nullable|required_if:service_type,schedule|date_format:H:i',
            'confirmation_type' => 'nullable|in:call,system',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Please enter a valid Philippine phone number.',
            'vehicle_category.required' => 'Please select your vehicle category before continuing.',
            'discount_code.regex' => 'Discount codes may only use letters, numbers, spaces, or dashes.',
            'vehicle_images.max'          => 'You may upload a maximum of 5 vehicle images.',
            'vehicle_images.*.mimes'      => 'Each vehicle image must be a JPG or PNG file only. GIF and other formats are not accepted.',
            'vehicle_images.*.max'        => 'Each vehicle image must not exceed 2 MB.',
            'vehicle_images.*.dimensions' => 'Each vehicle image must not exceed 2048×1536 pixels (standard booking photo size).',
            'truck_type_id.exists' => 'Selected vehicle type is currently unavailable. Please choose an available truck type.',
            'service_type.required' => 'Please select a booking mode (Book Now or Schedule Later).',
            'service_type.in' => 'Invalid booking mode selected.',
        ];
    }

    public function validatedData(): array
    {
        $validated = parent::validated();

        return array_merge($validated, [
            'distance_km' => $this->input('distance_km'),
            'distance' => $this->input('distance'),
            'price' => $this->input('price'),
            'discount_code' => $this->input('discount_code'),
            'pickup_notes' => $this->input('pickup_notes') ?: $this->input('pickup_landmark'),
            'pickup_landmark' => $this->input('pickup_landmark'),
            'dropoff_landmark' => $this->input('dropoff_landmark'),
            'additional_directions' => $this->input('additional_directions'),
            'vehicle_category' => $this->input('vehicle_category'),
            'is_pwd' => ($validated['customer_type'] ?? 'regular') === 'pwd',
            'is_senior' => ($validated['customer_type'] ?? 'regular') === 'senior',
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $pickupLat = $this->input('pickup_lat');
            $pickupLng = $this->input('pickup_lng');
            $dropLat = $this->input('drop_lat');
            $dropLng = $this->input('drop_lng');

            if (! is_numeric($pickupLat) || ! is_numeric($pickupLng) || ! is_numeric($dropLat) || ! is_numeric($dropLng)) {
                return;
            }

            if ($this->estimateCoordinateDistanceKm((float) $pickupLat, (float) $pickupLng, (float) $dropLat, (float) $dropLng) <= 0.05) {
                $validator->errors()->add('dropoff_address', 'Pickup and dropoff must be different locations with a valid route distance.');
            }
        });
    }

    protected function estimateCoordinateDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($deltaLng / 2) ** 2;

        return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    protected function normalizeVehicleCategory(?string $vehicleCategory): string
    {
        $value = strtolower(trim((string) $vehicleCategory));

        return match ($value) {
            '2 wheels', '2_wheels' => '2_wheeler',
            '3 wheels', '3_wheels' => '3_wheeler',
            '4 wheels', '4_wheels' => '4_wheeler',
            '6 wheeler', '6 wheels', '6_wheeler', '10 wheeler', '10 wheels', '10_wheeler', 'heavy vehicle', 'heavy_vehicle', 'heavy_vehicle_6_plus' => 'heavy_vehicle',
            default => $value,
        };
    }
}
