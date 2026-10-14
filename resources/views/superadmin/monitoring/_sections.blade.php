@php
    // Colours for solid status badges (labels come from the data).
    $presenter = app(\App\Services\OwnerBookingPresenter::class);
    $workloadTone = ['busy' => 'orange', 'available' => 'green', 'standby' => 'slate'];
    $availabilityTone = ['available' => 'green', 'on_job' => 'blue', 'reserved' => 'violet', 'maintenance' => 'orange', 'unavailable' => 'red'];
    $riskTone = ['blacklisted' => 'black', 'watchlist' => 'yellow'];
    $alertIcon = ['danger' => 'circle-alert', 'warning' => 'triangle-alert', 'info' => 'info', 'success' => 'circle-check'];
@endphp

{{-- Operations Summary --}}
<section class="oa-card oa-card-body mo-block" aria-labelledby="moSummaryTitle">
    <div class="oa-card-head">
        <h2 class="oa-card-title" id="moSummaryTitle"><i data-lucide="gauge" aria-hidden="true"></i> Operations Summary</h2>
    </div>

    <div class="oa-kpis mo-kpis" style="--oa-kpi-count: 5;">
        <div class="oa-kpi">
            <span class="oa-kpi-icon"><i data-lucide="briefcase" aria-hidden="true"></i></span>
            <span class="oa-kpi-label">Active Jobs</span>
            <div class="oa-kpi-value" id="monitorActiveJobs">{{ $monitoringStats['active_jobs'] }}</div>
        </div>
        <div class="oa-kpi">
            <span class="oa-kpi-icon"><i data-lucide="inbox" aria-hidden="true"></i></span>
            <span class="oa-kpi-label">Pending Requests</span>
            <div class="oa-kpi-value" id="monitorPendingRequests">{{ $monitoringStats['pending_requests'] }}</div>
        </div>
        <div class="oa-kpi">
            <span class="oa-kpi-icon"><i data-lucide="calendar-clock" aria-hidden="true"></i></span>
            <span class="oa-kpi-label">Scheduled Today</span>
            <div class="oa-kpi-value" id="monitorScheduledToday">{{ $monitoringStats['scheduled_today'] }}</div>
        </div>
        <div class="oa-kpi">
            <span class="oa-kpi-icon"><i data-lucide="truck" aria-hidden="true"></i></span>
            <span class="oa-kpi-label">Available Units</span>
            <div class="oa-kpi-value" id="monitorUnitsReady">{{ $monitoringStats['available_units'] }}</div>
        </div>
        <div class="oa-kpi">
            <span class="oa-kpi-icon"><i data-lucide="users" aria-hidden="true"></i></span>
            <span class="oa-kpi-label">Online Team Leaders</span>
            <div class="oa-kpi-value" id="monitorOnlineLeaders">{{ $monitoringStats['online_team_leaders'] }}</div>
        </div>
    </div>
</section>

{{-- Needs Attention --}}
<section class="oa-card oa-card-body mo-block" aria-labelledby="moAttentionTitle">
    <div class="oa-card-head" style="margin-bottom:8px;">
        <h2 class="oa-card-title" id="moAttentionTitle"><i data-lucide="bell-ring" aria-hidden="true"></i> Needs Attention</h2>
    </div>

    <div class="mo-alerts">
        @foreach ($attentionAlerts as $alert)
            <div class="mo-alert mo-alert--{{ $alert['level'] }}">
                <span class="mo-alert-icon"><i data-lucide="{{ $alertIcon[$alert['level']] ?? 'info' }}" aria-hidden="true"></i></span>
                <span class="mo-alert-title">{{ $alert['title'] }}</span>
                <span class="mo-alert-text">{{ $alert['message'] }}</span>
            </div>
        @endforeach
    </div>
</section>

