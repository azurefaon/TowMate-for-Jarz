@php
    /**
     * Date-range popover (From / To / Apply). Must sit inside the page's GET <form>.
     * $label: text on the button; $placeholder: true when no range is chosen yet.
     */
    $placeholder = $placeholder ?? false;
@endphp
<div class="oa-range {{ ($alignRight ?? false) ? 'oa-range--right' : '' }}" data-oa-range>
    <button type="button" class="oa-range-trigger" aria-haspopup="dialog" aria-expanded="false">
        <i data-lucide="calendar" aria-hidden="true"></i>
        <span class="oa-range-label {{ $placeholder ? 'is-placeholder' : '' }}">{{ $label }}</span>
        <i data-lucide="chevron-down" aria-hidden="true"></i>
    </button>

    <div class="oa-range-pop" role="dialog" aria-label="Choose a date range" hidden>
        <div class="oa-range-fields">
            <label>
                <span>From</span>
                <input type="date" name="from" value="{{ $from }}">
            </label>
            <label>
                <span>To</span>
                <input type="date" name="to" value="{{ $to }}">
            </label>
        </div>
        <div class="oa-range-actions">
            <button type="submit" class="oa-btn oa-btn--primary">Apply</button>
        </div>
    </div>
</div>
