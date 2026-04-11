<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;

class DriversController extends Controller
{
    public function __construct(protected TeamLeaderAvailabilityService $teamLeaderAvailability) {}

    public function index()
    {
        $teamLeaders = User::where('role_id', 3)
            ->with(['unit', 'unit.driver'])
            ->get();

        $busyTeamLeaders = $this->teamLeaderAvailability->busyTeamLeaderIds();
        $teamLeaderSummary = $this->teamLeaderAvailability->summarize($teamLeaders, $busyTeamLeaders);
        $teamLeaderStatuses = $teamLeaderSummary['leaders']->keyBy('id');
        $onlineTeamLeadersCount = $teamLeaderSummary['online_count'];
        $offlineTeamLeadersCount = $teamLeaderSummary['offline_count'];

        return view('admin-dashboard.pages.drivers', compact(
            'teamLeaders',
            'busyTeamLeaders',
            'teamLeaderStatuses',
            'onlineTeamLeadersCount',
            'offlineTeamLeadersCount'
        ));
    }
}
