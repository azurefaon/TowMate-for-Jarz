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
use App\Models\Customer;
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

        // BLOCK DUPLICATE / SPAM BOOKINGS
        $existingCustomer = Customer::where('phone', $data['phone'])
            ->orWhere('email', $data['email'] ?? '')
            ->first();

        if ($existingCustomer) {

            // ACTIVE BOOKING CHECK
            $activeBooking = Booking::where('customer_id', $existingCustomer->id)
                ->whereIn('status', ['requested', 'assigned', 'on_job'])
                ->exists();

            if ($activeBooking) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'phone' => 'You already have an ongoing booking. Please wait until it is completed.',
                    ]);
            }

            // COOLDOWN 5 mins
            $recentBooking = Booking::where('customer_id', $existingCustomer->id)
                ->latest()
                ->first();

            if ($recentBooking && now()->diffInMinutes($recentBooking->created_at) < 5) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'phone' => 'Please wait a few minutes before booking again.',
                    ]);
            }
        }

        //  Find truck type by name
        $truckType = TruckType::where('name', 'like', '%' . $data['truck_type_id'] . '%')->first();

        if (!$truckType) {
            $truckType = TruckType::first();
        }

        if (!$truckType) {
            return back()->withErrors([
                'truck_type_id' => 'No vehicle types available. Please contact support.'
            ]);
        }

        $data['truck_type_id'] = $truckType->id;

        // FIND OR CREATE CUSTOMER OPTIMIZED
        $customer = Customer::where('phone', $data['phone'])
            ->orWhere('email', $data['email'] ?? '')
            ->first();

        if (!$customer) {
            $customer = Customer::create([
                'full_name' => $data['full_name'],
                'age' => $data['age'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'is_pwd' => $data['is_pwd'],
                'is_senior' => $data['is_senior'],
            ]);
        } else {
            //  update latest info
            $customer->update([
                'full_name' => $data['full_name'],
                'age' => $data['age'],
            ]);
        }

        // Handle image upload
        if ($request->hasFile('vehicle_image')) {
            $imagePath = $request->file('vehicle_image')->store('vehicle_images', 'public');
            $data['vehicle_image'] = $imagePath;
        }

        // Create booking
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'truck_type_id' => $data['truck_type_id'],
            'age' => $data['age'],
            'created_by_admin_id' => null,
            'pickup_address' => $data['pickup_address'],
            'pickup_lat' => $data['pickup_lat'],
            'pickup_lng' => $data['pickup_lng'],
            'dropoff_address' => $data['dropoff_address'],
            'drop_lat' => $data['drop_lat'],
            'drop_lng' => $data['drop_lng'],
            'notes' => $data['notes'],
            'status' => 'requested',
        ]);

        // Send email 
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
            ->with(
                'success',
                'Booking created successfully! Booking ID: #' . $booking->id . '. We will contact you shortly.' .
                    ($customer->email ? ' A receipt has been sent to ' . $customer->email . '.' : '')
            );
    }
}
