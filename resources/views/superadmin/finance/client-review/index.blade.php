@extends('layouts.superadmin')

@section('title', 'Client Review')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@php
    $badge = ['pending' => 'amber', 'in_discussion' => 'blue', 'answered' => 'green', 'revision_requested' => 'red', 'closed' => 'grey'];
    $isOwner = \App\Support\Finance\FinanceAccess::isOwner(auth()->user());
@endphp

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerClientReview">
        <header class="ob-header fin-header">
            <div>
                <h1>Client Review</h1>
                <p>Pending client decisions, requested revisions and remarks for the Owner Finance module. Recording an answer here does not change any commission rule or financial record; a programmer implements answered decisions.</p>
            </div>
            <div class="fin-header-actions">
                <a class="oa-btn" href="{{ route('superadmin.finance.client-review.print') }}" target="_blank" rel="noopener">Printable summary</a>
                <a class="oa-btn oa-btn--primary" href="{{ route('superadmin.finance.client-review.pdf') }}" data-cr-download>Download summary (PDF)</a>
            </div>
        </header>

        @include('superadmin.finance._flashes')

        <div class="fin-flash fin-flash--warning" role="status" data-cr-policy>
            <i data-lucide="file-pen-line" aria-hidden="true"></i>
            <span>Commission policy in use: v{{ $policy?->version }} — {{ $policy?->name }}.
                @if ($policy && $policy->base_definition === \App\Services\Finance\Demo\FinanceDemoScenarios::STAND_IN)
                    This is a DEMONSTRATION stand-in, not a client confirmation.
                @elseif ($policy && $policy->status === 'active' && $policy->isConfirmed())
                    Active and client-confirmed.
                @else
                    Draft / Pending Client Confirmation: commission statements cannot be approved.
                @endif
            </span>
        </div>

        <div class="oa-kpis oa-kpis--six" style="--oa-kpi-count: 5;">
            @foreach (\App\Models\Finance\ClientReviewItem::STATUSES as $key => $label)
                <a class="oa-kpi" href="{{ route('superadmin.finance.client-review.index', ['status' => $key]) }}" style="text-decoration:none; color:inherit;">
                    <span class="oa-kpi-label">{{ $label }}</span><div class="oa-kpi-value" data-cr-count="{{ $key }}">{{ (int) ($counts[$key] ?? 0) }}</div>
                </a>
            @endforeach
        </div>
        @if ($status)<p class="fin-help">Showing: {{ \App\Models\Finance\ClientReviewItem::STATUSES[$status] }} · <a href="{{ route('superadmin.finance.client-review.index') }}">Show all</a></p>@endif

        @foreach ($items as $item)
            <section class="oa-card oa-card-body" data-cr-item="{{ $item->reference_code }}">
                <div class="oa-card-head"><div>
                    <h2 class="oa-card-title"><span class="fin-ref">{{ $item->reference_code }}</span> {{ $item->title }}</h2>
                    <p class="oa-card-sub">{{ $item->kindLabel() !== $item->statusLabel() ? $item->kindLabel() . ' · ' : '' }}{{ $item->topic_code ? $item->topic_code . ' · ' : '' }}<span class="fin-status fin-status--{{ $badge[$item->status] ?? 'grey' }}" data-cr-status="{{ $item->status }}">{{ $item->statusLabel() }}</span>
                        @if ($item->updater) · last updated by {{ $item->updater->full_name ?: $item->updater->name }} on {{ $item->updated_at?->format('M j, Y g:i A') }} @endif</p>
                </div></div>
                <table class="oa-table fin-table">
                    <tbody>
                        @if ($item->question)<tr><th scope="row" style="width:200px">Decision needed</th><td>{{ $item->question }}</td></tr>@endif
                        @if ($item->current_behaviour)<tr><th scope="row">Current (provisional) behaviour</th><td>{{ $item->current_behaviour }}</td></tr>@endif
                        @if ($item->blocks)<tr><th scope="row">Blocked until answered</th><td>{{ $item->blocks }}</td></tr>@endif
                        @if ($item->client_response)<tr><th scope="row">Client response</th><td>{{ $item->client_response }}</td></tr>@endif
                        @if ($item->requested_revision)<tr><th scope="row">Requested revision</th><td>{{ $item->requested_revision }}</td></tr>@endif
                        @if ($item->owner_remarks)<tr><th scope="row">Owner remarks</th><td>{{ $item->owner_remarks }}</td></tr>@endif
                    </tbody>
                </table>
                @if ($isOwner)
                    <details class="fin-correct">
                        <summary>Record response / update status</summary>
                        <form method="POST" action="{{ route('superadmin.finance.client-review.update', $item->id) }}" class="fin-form" data-fin-once>
                            @csrf
                            <div class="fin-grid">
                                <label class="fin-field"><span>Status</span>
                                    <select name="status" class="oa-select">
                                        @foreach (\App\Models\Finance\ClientReviewItem::STATUSES as $key => $label)<option value="{{ $key }}" @selected($item->status === $key)>{{ $label }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="fin-field fin-field--wide"><span>Client response</span><textarea name="client_response" maxlength="4000">{{ $item->client_response }}</textarea></label>
                                <label class="fin-field fin-field--wide"><span>Requested revision</span><textarea name="requested_revision" maxlength="4000">{{ $item->requested_revision }}</textarea></label>
                                <label class="fin-field fin-field--wide"><span>Owner remarks</span><textarea name="owner_remarks" maxlength="4000">{{ $item->owner_remarks }}</textarea></label>
                            </div>
                            <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--primary oa-btn--sm">Save</button></div>
                        </form>
                    </details>
                @endif
            </section>
        @endforeach

        @if ($isOwner)
            <section class="oa-card oa-card-body" aria-labelledby="crAddTitle">
                <div class="oa-card-head"><div><h2 class="oa-card-title" id="crAddTitle"><i data-lucide="plus" aria-hidden="true"></i> Add a decision, revision request or remark</h2></div></div>
                <form method="POST" action="{{ route('superadmin.finance.client-review.store') }}" class="fin-form" data-fin-once>
                    @csrf
                    <div class="fin-grid">
                        <label class="fin-field"><span>Type</span>
                            <select name="kind" class="oa-select" required>
                                @foreach (\App\Models\Finance\ClientReviewItem::KINDS as $key => $label)<option value="{{ $key }}" @selected(old('kind') === $key)>{{ $label }}</option>@endforeach
                            </select>
                        </label>
                        <label class="fin-field fin-field--wide"><span>Title</span><input type="text" name="title" required maxlength="200" value="{{ old('title') }}"></label>
                        <label class="fin-field fin-field--wide"><span>Details / question</span><textarea name="question" maxlength="4000">{{ old('question') }}</textarea></label>
                        <label class="fin-field fin-field--wide"><span>Requested revision <small>(optional)</small></span><textarea name="requested_revision" maxlength="4000">{{ old('requested_revision') }}</textarea></label>
                        <label class="fin-field fin-field--wide"><span>What it blocks <small>(optional)</small></span><input type="text" name="blocks" maxlength="2000" value="{{ old('blocks') }}"></label>
                    </div>
                    <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--primary oa-btn--sm">Add item</button></div>
                </form>
            </section>
        @endif
    </div>
@endsection
