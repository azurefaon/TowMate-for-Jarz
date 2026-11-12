@extends('layouts.superadmin')

@section('title', 'Job Crew · ' . $job['reference'])

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@php
    $participantKey = fn ($r) => $r->personnel_id ? "personnel:{$r->personnel_id}" : ($r->user_id ? "user:{$r->user_id}" : '');
    $slotLabels = ['team_leader' => 'Team Leader', 'driver_1' => 'Driver', 'driver_2' => 'Second Driver', 'crew_member_1' => 'Crew Mate', 'crew_member_2' => 'Crew Mate'];
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
            @php
                $rows = ($current[$uid] ?? collect())->merge($unitOptions->count() === 1 ? ($current[0] ?? collect()) : collect());
                $ev = $evidence[$uid] ?? null;
                $recordedIds = $rows->map(fn ($r) => $participantKey($r))->filter()->all();
                $fromHistory = collect($ev['members'] ?? [])->reject(fn ($m) => in_array($m['user_id'] ? "user:{$m['user_id']}" : "personnel:{$m['personnel_id']}", $recordedIds, true));
                $pendingWithRole = $rows->filter(fn ($r) => $r->needs_review && $r->commission_role)->count();
            @endphp
            <section class="oa-card oa-card-body fin-crew-unit" aria-labelledby="crUnit{{ $uid }}" data-crew-unit="{{ $uid }}">
                <div class="oa-card-head"><div>
                    <h2 class="oa-card-title" id="crUnit{{ $uid }}"><i data-lucide="truck" aria-hidden="true"></i> {{ $uname }}</h2>
                    <p class="oa-card-sub">The crew assigned to this unit for this job. <strong>Assigned</strong> means Dispatch put the person on the job; <strong>Confirmed</strong> means you (or the Team Leader's own completion) confirmed they worked it. Commission stays blocked until everyone is confirmed.</p>
                </div>
                    @if ($canManage && $pendingWithRole > 0)
                        <form method="POST" action="{{ route('superadmin.finance.crew.confirm-pending', $job['anchor']->id) }}" data-fin-once>@csrf
                            <input type="hidden" name="unit_id" value="{{ $uid }}">
                            <button class="oa-btn oa-btn--primary" type="submit" data-crew-confirm-all="{{ $uid }}">Confirm crew ({{ $pendingWithRole }})</button>
                        </form>
                    @endif
                </div>

                @if ($rows->isNotEmpty())
                    <ul class="fin-crew-roster" data-crew-recorded="{{ $uid }}">
                        @foreach ($rows as $r)
                            <li data-crew-entry="{{ $r->id }}">
                                <div class="fin-crew-roster-role">{{ $r->roleLabel() }}</div>
                                <div class="fin-crew-roster-who">
                                    <span class="fin-crew-roster-name">{{ $r->name_snapshot }}</span>
                                    @if ($r->is_substitute)<span class="fin-status fin-status--grey">Substitute</span>@endif
                                    @if ($r->borrowedFrom)<span class="is-dim">borrowed from {{ $r->borrowedFrom->name }}</span>@endif
                                    <div class="is-dim">{{ $r->sourceLabel() }} · {{ $r->captured_at?->format('M j, Y g:i A') }}@if (! $r->commission_role) · <span class="fin-flag">choose the role with Correct</span>@endif</div>
                                </div>
                                <div class="fin-crew-roster-state">
                                    @if ($r->needs_review)<span class="fin-status fin-status--amber" data-crew-state="assigned">Assigned · not confirmed</span>
                                    @else<span class="fin-status fin-status--green" data-crew-state="confirmed">Confirmed</span>@endif
                                </div>
                                @if ($canManage)
                                    <div class="fin-crew-roster-actions">
                                        @if ($r->needs_review && $r->commission_role)
                                            <form method="POST" action="{{ route('superadmin.finance.crew.confirm', $r->id) }}" data-fin-once>@csrf<button class="oa-btn oa-btn--sm" type="submit">Confirm</button></form>
                                        @endif
                                        <details class="fin-correct">
                                            <summary>Correct or remove</summary>
                                            <form method="POST" action="{{ route('superadmin.finance.crew.correct', $r->id) }}" class="fin-form" data-fin-once>
                                                @csrf
                                                @include('superadmin.finance.crew._fields', ['v' => ['unit_id' => $r->unit_id, 'participant' => $participantKey($r), 'commission_role' => $r->commission_role, 'borrowed_from_unit_id' => $r->borrowed_from_unit_id, 'is_substitute' => $r->is_substitute, 'notes' => $r->notes]])
                                                <label class="fin-field fin-field--wide"><span>Reason for the correction</span><input type="text" name="reason" required minlength="5" maxlength="500"></label>
                                                <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--sm oa-btn--primary">Save correction</button></div>
                                            </form>
                                            <form method="POST" action="{{ route('superadmin.finance.crew.remove', $r->id) }}" class="fin-inline-form" data-fin-once>@csrf
                                                <input type="text" name="reason" required minlength="5" maxlength="500" placeholder="Reason this person did not work" aria-label="Reason for removing {{ $r->name_snapshot }}">
                                                <button class="oa-btn oa-btn--sm fin-btn-danger" type="submit">Remove</button>
                                            </form>
                                        </details>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($fromHistory->isNotEmpty())
                    <form method="POST" action="{{ route('superadmin.finance.crew.confirm-history', $job['anchor']->id) }}" class="fin-crew-history" data-crew-history-form="{{ $uid }}" data-fin-once>
                        @csrf
                        <input type="hidden" name="unit_id" value="{{ $uid }}">
                        <div class="fin-crew-history-head">
                            <strong>{{ $rows->isEmpty() ? 'Crew found in the Dispatch history' : 'Also in the Dispatch history' }}</strong>
                            <span class="is-dim">{{ $ev['as_of'] ? 'Unit roster when the job started, ' . $ev['as_of']->setTimezone(config('finance.timezone'))->format('M j, Y g:i A') : 'From the booking' }}. Not recorded until you confirm. Untick anyone who did not work.</span>
                        </div>
                        <ul class="fin-crew-roster">
                            @foreach ($fromHistory as $slot => $m)
                                <li data-crew-history="{{ $slot }}">
                                    <div class="fin-crew-roster-role">{{ $slotLabels[$slot] }}</div>
                                    <label class="fin-crew-roster-who">
                                        @if ($canManage)<input type="checkbox" name="slots[]" value="{{ $slot }}" @checked(! $m['uncertain'] && $m['role'] !== null && ($m['personnel_id'] || $m['user_id']))>@endif
                                        <span class="fin-crew-roster-name">{{ $m['name'] }}</span>
                                        @if ($m['borrowed_from'])<span class="fin-status fin-status--grey">Borrowed</span>@endif
                                        <div class="is-dim">{{ $m['evidence'] }}@if (! $m['personnel_id'] && ! $m['user_id']) · no personnel record (add manually)@endif</div>
                                        @if ($m['uncertain'])<div class="fin-flag">Uncertain: {{ $m['reason'] }}</div>@endif
                                    </label>
                                    <div class="fin-crew-roster-state">
                                        @if ($m['uncertain'])<span class="fin-status fin-status--blue" data-crew-state="uncertain">Uncertain</span>
                                        @else<span class="fin-status fin-status--amber" data-crew-state="assigned">Assigned · not confirmed</span>@endif
                                    </div>
                                    @if ($slot === 'driver_2' && $canManage)
                                        <div class="fin-crew-roster-actions">
                                            <label class="fin-field"><span>Role performed (tick to include)</span>
                                                <select name="second_driver_role" class="oa-select"><option value="">Choose…</option><option value="driver">Driver</option><option value="rider">Rider / Substitute</option></select>
                                            </label>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @foreach ($ev['notes'] ?? [] as $note)<p class="fin-help">{{ $note }}</p>@endforeach
                        @if ($canManage)
                            <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--primary" data-crew-confirm-history="{{ $uid }}">Confirm crew</button></div>
                        @endif
                    </form>
                @elseif ($rows->isEmpty())
                    <div class="oa-empty" data-crew-empty="{{ $uid }}">No crew for this unit was found in the Dispatch records. {{ collect($ev['notes'] ?? [])->implode(' ') }} Add the people who worked below.</div>
                @endif
            </section>
        @endforeach

        @if ($canManage)
            <details class="oa-card oa-card-body fin-crew-manual" @if ($errors->any() || old('participant')) open @endif>
                <summary><i data-lucide="user-plus" aria-hidden="true"></i> Add a person manually <span class="is-dim">(only if someone who worked is missing above)</span></summary>
                <form method="POST" action="{{ route('superadmin.finance.crew.store', $job['anchor']->id) }}" class="fin-form" data-fin-once>
                    @csrf
                    @include('superadmin.finance.crew._fields', ['v' => old() ?: ['unit_id' => array_key_first($unitOptions->all())]])
                    <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--primary oa-btn--sm">Record participant</button></div>
                </form>
            </details>
        @endif

        @if ($supporting['booking'])
            <section class="oa-card oa-card-body" aria-labelledby="crSupportTitle">
                <div class="oa-card-head"><div><h2 class="oa-card-title" id="crSupportTitle"><i data-lucide="info" aria-hidden="true"></i> Names written on the booking</h2></div></div>
                <ul class="fin-trail">
                    @foreach ($supporting['booking'] as $s)<li>{{ $s['source'] }}: <strong>{{ $s['name'] }}</strong></li>@endforeach
                </ul>
            </section>
        @endif

        @if ($history->isNotEmpty())
            <section class="oa-card oa-card-body" aria-labelledby="crHistTitle">
                <div class="oa-card-head"><div><h2 class="oa-card-title" id="crHistTitle"><i data-lucide="history" aria-hidden="true"></i> Corrected and removed records</h2></div></div>
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Person</th><th scope="col">Role</th><th scope="col">Unit</th><th scope="col">Changed</th><th scope="col">Reason</th></tr></thead>
                        <tbody>
                            @foreach ($history as $h)
                                <tr class="fin-voided" data-crew-history-row="{{ $h->id }}">
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
