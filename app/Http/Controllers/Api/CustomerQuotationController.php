<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerQuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotationService) {}

    public function pending(Request $request): JsonResponse
    {
        $customer = Customer::where('user_id', $request->user()->id)->first();
        if (!$customer) return response()->json(['data' => null]);

        $quotation = Quotation::where('customer_id', $customer->id)
            ->where('status', 'sent')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with('truckType')
            ->latest('sent_at')
            ->first();

        if (!$quotation) return response()->json(['data' => null]);

        return response()->json(['data' => [
            'id'                => $quotation->id,
            'quotation_number'  => $quotation->quotation_number,
            'status'            => $quotation->status,
            'estimated_price'   => (float) $quotation->estimated_price,
            'additional_fee'    => (float) ($quotation->additional_fee ?? 0),
            'distance_km'       => (float) $quotation->distance_km,
            'pickup_address'    => $quotation->pickup_address,
            'dropoff_address'   => $quotation->dropoff_address,
            'pickup_notes'      => $quotation->pickup_notes,
            'truck_type_name'   => $quotation->truckType?->name ?? '',
            'truck_type_class'  => $quotation->truckType?->vehicle_class ?? null,
            'service_type'      => $quotation->service_type,
            'source_booking_id' => $quotation->source_booking_id,
            'expires_at'        => $quotation->expires_at?->toIso8601String(),
            'sent_at'           => $quotation->sent_at?->toIso8601String(),
        ]]);
    }

    public function accept(Request $request, Quotation $quotation): JsonResponse
    {
        $customer = Customer::where('user_id', $request->user()->id)->first();
        if (!$customer || (int) $quotation->customer_id !== $customer->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'quotation' => [
                function ($attribute, $value, $fail) use ($quotation) {
                    if ($quotation->status !== 'sent') {
                        $fail('This quotation has already been processed.');
                    }
                    if ($quotation->isExpired()) {
                        $fail('This quotation has expired.');
                    }
                },
            ],
        ]);

        try {
            $booking = $this->quotationService->acceptQuotation($quotation);
            return response()->json([
                'success'        => true,
                'message'        => 'Quotation accepted. Your booking is confirmed.',
                'booking_code'   => $booking->booking_code,
                'booking_status' => $booking->status,
            ]);
        } catch (\Exception $e) {
            Log::error('Mobile quotation accept failed', [
                'quotation_id' => $quotation->id,
                'error'        => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Failed to accept quotation.'], 500);
        }
    }

    public function reject(Request $request, Quotation $quotation): JsonResponse
    {
        $customer = Customer::where('user_id', $request->user()->id)->first();
        if (!$customer || (int) $quotation->customer_id !== $customer->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'reason'    => 'nullable|string|max:1000',
            'quotation' => [
                function ($attribute, $value, $fail) use ($quotation) {
                    if ($quotation->status !== 'sent') {
                        $fail('This quotation has already been processed.');
                    }
                },
            ],
        ]);

        $this->quotationService->rejectQuotation($quotation, $validated['reason'] ?? null);
        return response()->json(['success' => true, 'message' => 'Quotation declined.']);
    }
}
