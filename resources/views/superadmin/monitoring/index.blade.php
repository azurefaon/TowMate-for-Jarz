@extends('layouts.superadmin')

@section('title', 'Operations Monitor')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/monitor-ui.css') }}?v={{ filemtime(public_path('superadmin/css/monitor-ui.css')) }}">
@endpush

@section('content')
    <div class="ob-page oa-page monitor-page" id="ownerMonitor">

        <header class="ob-header mo-header">
            <div>
                <h1>Operations Monitor</h1>
                <p>View current jobs, fleet readiness, and operational activity.</p>
            </div>
            <span class="mo-sync" id="monitorLastSync" role="status" aria-live="polite">Updated {{ $generatedLabel }}</span>
        </header>

        <form method="GET" action="{{ route('superadmin.monitoring.index') }}" class="oa-card oa-toolbar" role="search">
            <label class="oa-search">
                <span class="ob-sr">Search bookings</span>
                <i data-lucide="search" aria-hidden="true"></i>
                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search booking, customer, or route" autocomplete="off">
            </label>

            <select name="status" data-custom aria-label="Status">
                <option value="">All Statuses</option>
                @foreach (['requested', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'completed', 'rejected', 'cancelled'] as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>
                        {{ str($status)->replace('_', ' ')->title() }}
                    </option>
                @endforeach
            </select>

            <select name="period" data-custom aria-label="Period">
                <option value="today" @selected($filters['period'] === 'today')>Today</option>
                <option value="week" @selected($filters['period'] === 'week')>This Week</option>
                <option value="all" @selected($filters['period'] === 'all')>All Time</option>
            </select>

            <button type="submit" class="oa-btn oa-btn--primary">Apply</button>

            @if ($filtered || $filters['period'] !== 'today')
                <a href="{{ route('superadmin.monitoring.index') }}" class="oa-btn">Reset</a>
            @endif
        </form>

        <p class="oa-note">
            <i data-lucide="info" aria-hidden="true"></i>
            <span>Search, status and period narrow the bookings list by date created. Counts, trucks and team leaders below always show the live position.</span>
        </p>

        <div id="monitorLive" data-hash="{{ $sectionsHash }}">
            {!! $sectionsHtml !!}
        </div>
    </div>
@endsection
@push('scripts')
    <script>
        (function () {
            const liveUrl = @json(route('superadmin.monitoring.live', request()->query()));
            const container = document.getElementById('monitorLive');
            const syncText = document.getElementById('monitorLastSync');
            let currentHash = container ? container.dataset.hash : '';
            let lastGood = @json($generatedLabel);
            let inFlight = false;

            async function refreshMonitoringSnapshot() {
                if (inFlight || document.hidden || !container) {
                    return;
                }
                inFlight = true;

                try {
                    const url = new URL(liveUrl, window.location.origin);
                    url.searchParams.set('view_hash', currentHash);

                    const response = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    });

                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    const data = await response.json();

                    // Only a changed board is swapped in; filters and scroll position stay put.
                    if (!data.unchanged && typeof data.html === 'string') {
                        container.innerHTML = data.html;
                        if (window.lucide && typeof window.lucide.createIcons === 'function') {
                            window.lucide.createIcons();
                        }
                    }

                    currentHash = data.hash;
                    lastGood = data.generated_label;
                    syncText.classList.remove('is-failed');
                    syncText.textContent = 'Updated ' + lastGood;
                } catch (error) {
                    syncText.classList.add('is-failed');
                    syncText.textContent = 'Could not refresh. Showing data from ' + lastGood + '. Retrying…';
                } finally {
                    inFlight = false;
                }
            }

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    refreshMonitoringSnapshot();
                }
            });

            setInterval(refreshMonitoringSnapshot, 15000);
        })();
    </script>
@endpush
