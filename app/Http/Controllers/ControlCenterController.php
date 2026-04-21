<?php

namespace App\Http\Controllers;

use App\Services\ControlCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ControlCenterController extends Controller
{
    public function __construct(protected ControlCenterService $controlCenterService) {}

    public function index(Request $request)
    {
        $payload = $this->controlCenterService->buildPayload(
            $request->user(),
            $request->only(['status', 'search', 'period'])
        );

        $payload['initialState'] = [
            'summary_cards' => $payload['summary_cards'],
            'attention_alerts' => $payload['attention_alerts'],
            'booking_pipeline' => $payload['booking_pipeline'],
            'active_bookings' => $payload['active_bookings'],
            'recent_bookings' => $payload['recent_bookings'],
            'schedule_overview' => $payload['schedule_overview'],
            'team_leader_statuses' => $payload['team_leader_statuses'],
            'dispatchers' => $payload['dispatchers'],
            'units_monitor' => $payload['units_monitor'],
            'flagged_customers' => $payload['flagged_customers'],
            'recent_activities' => $payload['recent_activities'],
            'governance_summary' => $payload['governance_summary'],
            'quick_links' => $payload['quick_links'],
            'highlights' => $payload['highlights'],
            'is_super_admin' => $payload['is_super_admin'],
            'live_url' => $payload['live_url'],
        ];

        $payload['shellLayout'] = (int) $request->user()->role_id === 1
            ? 'layouts.superadmin'
            : 'admin-dashboard.layouts.app';

        return view('control-center.index', $payload);
    }

    public function live(Request $request): JsonResponse
    {
        return response()->json(
            $this->controlCenterService->buildPayload(
                $request->user(),
                $request->only(['status', 'search', 'period'])
            )
        );
    }
}
