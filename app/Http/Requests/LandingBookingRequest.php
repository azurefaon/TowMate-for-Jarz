<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LandingBookingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $nameParts = split_full_name($this->input('full_name'));

        $firstName = trim((string) ($this->input('first_name') ?: $nameParts['first_name']));
        $middleName = trim((string) ($this->input('middle_name') ?: $nameParts['middle_name']));
        $lastName = trim((string) ($this->input('last_name') ?: $nameParts['last_name']));
        $customerType = $this->input('customer_type');
        $serviceType = $this->input('service_type', 'book_now');
        $scheduledDate = trim((string) $this->input('scheduled_date'));
        $scheduledTime = trim((string) $this->input('scheduled_time'));
        $notes = trim((string) $this->input('notes'));

        if (! in_array($customerType, ['regular', 'pwd', 'senior'], true)) {
            $customerType = $this->boolean('is_pwd') ? 'pwd' : ($this->boolean('is_senior') ? 'senior' : 'regular');
        }

        if (! in_array($serviceType, ['book_now', 'schedule'], true)) {
            $serviceType = 'book_now';
        }

        if ($serviceType === 'schedule') {
            $scheduleLine = 'Requested schedule: ' . ($scheduledDate !== '' ? $scheduledDate : 'Date pending') . ' ' . ($scheduledTime !== '' ? $scheduledTime : 'Time pending');
            $notes = trim($scheduleLine . ($notes !== '' ? PHP_EOL . $notes : ''));
        }

        $this->merge([
            'first_name' => $firstName !== '' ? $firstName : null,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'full_name' => build_full_name($firstName, $middleName, $lastName),
            'phone' => normalize_ph_phone($this->input('phone')) ?? $this->input('phone'),
            'email' => filled($this->input('email')) ? strtolower(trim((string) $this->input('email'))) : null,
            'customer_type' => $customerType,
            'service_type' => $serviceType,
            'scheduled_date' => $scheduledDate !== '' ? $scheduledDate : null,
            'scheduled_time' => $scheduledTime !== '' ? $scheduledTime : null,
            'notes' => $notes !== '' ? $notes : null,
            'confirmation_type' => $this->input('confirmation_type', 'system'),
        ]);
    }

    public function rules()
    {
        return [
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'age' => 'required|integer|min:1|max:120',
            'phone' => [
                'required',
                'regex:/^\+639\d{9}$/',
            ],
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
            'truck_type_id' => 'required|string|max:255',
            'pickup_address' => 'required|string|max:1000',
            'pickup_lat' => 'nullable|numeric',
            'pickup_lng' => 'nullable|numeric',
            'dropoff_address' => 'required|string|max:1000',
            'drop_lat' => 'nullable|numeric',
            'drop_lng' => 'nullable|numeric',
            'vehicle_image' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'notes' => 'nullable|string|max:1000',
            'customer_type' => 'required|in:regular,pwd,senior',
            'service_type' => 'required|in:book_now,schedule',
            'scheduled_date' => 'nullable|required_if:service_type,schedule|date|after_or_equal:today',
            'scheduled_time' => 'nullable|required_if:service_type,schedule|date_format:H:i',
            'confirmation_type' => 'nullable|in:call,system',
        ];
    }

    public function messages()
    {
        return [
            'phone.regex' => 'Please enter a valid Philippine phone number.',
            'vehicle_image.mimes' => 'Vehicle image must be a JPG or PNG file only.',
            'customer_type.in' => 'Please select a valid customer type.',
        ];
    }

    public function validatedData(): array
    {
        $validated = parent::validated();

        return array_merge($validated, [
            'is_pwd' => ($validated['customer_type'] ?? 'regular') === 'pwd',
            'is_senior' => ($validated['customer_type'] ?? 'regular') === 'senior',
        ]);
    }
}
