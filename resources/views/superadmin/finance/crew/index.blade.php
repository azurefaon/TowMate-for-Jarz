@extends('layouts.superadmin')

@section('title', 'Job Crew')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerJobCrew">
        <header class="ob-header fin-header">
            <div>
                <h1>Job Crew</h1>
                <p>Record who actually worked each completed job, for which towing unit and in which role. Week of {{ $week->format('M j') }} – {{ $week->addDays(5)->format('M j, Y') }} (Monday to Saturday).</p>
            </div>
        </header>

        @include('superadmin.finance._flashes')

        <div class="fin-flash fin-flash--warning" role="status">
            <i data-lucide="users" aria-hidden="true"></i>
            <span>Nobody is added automatically. A unit's current crew is not assumed to have worked past jobs. Commission for a job stays blocked until its crew is recorded here.
                How Rider, substitute, shared and combined roles are paid is still pending client confirmation.</span>
        </div>

        <form method="GET" action="{{ route('superadmin.finance.crew.index') }}" class="oa-card oa-filter-card" role="search">
            <div class="oa-filters">
                <div class="oa-field">
                    <label for="crWeek">Week (any day; Monday is used)</label>
                    <input id="crWeek" type="date" name="week" class="oa-select" value="{{ $week->toDateString() }}">
                </div>
                <div class="oa-field">
                    <label for="crUnit">Unit</label>
                    <select id="crUnit" name="unit_id" class="oa-select">
                        <option value="">All units</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}" @selected($unitId === $unit->id)>{{ $unit->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <span class="oa-field-label">&nbsp;</span>
                    <div style="display:flex; gap:12px;">
                        <button type="submit" class="oa-btn oa-btn--primary">Show</button>
                        <a class="oa-btn" href="{{ route('superadmin.finance.crew.index', ['week' => $week->subWeek()->toDateString(), 'unit_id' => $unitId]) }}">Previous week</a>
                    </div>
                </div>
            </div>
        </form>

        <section class="oa-card oa-card-body" aria-labelledby="crJobsTitle">
            <div class="oa-card-head"><div><h2 class="oa-card-title" id="crJobsTitle"><i data-lucide="truck" aria-hidden="true"></i> Completed jobs</h2></div></div>
            @if ($rows->isEmpty())
                <div class="oa-empty">No completed jobs in this week.</div>
            @else
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Job</th><th scope="col">Service date</th><th scope="col">Unit</th><th scope="col">Crew recorded</th><th scope="col">Status</th><th scope="col"><span class="fin-sr">Open</span></th></tr></thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @foreach ($row['units'] ?: [['unit_id' => null, 'name' => 'No unit assigned', 'people' => 0, 'roles' => [], 'needs_review' => 0]] as $u)
                                    <tr data-crew-row="{{ $row['job']['reference'] }}-{{ $u['unit_id'] }}">
                                        <td><span class="fin-ref">{{ $row['job']['reference'] }}</span>@if ($row['job']['group_code'])<div class="is-dim">Group {{ $row['job']['group_code'] }}</div>@endif</td>
                                        <td>{{ $row['job']['service_date']?->format('D, M j') }}</td>
                                        <td>{{ $u['name'] }}</td>
                                        <td>{{ $u['people'] }} @if ($u['roles'])<span class="is-dim">({{ collect($u['roles'])->map(fn ($r) => \App\Models\Finance\JobPersonnel::ROLE_LABELS[$r] ?? $r)->implode(', ') }})</span>@endif</td>
                                        <td>
                                            @if ($u['people'] === 0)
                                                <span class="fin-status fin-status--red">Not recorded</span>
                                            @elseif ($u['needs_review'] > 0)
                                                <span class="fin-status fin-status--amber">Needs review</span>
                                            @else
                                                <span class="fin-status fin-status--green">Recorded</span>
                                            @endif
                                            @if ($row['settled'])<span class="fin-status fin-status--blue">In approved statement</span>@endif
                                        </td>
                                        <td>@if ($loop->first)<a class="oa-btn oa-btn--sm" href="{{ route('superadmin.finance.crew.show', $row['job']['anchor']->id) }}">Open</a>@endif</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
