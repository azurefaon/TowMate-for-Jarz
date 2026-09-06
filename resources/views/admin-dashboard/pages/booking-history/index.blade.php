@extends('admin-dashboard.layouts.app')

@section('title', 'Booking History')

@push('styles')
    {{-- Reuses Dispatch Queue/Active Jobs' tab and table classes (.rb-tab,
         .jobs-table, .jobs-cell-primary, .jobs-status-text, etc.) instead of
         duplicating them — only page-specific pieces live in booking-history.css. --}}
    <link rel="stylesheet" href="{{ asset('dispatcher/css/jobs.css') }}">
    <link rel="stylesheet" href="{{ asset('admin/css/booking-history.css') }}">
@endpush

@section('content')
    <div class="bh-page">

        <div class="jobs-tabs" id="bhTabs">
            <a href="#" data-status="all" class="rb-tab bh-tab-link {{ $status === 'all' ? 'is-active' : '' }}">
                All <span class="rb-tab-count" id="bhCountAll">{{ $counts['all'] }}</span>
            </a>
            <a href="#" data-status="completed" class="rb-tab bh-tab-link {{ $status === 'completed' ? 'is-active' : '' }}">
                Completed <span class="rb-tab-count" id="bhCountCompleted">{{ $counts['completed'] }}</span>
            </a>
            <a href="#" data-status="cancelled" class="rb-tab bh-tab-link {{ $status === 'cancelled' ? 'is-active' : '' }}">
                Cancelled <span class="rb-tab-count" id="bhCountCancelled">{{ $counts['cancelled'] }}</span>
            </a>
        </div>

        <div class="bh-toolbar">
            <div class="bh-filter-group">
                <label for="bhSearchInput">Search</label>
                <input type="text" id="bhSearchInput" value="{{ $search }}" placeholder="Search booking or customer">
            </div>
            <div class="bh-filter-group">
                <label for="bhDateFrom">Date</label>
                <div class="bh-date-range">
                    <input type="date" id="bhDateFrom" value="{{ $dateFrom }}">
                    <span class="bh-date-sep">–</span>
                    <input type="date" id="bhDateTo" value="{{ $dateTo }}">
                    <button type="button" class="bh-date-clear" id="bhDateClear">Clear</button>
                </div>
            </div>
        </div>

        <div id="bhResults">
            @include('admin-dashboard.pages.booking-history._table', ['bookings' => $bookings])
        </div>

    </div>
@endsection

@push('scripts')
    <script>
        (function() {
            const resultsEl = document.getElementById('bhResults');
            const searchInput = document.getElementById('bhSearchInput');
            const dateFromInput = document.getElementById('bhDateFrom');
            const dateToInput = document.getElementById('bhDateTo');
            const tabsEl = document.getElementById('bhTabs');
            let currentStatus = '{{ $status }}';
            let debounceTimer = null;

            function fetchResults(page) {
                const params = new URLSearchParams();
                if (currentStatus !== 'all') params.set('status', currentStatus);
                if (searchInput.value.trim() !== '') params.set('search', searchInput.value.trim());
                if (dateFromInput.value !== '') params.set('date_from', dateFromInput.value);
                if (dateToInput.value !== '') params.set('date_to', dateToInput.value);
                if (page) params.set('page', page);

                fetch('{{ route('admin.booking-history') }}?' + params.toString(), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    })
                    .then(r => r.text())
                    .then(html => {
                        resultsEl.innerHTML = html;
                        bindPaginationLinks();
                    })
                    .catch(() => {});
            }

            function bindPaginationLinks() {
                resultsEl.querySelectorAll('.bh-pagination a[href]').forEach(function(a) {
                    a.addEventListener('click', function(e) {
                        e.preventDefault();
                        const url = new URL(a.href);
                        fetchResults(url.searchParams.get('page'));
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    });
                });
            }

            searchInput.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    fetchResults();
                }, 350);
            });

            dateFromInput.addEventListener('change', function() { fetchResults(); });
            dateToInput.addEventListener('change', function() { fetchResults(); });

            document.getElementById('bhDateClear')?.addEventListener('click', function() {
                dateFromInput.value = '';
                dateToInput.value = '';
                fetchResults();
            });

            tabsEl.querySelectorAll('.bh-tab-link').forEach(function(tab) {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    currentStatus = tab.dataset.status;
                    tabsEl.querySelectorAll('.bh-tab-link').forEach(t => t.classList.remove('is-active'));
                    tab.classList.add('is-active');
                    fetchResults();
                });
            });

            bindPaginationLinks();
        })();
    </script>
@endpush
