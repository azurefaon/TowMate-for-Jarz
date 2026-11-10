@extends('layouts.superadmin')

@section('title', 'Unit Funds')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@php
    $openForm = old('form');
    $periodLabel = $from->equalTo($to) ? $from->format('M j, Y') : $from->format('M j, Y') . ' – ' . $to->format('M j, Y');
    $filterQuery = array_filter(['unit_id' => $unitId, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
@endphp

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerUnitFunds">

        <header class="ob-header fin-header">
            <div>
                <h1>Unit Funds</h1>
                <p>Cash issued to towing units, what they spent from it, and what they returned.</p>
            </div>
            <div class="fin-header-actions">
                <button type="button" class="oa-btn oa-btn--primary" data-fin-open="issue"><i data-lucide="hand-coins" aria-hidden="true"></i> Issue funds</button>
                <button type="button" class="oa-btn" data-fin-open="return"><i data-lucide="undo-2" aria-hidden="true"></i> Record returned funds</button>
            </div>
        </header>

        @if (session('success'))
            <div class="fin-flash fin-flash--success" role="status">
                <i data-lucide="circle-check" aria-hidden="true"></i><span>{{ session('success') }}</span>
            </div>
        @endif
        @if (session('warning'))
            <div class="fin-flash fin-flash--warning" role="status">
                <i data-lucide="triangle-alert" aria-hidden="true"></i><span>{{ session('warning') }}</span>
            </div>
        @endif
        @if ($errors->any())
            <div class="fin-flash fin-flash--error" role="alert">
                <i data-lucide="circle-alert" aria-hidden="true"></i>
                <div>
                    <strong>The entry was not saved.</strong>
                    <ul>
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        {{-- Issue funds --}}
        <section class="oa-card oa-card-body fin-form-card" id="finIssueForm" data-fin-panel="issue" @if ($openForm !== 'issue') hidden @endif aria-labelledby="finIssueTitle">
            <div class="oa-card-head">
                <div>
                    <h2 class="oa-card-title" id="finIssueTitle"><i data-lucide="hand-coins" aria-hidden="true"></i> Issue funds to a unit</h2>
                    <p class="oa-card-sub">Record the cash handed to the unit's crew before or during operations.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('superadmin.finance.unit-funds.issue') }}" class="fin-form" data-fin-once>
                @csrf
                <input type="hidden" name="form" value="issue">
                <input type="hidden" name="idempotency_key" value="{{ $issueKey }}">
                <div class="fin-grid">
                    <label class="fin-field">
                        <span>Unit</span>
                        <select name="unit_id" class="oa-select" required data-fin-unit>
                            <option value="">Select a unit</option>
                            @foreach ($activeUnits as $unit)
                                <option value="{{ $unit->id }}" data-has-initial="{{ in_array($unit->id, $unitsWithInitial) ? '1' : '0' }}"
                                    @selected($openForm === 'issue' && (string) old('unit_id') === (string) $unit->id)>
                                    {{ $unit->name }}{{ $unit->plate_number ? ' · ' . $unit->plate_number : '' }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <fieldset class="fin-field fin-radios">
                        <legend>Type</legend>
                        @foreach (config('finance.issue_kinds') as $kind => $label)
                            <label><input type="radio" name="issue_kind" value="{{ $kind }}" @checked(old('issue_kind') === $kind) required data-fin-kind> {{ $label }}</label>
                        @endforeach
                    </fieldset>
                    <label class="fin-field">
                        <span>Date</span>
                        <input type="date" name="entry_date" value="{{ $openForm === 'issue' ? old('entry_date', $today) : $today }}" max="{{ $today }}" required>
                    </label>
                    <label class="fin-field">
                        <span>Amount (₱)</span>
                        <input type="text" name="amount" inputmode="decimal" placeholder="0.00" value="{{ $openForm === 'issue' ? old('amount') : '' }}" required pattern="^[0-9,]{1,13}(\.[0-9]{1,2})?$" autocomplete="off">
                    </label>
                    <label class="fin-field">
                        <span>Received by</span>
                        <input type="text" name="counterparty_name" maxlength="150" value="{{ $openForm === 'issue' ? old('counterparty_name') : '' }}" required placeholder="Crew member who received the cash">
                    </label>
                    <label class="fin-field">
                        <span>Reference <small>(optional)</small></span>
                        <input type="text" name="external_reference" maxlength="100" value="{{ $openForm === 'issue' ? old('external_reference') : '' }}" placeholder="Voucher or slip number">
                    </label>
                    <label class="fin-field fin-field--wide">
                        <span>Remarks <small>(optional)</small></span>
                        <textarea name="notes" rows="2" maxlength="2000">{{ $openForm === 'issue' ? old('notes') : '' }}</textarea>
                    </label>
                </div>
                <div class="fin-form-actions">
                    <button type="button" class="oa-btn" data-fin-close>Cancel</button>
                    <button type="submit" class="oa-btn oa-btn--primary">Record issued funds</button>
                </div>
            </form>
        </section>

        {{-- Record returned funds --}}
        <section class="oa-card oa-card-body fin-form-card" id="finReturnForm" data-fin-panel="return" @if ($openForm !== 'return') hidden @endif aria-labelledby="finReturnTitle">
            <div class="oa-card-head">
                <div>
                    <h2 class="oa-card-title" id="finReturnTitle"><i data-lucide="undo-2" aria-hidden="true"></i> Record returned funds</h2>
                    <p class="oa-card-sub">Unused cash handed back by the unit. It cannot exceed the unit's remaining balance, after expenses still awaiting review are set aside.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('superadmin.finance.unit-funds.return') }}" class="fin-form" data-fin-once>
                @csrf
                <input type="hidden" name="form" value="return">
                <input type="hidden" name="idempotency_key" value="{{ $returnKey }}">
                <div class="fin-grid">
                    <label class="fin-field">
                        <span>Unit</span>
                        <select name="unit_id" class="oa-select" required>
                            <option value="">Select a unit</option>
                            @foreach ($filterUnits as $unit)
                                <option value="{{ $unit->id }}" @selected($openForm === 'return' && (string) old('unit_id') === (string) $unit->id)>
                                    {{ $unit->name }}{{ $unit->plate_number ? ' · ' . $unit->plate_number : '' }}{{ $unit->archived_at ? ' (archived)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <label class="fin-field">
                        <span>Date</span>
                        <input type="date" name="entry_date" value="{{ $openForm === 'return' ? old('entry_date', $today) : $today }}" max="{{ $today }}" required>
                    </label>
                    <label class="fin-field">
                        <span>Amount (₱)</span>
                        <input type="text" name="amount" inputmode="decimal" placeholder="0.00" value="{{ $openForm === 'return' ? old('amount') : '' }}" required pattern="^[0-9,]{1,13}(\.[0-9]{1,2})?$" autocomplete="off">
                    </label>
                    <label class="fin-field">
                        <span>Returned by</span>
                        <input type="text" name="counterparty_name" maxlength="150" value="{{ $openForm === 'return' ? old('counterparty_name') : '' }}" required placeholder="Crew member who returned the cash">
                    </label>
                    <label class="fin-field">
                        <span>Reference <small>(optional)</small></span>
                        <input type="text" name="external_reference" maxlength="100" value="{{ $openForm === 'return' ? old('external_reference') : '' }}" placeholder="Receipt or slip number">
                    </label>
                    <label class="fin-field fin-field--wide">
                        <span>Remarks <small>(optional)</small></span>
                        <textarea name="notes" rows="2" maxlength="2000">{{ $openForm === 'return' ? old('notes') : '' }}</textarea>
                    </label>
                </div>
                <div class="fin-form-actions">
                    <button type="button" class="oa-btn" data-fin-close>Cancel</button>
                    <button type="submit" class="oa-btn oa-btn--primary">Record returned funds</button>
                </div>
            </form>
        </section>

        {{-- Filters --}}
        <form method="GET" action="{{ route('superadmin.finance.unit-funds.index') }}" class="oa-card oa-filter-card" role="search">
            <div class="oa-filters">
                <div class="oa-field">
                    <label for="finUnitFilter">Unit</label>
                    <select id="finUnitFilter" name="unit_id" class="oa-select" onchange="this.form.submit()">
                        <option value="">All units</option>
                        @foreach ($filterUnits as $unit)
                            <option value="{{ $unit->id }}" @selected($unitId === $unit->id)>{{ $unit->name }}{{ $unit->archived_at ? ' (archived)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <span class="oa-field-label">Reporting period</span>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        @include('superadmin.analytics._range', ['label' => $periodLabel, 'from' => $from->toDateString(), 'to' => $to->toDateString()])
                        <a href="{{ route('superadmin.finance.unit-funds.index') }}" class="oa-btn">This week</a>
                    </div>
                </div>
            </div>
        </form>

        {{-- Balance summary --}}
        <div class="oa-kpis oa-kpis--six" style="--oa-kpi-count: 6;">
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="archive" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Opening balance</span>
                <div class="oa-kpi-value" data-fin-total="opening">{{ $totals['opening']->format() }}</div>
            </div>
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="plus" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Funds issued</span>
                <div class="oa-kpi-value" data-fin-total="issued">{{ $totals['issued']->format() }}</div>
            </div>
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="receipt" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Advance-funded spending</span>
                <div class="oa-kpi-value" data-fin-total="spent">{{ $totals['spent']->format() }}</div>
            </div>
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="undo-2" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Funds returned</span>
                <div class="oa-kpi-value" data-fin-total="returned">{{ $totals['returned']->format() }}</div>
            </div>
            <div class="oa-kpi is-dark">
                <span class="oa-kpi-icon"><i data-lucide="wallet" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Remaining balance</span>
                <div class="oa-kpi-value" data-fin-total="remaining">{{ $totals['remaining']->format() }}</div>
            </div>
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="hourglass" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Expenses awaiting review</span>
                <div class="oa-kpi-value" data-fin-total="pending">{{ $totals['pending_amount']->format() }}</div>
            </div>
        </div>

        <p class="oa-note">
            <i data-lucide="info" aria-hidden="true"></i>
            <span>
                <strong>Remaining balance = Opening balance + Funds issued − Advance-funded spending − Funds returned.</strong>
                The opening balance carries forward everything recorded before {{ $from->format('M j, Y') }}.
                Only approved expenses paid from the unit fund are deducted. Expenses still awaiting Dispatcher or Owner review are shown separately and are not deducted yet.
                Customer collections and commission payouts are never part of a unit fund.
            </span>
        </p>

        <section class="oa-card oa-card-body" aria-labelledby="finBalancesTitle">
            <div class="oa-card-head">
                <div>
                    <h2 class="oa-card-title" id="finBalancesTitle"><i data-lucide="truck" aria-hidden="true"></i> Balances by unit</h2>
                    <p class="oa-card-sub">{{ $periodLabel }}</p>
                </div>
            </div>
            @if ($rows->isNotEmpty())
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table" id="finBalancesTable">
                        <thead>
                            <tr>
                                <th scope="col">Unit</th>
                                <th scope="col" class="is-num">Opening</th>
                                <th scope="col" class="is-num">Issued</th>
                                <th scope="col" class="is-num">Spent</th>
                                <th scope="col" class="is-num">Returned</th>
                                <th scope="col" class="is-num">Remaining</th>
                                <th scope="col" class="is-num">Awaiting review</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr data-fin-unit-row="{{ $row['unit_id'] }}">
                                    <td>
                                        <a href="{{ route('superadmin.finance.unit-funds.index', array_merge($filterQuery, ['unit_id' => $row['unit_id']])) }}">{{ $row['unit_name'] }}</a>
                                        @if ($row['archived'])<span class="fin-tag">Archived</span>@endif
                                        @if ($row['plate_number'])<div class="is-dim">{{ $row['plate_number'] }}</div>@endif
                                    </td>
                                    <td class="is-num">{{ $row['opening']->format() }}</td>
                                    <td class="is-num">{{ $row['issued']->format() }}</td>
                                    <td class="is-num">{{ $row['spent']->format() }}</td>
                                    <td class="is-num">{{ $row['returned']->format() }}</td>
                                    <td class="is-num fin-strong {{ $row['remaining']->isNegative() ? 'fin-negative' : '' }}" data-fin-remaining>{{ $row['remaining']->format() }}</td>
                                    <td class="is-num">
                                        @if ($row['pending_count'] > 0)
                                            <span class="fin-pending" title="Unit-fund expenses submitted or verified but not yet approved">{{ $row['pending_amount']->format() }} · {{ $row['pending_count'] }} {{ \Illuminate\Support\Str::plural('expense', $row['pending_count']) }}</span>
                                        @else
                                            <span class="is-dim">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        @if ($rows->count() > 1)
                            <tfoot>
                                <tr>
                                    <th scope="row">Total</th>
                                    <td class="is-num">{{ $totals['opening']->format() }}</td>
                                    <td class="is-num">{{ $totals['issued']->format() }}</td>
                                    <td class="is-num">{{ $totals['spent']->format() }}</td>
                                    <td class="is-num">{{ $totals['returned']->format() }}</td>
                                    <td class="is-num fin-strong">{{ $totals['remaining']->format() }}</td>
                                    <td class="is-num">{{ $totals['pending_amount']->format() }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            @else
                <div class="oa-empty">No units to show.</div>
            @endif
        </section>

        <section class="oa-card oa-card-body" aria-labelledby="finHistoryTitle">
            <div class="oa-card-head">
                <div>
                    <h2 class="oa-card-title" id="finHistoryTitle"><i data-lucide="history" aria-hidden="true"></i> Fund movements</h2>
                    <p class="oa-card-sub">Issued and returned funds in this period. Voided and corrected entries stay in the history.</p>
                </div>
            </div>

            @if ($history->isNotEmpty())
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table" id="finHistoryTable">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Reference</th>
                                <th scope="col">Unit</th>
                                <th scope="col">Type</th>
                                <th scope="col">Person</th>
                                <th scope="col" class="is-num">Amount</th>
                                <th scope="col">Recorded by</th>
                                <th scope="col"><span class="fin-sr">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $entry)
                                @php $isVoided = $entry->status === 'voided'; $formKey = 'entry-' . $entry->id; @endphp
                                <tr class="{{ $isVoided ? 'fin-voided' : '' }}" data-fin-entry="{{ $entry->id }}">
                                    <td>{{ $entry->entry_date->format('M j, Y') }}</td>
                                    <td>
                                        <span class="fin-ref">{{ $entry->reference_code }}</span>
                                        @if ($entry->external_reference)<div class="is-dim">Ref. {{ $entry->external_reference }}</div>@endif
                                        @if ($entry->correctedEntry)<div class="is-dim">Corrects {{ $entry->correctedEntry->reference_code }}</div>@endif
                                    </td>
                                    <td>{{ $entry->unit?->name }}</td>
                                    <td>
                                        <span class="fin-type fin-type--{{ $entry->entry_type }}">{{ $entry->typeLabel() }}</span>
                                        @if ($isVoided)
                                            <span class="fin-tag fin-tag--void">Voided</span>
                                        @endif
                                    </td>
                                    <td>{{ $entry->counterparty_name ?? '—' }}</td>
                                    <td class="is-num fin-strong">{{ $entry->entry_type === 'return' ? '−' : '+' }}{{ \App\Support\Money::of($entry->amount)->format() }}</td>
                                    <td>
                                        {{ $entry->recorder?->full_name ?: $entry->recorder?->name ?: '—' }}
                                        <div class="is-dim">{{ $entry->created_at->timezone(config('finance.timezone'))->format('M j, Y g:i A') }}</div>
                                    </td>
                                    <td class="fin-actions">
                                        @if (! $isVoided)
                                            <details class="fin-details" @if ($openForm === $formKey) open @endif>
                                                <summary class="oa-btn oa-btn--sm">Correct or void</summary>
                                                <div class="fin-details-body">
                                                    <form method="POST" action="{{ route('superadmin.finance.unit-funds.correct', $entry->id) }}" class="fin-form" data-fin-once>
                                                        @csrf
                                                        <input type="hidden" name="form" value="{{ $formKey }}">
                                                        <input type="hidden" name="idempotency_key" value="{{ $openForm === $formKey ? old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) : (string) \Illuminate\Support\Str::uuid() }}">
                                                        <p class="fin-help">Correcting voids this entry and records a replacement linked to it.</p>
                                                        <div class="fin-grid fin-grid--compact">
                                                            <label class="fin-field"><span>Date</span><input type="date" name="entry_date" max="{{ $today }}" value="{{ $openForm === $formKey ? old('entry_date') : $entry->entry_date->toDateString() }}" required></label>
                                                            <label class="fin-field"><span>Amount (₱)</span><input type="text" name="amount" inputmode="decimal" value="{{ $openForm === $formKey ? old('amount') : $entry->amount }}" required></label>
                                                            <label class="fin-field"><span>Person</span><input type="text" name="counterparty_name" maxlength="150" value="{{ $openForm === $formKey ? old('counterparty_name') : $entry->counterparty_name }}" required></label>
                                                            <label class="fin-field"><span>Reference</span><input type="text" name="external_reference" maxlength="100" value="{{ $openForm === $formKey ? old('external_reference') : $entry->external_reference }}"></label>
                                                            <label class="fin-field fin-field--wide"><span>Remarks</span><textarea name="notes" rows="2" maxlength="2000">{{ $openForm === $formKey ? old('notes') : $entry->notes }}</textarea></label>
                                                            <label class="fin-field fin-field--wide"><span>Reason for correction</span><input type="text" name="reason" maxlength="500" required value="{{ $openForm === $formKey ? old('reason') : '' }}"></label>
                                                        </div>
                                                        <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--primary oa-btn--sm">Save correction</button></div>
                                                    </form>
                                                    <form method="POST" action="{{ route('superadmin.finance.unit-funds.void', $entry->id) }}" class="fin-form fin-void-form" data-fin-once
                                                        onsubmit="return confirm('Void {{ $entry->reference_code }}? It stays in the history with your reason.');">
                                                        @csrf
                                                        <label class="fin-field fin-field--wide"><span>Reason for voiding</span><input type="text" name="reason" maxlength="500" required></label>
                                                        <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--sm fin-btn-danger">Void entry</button></div>
                                                    </form>
                                                </div>
                                            </details>
                                        @else
                                            <div class="fin-void-note">
                                                {{ $entry->void_reason }}
                                                <div class="is-dim">Voided {{ $entry->voided_at?->timezone(config('finance.timezone'))->format('M j, Y g:i A') }}{{ $entry->voider ? ' by ' . ($entry->voider->full_name ?: $entry->voider->name) : '' }}{{ $entry->correction ? ' · replaced by ' . $entry->correction->reference_code : '' }}</div>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                                @if ($entry->notes)
                                    <tr class="fin-notes-row {{ $isVoided ? 'fin-voided' : '' }}"><td></td><td colspan="7" class="is-dim">Remarks: {{ $entry->notes }}</td></tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="fin-pager">{{ $history->links() }}</div>
            @else
                <div class="oa-empty">No fund movements recorded for this period.</div>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/analytics.js') }}?v={{ filemtime(public_path('superadmin/js/analytics.js')) }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const panels = document.querySelectorAll('[data-fin-panel]');
            const show = function (name) {
                panels.forEach(function (panel) { panel.hidden = panel.dataset.finPanel !== name; });
                const target = document.querySelector('[data-fin-panel="' + name + '"]');
                if (target) { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); target.querySelector('select, input:not([type=hidden])')?.focus(); }
            };
            document.querySelectorAll('[data-fin-open]').forEach(function (btn) {
                btn.addEventListener('click', function () { show(btn.dataset.finOpen); });
            });
            document.querySelectorAll('[data-fin-close]').forEach(function (btn) {
                btn.addEventListener('click', function () { btn.closest('[data-fin-panel]').hidden = true; });
            });

            // Suggest "Daily operating funds" for the unit's first hand-over today and "Additional funds" otherwise. Either is allowed.
            const unitSelect = document.querySelector('[data-fin-unit]');
            if (unitSelect) {
                unitSelect.addEventListener('change', function () {
                    const option = unitSelect.selectedOptions[0];
                    if (!option || !option.value) { return; }
                    const kind = option.dataset.hasInitial === '1' ? 'additional' : 'initial';
                    const radio = document.querySelector('[data-fin-kind][value="' + kind + '"]');
                    if (radio) { radio.checked = true; }
                });
            }

            // One click = one submission. The server also rejects a repeated idempotency key.
            document.querySelectorAll('form[data-fin-once]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (event.defaultPrevented) { return; }
                    if (form.dataset.submitted === '1') { event.preventDefault(); return; }
                    form.dataset.submitted = '1';
                    form.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; });
                });
            });
        });
    </script>
@endpush
