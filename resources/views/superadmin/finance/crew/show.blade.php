@extends('layouts.superadmin')

@section('title', 'Job Crew · ' . $job['reference'])

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@php
    $participantKey = fn ($r) => $r->personnel_id ? "personnel:{$r->personnel_id}" : ($r->user_id ? "user:{$r->user_id}" : '');
@endphp

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerJobCrewShow">
        <header class="ob-header fin-header">
            <div>
                <h1>Job crew: {{ $job['reference'] }}</h1>
                <p>{{ $job['customer']?->full_name }} · service {{ $job['service_date']?->format('D, M j, Y g:i A') ?? 'date unknown' }} · {{ $job['vehicles'] }} vehicle(s)
                    @if ($job['group_code']) · group {{ $job['group_code'] }} @endif</p>
            </div>
            <div class="fin-header-actions">
                <a class="oa-btn" href="{{ route('superadmin.finance.crew.index', ['week' => $job['service_date']?->startOfWeek(\Carbon\CarbonImmutable::MONDAY)->toDateString()]) }}">Back to Job Crew</a>
            </div>
        </header>

        @include('superadmin.finance._flashes')

        @if ($settled->isNotEmpty())
            <div class="fin-flash fin-flash--warning" role="status" data-crew-settled>
                <i data-lucide="lock" aria-hidden="true"></i>
                <span>This job is in approved statement {{ $settled->pluck('reference_code')->implode(', ') }}. Changes here are recorded but do not change an approved statement; use Correct on the statement to re-issue it.</span>
            </div>
        @endif

        @foreach ($unitOptions as $uid => $uname)
            <section class="oa-card oa-card-body" aria-labelledby="crUnit{{ $uid }}">
                <div class="oa-card-head"><div>
                    <h2 class="oa-card-title" id="crUnit{{ $uid }}"><i data-lucide="truck" aria-hidden="true"></i> {{ $uname }}</h2>
                    <p class="oa-card-sub">People who actually worked this job for this unit. Crew assigned by Dispatch stays Pending confirmation until you confirm or correct it.</p>
                </div></div>
                @php $rows = ($current[$uid] ?? collect())->merge($unitOptions->count() === 1 ? ($current[0] ?? collect()) : collect()); @endphp
                @if ($rows->isEmpty())
                    <div class="oa-empty" data-crew-empty="{{ $uid }}">No crew recorded. Commission for this unit stays blocked until you record who worked.</div>
                @else
                    <div class="oa-table-wrap">
                        <table class="oa-table fin-table">
                            <thead><tr><th scope="col">Person</th><th scope="col">Role performed</th><th scope="col">Details</th><th scope="col">Recorded</th><th scope="col"><span class="fin-sr">Actions</span></th></tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr data-crew-entry="{{ $r->id }}">
                                        <td class="fin-strong">{{ $r->name_snapshot }}</td>
                                        <td>{{ $r->roleLabel() }}
                                            @if (! $r->commission_role)<div class="fin-flag">Role not chosen yet: approval is blocked.</div>@endif</td>
                                        <td>
                                            @if ($r->is_substitute)<span class="fin-status fin-status--amber">Substitute</span>@endif
                                            @if ($r->borrowedFrom)<div class="is-dim">Borrowed from {{ $r->borrowedFrom->name }}</div>@endif
                                            @if ($r->notes)<div class="is-dim">{{ $r->notes }}</div>@endif
                                            @if ($r->change_reason)<div class="is-dim">Correction: {{ $r->change_reason }}</div>@endif
                                        </td>
                                        <td>
                                            {{ $r->captured_at?->format('M j, Y g:i A') }} <span class="is-dim">({{ $r->sourceLabel() }})</span>
                                            @if ($r->needs_review)<div><span class="fin-status fin-status--amber">Pending confirmation</span></div>@endif
                                        </td>
                                        <td class="fin-actions">
                                            @if ($canManage)
                                                @if ($r->needs_review)
                                                    <form method="POST" action="{{ route('superadmin.finance.crew.confirm', $r->id) }}" data-fin-once>@csrf<button class="oa-btn oa-btn--sm oa-btn--primary" type="submit">Confirm</button></form>
                                                @endif
                                                <details class="fin-correct">
                                                    <summary>Correct</summary>
                                                    <form method="POST" action="{{ route('superadmin.finance.crew.correct', $r->id) }}" class="fin-form" data-fin-once>
                                                        @csrf
                                                        @include('superadmin.finance.crew._fields', ['v' => ['unit_id' => $r->unit_id, 'participant' => $participantKey($r), 'commission_role' => $r->commission_role, 'borrowed_from_unit_id' => $r->borrowed_from_unit_id, 'is_substitute' => $r->is_substitute, 'notes' => $r->notes]])
                                                        <label class="fin-field fin-field--wide"><span>Reason for the correction</span><input type="text" name="reason" required minlength="5" maxlength="500"></label>
                                                        <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--sm oa-btn--primary">Save correction</button></div>
                                                    </form>
                                                </details>
                                                <form method="POST" action="{{ route('superadmin.finance.crew.remove', $r->id) }}" class="fin-inline-form" data-fin-once>@csrf
                                                    <input type="text" name="reason" required minlength="5" maxlength="500" placeholder="Reason" aria-label="Reason for removing {{ $r->name_snapshot }}">
                                                    <button class="oa-btn oa-btn--sm fin-btn-danger" type="submit">Remove</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endforeach

        @if ($canManage)
            <section class="oa-card oa-card-body" aria-labelledby="crAddTitle">
                <div class="oa-card-head"><div>
                    <h2 class="oa-card-title" id="crAddTitle"><i data-lucide="user-plus" aria-hidden="true"></i> Record a participant</h2>
                    <p class="oa-card-sub">Choose the role the person actually performed. Recording it here confirms it.</p>
                </div></div>
                <form method="POST" action="{{ route('superadmin.finance.crew.store', $job['anchor']->id) }}" class="fin-form" data-fin-once>
                    @csrf
                    @include('superadmin.finance.crew._fields', ['v' => old() ?: ['unit_id' => array_key_first($unitOptions->all())]])
                    <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--primary oa-btn--sm">Record participant</button></div>
                </form>
            </section>
        @endif

        <section class="oa-card oa-card-body" aria-labelledby="crSupportTitle">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="crSupportTitle"><i data-lucide="info" aria-hidden="true"></i> Supporting information</h2>
                <p class="oa-card-sub">Shown to help you remember who worked. None of it is recorded or paid until you record the person above.</p>
            </div></div>
            @if ($supporting['booking'])
                <ul class="fin-trail">
                    @foreach ($supporting['booking'] as $s)<li>{{ $s['source'] }}: <strong>{{ $s['name'] }}</strong></li>@endforeach
                </ul>
            @else
                <p class="fin-help">The booking does not name any crew.</p>
            @endif
            @if ($supporting['current_roster'])
                <p class="fin-help"><strong>Today's unit roster</strong> (current assignment, not evidence of who worked on {{ $job['service_date']?->format('M j') }}):
                    {{ collect($supporting['current_roster'])->map(fn ($r) => $r['name'] . ' (' . $r['unit'] . ')')->implode(', ') }}</p>
            @endif
        </section>

        @if ($history->isNotEmpty())
            <section class="oa-card oa-card-body" aria-labelledby="crHistTitle">
                <div class="oa-card-head"><div><h2 class="oa-card-title" id="crHistTitle"><i data-lucide="history" aria-hidden="true"></i> Corrected and removed records</h2></div></div>
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Person</th><th scope="col">Role</th><th scope="col">Unit</th><th scope="col">Changed</th><th scope="col">Reason</th></tr></thead>
                        <tbody>
                            @foreach ($history as $h)
                                <tr class="fin-voided" data-crew-history="{{ $h->id }}">
                                    <td>{{ $h->name_snapshot }}</td><td>{{ $h->roleLabel() }}</td><td>{{ $h->unit?->name }}</td>
                                    <td>{{ $h->superseded_at?->format('M j, Y g:i A') }} by {{ $h->supersededByUser?->full_name ?: $h->supersededByUser?->name ?? '—' }}
                                        <div class="is-dim">{{ $h->superseded_by_id ? 'Replaced' : 'Removed' }}</div></td>
                                    <td class="fin-void-note">{{ $h->supersede_reason }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/finance.js') }}?v={{ filemtime(public_path('superadmin/js/finance.js')) }}" defer></script>
@endpush
