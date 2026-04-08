<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LandingBookingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'full_name' => 'required|string|max:255',
            'age' => 'required|integer|min:1|max:120',
            'phone' => [
                'required',
                'regex:/^(09\d{9}|\+639\d{9}|639\d{9})$/',
                'max:13'
            ],
            'email' => [
                'nullable',
                'regex:/^[a-zA-Z0-9._%+-]+@gmail\.com$/',
                'max:255'
            ],
            'truck_type_id' => 'required|string|max:255', // Now string
            'pickup_address' => 'required|string|max:1000',
            'pickup_lat' => 'nullable|numeric',
            'pickup_lng' => 'nullable|numeric',
            'dropoff_address' => 'required|string|max:1000',
            'drop_lat' => 'nullable|numeric',
            'drop_lng' => 'nullable|numeric',
            'vehicle_image' => 'nullable|image|max:2048',
            'notes' => 'nullable|string|max:1000',
            'is_pwd' => 'nullable|boolean',
            'is_senior' => 'nullable|boolean',
        ];
    }

    public function messages()
    {
        return [
            'phone.regex' => 'Please enter a valid Philippine phone number (e.g., 09123456789 or +639123456789).',
            'phone.max' => 'Phone number cannot exceed 13 characters.',
            'email.regex' => 'Only Gmail addresses are accepted (e.g., example@gmail.com).',
        ];
    }

    public function validatedData(): array
    {
        return array_merge(parent::validated(), [
            'is_pwd' => $this->has('is_pwd'),
            'is_senior' => $this->has('is_senior'),
        ]);
    }
}
