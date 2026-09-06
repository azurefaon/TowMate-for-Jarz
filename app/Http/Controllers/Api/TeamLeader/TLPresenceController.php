<?php

namespace App\Http\Controllers\Api\TeamLeader;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Unit;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TLPresenceController extends Controller
{
    public function __construct(protected TeamLeaderAvailabilityService $teamLeaderAvailability) {}

    public function ping(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');
        $this->teamLeaderAvailability->markOnline($user);

        $existingUnit = Unit::where('team_leader_id', $user->id)->first();

        if ($existingUnit) {
            // Self-heals only the one genuinely stuck legacy state (a unit left
            // at 'on_job' by an app crash without a clean offline call) — never
            // touches 'maintenance', which is a deliberate Owner/Dispatcher
            // fleet decision that a mere presence ping must not undo. Uses the
            // same canonical busy-status list as everywhere else (previously a
            // shorter, drifted local copy that didn't include 'accepted',
            // 'assigned', 'arrived_pickup', 'loading_vehicle', 'arrived_dropoff').
            $hasActiveJob = Booking::whereIn('status', $this->teamLeaderAvailability->busyStatusesList())
                ->where(function ($q) use ($user, $existingUnit) {
                    $q->where('assigned_team_leader_id', $user->id)
                      ->orWhere('assigned_unit_id', $existingUnit->id);
                })
                ->where(function ($q) {
                    $q->where('status', '!=', 'waiting_verification')
                        ->orWhereNull('payment_submitted_at');
                })
                ->exists();

            if (! $hasActiveJob && $existingUnit->status === 'on_job') {
                $existingUnit->update(['status' => 'available']);
            }
        }

        return response()->json(['success' => true, 'presence' => 'online']);
    }

    public function offline(Request $request): JsonResponse
    {
        $this->teamLeaderAvailability->markOffline($request->user());

        return response()->json(['success' => true, 'presence' => 'offline']);
    }

    public function away(Request $request): JsonResponse
    {
        $this->teamLeaderAvailability->markAway($request->user());

        return response()->json(['success' => true, 'presence' => 'away']);
    }
}
