@extends('layouts.superadmin')

@section('title', 'Bookings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
@endpush

@section('content')
    <div class="ob-page" id="ownerBookings"
        data-index-url="{{ route('superadmin.bookings.index') }}"
        data-show-url="{{ route('superadmin.bookings.show', '__CODE__') }}">

        <header class="ob-header">
            <h1>Bookings</h1>
            <p>All towing bookings, newest first</p>
        </header>

        <form class="ob-filters" id="bookingFilters" method="GET" action="{{ route('superadmin.bookings.index') }}" role="search">
            <label class="ob-search">
                <span class="ob-sr">Search bookings</span>
                <i data-lucide="search" aria-hidden="true"></i>
                <input type="search" name="search" value="{{ $filters['search'] }}"
                    placeholder="Search booking no., customer, or address" autocomplete="off">
            </label>

            <input type="hidden" name="status" value="{{ $filters['status'] }}">
            <input type="hidden" name="date" value="{{ $filters['date'] }}">
            <input type="hidden" name="from" value="{{ $filters['from'] }}">
            <input type="hidden" name="to" value="{{ $filters['to'] }}">

            <div class="ob-select" data-select="status">
                <button type="button" class="ob-select-trigger {{ $filters['status'] !== '' ? 'is-active' : '' }}"
                    aria-haspopup="listbox" aria-expanded="false" aria-controls="statusMenu" id="statusTrigger">
                    <i data-lucide="list-filter" aria-hidden="true"></i>
                    <span class="ob-select-label" data-label>{{ $filters['statusLabel'] }}</span>
                    <i data-lucide="chevron-down" class="ob-chevron" aria-hidden="true"></i>
                </button>
                <ul class="ob-menu ob-menu--status" id="statusMenu" role="listbox" aria-labelledby="statusTrigger" tabindex="-1" hidden>
                    <li role="option" class="ob-option" data-value="" aria-selected="{{ $filters['status'] === '' ? 'true' : 'false' }}">
                        <span class="ob-option-text"><span>All statuses</span></span>
                        <i data-lucide="check" class="ob-check" aria-hidden="true"></i>
                    </li>
                    @foreach ($statusGroups as $value => $group)
                        @if (! empty($group['divider']))
                            <li role="separator" class="ob-menu-divider"></li>
                        @endif
                        <li role="option" class="ob-option" data-value="{{ $value }}" aria-selected="{{ $filters['status'] === $value ? 'true' : 'false' }}">
                            <span class="ob-option-text">
                                <span>{{ $group['label'] }}</span>
                                @if ($group['hint'])
                                    <small>{{ $group['hint'] }}</small>
                                @endif
                            </span>
                            <i data-lucide="check" class="ob-check" aria-hidden="true"></i>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="ob-select" data-select="date">
                <button type="button" class="ob-select-trigger {{ $filters['date'] !== 'all' ? 'is-active' : '' }}"
                    aria-haspopup="listbox" aria-expanded="false" aria-controls="dateMenu" id="dateTrigger">
                    <i data-lucide="calendar" aria-hidden="true"></i>
                    <span class="ob-select-label" data-label>{{ $filters['dateLabel'] }}</span>
                    <i data-lucide="chevron-down" class="ob-chevron" aria-hidden="true"></i>
                </button>
                <div class="ob-menu ob-menu--date" id="datePanel" hidden>
                    <ul role="listbox" id="dateMenu" aria-labelledby="dateTrigger" tabindex="-1">
                        @foreach ($datePresets as $value => $label)
                            <li role="option" class="ob-option" data-value="{{ $value }}" aria-selected="{{ $filters['date'] === $value ? 'true' : 'false' }}">
                                <span class="ob-option-text"><span>{{ $label }}</span></span>
                                <i data-lucide="check" class="ob-check" aria-hidden="true"></i>
                            </li>
                        @endforeach
                    </ul>
                    <div class="ob-range" data-range {{ $filters['date'] === 'custom' ? '' : 'hidden' }}>
                        <div class="ob-range-fields">
                            <label>
                                <span>From</span>
                                <input type="date" data-range-from value="{{ $filters['from'] }}" max="{{ now()->toDateString() }}">
                            </label>
                            <label>
                                <span>To</span>
                                <input type="date" data-range-to value="{{ $filters['to'] }}" max="{{ now()->toDateString() }}">
                            </label>
                        </div>
                        <p class="ob-range-error" data-range-error role="alert" hidden>Choose a start date on or before the end date.</p>
                        <button type="button" class="ob-apply" data-range-apply>Apply</button>
                    </div>
                </div>
            </div>

            <a href="{{ route('superadmin.bookings.index') }}" class="ob-clear" data-clear {{ $hasFilters ? '' : 'hidden' }}>Clear filters</a>
        </form>

        <div id="bookingResults" aria-live="polite">
            @include('superadmin.bookings._results')
        </div>
    </div>

    <div class="ob-modal" id="bookingModal" hidden>
        <div class="ob-modal-backdrop" data-modal-close></div>
        <div class="ob-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="bookingModalTitle">
            <header class="ob-modal-head">
                <div>
                    <h2 id="bookingModalTitle" tabindex="-1">Booking</h2>
                    <div class="ob-modal-meta" data-modal-meta></div>
                </div>
                <button type="button" class="ob-modal-close" data-modal-close aria-label="Close booking details">
                    <i data-lucide="x" aria-hidden="true"></i>
                </button>
            </header>
            <div class="ob-modal-body" data-modal-body></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/owner-bookings.js') }}?v={{ filemtime(public_path('superadmin/js/owner-bookings.js')) }}" defer></script>
@endpush
