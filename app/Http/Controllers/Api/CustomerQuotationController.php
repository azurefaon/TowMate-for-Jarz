<?php

namespace App\Http\Controllers\Api;

use App\Events\CustomerInquirySent;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Quotation;
use App\Services\BookingService;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerQuotationController extends Controller
{
    public function __construct(
        private readonly QuotationService $quotationService,
        private readonly BookingService $bookingService,
    ) {}

    public function pending(Request $request): JsonResponse
    {
        $customer = Customer::where('user_id', $request->user()->id)->first();
        if (!$customer) return response()->json(['data' => null]);

        $quotation = Quotation::where('customer_id', $customer->id)
            ->whereIn('status', ['sent', 'price_review_requested'])
            ->whereNotNull('source_booking_id')
            ->current()
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with(['truckType', 'sourceBooking'])
            ->latest('sent_at')
            ->first();

        if (!$quotation) return response()->json(['data' => null]);

        $sourceBooking = $quotation->sourceBooking;
        $distanceKm    = (float) ($quotation->distance_km ?? 0);
        $distanceFee   = $this->bookingService->distanceFeeFor($distanceKm, (float) ($quotation->truckType?->per_km_rate ?? 0));

        // Derived live from the CURRENT quotation's authoritative estimated_price —
        // never from booking.vat_amount, which is only guaranteed fresh at send
        // and at acceptance and can go stale in between (e.g. after a Price
        // Review adjustment creates a new current version). Mirrors the same
        // total/1.12 split the dispatcher drawer already uses.
        $additionalFee = (float) ($quotation->additional_fee ?? 0);
        $vatExclusive  = round(((float) $quotation->estimated_price) / 1.12, 2);
        $vatAmount     = round(((float) $quotation->estimated_price) - $vatExclusive, 2);

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
            // Only meaningful once status === 'price_review_requested' — the
            // customer's own submitted reason, echoed back for the waiting UI.
            'response_note'       => $quotation->response_note,
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
                    if (! $quotation->is_current) {
                        $fail('This quotation was revised. Please refresh and review the latest version.');
                    }
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

        if (! $quotation->is_current || $quotation->status !== 'sent') {
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

    /**
     * Reason-only replacement for the old (never actually live) counter-offer
     * negotiation — no price field, just a required reason. Pauses the
     * customer-response clock: see QuotationService::requestPriceReview().
     * Available to both Book Now and Scheduled quotations — the dispatcher's
     * Keep Current Price / Adjust Price response already caps any refreshed
     * expiry at scheduled_for - 2h for Scheduled via QuotationService's
     * resolveExpiry(), so no separate handling is needed here.
     */
    public function requestPriceReview(Request $request, Quotation $quotation): JsonResponse
    {
        $customer = Customer::where('user_id', $request->user()->id)->first();
        if (!$customer || (int) $quotation->customer_id !== $customer->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        if (! $quotation->is_current || $quotation->status !== 'sent' || $quotation->isExpired()) {
            return response()->json(['success' => false, 'message' => 'This quotation is no longer active.'], 422);
        }

        $validated = $request->validate(['reason' => 'required|string|max:1000']);

        $this->quotationService->requestPriceReview($quotation, $validated['reason']);

        return response()->json(['success' => true, 'message' => 'Your request has been sent. We will review the price and follow up shortly.']);
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
                    if (! $quotation->is_current) {
                        $fail('This quotation was revised. Please refresh and review the latest version.');
                    }
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
