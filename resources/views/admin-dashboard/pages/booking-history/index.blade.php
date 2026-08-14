@extends('admin-dashboard.layouts.app')

@section('title', 'Booking History')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/booking-history.css') }}">
@endpush

@section('content')
    <div class="bh-page">

        <div class="bh-toolbar">
            <div class="bh-tabs" id="bhTabs">
                <a href="#" data-status="all" class="bh-tab bh-tab-link {{ $status === 'all' ? 'is-active' : '' }}">
                    All
                </a>
                <a href="#" data-status="completed" class="bh-tab bh-tab-link {{ $status === 'completed' ? 'is-active' : '' }}">
                    Completed (<span id="bhCountCompleted">{{ $counts['completed'] }}</span>)
                </a>
                <a href="#" data-status="cancelled" class="bh-tab bh-tab-link {{ $status === 'cancelled' ? 'is-active' : '' }}">
                    Cancelled (<span id="bhCountCancelled">{{ $counts['cancelled'] }}</span>)
                </a>
            </div>

            <div class="bh-search">
                <input type="text" id="bhSearchInput" value="{{ $search }}" placeholder="Search booking # or customer">
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
            const tabsEl = document.getElementById('bhTabs');
            let currentStatus = '{{ $status }}';
            let debounceTimer = null;

            function fetchResults(page) {
                const params = new URLSearchParams();
                if (currentStatus !== 'all') params.set('status', currentStatus);
                if (searchInput.value.trim() !== '') params.set('search', searchInput.value.trim());
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
