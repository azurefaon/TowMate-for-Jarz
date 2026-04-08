<?php

namespace App\Http\Controllers\Customer;

use App\Events\NewBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingRequest;
use App\Http\Requests\LandingBookingRequest;
use App\Mail\BookingReceiptMail;
use App\Services\BookingService;
use App\Models\Booking;
use App\Models\TruckType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BookingController extends Controller
{
    protected BookingService $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    public function store(BookingRequest $request)
    {
        $booking = $this->bookingService->createBooking($request->validatedData(), Auth::user());

        broadcast(new NewBooking($booking));

        return redirect()->route('landing')
            ->with('success', 'Booking created successfully! Booking ID: #' . $booking->id . '. We will contact you shortly.');
    }

    public function landingStore(LandingBookingRequest $request)
    {
        $data = $request->validatedData();

        // Find truck type by name
        $truckType = \App\Models\TruckType::where('name', 'like', '%' . $data['truck_type_id'] . '%')->first();
        if (!$truckType) {
            // If not found, default to first available
            $truckType = \App\Models\TruckType::first();
        }
        if (!$truckType) {
            // If still no truck type, return error
            return back()->withErrors(['truck_type_id' => 'No vehicle types available. Please contact support.']);
        }
        $data['truck_type_id'] = $truckType->id;

        // Create customer
        $customer = \App\Models\Customer::create([
            'full_name' => $data['full_name'],
            'age' => $data['age'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'is_pwd' => $data['is_pwd'],
            'is_senior' => $data['is_senior'],
        ]);

        // Handle image upload
        if ($request->hasFile('vehicle_image')) {
            $imagePath = $request->file('vehicle_image')->store('vehicle_images', 'public');
            $data['vehicle_image'] = $imagePath;
        }

        // Create booking
        $booking = \App\Models\Booking::create([
            'customer_id' => $customer->id,
            'truck_type_id' => $data['truck_type_id'],
            'age' => $data['age'],
            'created_by_admin_id' => null, // Landing booking, no admin
            'pickup_address' => $data['pickup_address'],
            'pickup_lat' => $data['pickup_lat'],
            'pickup_lng' => $data['pickup_lng'],
            'dropoff_address' => $data['dropoff_address'],
            'drop_lat' => $data['drop_lat'],
            'drop_lng' => $data['drop_lng'],
            'notes' => $data['notes'],
            'status' => 'requested', // Requested status for dispatcher to assign
        ]);

        if ($customer->email) {
            try {
                Mail::to($customer->email)->send(new BookingReceiptMail($booking));
            } catch (\Exception $e) {
                Log::error('Failed to send booking receipt email', [
                    'booking_id' => $booking->id,
                    'customer_email' => $customer->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        broadcast(new NewBooking($booking));

        return redirect()->route('landing')
            ->with('success', 'Booking created successfully! Booking ID: #' . $booking->id . '. We will contact you shortly.' . ($customer->email ? ' A receipt has been sent to ' . $customer->email . '.' : ''));
    }
}
