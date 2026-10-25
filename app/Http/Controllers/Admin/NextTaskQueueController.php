<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\QueueOfferException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingQueueOffer;
use App\Models\Unit;
use App\Services\NextTaskQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Dispatcher side of the next-task queue: offer, watch, cancel, promote. */
class NextTaskQueueController extends Controller
{
    public function __construct(protected NextTaskQueueService $queue)
    {
    }

    /** The booking's current offer (if any), for the drawer and the live map. */
    public function show(Booking $booking): JsonResponse
    {
        $this->queue->sweep();
        $offer = $this->queue->activeOfferForBooking($booking);

        return response()->json(['offer' => $offer ? $this->queue->present($offer) : null]);
    }

    public function store(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate(['unit_id' => ['required', 'integer', 'exists:units,id']]);

        return $this->respond(function () use ($booking, $data, $request) {
            $unit = Unit::with(['teamLeader', 'truckType'])->findOrFail($data['unit_id']);
            $offer = $this->queue->createOffer($booking, $unit, $request->user());

            return ['message' => 'Next task offered. The Team Leader has ' . $this->queue->ttlMinutes() . ' minutes to respond.', 'offer' => $this->queue->present($offer)];
        }, 201);
    }

    public function cancel(Request $request, BookingQueueOffer $offer): JsonResponse
    {
        return $this->respond(function () use ($offer, $request) {
            $cancelled = $this->queue->cancel($offer->id, $request->user());

            return ['message' => 'Next-task offer cancelled.', 'offer' => $this->queue->present($cancelled)];
        });
    }

    public function promote(Request $request, BookingQueueOffer $offer): JsonResponse
    {
        return $this->respond(function () use ($offer, $request) {
            $result = $this->queue->promote($offer->id, $request->user());

            return [
                'message' => 'Next task assigned. The Team Leader can now accept it from their task screen.',
                'offer' => $this->queue->present($result['offer']),
                'booking_code' => $result['booking']->booking_code,
            ];
        });
    }

    protected function respond(callable $action, int $okStatus = 200): JsonResponse
    {
        try {
            return response()->json($action(), $okStatus);
        } catch (QueueOfferException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }
    }
}
