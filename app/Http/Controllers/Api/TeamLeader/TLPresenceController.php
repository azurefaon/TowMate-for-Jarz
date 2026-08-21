<?php

namespace App\Http\Controllers\Api\TeamLeader;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\TruckType;
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
            // If the unit got stuck in a non-available state (e.g. app crash without offline call),
            // reset it to available as long as there is no active job still running.
            $activeJobStatuses = ['on_the_way', 'in_progress', 'waiting_verification', 'payment_pending', 'payment_submitted'];
            $hasActiveJob = Booking::whereIn('status', $activeJobStatuses)
                ->where(function ($q) use ($user, $existingUnit) {
                    $q->where('assigned_team_leader_id', $user->id)
                      ->orWhere('assigned_unit_id', $existingUnit->id);
                })->exists();

            if (! $hasActiveJob && $existingUnit->status !== 'available') {
                $existingUnit->update(['status' => 'available']);
            }
        } else {
            // Auto-assign a unit if TL has none so dispatchAvailability() can find them
            $unit = Unit::where('status', 'available')->whereNull('team_leader_id')->first();

            if (! $unit) {
                $truckTypeQuery = TruckType::where('status', 'active');
                if ($user->duty_class) {
                    $truckTypeQuery->where('class', $user->duty_class);
                }
                $truckType = $truckTypeQuery->first();
                if ($truckType) {
                    $unit = Unit::create([
                        'name'          => ($user->name ?? 'TL') . "'s Unit",
                        'plate_number'  => 'AUTO-' . strtoupper(substr(md5((string) $user->id), 0, 6)),
                        'truck_type_id' => $truckType->id,
                        'driver_name'   => $user->name ?? '',
                        'status'        => 'available',
                    ]);
                }
            }

            if ($unit) {
                $unit->update(['team_leader_id' => $user->id]);
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
