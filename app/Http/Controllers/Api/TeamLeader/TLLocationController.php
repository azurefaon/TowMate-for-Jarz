<?php

namespace App\Http\Controllers\Api\TeamLeader;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\ZoneLocatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TLLocationController extends Controller
{
    public function __construct(protected ZoneLocatorService $zoneLocator)
    {
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
        ]);

        $unit = Unit::where('team_leader_id', $request->user()->id)->first();

        if (! $unit) {
            return response()->json(['success' => false, 'message' => 'No unit assigned to this team leader.'], 404);
        }

        // Rate-limit: skip write if last update was less than 10 seconds ago
        if ($unit->location_updated_at && $unit->location_updated_at->diffInSeconds(now()) < 10) {
            return response()->json(['success' => true]);
        }

        $unit->update([
            'current_lat'         => $validated['lat'],
            'current_lng'         => $validated['lng'],
            'location_accuracy'   => $validated['accuracy'] ?? null,
            'location_updated_at' => now(),
        ]);

        if (! $unit->zone_confirmed) {
            $resolvedZoneId = $this->zoneLocator->resolve($validated['lat'], $validated['lng'])?->id;

            if ($resolvedZoneId !== $unit->zone_id) {
                $unit->update(['zone_id' => $resolvedZoneId]);
            }
        }

        return response()->json(['success' => true]);
    }
}
