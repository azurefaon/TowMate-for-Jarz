@extends($shellLayout)

@section('title', 'System Control Center')

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=DM+Sans:wght@400;500;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --cc-bg: linear-gradient(180deg, #fff8dc 0%, #fffdf5 34%, #f7fafc 100%);
            --cc-panel: rgba(255, 255, 255, 0.88);
            --cc-panel-strong: #ffffff;
            --cc-border: rgba(17, 24, 39, 0.08);
            --cc-text: #111827;
            --cc-muted: #5b6475;
            --cc-amber: #f59e0b;
            --cc-ink: #0f172a;
            --cc-sky: #0ea5e9;
            --cc-mint: #10b981;
            --cc-rose: #e11d48;
            --cc-violet: #7c3aed;
            --cc-gold: #d97706;
            --cc-danger: #b91c1c;
            --cc-shadow: 0 24px 60px rgba(15, 23, 42, 0.10);
        }

        .control-center-page {
            font-family: 'DM Sans', sans-serif;
            color: var(--cc-text);
            background: var(--cc-bg);
            border: 1px solid rgba(255, 255, 255, 0.35);
            border-radius: 28px;
            padding: 24px;
            box-shadow: var(--cc-shadow);
            overflow: hidden;
            position: relative;
        }

        .cc-shell {
            position: relative;
            z-index: 1;
            display: grid;
            gap: 20px;
        }

        .cc-panel {
            padding: 20px;
            border-radius: 24px;
            background: var(--cc-panel);
            border: 1px solid var(--cc-border);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
        }

        .cc-tabs {
            display: flex;
            gap: 8px;
            background: #f3f4f6;
            padding: 6px;
            border-radius: 30px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .cc-tab {
            flex: 1;
            border: none;
            background: transparent;
            padding: 10px;
            border-radius: 20px;
            font-size: 14px;
            cursor: pointer;
            color: #374151;
            font-weight: 500;
            transition: 0.2s;
        }

        .cc-tab.is-active {
            background: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            font-weight: 600;
            color: #111827;
        }

        .cc-tab-panel {
            display: none;
            padding: 20px;
        }

        .cc-tab-panel.is-active {
            display: block;
        }
    </style>
@endpush

@section('content')
    <div class="control-center-page">
        <div class="cc-shell">
            <div class="cc-panel" style="margin-bottom:18px;display:flex;gap:18px;flex-wrap:wrap;align-items:center;">
                <a href="{{ route('superadmin.dashboard') }}" class="quick-link-btn">
                    <i data-lucide="layout-dashboard"></i>
                    <span>Dashboard</span>
                </a>
                <a href="{{ route('superadmin.monitoring.index') }}" class="quick-link-btn">
                    <i data-lucide="activity"></i>
                    <span>Monitoring</span>
                </a>
                <a href="{{ route('superadmin.users.index') }}" class="quick-link-btn">
                    <i data-lucide="users"></i>
                    <span>Users</span>
                </a>
                <a href="{{ route('superadmin.unit-truck.index') }}" class="quick-link-btn">
                    <i data-lucide="truck"></i>
                    <span>Units</span>
                </a>
                <a href="{{ route('superadmin.bookings.index') }}" class="quick-link-btn">
                    <i data-lucide="clipboard-list"></i>
                    <span>Bookings</span>
                </a>
                <a href="{{ route('superadmin.audit.logs') }}" class="quick-link-btn">
                    <i data-lucide="shield-check"></i>
                    <span>Audit</span>
                </a>
                <a href="{{ route('superadmin.settings.index') }}" class="quick-link-btn">
                    <i data-lucide="settings"></i>
                    <span>Settings</span>
                </a>
            </div>
            <style>
                .quick-link-btn {
                    display: inline-flex;
                    align-items: center;
                    gap: 7px;
                    padding: 8px 16px;
                    border-radius: 14px;
                    background: #fffbe6;
                    border: 1px solid #fde68a;
                    color: #7c5a00;
                    font-weight: 500;
                    font-size: 0.98rem;
                    text-decoration: none;
                    transition: 0.18s;
                    box-shadow: 0 4px 12px rgba(245, 213, 101, 0.08);
                }

                .quick-link-btn:hover {
                    background: #fff1b8;
                    border-color: #eab308;
                    color: #b45309;
                }

                .quick-link-btn i {
                    width: 18px;
                    height: 18px;
                }
            </style>
            <div class="cc-tabs" id="controlCenterTabs">
                <button type="button" class="cc-tab is-active" data-tab="operations">Live Operations</button>
                <button type="button" class="cc-tab" data-tab="fleet">Leaders & Units</button>
                <button type="button" class="cc-tab" data-tab="customers">Customers & Risk</button>
            </div>
            <section class="cc-tab-panel is-active" data-panel="operations">
                <!-- Live Operations: summary cards, alerts, pipeline, trackers -->
                <div class="monitor-stats-expanded" style="margin-bottom:18px;">
                    <div class="monitor-stat">
                        <small>Active Jobs</small>
                        <strong>{{ $initialState['summary_cards']['active_jobs'] ?? 0 }}</strong>
                    </div>
                    <div class="monitor-stat">
                        <small>Pending Requests</small>
                        <strong>{{ $initialState['summary_cards']['pending_requests'] ?? 0 }}</strong>
                    </div>
                    <div class="monitor-stat">
                        <small>Scheduled Today</small>
                        <strong>{{ $initialState['summary_cards']['scheduled_today'] ?? 0 }}</strong>
                    </div>
                    <div class="monitor-stat">
                        <small>Returned Tasks</small>
                        <strong>{{ $initialState['summary_cards']['returned_tasks'] ?? 0 }}</strong>
                    </div>
                    <div class="monitor-stat">
                        <small>Online Leaders</small>
                        <strong>{{ $initialState['summary_cards']['online_team_leaders'] ?? 0 }}</strong>
                    </div>
                    <div class="monitor-stat">
                        <small>Busy Leaders</small>
                        <strong>{{ $initialState['summary_cards']['busy_team_leaders'] ?? 0 }}</strong>
                    </div>
                    <div class="monitor-stat">
                        <small>Dispatchers</small>
                        <strong>{{ $initialState['summary_cards']['dispatchers'] ?? 0 }}</strong>
                    </div>
                    <div class="monitor-stat">
                        <small>Available Units</small>
                        <strong>{{ $initialState['summary_cards']['available_units'] ?? 0 }}</strong>
                    </div>
                    <div class="monitor-stat">
                        <small>Watchlist Customers</small>
                        <strong>{{ $initialState['summary_cards']['watchlist_customers'] ?? 0 }}</strong>
                    </div>
                    <div class="monitor-stat">
                        <small>Blacklisted Customers</small>
                        <strong>{{ $initialState['summary_cards']['blacklisted_customers'] ?? 0 }}</strong>
                    </div>
                </div>

                <div class="monitor-alert-list" style="margin-bottom:18px;">
                    @foreach ($initialState['attention_alerts'] ?? [] as $alert)
                        <div class="monitor-alert {{ $alert['level'] }}">
                            <h4>{{ $alert['title'] }}</h4>
                            <p>{{ $alert['message'] }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="monitor-pipeline" style="margin-bottom:18px;">
                    @foreach ($initialState['booking_pipeline'] ?? [] as $stage)
                        <div class="pipeline-card {{ $stage['tone'] }}">
                            <span>{{ $stage['label'] }}</span>
                            <strong>{{ $stage['count'] }}</strong>
                        </div>
                    @endforeach
                </div>

                <div class="monitor-list" style="margin-bottom:18px;">
                    @forelse ($initialState['active_bookings'] ?? [] as $booking)
                        <div class="monitor-item">
                            <div class="monitor-item-head">
                                <strong>{{ $booking['job_code'] }}</strong>
                                <span
                                    class="monitor-badge {{ $booking['status'] }}">{{ str($booking['status'])->replace('_', ' ')->title() }}</span>
                            </div>
                            <p>{{ $booking['pickup_address'] }} → {{ $booking['dropoff_address'] }}</p>
                            <small>
                                {{ $booking['customer_name'] ?? 'Customer pending' }} ·
                                {{ $booking['team_leader_name'] ?? 'Awaiting crew' }}
                            </small>
                            <div class="tracker-metrics">
                                <span>{{ $booking['service_mode_label'] ?? '' }}</span>
                                <span>{{ $booking['schedule_window_label'] ?? '' }}</span>
                                <span>{{ $booking['updated_at_human'] ?? '' }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="monitor-item">
                            <strong>No active jobs right now.</strong>
                            <p>The queue is clear.</p>
                        </div>
                    @endforelse
                </div>

                <div class="monitor-grid">
                    <div class="monitor-card">
                        <div class="monitor-item-head">
                            <div>
                                <h3>Team Leader Tracker</h3>
                                <p>See each leader’s presence, current unit, active job, returns, and completions.</p>
                            </div>
                        </div>
                        <div class="monitor-list">
                            @forelse ($initialState['team_leader_statuses'] ?? [] as $leader)
                                <div class="monitor-item">
                                    <div class="monitor-item-head">
                                        <strong>{{ $leader['name'] }}</strong>
                                        <span class="monitor-badge {{ $leader['presence'] }} {{ $leader['workload'] }}">
                                            {{ $leader['status_summary'] }}
                                        </span>
                                    </div>
                                    <p>{{ $leader['unit_name'] }} · {{ $leader['driver_name'] }}</p>
                                    <small>{{ $leader['active_job_code'] }} · {{ $leader['active_job_status'] }}</small>
                                    <div class="tracker-metrics">
                                        <span>Returns: {{ $leader['returns_today'] }}</span>
                                        <span>Completed: {{ $leader['completed_today'] }}</span>
                                        <span>{{ $leader['schedule_note'] }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="monitor-item">No team leaders found.</div>
                            @endforelse
                        </div>
                    </div>
                    <div class="monitor-card">
                        <div class="monitor-item-head">
                            <div>
                                <h3>Dispatcher Tracker</h3>
                                <p>Watch dispatcher activity, quote output, and current operational workload.</p>
                            </div>
                        </div>
                        <div class="monitor-list">
                            @forelse ($initialState['dispatchers'] ?? [] as $dispatcher)
                                <div class="monitor-item">
                                    <div class="monitor-item-head">
                                        <strong>{{ $dispatcher['name'] }}</strong>
                                        <span class="monitor-badge">{{ $dispatcher['workload_label'] }}</span>
                                    </div>
                                    <p>{{ $dispatcher['last_action'] }}</p>
                                    <small>{{ $dispatcher['last_seen'] }}</small>
                                    <div class="tracker-metrics">
                                        <span>Actions: {{ $dispatcher['actions_today'] }}</span>
                                        <span>Quotes: {{ $dispatcher['quotes_today'] }}</span>
                                        <span>Bookings: {{ $dispatcher['bookings_today'] }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="monitor-item">No dispatcher activity yet.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </section>
            <section class="cc-tab-panel" data-panel="fleet">
                <!-- Leaders & Units: units monitor and team leader tracker -->
                <div class="monitor-grid">
                    <div class="monitor-card">
                        <div class="monitor-item-head">
                            <div>
                                <h3>Unit & Schedule Monitor</h3>
                                <p>See which units are ready, who is assigned, and what jobs are coming up.</p>
                            </div>
                        </div>
                        <div class="monitor-list">
                            @forelse ($initialState['units_monitor'] ?? [] as $unit)
                                <div class="monitor-item">
                                    <div class="monitor-item-head">
                                        <strong>{{ $unit['name'] }} · {{ $unit['plate_number'] }}</strong>
                                        <span
                                            class="monitor-badge {{ $unit['status'] }}">{{ $unit['status_label'] }}</span>
                                    </div>
                                    <p>{{ $unit['team_leader'] }} · {{ $unit['driver'] }}</p>
                                    <small>{{ $unit['truck_type'] }} · {{ $unit['booking_code'] }}</small>
                                    <div class="tracker-metrics">
                                        <span>{{ $unit['schedule_label'] }}</span>
                                        <span>{{ $unit['updated_at'] }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="monitor-item">No units found.</div>
                            @endforelse
                        </div>
                    </div>
                    <div class="monitor-card">
                        <div class="monitor-item-head">
                            <div>
                                <h3>Team Leader Tracker</h3>
                                <p>See each leader’s presence, current unit, active job, returns, and completions.</p>
                            </div>
                        </div>
                        <div class="monitor-list">
                            @forelse ($initialState['team_leader_statuses'] ?? [] as $leader)
                                <div class="monitor-item">
                                    <div class="monitor-item-head">
                                        <strong>{{ $leader['name'] }}</strong>
                                        <span class="monitor-badge {{ $leader['presence'] }} {{ $leader['workload'] }}">
                                            {{ $leader['status_summary'] }}
                                        </span>
                                    </div>
                                    <p>{{ $leader['unit_name'] }} · {{ $leader['driver_name'] }}</p>
                                    <small>{{ $leader['active_job_code'] }} · {{ $leader['active_job_status'] }}</small>
                                    <div class="tracker-metrics">
                                        <span>Returns: {{ $leader['returns_today'] }}</span>
                                        <span>Completed: {{ $leader['completed_today'] }}</span>
                                        <span>{{ $leader['schedule_note'] }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="monitor-item">No team leaders found.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </section>
            <section class="cc-tab-panel" data-panel="customers">
                <!-- Customers & Risk: flagged customers and recent activities -->
                <div class="monitor-grid">
                    <div class="monitor-card">
                        <div class="monitor-item-head">
                            <div>
                                <h3>Risk Watchlist</h3>
                                <p>Customers tagged for unreachable, no-show, or non-payment behavior.</p>
                            </div>
                        </div>
                        <div class="monitor-list">
                            @forelse ($initialState['flagged_customers'] ?? [] as $customer)
                                <div class="monitor-item">
                                    <div class="monitor-item-head">
                                        <strong>{{ $customer['name'] }}</strong>
                                        <span
                                            class="monitor-badge {{ $customer['risk_level'] }}">{{ $customer['risk_label'] }}</span>
                                    </div>
                                    <p>{{ $customer['reason'] }}</p>
                                    <small>{{ $customer['phone'] }} · {{ $customer['updated_at'] }}</small>
                                </div>
                            @empty
                                <div class="monitor-item">
                                    <strong>No flagged customers right now.</strong>
                                    <p>The customer risk list is currently clear.</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                    <div class="monitor-card">
                        <div class="monitor-item-head">
                            <div>
                                <h3>Recent System Activity</h3>
                                <p>Latest dispatcher and team leader actions from the audit stream.</p>
                            </div>
                        </div>
                        <div class="monitor-list">
                            @forelse ($initialState['recent_activities'] ?? [] as $activity)
                                <div class="monitor-item">
                                    <div class="monitor-item-head">
                                        <strong>{{ str($activity['action'])->replace('_', ' ')->title() }}</strong>
                                        <small>{{ $activity['created_at_human'] }}</small>
                                    </div>
                                    <p>{{ $activity['user_name'] ?? 'System' }}</p>
                                    <small>{{ $activity['entity_type'] }} #{{ $activity['entity_id'] }}</small>
                                </div>
                            @empty
                                <div class="monitor-item">No audit activity available.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </section>
            @if ($is_super_admin)
                {{-- Governance tab and panel removed for superadmin as requested --}}
            @endif
        </div>
    </div>
    <script>
        // Simple tab switching logic
        document.querySelectorAll('.cc-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.cc-tab').forEach(t => t.classList.remove('is-active'));
                document.querySelectorAll('.cc-tab-panel').forEach(p => p.classList.remove('is-active'));
                tab.classList.add('is-active');
                document.querySelector('.cc-tab-panel[data-panel="' + tab.dataset.tab + '"]').classList.add(
                    'is-active');
            });
        });
    </script>
@endsection
