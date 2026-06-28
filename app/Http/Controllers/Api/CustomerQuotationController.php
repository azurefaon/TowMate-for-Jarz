<?php

namespace App\Http\Controllers\Api;

use App\Events\CustomerInquirySent;
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
            ->with(['truckType', 'sourceBooking'])
            ->latest('sent_at')
            ->first();

        if (!$quotation) return response()->json(['data' => null]);

        $sourceBooking = $quotation->sourceBooking;
        $distanceKm    = (float) ($quotation->distance_km ?? 0);
        $distanceFee   = $distanceKm >= 4.0 ? round($distanceKm * 300.0, 2) : 0.0;

        // Prefer stored vat_amount on booking; fall back to back-calculation
        $additionalFee = (float) ($quotation->additional_fee ?? 0);
        $vatAmount     = (float) ($sourceBooking?->vat_amount ?? 0);
        if ($vatAmount <= 0 && (float) $quotation->estimated_price > 0) {
            $vatAmount = round(((float) $quotation->estimated_price - $additionalFee) / 1.12 * 0.12, 2);
        }

        // Use the dispatcher note from the source booking as the fee description,
        // or fall back to the latest reason in the price change log.
        $additionalFeeNote = $sourceBooking?->dispatcher_note
            ?? collect($quotation->price_change_log ?? [])->last()['reason']
            ?? null;

        return response()->json(['data' => [
            'id'                  => $quotation->id,
            'quotation_number'    => $quotation->quotation_number,
            'status'              => $quotation->status,
            'estimated_price'     => (float) $quotation->estimated_price,
            'base_rate'           => (float) ($sourceBooking?->base_rate ?? 0),
            'distance_km'         => $distanceKm,
            'distance_fee'        => $distanceFee,
            'vat_amount'          => $vatAmount,
            'additional_fee'      => $additionalFee,
            'additional_fee_note' => $additionalFeeNote,
            'pickup_address'      => $quotation->pickup_address,
            'dropoff_address'     => $quotation->dropoff_address,
            'pickup_notes'        => $quotation->pickup_notes,
            'truck_type_name'     => $quotation->truckType?->name ?? '',
            'truck_type_class'    => $quotation->truckType?->vehicle_class ?? null,
            'service_type'        => $quotation->service_type,
            'source_booking_id'   => $quotation->source_booking_id,
            'expires_at'          => $quotation->expires_at?->toIso8601String(),
            'sent_at'             => $quotation->sent_at?->toIso8601String(),
            'price_change_log'    => $quotation->price_change_log ?? [],
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
                'quotation_id'     => $quotation->id,
                'source_booking_id' => $quotation->source_booking_id,
                'service_type'     => $quotation->service_type,
                'error'            => $e->getMessage(),
                'trace'            => $e->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => 'Failed to accept quotation.'], 500);
        }
    }

    public function inquire(Request $request, Quotation $quotation): JsonResponse
    {
        $customer = Customer::where('user_id', $request->user()->id)->first();
        if (!$customer || (int) $quotation->customer_id !== $customer->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        if ($quotation->status !== 'sent') {
            return response()->json(['success' => false, 'message' => 'Quotation is no longer active.'], 422);
        }

        $validated = $request->validate(['message' => 'required|string|max:500']);

        $quotation->update([
            'customer_inquiry' => $validated['message'],
            'inquiry_sent_at'  => now(),
        ]);

        broadcast(new CustomerInquirySent($quotation, $validated['message']))->toOthers();

        return response()->json(['success' => true, 'message' => 'Your message has been sent to the dispatcher.']);
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
