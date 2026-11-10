@extends('layouts.superadmin')

@section('title', 'Expenses')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@php
    $openForm = old('form');
    $name = fn ($user) => $user ? ($user->full_name ?: $user->name) : '—';
    $at = fn ($ts) => $ts ? $ts->timezone(config('finance.timezone'))->format('M j, Y g:i A') : '';
    $statusTone = ['submitted' => 'amber', 'verified' => 'blue', 'approved' => 'green', 'rejected' => 'red', 'voided' => 'grey'];
@endphp

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerExpenses">

        <header class="ob-header fin-header">
            <div>
                <h1>Expenses</h1>
                <p>Unit and job expenses with their receipts. Team Leader submits, Dispatcher verifies, Owner approves.</p>
            </div>
            <div class="fin-header-actions">
                <button type="button" class="oa-btn oa-btn--primary" data-fin-open="create"><i data-lucide="receipt" aria-hidden="true"></i> Record expense</button>
            </div>
        </header>

        @include('superadmin.finance._flashes')

        <datalist id="finBookingList">
            @foreach ($recentBookings as $booking)
                <option value="{{ $booking->booking_code }}"></option>
            @endforeach
        </datalist>

        {{-- Record expense --}}
        <section class="oa-card oa-card-body fin-form-card" data-fin-panel="create" @if ($openForm !== 'create') hidden @endif aria-labelledby="finCreateTitle">
            <div class="oa-card-head">
                <div>
                    <h2 class="oa-card-title" id="finCreateTitle"><i data-lucide="receipt" aria-hidden="true"></i> Record an expense</h2>
                    <p class="oa-card-sub">Expenses you record can be approved by you directly once a receipt is attached.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('superadmin.finance.expenses.store') }}" enctype="multipart/form-data" class="fin-form" data-fin-once>
                @csrf
                <input type="hidden" name="form" value="create">
                <input type="hidden" name="idempotency_key" value="{{ $createKey }}">
                @include('superadmin.finance.expenses._fields', ['v' => $openForm === 'create' ? old() : [], 'includeUnit' => true])
                <label class="fin-field fin-field--wide fin-upload">
                    <span>Receipt or payment evidence <small>(JPG, PNG, WEBP or PDF, up to {{ intdiv(config('finance.evidence.max_kilobytes'), 1024) }} MB each; required before approval)</small></span>
                    <input type="file" name="receipts[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
                </label>
                <div class="fin-form-actions">
                    <button type="button" class="oa-btn" data-fin-close>Cancel</button>
                    <button type="submit" class="oa-btn oa-btn--primary">Record expense</button>
                </div>
            </form>
        </section>

        {{-- Filters --}}
        <form method="GET" action="{{ route('superadmin.finance.expenses.index') }}" class="oa-card oa-filter-card fin-filters" role="search">
            <div class="oa-filters">
                <div class="oa-field">
                    <label for="fxUnit">Unit</label>
                    <select id="fxUnit" name="unit_id" class="oa-select">
                        <option value="">All units</option>
                        @foreach ($filterUnits as $unit)
                            <option value="{{ $unit->id }}" @selected($filters['unit_id'] === $unit->id)>{{ $unit->name }}{{ $unit->archived_at ? ' (archived)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="fxBooking">Booking</label>
                    <input id="fxBooking" type="text" name="booking" class="oa-select" value="{{ $filters['booking'] }}" placeholder="Booking code">
                </div>
                <div class="oa-field">
                    <label for="fxCategory">Category</label>
                    <select id="fxCategory" name="category" class="oa-select">
                        <option value="">All categories</option>
                        @foreach (config('finance.expense_categories') as $key => $label)
                            <option value="{{ $key }}" @selected($filters['category'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="fxStatus">Status</label>
                    <select id="fxStatus" name="status" class="oa-select">
                        <option value="">All statuses</option>
                        <option value="pending" @selected($filters['status'] === 'pending')>Pending review</option>
                        @foreach (\App\Models\Finance\Expense::STATUS_LABELS as $key => $label)
                            <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="fxScope">Type</label>
                    <select id="fxScope" name="scope" class="oa-select">
                        <option value="">Job and unit</option>
                        @foreach (config('finance.expense_scopes') as $key => $label)
                            <option value="{{ $key }}" @selected($filters['scope'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="fxFunding">Paid with</label>
                    <select id="fxFunding" name="funding" class="oa-select">
                        <option value="">Any source</option>
                        @foreach (config('finance.funding_sources') as $key => $label)
                            <option value="{{ $key }}" @selected($filters['funding'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <span class="oa-field-label">Expense date</span>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        @include('superadmin.analytics._range', ['label' => $periodLabel, 'from' => $from->toDateString(), 'to' => $to->toDateString()])
                        <button type="submit" class="oa-btn oa-btn--primary">Apply</button>
                        <a href="{{ route('superadmin.finance.expenses.index') }}" class="oa-btn">Reset</a>
                    </div>
                </div>
            </div>
        </form>

        {{-- Summary --}}
        <div class="oa-kpis oa-kpis--six" style="--oa-kpi-count: 6;">
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="inbox" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Awaiting Dispatcher review</span>
                <div class="oa-kpi-value" data-fx-total="submitted">{{ $summary['submitted']['total']->format() }}</div>
                <span class="fin-kpi-sub">{{ $summary['submitted']['count'] }} {{ \Illuminate\Support\Str::plural('expense', $summary['submitted']['count']) }}</span>
            </div>
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="hourglass" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Awaiting Owner approval</span>
                <div class="oa-kpi-value" data-fx-total="verified">{{ $summary['verified']['total']->format() }}</div>
                <span class="fin-kpi-sub">{{ $summary['verified']['count'] }} {{ \Illuminate\Support\Str::plural('expense', $summary['verified']['count']) }}</span>
            </div>
            <div class="oa-kpi is-dark">
                <span class="oa-kpi-icon"><i data-lucide="circle-check" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Approved expenses</span>
                <div class="oa-kpi-value" data-fx-total="approved">{{ $summary['approved']['total']->format() }}</div>
                <span class="fin-kpi-sub">{{ $summary['approved']['count'] }} approved</span>
            </div>
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="wallet" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Approved · from unit funds</span>
                <div class="oa-kpi-value" data-fx-total="unit_fund">{{ $summary['approved_unit_fund']->format() }}</div>
            </div>
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="building-2" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Approved · company paid</span>
                <div class="oa-kpi-value" data-fx-total="company">{{ $summary['approved_company']->format() }}</div>
            </div>
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="user-round" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Approved · personal advances</span>
                <div class="oa-kpi-value" data-fx-total="personal">{{ $summary['approved_personal']->format() }}</div>
            </div>
        </div>

        <p class="oa-note">
            <i data-lucide="info" aria-hidden="true"></i>
            <span>
                Only the <strong>issued unit funds</strong> part of an approved expense is deducted from that unit's fund balance, once. Company-paid parts and personal advances never reduce issued funds.
                @if ($summary['missing_evidence'] > 0)
                    <strong>{{ $summary['missing_evidence'] }} pending {{ \Illuminate\Support\Str::plural('expense', $summary['missing_evidence']) }} still need a receipt</strong> before they can be verified or approved.
                @endif
                Pending client confirmation: {{ config('finance.pending_rules.monthly_allocation') }} {{ config('finance.pending_rules.personal_advance_reimbursement') }}
            </span>
        </p>

        {{-- History --}}
        <section class="oa-card oa-card-body" aria-labelledby="fxHistoryTitle">
            <div class="oa-card-head">
                <div>
                    <h2 class="oa-card-title" id="fxHistoryTitle"><i data-lucide="list" aria-hidden="true"></i> Expense history</h2>
                    <p class="oa-card-sub">{{ $periodLabel }}. Items waiting for you are listed first.</p>
                </div>
            </div>

            @if ($expenses->isNotEmpty())
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table" id="fxTable">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Reference</th>
                                <th scope="col">Unit / job</th>
                                <th scope="col">Category</th>
                                <th scope="col" class="is-num">Amount</th>
                                <th scope="col">Paid with</th>
                                <th scope="col">Status</th>
                                <th scope="col"><span class="fin-sr">Details</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($expenses as $expense)
                                @php
                                    $formKey = 'expense-' . $expense->id;
                                    $flags = $expense->reviewFlags();
                                    $isClosed = in_array($expense->status, ['rejected', 'voided'], true);
                                @endphp
                                <tr class="{{ $isClosed ? 'fin-voided' : '' }}" data-fx-row="{{ $expense->id }}">
                                    <td>{{ $expense->expense_date->format('M j, Y') }}</td>
                                    <td>
                                        <span class="fin-ref">{{ $expense->reference_code }}</span>
                                        @if ($expense->correctedExpense)<div class="is-dim">Corrects {{ $expense->correctedExpense->reference_code }}</div>@endif
                                    </td>
                                    <td>
                                        {{ $expense->unit?->name }}
                                        <div class="is-dim">
                                            @if ($expense->expense_scope === 'job')
                                                Job {{ $expense->booking?->booking_code }}
                                            @else
                                                Unit / monthly · {{ $expense->coverage_month?->format('M Y') }}
                                            @endif
                                        </div>
                                    </td>
                                    <td>{{ $expense->categoryLabel() }}</td>
                                    <td class="is-num fin-strong">{{ \App\Support\Money::of($expense->amount)->format() }}</td>
                                    <td>
                                        @foreach ($expense->fundings as $funding)
                                            <div class="fin-funding-line"><span>{{ $funding->label() }}</span> <span class="fin-strong">{{ \App\Support\Money::of($funding->amount)->format() }}</span></div>
                                        @endforeach
                                    </td>
                                    <td>
                                        <span class="fin-status fin-status--{{ $statusTone[$expense->status] ?? 'grey' }}">{{ $expense->statusLabel() }}</span>
                                        @foreach ($flags as $key => $label)
                                            <div class="fin-flag fin-flag--{{ $key }}">{{ $label }}</div>
                                        @endforeach
                                    </td>
                                    <td class="fin-actions">
                                        <details class="fin-details" @if ($openForm === $formKey) open @endif>
                                            <summary class="oa-btn oa-btn--sm">{{ $expense->isPending() ? 'Review' : 'Details' }}</summary>
                                            <div class="fin-details-body">
                                                <p class="fin-desc">{{ $expense->description }}</p>

                                                <h3 class="fin-subhead">Evidence</h3>
                                                @if ($expense->evidence->isNotEmpty())
                                                    <ul class="fin-evidence">
                                                        @foreach ($expense->evidence as $file)
                                                            <li>
                                                                <a href="{{ route('superadmin.finance.expenses.evidence', [$expense->id, $file->id]) }}" target="_blank" rel="noopener">
                                                                    <i data-lucide="{{ $file->mime_type === 'application/pdf' ? 'file-text' : 'image' }}" aria-hidden="true"></i>
                                                                    {{ $file->original_name ?: 'Receipt ' . $loop->iteration }}
                                                                </a>
                                                                <span class="is-dim">{{ number_format($file->size_bytes / 1024, 0) }} KB</span>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @else
                                                    <p class="fin-inline-warning">No receipt or payment evidence yet. It is required before verification and approval.</p>
                                                @endif

                                                @if ($expense->isPending())
                                                    <form method="POST" action="{{ route('superadmin.finance.expenses.upload-evidence', $expense->id) }}" enctype="multipart/form-data" class="fin-form fin-inline-form" data-fin-once>
                                                        @csrf
                                                        <input type="file" name="receipts[]" multiple required accept=".jpg,.jpeg,.png,.webp,.pdf" aria-label="Add receipt for {{ $expense->reference_code }}">
                                                        <button type="submit" class="oa-btn oa-btn--sm">Upload</button>
                                                    </form>
                                                @endif

                                                <h3 class="fin-subhead">Review trail</h3>
                                                <ul class="fin-trail">
                                                    <li>Submitted by {{ $name($expense->submitter) }} · {{ $at($expense->submitted_at) }}</li>
                                                    @if ($expense->verified_at)<li>Verified by {{ $name($expense->verifier) }} · {{ $at($expense->verified_at) }}{{ $expense->verification_note ? ' — ' . $expense->verification_note : '' }}</li>@endif
                                                    @if ($expense->approved_at)<li>Approved by {{ $name($expense->approver) }} · {{ $at($expense->approved_at) }}</li>@endif
                                                    @if ($expense->rejected_at)<li>Rejected by {{ $name($expense->rejecter) }} · {{ $at($expense->rejected_at) }} — {{ $expense->rejection_reason }}</li>@endif
                                                    @if ($expense->voided_at)<li>Voided by {{ $name($expense->voider) }} · {{ $at($expense->voided_at) }} — {{ $expense->void_reason }}</li>@endif
                                                    @if ($expense->correction)<li>Replaced by {{ $expense->correction->reference_code }}</li>@endif
                                                </ul>

                                                @if ($expense->isPending())
                                                    <div class="fin-review-actions">
                                                        <form method="POST" action="{{ route('superadmin.finance.expenses.approve', $expense->id) }}" data-fin-once>
                                                            @csrf
                                                            <button type="submit" class="oa-btn oa-btn--primary oa-btn--sm" @disabled(isset($flags['missing_evidence']))>Approve</button>
                                                            @if ($expense->status === 'submitted' && (int) $expense->submitted_by !== (int) auth()->id())
                                                                <span class="fin-help">Waiting for Dispatcher verification.</span>
                                                            @endif
                                                        </form>
                                                        <form method="POST" action="{{ route('superadmin.finance.expenses.reject', $expense->id) }}" class="fin-inline-form" data-fin-once>
                                                            @csrf
                                                            <input type="text" name="reason" maxlength="500" required placeholder="Reason for rejecting" aria-label="Reason for rejecting {{ $expense->reference_code }}">
                                                            <button type="submit" class="oa-btn oa-btn--sm fin-btn-danger">Reject</button>
                                                        </form>
                                                    </div>
                                                @endif

                                                @if ($expense->status === 'approved')
                                                    <form method="POST" action="{{ route('superadmin.finance.expenses.void', $expense->id) }}" class="fin-inline-form" data-fin-once
                                                        onsubmit="return confirm('Void {{ $expense->reference_code }}? It stays in the history with your reason.');">
                                                        @csrf
                                                        <input type="text" name="reason" maxlength="500" required placeholder="Reason for voiding" aria-label="Reason for voiding {{ $expense->reference_code }}">
                                                        <button type="submit" class="oa-btn oa-btn--sm fin-btn-danger">Void</button>
                                                    </form>
                                                @endif

                                                @if ($expense->isPending() || $expense->status === 'approved')
                                                    <details class="fin-correct" @if ($openForm === $formKey && old('reason') !== null && old('expense_scope') !== null) open @endif>
                                                        <summary>Correct this expense</summary>
                                                        <form method="POST" action="{{ route('superadmin.finance.expenses.correct', $expense->id) }}" class="fin-form" data-fin-once>
                                                            @csrf
                                                            <input type="hidden" name="form" value="{{ $formKey }}">
                                                            <input type="hidden" name="idempotency_key" value="{{ $openForm === $formKey ? old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) : (string) \Illuminate\Support\Str::uuid() }}">
                                                            <p class="fin-help">The original is closed with your reason and a linked replacement is recorded. Its receipts are copied over.</p>
                                                            @include('superadmin.finance.expenses._fields', [
                                                                'includeUnit' => false,
                                                                'v' => $openForm === $formKey && old('expense_scope') !== null ? old() : [
                                                                    'expense_scope' => $expense->expense_scope,
                                                                    'booking_ref' => $expense->booking?->booking_code,
                                                                    'coverage_month' => $expense->coverage_month?->format('Y-m'),
                                                                    'expense_date' => $expense->expense_date->toDateString(),
                                                                    'category' => $expense->category,
                                                                    'amount' => $expense->amount,
                                                                    'description' => $expense->description,
                                                                    'fundings' => $expense->fundings->mapWithKeys(fn ($f) => [$f->source => $f->amount])->all(),
                                                                ],
                                                            ])
                                                            <label class="fin-field fin-field--wide"><span>Reason for correction</span><input type="text" name="reason" maxlength="500" required></label>
                                                            <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--primary oa-btn--sm">Save correction</button></div>
                                                        </form>
                                                    </details>
                                                @endif
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="fin-pager">{{ $expenses->links() }}</div>
            @else
                <div class="oa-empty">No expenses match these filters.</div>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/analytics.js') }}?v={{ filemtime(public_path('superadmin/js/analytics.js')) }}" defer></script>
    <script src="{{ asset('superadmin/js/finance.js') }}?v={{ filemtime(public_path('superadmin/js/finance.js')) }}" defer></script>
@endpush
