<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Booking\ActiveQuotationException;
use App\Exceptions\Booking\BlacklistedCustomerException;
use App\Exceptions\Booking\NoTruckTypeAvailableException;
use App\Exceptions\Booking\ScheduledCapacityException;
use App\Exceptions\Booking\SpamCooldownException;
use App\Http\Controllers\Controller;
use App\Services\BookingService;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(protected BookingService $bookingService) {}

    public function store(Request $request)
    {
        $data = $request->validate(
            [
                'truck_type_id' => 'required|integer|exists:truck_types,id',
                'pickup_address'       => 'required|string|max:255',
                'dropoff_address'      => 'required|string|max:255|different:pickup_address',
                'distance_km'          => 'required|numeric|min:0.1|max:1000',
                'phone'                => 'required|string|max:30',
                'first_name'           => 'required|string|max:100',
                'last_name'            => 'required|string|max:100',
                'middle_name'          => 'nullable|string|max:100',
                'email'                => 'nullable|email|max:255',
                'pickup_notes'         => 'nullable|string|max:1000',
                'service_type'         => 'nullable|in:book_now,schedule',
                'scheduled_date'       => 'nullable|date',
                'scheduled_time'       => 'nullable|string',
                'customer_type'        => 'nullable|in:regular,pwd,senior',
                'vehicle_make'         => 'nullable|string|max:100',
                'vehicle_model'        => 'nullable|string|max:100',
                'vehicle_year'         => 'nullable|string|max:10',
                'vehicle_color'        => 'nullable|string|max:50',
                'vehicle_plate_number' => 'nullable|string|max:20',
                'eta_minutes'          => 'nullable|numeric',
                'price'                => 'nullable|numeric|min:0',
                'vehicle_images'       => 'nullable|array',
                'vehicle_images.*'     => 'file|mimes:jpg,jpeg,png|max:5120',
            ],
            [
                'truck_type_id.required' => 'Truck type is required.',
                'truck_type_id.integer'  => 'Truck type must be a number.',
                'truck_type_id.exists'   => 'Selected truck type does not exist.',
            ]
        );

        if ($request->hasFile('vehicle_images')) {
            $data['vehicle_image_path'] = $this->bookingService->storeVehicleImages($request->file('vehicle_images'));
        }

        try {
            $quotation = $this->bookingService->createCustomerQuotation($data, $request->user());
        } catch (BlacklistedCustomerException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => 'phone'], 422);
        } catch (ActiveQuotationException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => 'phone', 'quotation_number' => $e->quotationNumber], 422);
        }

        return response()->json([
            'success'          => true,
            'quotation_number' => $quotation->quotation_number,
            'message'          => 'Quotation request submitted! We will send you a quotation shortly.',
        ], 201);
    }

    public function landingStore(Request $request)
    {
        $data = $request->validate(
            [
                'truck_type_id' => 'required|integer|exists:truck_types,id',
                'pickup_address'             => 'required|string|max:255',
                'dropoff_address'            => 'required|string|max:255|different:pickup_address',
                'distance_km'                => 'required|numeric|min:0.1|max:1000',
                'phone'                      => 'required|string|max:30',
                'first_name'                 => 'required|string|max:100',
                'last_name'                  => 'required|string|max:100',
                'middle_name'                => 'nullable|string|max:100',
                'email'                      => 'nullable|email|max:255',
                'pickup_notes'               => 'nullable|string|max:1000',
                'service_type'               => 'nullable|in:book_now,schedule',
                'scheduled_date'             => 'nullable|date',
                'scheduled_time'             => 'nullable|string',
                'customer_type'              => 'nullable|in:regular,pwd,senior',
                'vehicle_make'               => 'nullable|string|max:100',
                'vehicle_model'              => 'nullable|string|max:100',
                'vehicle_year'               => 'nullable|string|max:10',
                'vehicle_color'              => 'nullable|string|max:50',
                'vehicle_plate_number'       => 'nullable|string|max:20',
                'eta_minutes'                => 'nullable|numeric',
                'duration_min'               => 'nullable|numeric',
                'price'                      => 'nullable|numeric|min:0',
                'add_second_vehicle'         => 'nullable|boolean',
                'vehicle_2_truck_type_id' => 'nullable|exists:truck_types,id',
                'vehicle_2_route_override'   => 'nullable|boolean',
                'vehicle_2_pickup_address'   => 'nullable|string|max:255',
                'vehicle_2_dropoff_address'  => 'nullable|string|max:255',
                'vehicle_2_distance_km'      => 'nullable|numeric',
                'vehicle_2_eta_minutes'      => 'nullable|numeric',
                'vehicle_2_price'            => 'nullable|numeric|min:0',
                'vehicle_images'             => 'nullable|array',
                'vehicle_images.*'           => 'file|mimes:jpg,jpeg,png|max:5120',
            ],
            [
                'truck_type_id.required' => 'Truck type is required.',
                'truck_type_id.integer'  => 'Truck type must be a number.',
                'truck_type_id.exists'   => 'Selected truck type does not exist.',
            ]
        );

        if (isset($data['duration_min']) && ! isset($data['eta_minutes'])) {
            $data['eta_minutes'] = $data['duration_min'];
        }

        if ($request->hasFile('vehicle_images')) {
            $data['vehicle_image_path'] = $this->bookingService->storeVehicleImages($request->file('vehicle_images'));
        }

        try {
            $result = $this->bookingService->createLandingQuotation($data);
        } catch (BlacklistedCustomerException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => 'phone'], 422);
        } catch (ActiveQuotationException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => 'phone', 'quotation_number' => $e->quotationNumber], 422);
        } catch (SpamCooldownException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => 'phone'], 429);
        } catch (ScheduledCapacityException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => 'scheduled_date'], 422);
        } catch (NoTruckTypeAvailableException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => 'truck_type_id'], 422);
        }

        $quotation = $result['quotation'];
        $second = $result['second_quotation'];

        return response()->json([
            'success'                   => true,
            'quotation_number'          => $quotation->quotation_number,
            'second_quotation_number'   => $second?->quotation_number,
            'service_type'              => $result['data']['service_type'] ?? 'book_now',
            'message'                   => 'Quotation request submitted! We will send you a quotation shortly.',
        ], 201);
    }
}
