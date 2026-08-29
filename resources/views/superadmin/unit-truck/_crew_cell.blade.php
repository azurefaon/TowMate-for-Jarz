@php
    $column = \App\Models\Unit::SLOT_COLUMNS[$slotKey];
    $value = $unit->{$column};
    $loanIn = $loansInBySlot->get($unit->id . ':' . $slotKey);
    $loanOut = $loansOutBySlot->get($unit->id . ':' . $slotKey);
@endphp

<div class="crew-slot">
    @if ($loanIn)
        <span class="cell-main" title="Borrowed from {{ $loanIn->fromUnit->name ?? '-' }}">{{ $value }}</span>
        <form method="POST" action="{{ route('superadmin.unit-crew-loans.return', $loanIn->id) }}">
            @csrf
            @method('PATCH')
            <button type="submit" class="crew-slot-link">Return</button>
        </form>
    @elseif ($value)
        <span class="cell-main">{{ $value }}</span>
    @elseif ($loanOut)
        <span class="crew-slot-badge crew-slot-badge--loaned"
            title="On transfer to {{ $loanOut->toUnit->name ?? '-' }}">On Transfer</span>
    @else
        <button type="button" class="crew-slot-action js-assign-crew"
            data-to-unit="{{ $unit->id }}" data-to-unit-name="{{ $unit->name }}"
            data-to-slot="{{ $slotKey }}" data-to-slot-label="{{ $slotLabel }}">Assign</button>
    @endif
</div>
