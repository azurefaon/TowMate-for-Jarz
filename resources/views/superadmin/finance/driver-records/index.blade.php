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
                <p>Completed jobs, vehicles towed and draft commission per week, with each person's history. {{ $periodLabel }}. Weeks run Monday to Saturday.</p>
            </div>
            <div class="fin-header-actions"><button type="button" class="oa-btn" onclick="window.print()">Print</button></div>
        </header>

        @include('superadmin.finance._flashes')

        <div class="fin-flash fin-flash--warning" role="status">
            <i data-lucide="users" aria-hidden="true"></i>
            <span>Crew is captured from Dispatch when a unit is assigned and stays <strong>Pending confirmation</strong> until you confirm it under <a href="{{ route('superadmin.finance.crew.index') }}">Job Crew</a>. Commission amounts are a draft estimate and cannot be approved until the commission policy is confirmed.</span>
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

        <div class="oa-kpis oa-kpis--six" style="--oa-kpi-count: 5;">
            <div class="oa-kpi"><span class="oa-kpi-label">{{ $d['role_label'] }} with jobs</span><div class="oa-kpi-value" data-dr="people">{{ $t['people'] }}</div></div>
            <div class="oa-kpi is-dark"><span class="oa-kpi-label">Completed jobs</span><div class="oa-kpi-value" data-dr="jobs">{{ $t['completed_jobs'] }}</div><span class="fin-kpi-sub">{{ $t['vehicles'] }} vehicle(s) towed</span></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Awaiting confirmation</span><div class="oa-kpi-value" data-dr="pending">{{ $t['pending_participations'] }}</div><span class="fin-kpi-sub">assignments not yet confirmed</span></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Jobs with no crew recorded</span><div class="oa-kpi-value" data-dr="unrecorded">{{ $t['jobs_without_record'] }}</div></div>
            <div class="oa-kpi"><span class="oa-kpi-label">Draft commission, fully paid jobs</span><div class="oa-kpi-value" data-dr="draft">{{ $t['draft']->format() }}</div><span class="fin-kpi-sub" data-dr="not-paid">{{ $t['pending_collection'] }} pending collection · {{ $t['awaiting_payment'] }} awaiting payment</span></div>
        </div>

        <section class="oa-card oa-card-body" aria-labelledby="drWeekly">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="drWeekly"><i data-lucide="calendar-range" aria-hidden="true"></i> Jobs and vehicles towed per week</h2>
                <p class="oa-card-sub">Each week shows completed jobs / vehicles towed, then the draft commission from fully paid jobs only. Partially paid and unpaid jobs count as work but add no commission until they are paid in full. A group trip is one job with several vehicles.</p>
            </div></div>
            @if ($d['people'])
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table" id="drTable">
                        <thead><tr>
                            <th scope="col">Name</th>
                            @foreach ($d['weeks'] as $w)<th scope="col" class="is-num">{{ $w->format('M j') }}</th>@endforeach
                            <th scope="col" class="is-num">Jobs</th><th scope="col" class="is-num">Vehicles</th><th scope="col" class="is-num">Draft commission</th><th scope="col"><span class="fin-sr">History</span></th>
                        </tr></thead>
                        <tbody>
                            @foreach ($d['people'] as $p)
                                <tr data-dr-person="{{ $p['name'] }}">
                                    <td class="fin-strong">{{ $p['name'] }}<div class="is-dim">{{ $p['role_list'] }} · {{ $p['unit_list'] }}</div></td>
                                    @foreach ($d['weeks'] as $key => $w)
                                        <td class="is-num" data-dr-week="{{ $key }}">
                                            @if (isset($p['weeks'][$key]))
                                                <span class="fin-strong">{{ $p['weeks'][$key]['jobs'] }}</span> <span class="is-dim">/ {{ $p['weeks'][$key]['vehicles'] }}</span>
                                                <div class="is-dim">{{ $p['weeks'][$key]['draft']->format() }}</div>
                                                @if ($p['weeks'][$key]['pending'])<div class="fin-flag">{{ $p['weeks'][$key]['pending'] }} pending</div>@endif
                                                @if ($p['weeks'][$key]['pending_collection'] + $p['weeks'][$key]['awaiting_payment'] + $p['weeks'][$key]['under_review'])<div class="is-dim" data-dr-week-unpaid>{{ $p['weeks'][$key]['pending_collection'] + $p['weeks'][$key]['awaiting_payment'] + $p['weeks'][$key]['under_review'] }} not fully paid</div>@endif
                                            @else<span class="is-dim">–</span>@endif
                                        </td>
                                    @endforeach
                                    <td class="is-num fin-strong" data-dr-total="jobs">{{ $p['jobs'] }}@if ($p['pending_jobs'])<div class="fin-flag">{{ $p['pending_jobs'] }} pending</div>@endif</td>
                                    <td class="is-num fin-strong" data-dr-total="vehicles">{{ $p['vehicles'] }}</td>
                                    <td class="is-num fin-strong" data-dr-total="draft">{{ $p['draft']->format() }}</td>
                                    <td><a class="oa-btn oa-btn--sm" href="{{ route('superadmin.finance.driver-records.index', $query + ['person' => $p['key']]) }}#drHistory">History</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="oa-empty" data-dr-empty>No one has a recorded job in this period. Crew is captured when Dispatch assigns a unit; earlier jobs can be recorded under Job Crew.</div>
            @endif
        </section>

        @if ($person)
            <section class="oa-card oa-card-body" aria-labelledby="drHistory" data-dr-history="{{ $person['name'] }}">
                <div class="oa-card-head"><div>
                    <h2 class="oa-card-title" id="drHistory"><i data-lucide="history" aria-hidden="true"></i> Performance history: {{ $person['name'] }}</h2>
                    <p class="oa-card-sub">{{ $person['role_list'] }} · {{ $person['unit_list'] }} · {{ $person['jobs'] }} completed job(s) ({{ $person['confirmed_jobs'] }} confirmed, {{ $person['pending_jobs'] }} pending confirmation), {{ $person['vehicles'] }} vehicle(s) towed, active in {{ $person['weeks_active'] }} of {{ count($d['weeks']) }} week(s) · draft commission {{ $person['draft']->format() }}</p>
                </div><a class="oa-btn oa-btn--sm" href="{{ route('superadmin.finance.driver-records.index', $query) }}">Close</a></div>
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Service date</th><th scope="col">Booking</th><th scope="col">Unit</th><th scope="col">Role</th><th scope="col">Participation</th><th scope="col" class="is-num">Vehicles</th><th scope="col" class="is-num">Draft commission <span class="fin-sr">(fully paid jobs only)</span></th></tr></thead>
                        <tbody>
                            @foreach (collect($person['history'])->groupBy('week') as $weekKey => $rows)
                                @foreach ($rows as $h)
                                    <tr data-dr-job="{{ $h['reference'] }}">
                                        <td>{{ $h['service_date']->format('D, M j, Y') }}@if ($h['sunday'])<div class="fin-flag">Sunday (outside the Monday–Saturday week)</div>@endif</td>
                                        <td><a href="{{ route('superadmin.finance.crew.show', $h['booking_id']) }}"><span class="fin-ref">{{ $h['reference'] }}</span></a><div class="is-dim">{{ $h['customer'] }}{{ $h['group_code'] ? ' · Group ' . $h['group_code'] : '' }}</div></td>
                                        <td>{{ $h['unit'] }}</td>
                                        <td>{{ $h['role'] }}@if ($h['substitute']) <span class="fin-status fin-status--grey">Substitute</span>@endif</td>
                                        <td>
                                            @if ($h['pending'])<span class="fin-status fin-status--amber" data-dr-participation="pending">Pending confirmation</span>
                                            @else<span class="fin-status fin-status--green" data-dr-participation="confirmed">Confirmed</span>@endif
                                            <div class="is-dim">{{ $h['source'] }}</div>
                                        </td>
                                        <td class="is-num">{{ $h['vehicles'] }}</td>
                                        <td class="is-num" data-dr-commission-state="{{ $h['commission_state'] }}">
                                            @if ($h['draft'])<span class="fin-strong" data-dr-job-draft>{{ $h['draft']->format() }}</span>
                                            @elseif ($h['commission_state'] !== 'eligible')<span class="fin-status fin-status--{{ \App\Services\Finance\CommissionEligibility::TONES[$h['commission_state']] }}">{{ $h['draft_note'] }}</span>
                                            @else<span class="is-dim">{{ $h['draft_note'] }}</span>@endif
                                            @if ($h['commission_state'] !== 'eligible')<div class="is-dim">{{ $h['payment_label'] }}</div>@endif
                                        </td>
                                    </tr>
                                @endforeach
                                <tr class="fin-week-total" data-dr-week-total="{{ $weekKey }}">
                                    <td colspan="5">Week of {{ \Carbon\CarbonImmutable::parse($weekKey)->format('M j, Y') }}: {{ $rows->count() }} job(s)</td>
                                    <td class="is-num">{{ $rows->sum('vehicles') }}</td>
                                    <td class="is-num">{{ $person['weeks'][$weekKey]['draft']->format() }}</td>
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
                    <h2 class="oa-card-title" id="drMissing"><i data-lucide="user-x" aria-hidden="true"></i> Completed jobs with no {{ $d['role'] === 'all' ? 'crew' : mb_strtolower(rtrim($d['role_label'], 's')) }} recorded ({{ count($d['unrecorded']) }})</h2>
                    <p class="oa-card-sub">These jobs are not in anyone's record yet and are not counted as zero work. Where the Dispatch history names the crew, open the job and confirm it in one step.</p>
                </div></div>
                <ul class="fin-trail">
                    @foreach ($d['unrecorded'] as $u)
                        <li data-dr-unrecorded="{{ $u['reference'] }}"><a href="{{ route('superadmin.finance.crew.show', $u['booking_id']) }}"><span class="fin-ref">{{ $u['reference'] }}</span></a> · {{ $u['service_date']->format('M j, Y') }} · {{ $u['units'] ?: 'No unit' }}
                            @if ($u['in_history'] ?? [])<div class="is-dim" data-dr-in-history>In the Dispatch history: {{ implode(', ', $u['in_history']) }} · <a href="{{ route('superadmin.finance.crew.show', $u['booking_id']) }}">confirm crew</a></div>@endif</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/analytics.js') }}?v={{ filemtime(public_path('superadmin/js/analytics.js')) }}" defer></script>
@endpush
