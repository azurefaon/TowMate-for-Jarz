<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\TruckType;
use App\Services\DocumentGenerationService;
use Illuminate\Support\Facades\Storage;

function p5dBooking(): Booking
{
    $customer = Customer::create([
        'full_name' => 'Dompdf Regression Customer',
        'phone' => '09170000009',
        'email' => 'p5dompdf-' . uniqid() . '@example.com',
    ]);
    $truckType = TruckType::create([
        'name' => 'P5 Dompdf Truck',
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
    $admin = \App\Models\User::factory()->create(['role_id' => 2]);

    return Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'created_by_admin_id' => $admin->id,
        'pickup_address' => 'Quezon City',
        'dropoff_address' => 'Makati City',
        'distance_km' => 12.5,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 2250,
        'vat_exclusive_total' => 2250,
        'vat_amount' => 270,
        'final_total' => 2520,
        'status' => 'completed',
        'completed_at' => now(),
    ]);
}

it('1: generateReceipt produces a non-empty, valid PDF on the private disk with a protected URL', function () {
    $booking = p5dBooking();
    $service = app(DocumentGenerationService::class);

    $receipt = $service->generateReceipt($booking);

    expect(Storage::disk('local')->exists($receipt->pdf_path))->toBeTrue();
    $bytes = Storage::disk('local')->get($receipt->pdf_path);
    expect(strlen($bytes))->toBeGreaterThan(500);
    expect(substr($bytes, 0, 4))->toBe('%PDF');

    $url = $service->publicDocumentUrl($receipt->pdf_path);
    expect($url)->toContain('/protected-storage/');
});

it('2: generateInvoice produces a non-empty, valid PDF with correct currency/totals rendering', function () {
    $booking = p5dBooking();
    $invoice = Invoice::create([
        'booking_id' => $booking->id,
        'subtotal' => 2250,
        'additional_fee' => 0,
        'discount' => 0,
        'total' => 2520,
        'status' => 'issued',
        'is_current' => true,
    ]);
    $service = app(DocumentGenerationService::class);

    $result = $service->generateInvoice($invoice);

    expect(Storage::disk('local')->exists($result->pdf_path))->toBeTrue();
    $bytes = Storage::disk('local')->get($result->pdf_path);
    expect(strlen($bytes))->toBeGreaterThan(500);
    expect(substr($bytes, 0, 4))->toBe('%PDF');
});

it('3: generateQuotation produces a non-empty, valid PDF', function () {
    $booking = p5dBooking();
    $service = app(DocumentGenerationService::class);

    $path = $service->generateQuotation($booking, true);

    expect(Storage::disk('local')->exists($path))->toBeTrue();
    $bytes = Storage::disk('local')->get($path);
    expect(strlen($bytes))->toBeGreaterThan(500);
    expect(substr($bytes, 0, 4))->toBe('%PDF');
});
