@extends('layouts.superadmin')

@section('title', 'Driver Records')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@php $t = $d['totals']; @endphp

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerDriverRecords">
        <header class="ob-header fin-header">
            <div>
                <h1>Driver Records</h1>
                <p>Completed jobs and vehicles towed per week, and each person's history. {{ $periodLabel }}. Weeks run Monday to Saturday.</p>
            </div>
            <div class="fin-header-actions"><button type="button" class="oa-btn" onclick="window.print()">Print</button></div>
        </header>

        @include('superadmin.finance._flashes')

        <div class="fin-flash fin-flash--warning" role="status">
            <i data-lucide="users" aria-hidden="true"></i>
            <span>A job counts for a person only after its crew is recorded under <a href="{{ route('superadmin.finance.crew.index') }}">Job Crew</a>. Bookings do not store a driver, and a unit's current crew is never assumed to have worked past jobs. This page does not calculate commission.</span>
        </div>

        <form method="GET" action="{{ route('superadmin.finance.driver-records.index') }}" class="oa-card oa-filter-card" role="search">
            <div class="oa-filters fin-filters">
                <div class="oa-field">
                    <label for="drRole">Show</label>
                    <select id="drRole" name="role" class="oa-select" onchange="this.form.submit()">
                        @foreach (\App\Services\Finance\DriverRecordService::ROLE_FILTERS as $key => $label)<option value="{{ $key }}" @selected($d['role'] === $key)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="drUnit">Unit</label>
                    <select id="drUnit" name="unit_id" class="oa-select" onchange="this.form.submit()">
                        <option value="">All units</option>
                        @foreach ($units as $unit)<option value="{{ $unit->id }}" @selected($unitId === $unit->id)>{{ $unit->name }}{{ $unit->archived_at ? ' (archived)' : '' }}</option>@endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <span class="oa-field-label">Period</span>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        @include('superadmin.analytics._range', ['label' => $periodLabel, 'from' => $from->toDateString(), 'to' => $to->toDateString()])
                        <a href="{{ route('superadmin.finance.driver-records.index', array_filter(['role' => $d['role'], 'unit_id' => $unitId])) }}" class="oa-btn">Last 8 weeks</a>
                    </div>
                </div>
            </div>
        </form>

        <div class="oa-kpis" style="--oa-kpi-count: 4;">
            <div class="oa-kpi"><span class="oa-kpi-label">{{ $d['role_label'] }} with recorded jobs</span><div class="oa-kpi-value" data-dr="people">{{ $t['people'] }}</div></div>
            <div class="oa-kpi is-dark"><span class="oa-kpi-label">Completed jobs</span><div class="oa-kpi-value" data-dr="jobs">{{ $t['completed_jobs'] }}</div><span class="fin-kpi-sub">{{ $t['vehicles'] }} vehicle(s) towed</span></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Jobs with a recorded {{ $d['role'] === 'all' ? 'crew' : strtolower(rtrim($d['role_label'], 's')) }}</span><div class="oa-kpi-value" data-dr="recorded">{{ $t['jobs_with_record'] }}</div></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Jobs not yet recorded</span><div class="oa-kpi-value" data-dr="unrecorded">{{ $t['jobs_without_record'] }}</div></div>
        </div>

        <section class="oa-card oa-card-body" aria-labelledby="drWeekly">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="drWeekly"><i data-lucide="calendar-range" aria-hidden="true"></i> Jobs and vehicles towed per week</h2>
                <p class="oa-card-sub">Each cell shows completed jobs, then vehicles towed. A group trip is one job with several vehicles.</p>
            </div></div>
            @if ($d['people'])
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table" id="drTable">
                        <thead><tr>
                            <th scope="col">Name</th><th scope="col">Role</th>
                            @foreach ($d['weeks'] as $w)<th scope="col" class="is-num">{{ $w->format('M j') }}</th>@endforeach
                            <th scope="col" class="is-num">Jobs</th><th scope="col" class="is-num">Vehicles</th><th scope="col"><span class="fin-sr">History</span></th>
                        </tr></thead>
                        <tbody>
                            @foreach ($d['people'] as $p)
                                <tr data-dr-person="{{ $p['name'] }}">
                                    <td class="fin-strong">{{ $p['name'] }}<div class="is-dim">{{ $p['unit_list'] }}</div></td>
                                    <td>{{ $p['role_list'] }}</td>
                                    @foreach ($d['weeks'] as $key => $w)
                                        <td class="is-num" data-dr-week="{{ $key }}">@if (isset($p['weeks'][$key])){{ $p['weeks'][$key]['jobs'] }} <span class="is-dim">/ {{ $p['weeks'][$key]['vehicles'] }}</span>@else<span class="is-dim">–</span>@endif</td>
                                    @endforeach
                                    <td class="is-num fin-strong" data-dr-total="jobs">{{ $p['jobs'] }}</td>
                                    <td class="is-num fin-strong" data-dr-total="vehicles">{{ $p['vehicles'] }}</td>
                                    <td><a class="oa-btn oa-btn--sm" href="{{ route('superadmin.finance.driver-records.index', $query + ['person' => $p['key']]) }}#drHistory">History</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="oa-empty" data-dr-empty>No one has a recorded job in this period. Record the crew for completed jobs under Job Crew.</div>
            @endif
        </section>

        @if ($person)
            <section class="oa-card oa-card-body" aria-labelledby="drHistory" data-dr-history="{{ $person['name'] }}">
                <div class="oa-card-head"><div>
                    <h2 class="oa-card-title" id="drHistory"><i data-lucide="history" aria-hidden="true"></i> Performance history: {{ $person['name'] }}</h2>
                    <p class="oa-card-sub">{{ $person['role_list'] }} · {{ $person['unit_list'] }} · {{ $person['jobs'] }} completed job(s), {{ $person['vehicles'] }} vehicle(s) towed, active in {{ $person['weeks_active'] }} of {{ count($d['weeks']) }} week(s) · first job {{ $person['first']?->format('M j, Y') }}, latest {{ $person['last']?->format('M j, Y') }}</p>
                </div><a class="oa-btn oa-btn--sm" href="{{ route('superadmin.finance.driver-records.index', $query) }}">Close</a></div>
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Service date</th><th scope="col">Booking</th><th scope="col">Customer</th><th scope="col">Unit</th><th scope="col">Role</th><th scope="col" class="is-num">Vehicles</th><th scope="col">Customer payment</th></tr></thead>
                        <tbody>
                            @foreach ($person['history'] as $h)
                                <tr data-dr-job="{{ $h['reference'] }}">
                                    <td>{{ $h['service_date']->format('D, M j, Y') }}@if ($h['sunday'])<div class="fin-flag">Sunday (outside the Monday–Saturday week)</div>@endif</td>
                                    <td><a href="{{ route('superadmin.finance.crew.show', $h['booking_id']) }}"><span class="fin-ref">{{ $h['reference'] }}</span></a>@if ($h['group_code'])<div class="is-dim">Group {{ $h['group_code'] }}</div>@endif</td>
                                    <td>{{ $h['customer'] }}</td><td>{{ $h['unit'] }}</td>
                                    <td>{{ $h['role'] }}@if ($h['substitute']) <span class="fin-status fin-status--amber">Substitute</span>@endif</td>
                                    <td class="is-num">{{ $h['vehicles'] }}</td><td>{{ $h['payment_status'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($d['unrecorded'])
            <section class="oa-card oa-card-body" aria-labelledby="drMissing">
                <div class="oa-card-head"><div>
                    <h2 class="oa-card-title" id="drMissing"><i data-lucide="user-x" aria-hidden="true"></i> Completed jobs not yet recorded ({{ count($d['unrecorded']) }})</h2>
                    <p class="oa-card-sub">These jobs are not in anyone's record yet. Open a job to record who worked it.</p>
                </div></div>
                <ul class="fin-trail">
                    @foreach ($d['unrecorded'] as $u)
                        <li data-dr-unrecorded="{{ $u['reference'] }}"><a href="{{ route('superadmin.finance.crew.show', $u['booking_id']) }}"><span class="fin-ref">{{ $u['reference'] }}</span></a> · {{ $u['service_date']->format('M j, Y') }} · {{ $u['units'] ?: 'No unit' }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/analytics.js') }}?v={{ filemtime(public_path('superadmin/js/analytics.js')) }}" defer></script>
@endpush
