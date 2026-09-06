@php
    $column = \App\Models\Unit::SLOT_COLUMNS[$slotKey];
    $value = $unit->{$column};
    $loanIn = $loansInBySlot->get($unit->id . ':' . $slotKey);
    $loanOut = $loansOutBySlot->get($unit->id . ':' . $slotKey);
@endphp

{{-- Driver/Crew borrow/return is managed exclusively in Dispatcher →
     Units & Leaders; this view is read-only. --}}
<div class="crew-slot">
    @if ($loanIn)
        <span class="cell-main" title="Borrowed from {{ $loanIn->fromUnit->name ?? '-' }}">{{ $value }}</span>
    @elseif ($value)
        <span class="cell-main">{{ $value }}</span>
    @elseif ($loanOut)
        <span class="crew-slot-badge crew-slot-badge--loaned"
            title="On transfer to {{ $loanOut->toUnit->name ?? '-' }}">On Transfer</span>
    @else
        <span class="not-assigned">—</span>
    @endif
</div>