{{-- Current Operations --}}
<section class="oa-card oa-list-card mo-block" aria-labelledby="moJobsTitle">
    <div class="oa-activity-head">
        <h2 class="oa-card-title" id="moJobsTitle"><i data-lucide="list-checks" aria-hidden="true"></i> Current Operations</h2>
        <a href="{{ route('superadmin.bookings.index') }}" class="oa-btn oa-btn--sm">Open Bookings <i data-lucide="arrow-right" aria-hidden="true"></i></a>
    </div>

    <div class="mo-pipeline" role="list">
        @foreach ($bookingPipeline as $stage)
            <div class="mo-stage" role="listitem">
                <span class="mo-stage-label">{{ $stage['label'] }}</span>
                <span class="mo-stage-count">{{ $stage['count'] }}</span>
            </div>
        @endforeach
    </div>

    <p class="mo-caption">{{ $listCaption }}</p>

    <div class="oa-table-wrap">
        <table class="oa-table mo-table">
            <thead>
                <tr>
                    <th scope="col">Booking</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Route</th>
                    <th scope="col">Unit / Team</th>
                    <th scope="col">Status</th>
                    <th scope="col">Schedule</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($activeBookings as $booking)
                    @php
                        $route = $booking->pickup_address . ' → ' . $booking->dropoff_address;
                        $unitName = $booking->display_unit_name;
                        $leaderName = optional($booking->assignedTeamLeader)->full_name ?: optional($booking->assignedTeamLeader)->name;
                    @endphp
                    <tr>
                        <td>
                            <a class="oa-code" href="{{ route('superadmin.bookings.index', ['search' => $booking->job_code]) }}">{{ $booking->job_code }}</a>
                        </td>
                        <td>{{ optional($booking->customer)->full_name ?? '—' }}</td>
                        <td class="monitor-route-cell mo-route" title="{{ $route }}">{{ $route }}</td>
                        <td>
                            @if ($unitName)
                                {{ $unitName }}
                                @if ($leaderName)
                                    <span class="oa-sub">{{ $leaderName }}</span>
                                @endif
                            @elseif ($leaderName)
                                {{ $leaderName }}
                            @else
                                —
                            @endif
                        </td>
                        <td><span class="oa-badge tone-{{ $presenter->statusTone($booking->status) }}">{{ $presenter->statusLabel($booking->status) }}</span></td>
                        <td>{{ $booking->schedule_window_label }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="mo-empty">No active jobs right now.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<div class="monitor-readiness-stack mo-stack">
    {{-- Team Leaders --}}
    <section class="oa-card oa-list-card mo-block" aria-labelledby="moLeadersTitle">
        <div class="oa-activity-head">
            <h2 class="oa-card-title" id="moLeadersTitle"><i data-lucide="users" aria-hidden="true"></i> Team Leaders</h2>
        </div>

        <div class="oa-table-wrap">
            <table class="oa-table mo-table">
                <thead>
                    <tr>
                        <th scope="col">Team Leader</th>
                        <th scope="col">Unit</th>
                        <th scope="col">Presence</th>
                        <th scope="col">Workload</th>
                        <th scope="col">Current Job</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($teamLeaderStatuses as $leader)
                        <tr>
                            <td>{{ $leader['name'] }}</td>
                            <td>{{ $leader['unit_name'] === 'No assigned unit' ? '—' : $leader['unit_name'] }}</td>
                            <td><span class="oa-badge monitor-presence--{{ $leader['presence'] }} tone-{{ $leader['presence'] === 'online' ? 'green' : 'neutral' }}">{{ $leader['presence_label'] }}</span></td>
                            <td><span class="oa-badge monitor-workload--{{ $leader['workload'] }} tone-{{ $workloadTone[$leader['workload']] ?? 'neutral' }}">{{ $leader['workload_label'] }}</span></td>
                            <td>
                                @if ($leader['active_job_code'] === 'No active booking')
                                    —
                                @else
                                    <a class="oa-code" href="{{ route('superadmin.bookings.index', ['search' => $leader['active_job_code']]) }}">{{ $leader['active_job_code'] }}</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="mo-empty">No team leaders found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Units --}}
    <section class="oa-card oa-list-card mo-block" aria-labelledby="moUnitsTitle">
        <div class="oa-activity-head">
            <h2 class="oa-card-title" id="moUnitsTitle"><i data-lucide="truck" aria-hidden="true"></i> Units</h2>
        </div>

        <div class="oa-table-wrap">
            <table class="oa-table mo-table">
                <thead>
                    <tr>
                        <th scope="col">Unit</th>
                        <th scope="col">Truck Type</th>
                        <th scope="col">Team Leader</th>
                        <th scope="col">Current Job</th>
                        <th scope="col">Availability</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($unitsMonitor as $unit)
                        <tr>
                            <td>
                                {{ $unit['name'] }}
                                <span class="oa-sub">{{ $unit['plate_number'] }}</span>
                            </td>
                            <td>{{ $unit['truck_type'] }}</td>
                            <td>{{ $unit['team_leader'] === 'Unassigned' ? '—' : $unit['team_leader'] }}</td>
                            <td>
                                @if ($unit['booking_code'] === 'No linked booking')
                                    —
                                @else
                                    <a class="oa-code" href="{{ route('superadmin.bookings.index', ['search' => $unit['booking_code']]) }}">{{ $unit['booking_code'] }}</a>
                                @endif
                            </td>
                            <td><span class="oa-badge monitor-availability--{{ $unit['status'] }} tone-{{ $availabilityTone[$unit['status']] ?? 'neutral' }}">{{ $unit['status_label'] }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="mo-empty">No units found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

{{-- Dispatcher Activity --}}
<section class="oa-card oa-list-card mo-block" aria-labelledby="moDispatchTitle">
    <div class="oa-activity-head">
        <h2 class="oa-card-title" id="moDispatchTitle"><i data-lucide="headset" aria-hidden="true"></i> Dispatcher Activity</h2>
        <a href="{{ route('superadmin.reports.activity') }}" class="oa-btn oa-btn--sm">View Logs <i data-lucide="arrow-right" aria-hidden="true"></i></a>
    </div>

    <div class="oa-table-wrap">
        <table class="oa-table mo-table">
            <thead>
                <tr>
                    <th scope="col">Dispatcher</th>
                    <th scope="col">Last Activity</th>
                    <th scope="col" class="is-num">Bookings Today</th>
                    <th scope="col" class="is-num">Quotes Today</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($dispatchers as $dispatcher)
                    <tr>
                        <td>{{ $dispatcher['name'] }}</td>
                        <td>{{ $dispatcher['last_seen'] }}</td>
                        <td class="is-num">{{ $dispatcher['bookings_today'] }}</td>
                        <td class="is-num">{{ $dispatcher['quotes_today'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="mo-empty">No dispatcher activity yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

{{-- Risk Watchlist --}}
<section class="oa-card oa-list-card mo-block" aria-labelledby="moRiskTitle">
    <div class="oa-activity-head">
        <h2 class="oa-card-title" id="moRiskTitle"><i data-lucide="shield-alert" aria-hidden="true"></i> Risk Watchlist</h2>
    </div>

    @if ($flaggedCustomers->isEmpty())
        <div class="mo-empty mo-empty--block">No flagged customers.</div>
    @else
        <div class="oa-table-wrap">
            <table class="oa-table mo-table">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Risk</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Phone</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($flaggedCustomers as $customer)
                        <tr>
                            <td>{{ $customer['name'] }}</td>
                            <td><span class="oa-badge monitor-risk--{{ $customer['risk_level'] }} tone-{{ $riskTone[$customer['risk_level']] ?? 'neutral' }}">{{ $customer['risk_label'] }}</span></td>
                            <td>{{ $customer['reason'] }}</td>
                            <td>{{ $customer['phone'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
