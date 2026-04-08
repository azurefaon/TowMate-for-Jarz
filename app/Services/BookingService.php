<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use Illuminate\Contracts\Auth\Authenticatable;

class BookingService
{
    public function createBooking(array $data, ?Authenticatable $user = null): Booking
    {
        $customer = $this->resolveCustomer($data, $user);

        $distance = $this->parseDistance($data['distance']);
        $price = $this->parsePrice($data['price']);

        return Booking::create([
            'customer_id' => $customer->id,
            'truck_type_id' => $data['truck_type_id'],
            'created_by_admin_id' => 1,
            'age' => $data['age'],
            'pickup_address' => $data['pickup_address'],
            'pickup_lat' => $data['pickup_lat'],
            'pickup_lng' => $data['pickup_lng'],
            'dropoff_address' => $data['dropoff_address'],
            'dropoff_lat' => $data['drop_lat'],
            'dropoff_lng' => $data['drop_lng'],
            'distance_km' => $distance,
            'base_rate' => $data['base_rate'],
            'per_km_rate' => $data['per_km_rate'],
            'computed_total' => $price,
            'final_total' => $price,
            'notes' => $data['notes'] ?? null,
            'status' => 'requested',
            'quotation_generated' => false,
        ]);
    }

    public function resolveCustomer(array $data, ?Authenticatable $user = null): Customer
    {
        if ($user && method_exists($user, 'customer') && $user->customer) {
            return $user->customer;
        }

        return Customer::create([
            'full_name' => $data['full_name'],
            'age' => $data['age'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'is_pwd' => $data['is_pwd'] ?? false,
            'is_senior' => $data['is_senior'] ?? false,
        ]);
    }

    public function generateQuotationNumber(Booking $booking): string
    {
        return sprintf('Q-%s-%04d', now()->format('Ymd'), $booking->id);
    }

    protected function parseDistance(string $distance): float
    {
        return floatval(str_replace([' km', ','], '', $distance));
    }

    protected function parsePrice(string $price): float
    {
        return floatval(str_replace(['₱', ','], '', $price));
    }
}
