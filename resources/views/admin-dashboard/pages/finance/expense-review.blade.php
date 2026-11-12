@extends('admin-dashboard.layouts.app')

@section('title', 'Expense Review')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
    <style>
        .xr-page { display: flex; flex-direction: column; gap: 16px; }
        .xr-card { background: #fff; border: 1px solid var(--fin-line); border-radius: 12px; padding: 18px 20px; }
        .xr-card h2 { margin: 0 0 4px; font-size: 18px; }
        .xr-sub { margin: 0 0 14px; color: var(--fin-body); font-size: 14px; }
        .xr-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .xr-table th { text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--fin-ink); padding: 8px 10px; border-bottom: 2px solid var(--fin-ink); }
        .xr-table td { padding: 12px 10px; border-bottom: 1px solid var(--fin-line); vertical-align: top; }
        .xr-table .is-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .xr-wrap { overflow-x: auto; }
        .xr-empty { padding: 24px; text-align: center; color: var(--fin-body); }
        .xr-page .oa-btn { height: 36px; padding: 0 14px; border-radius: 8px; border: 1px solid var(--fin-control-line); background: #fff; color: var(--fin-ink); font: inherit; font-weight: 600; cursor: pointer; }
        .xr-page .oa-btn--primary { background: #facc15; border-color: #facc15; color: #171717; }
        .xr-page .oa-btn--primary:hover { background: #eab308; border-color: #eab308; }
        .xr-page .oa-btn[disabled] { opacity: .45; cursor: not-allowed; }
        .xr-page .is-dim { color: var(--fin-muted); font-size: 13px; }
    </style>
@endpush

@section('content')
    <div class="xr-page fin-page" id="dispatcherExpenseReview">
        <header>
            <h1 style="margin:0;">Expense Review</h1>
            <p class="xr-sub">Check each Team Leader expense against its receipt. Verified expenses go to the Owner for approval. You cannot approve expenses.</p>
        </header>

        @include('superadmin.finance._flashes')

        <section class="xr-card" aria-labelledby="xrPendingTitle">
            <h2 id="xrPendingTitle">Waiting for your review ({{ $pending->count() }})</h2>
            <p class="xr-sub">Oldest first. An expense without a receipt cannot be verified.</p>
            @if ($pending->isNotEmpty())
                <div class="xr-wrap">
                    <table class="xr-table">
                        <thead>
                            <tr>
                                <th scope="col">Submitted</th>
                                <th scope="col">Unit / job</th>
                                <th scope="col">Expense</th>
                                <th scope="col" class="is-num">Amount</th>
                                <th scope="col">Receipts</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pending as $expense)
                                <tr data-xr-row="{{ $expense->id }}">
                                    <td>
                                        <span class="fin-ref">{{ $expense->reference_code }}</span>
                                        <div class="is-dim">{{ $expense->submitter?->full_name ?: $expense->submitter?->name }}</div>
                                        <div class="is-dim">{{ $expense->submitted_at?->timezone(config('finance.timezone'))->format('M j, g:i A') }}</div>
                                    </td>
                                    <td>
                                        {{ $expense->unit?->name }}
                                        <div class="is-dim">{{ $expense->expense_scope === 'job' ? 'Job ' . $expense->booking?->booking_code : 'Unit / monthly · ' . $expense->coverage_month?->format('M Y') }}</div>
                                    </td>
                                    <td>
                                        <strong>{{ $expense->categoryLabel() }}</strong> · {{ $expense->expense_date->format('M j, Y') }}
                                        <div>{{ $expense->description }}</div>
                                        @foreach ($expense->fundings as $funding)
                                            <div class="is-dim">{{ $funding->label() }}: {{ \App\Support\Money::of($funding->amount)->format() }}</div>
                                        @endforeach
                                        @foreach ($expense->reviewFlags() as $key => $label)
                                            <div class="fin-flag fin-flag--{{ $key }}">{{ $label }}</div>
                                        @endforeach
                                    </td>
                                    <td class="is-num"><strong>{{ \App\Support\Money::of($expense->amount)->format() }}</strong></td>
                                    <td>
                                        @forelse ($expense->evidence as $file)
                                            <div><a href="{{ route('admin.expense-review.evidence', [$expense->id, $file->id]) }}" target="_blank" rel="noopener">{{ $file->original_name ?: 'Receipt ' . $loop->iteration }}</a></div>
                                        @empty
                                            <span class="fin-flag fin-flag--missing_evidence">No receipt</span>
                                        @endforelse
                                    </td>
                                    <td style="min-width:240px;">
                                        <form method="POST" action="{{ route('admin.expense-review.verify', $expense->id) }}" class="fin-inline-form" data-fin-once>
                                            @csrf
                                            <input type="text" name="note" maxlength="1000" placeholder="Note (optional)" aria-label="Verification note for {{ $expense->reference_code }}">
                                            <button type="submit" class="oa-btn oa-btn--sm oa-btn--primary" @disabled($expense->evidence->isEmpty())>Verify</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.expense-review.reject', $expense->id) }}" class="fin-inline-form" data-fin-once>
                                            @csrf
                                            <input type="text" name="reason" maxlength="500" required placeholder="Reason for rejecting" aria-label="Reason for rejecting {{ $expense->reference_code }}">
                                            <button type="submit" class="oa-btn oa-btn--sm fin-btn-danger">Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="xr-empty">No expenses are waiting for review.</div>
            @endif
        </section>

        <section class="xr-card" aria-labelledby="xrRecentTitle">
            <h2 id="xrRecentTitle">Recently verified</h2>
            @if ($recent->isNotEmpty())
                <div class="xr-wrap">
                    <table class="xr-table">
                        <thead><tr><th scope="col">Reference</th><th scope="col">Unit</th><th scope="col">Category</th><th scope="col" class="is-num">Amount</th><th scope="col">Verified</th><th scope="col">Now</th></tr></thead>
                        <tbody>
                            @foreach ($recent as $expense)
                                <tr>
                                    <td><span class="fin-ref">{{ $expense->reference_code }}</span></td>
                                    <td>{{ $expense->unit?->name }}</td>
                                    <td>{{ $expense->categoryLabel() }}</td>
                                    <td class="is-num">{{ \App\Support\Money::of($expense->amount)->format() }}</td>
                                    <td>{{ $expense->verifier?->full_name ?: $expense->verifier?->name }} · {{ $expense->verified_at?->timezone(config('finance.timezone'))->format('M j, g:i A') }}</td>
                                    <td><span class="fin-status fin-status--{{ ['submitted' => 'amber', 'verified' => 'amber', 'approved' => 'green', 'rejected' => 'red', 'voided' => 'grey'][$expense->status] ?? 'grey' }}">{{ $expense->statusLabel() }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="xr-empty">Nothing verified yet.</div>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/finance.js') }}?v={{ filemtime(public_path('superadmin/js/finance.js')) }}" defer></script>
@endpush
