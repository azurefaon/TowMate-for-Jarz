<?php

namespace App\Http\Controllers\Api\TeamLeader;

use App\Http\Controllers\Controller;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TLPresenceController extends Controller
{
    public function __construct(protected TeamLeaderAvailabilityService $teamLeaderAvailability) {}

    public function ping(Request $request): JsonResponse
    {
        $this->teamLeaderAvailability->markOnline($request->user());

        return response()->json(['success' => true, 'presence' => 'online']);
    }

    public function offline(Request $request): JsonResponse
    {
        $this->teamLeaderAvailability->markOffline($request->user());

        return response()->json(['success' => true, 'presence' => 'offline']);
    }
}
