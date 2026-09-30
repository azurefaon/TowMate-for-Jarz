<?php

namespace App\Http\Controllers\Api\TeamLeader;

use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Services\CustomerNotificationService;
use App\Support\TlDemoFixture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL/DEMO-ONLY. Simulates the physical arrival step for the seeded demo
 * fixture so a presentation can proceed without real GPS. It skips ONLY the
 * proximity check; everything else (auth, ownership, current-status
 * transition validity) is enforced exactly as on the real status endpoint.
 *
 * Fails closed: outside local/testing, or for anything that is not the demo
 * account + demo booking, it responds 404 and mutates nothing. There is
 * deliberately no request parameter that influences the target status or the
 * guard — the arrival is derived from the booking's current status.
 */
class TLDemoController extends Controller
{
    public function simulateArrival(Booking $booking, Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Environment — checked first, before touching the booking, so a
        //    production request performs no work and reveals nothing.
        if (! TlDemoFixture::environmentAllowsDemo()) {
            return $this->notFound();
        }

        // 2. Demo account + demo booking (also proves assigned-TL ownership).
        if (! TlDemoFixture::isDemoBooking($booking, $user)) {
            return $this->notFound();
        }

        $result = DB::transaction(function () use ($booking, $user) {
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();

            // Re-check on the locked row: a dispatcher reassignment racing this
            // request must not be overwritten.
            if (! $locked || (int) $locked->assigned_team_leader_id !== (int) $user->id) {
                return ['status' => 403, 'message' => 'This task is not assigned to you.'];
            }

            // 3. Current-status transition validity (the normal table is authoritative).
            $target = TlDemoFixture::targetFor($locked->status);
            if ($target === null) {
                return [
                    'status' => 422,
                    'message' => "Arrival cannot be simulated from '{$locked->status}'.",
                ];
            }

            $from = $locked->status;
            $locked->update(['status' => $target]);

            AuditLog::create([
                'user_id'     => $user->id,
                'action'      => 'demo_arrival_simulated',
                'entity_type' => 'Booking',
                'entity_id'   => $locked->id,
                'reference'   => $locked->booking_code,
                'description' => "DEMO: arrival simulated ('{$from}' → '{$target}'); GPS proximity check skipped.",
            ]);

            $locked->load(['customer']);

            return ['status' => 200, 'booking' => $locked, 'from' => $from, 'to' => $target];
        });

        if ($result['status'] !== 200) {
            return response()->json(['success' => false, 'message' => $result['message']], $result['status']);
        }

        $updated = $result['booking'];

        try { BookingStatusUpdated::safeFire($updated); } catch (\Throwable) {}

        // Same customer notification the real arrived_pickup transition sends.
        if ($result['to'] === 'arrived_pickup' && $updated->customer && $updated->customer->user_id) {
            CustomerNotificationService::send(
                userId: $updated->customer->user_id,
                type: 'booking_update',
                title: 'Your tow truck has arrived at the pickup location',
                body: 'Booking ' . $updated->booking_code,
                bookingCode: $updated->booking_code,
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Demo arrival simulated.',
            'status'  => $updated->status,
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Not found.'], 404);
    }
}
