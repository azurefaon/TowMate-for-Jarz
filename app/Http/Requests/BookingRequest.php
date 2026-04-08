<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
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
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:255',
            'truck_type_id' => 'required|exists:truck_types,id',
            'pickup_address' => 'required|string|max:1000',
            'pickup_lat' => 'required|numeric',
            'pickup_lng' => 'required|numeric',
            'dropoff_address' => 'required|string|max:1000',
            'drop_lat' => 'required|numeric',
            'drop_lng' => 'required|numeric',
            'distance' => 'required|string|max:50',
            'price' => 'required|string|max:50',
            'base_rate' => 'required|numeric',
            'per_km_rate' => 'required|numeric',
            'notes' => 'nullable|string|max:1000',
            'is_pwd' => 'nullable|boolean',
            'is_senior' => 'nullable|boolean',
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
