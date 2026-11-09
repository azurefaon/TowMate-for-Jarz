@php
    /**
     * Shared expense fields for the "Record expense" and "Correct" forms.
     * $v: current values (old input or the expense being corrected); $p: unique id prefix; $includeUnit: show the unit picker.
     */
    $v = $v ?? [];
    $scope = $v['expense_scope'] ?? 'job';
    $fund = $v['fundings'] ?? [];
@endphp
<div class="fin-grid" data-fin-expense-form>
    @if ($includeUnit)
        <label class="fin-field">
            <span>Unit</span>
            <select name="unit_id" class="oa-select" required data-fin-expense-unit>
                <option value="">Select a unit</option>
                @foreach ($activeUnits as $unit)
                    <option value="{{ $unit->id }}" data-available="{{ $availableByUnit[$unit->id] ?? '0.00' }}" @selected((string) ($v['unit_id'] ?? '') === (string) $unit->id)>
                        {{ $unit->name }}{{ $unit->plate_number ? ' · ' . $unit->plate_number : '' }}
                    </option>
                @endforeach
            </select>
        </label>
    @endif

    <fieldset class="fin-field fin-radios">
        <legend>Expense type</legend>
        @foreach (config('finance.expense_scopes') as $key => $label)
            <label><input type="radio" name="expense_scope" value="{{ $key }}" @checked($scope === $key) required data-fin-scope> {{ $label }}</label>
        @endforeach
    </fieldset>

    <label class="fin-field" data-fin-scope-show="job" @if ($scope !== 'job') hidden @endif>
        <span>Booking reference</span>
        <input type="text" name="booking_ref" maxlength="40" list="finBookingList" value="{{ $v['booking_ref'] ?? '' }}" placeholder="Booking code, e.g. 0000123" autocomplete="off">
    </label>
    <label class="fin-field" data-fin-scope-show="unit" @if ($scope !== 'unit') hidden @endif>
        <span>Month covered</span>
        <input type="month" name="coverage_month" value="{{ $v['coverage_month'] ?? '' }}">
    </label>

    <label class="fin-field">
        <span>Expense date</span>
        <input type="date" name="expense_date" value="{{ $v['expense_date'] ?? $today }}" max="{{ $today }}" required>
    </label>
    <label class="fin-field">
        <span>Category</span>
        <select name="category" class="oa-select" required>
            <option value="">Select a category</option>
            @foreach (config('finance.expense_categories') as $key => $label)
                <option value="{{ $key }}" @selected(($v['category'] ?? '') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="fin-field">
        <span>Amount (₱)</span>
        <input type="text" name="amount" inputmode="decimal" placeholder="0.00" value="{{ $v['amount'] ?? '' }}" required pattern="^[0-9,]{1,13}(\.[0-9]{1,2})?$" autocomplete="off" data-fin-amount>
    </label>
    <label class="fin-field fin-field--wide">
        <span>Description</span>
        <textarea name="description" rows="2" maxlength="2000" required placeholder="What was paid for, and where">{{ $v['description'] ?? '' }}</textarea>
    </label>

    <fieldset class="fin-field fin-field--wide fin-funding">
        <legend>How was it paid? <small>Split across sources if needed. The parts must add up to the amount.</small></legend>
        <div class="fin-grid fin-grid--compact">
            @foreach (config('finance.funding_sources') as $key => $label)
                <label class="fin-field">
                    <span>{{ $label }} (₱)</span>
                    <input type="text" name="fundings[{{ $key }}]" inputmode="decimal" placeholder="0.00" value="{{ $fund[$key] ?? '' }}" pattern="^[0-9,]{0,13}(\.[0-9]{1,2})?$" autocomplete="off" data-fin-funding="{{ $key }}">
                </label>
            @endforeach
        </div>
        <p class="fin-help" data-fin-funding-status aria-live="polite"></p>
        <p class="fin-inline-warning" data-fin-overspend hidden aria-live="polite"></p>
    </fieldset>
</div>
