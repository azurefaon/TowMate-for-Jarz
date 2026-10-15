@extends('layouts.superadmin')

@section('title', 'Business Activity')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
@endpush

@section('content')
    @php
        // The range shown is the range applied: the current month by default, or the chosen custom range.
        $rangeLabel = $rangeStart->format('M j, Y') . ' – ' . $rangeEnd->format('M j, Y');
        $hasFilters = $search !== '' || $category !== '' || $entityType !== '' || filled($actorId) || $customRange;

        // Stored status values are slugs (waiting_verification); show them as words.
        $pretty = fn ($value) => is_string($value) && preg_match('/^[a-z][a-z_]*$/', $value) ? ucfirst(str_replace('_', ' ', $value)) : $value;

        // Badge colour per activity category (the label itself comes from the record).
        $tones = [
            'create' => 'green',
            'update' => 'blue',
            'archive' => 'neutral',
            'restore' => 'violet',
            'delete' => 'red',
            'dispatch' => 'orange',
            'assignment_change' => 'blue',
            'quotation_change' => 'amber',
            'status_change' => 'neutral',
            'system' => 'slate',
        ];
    @endphp

    <div class="ob-page oa-page" id="ownerActivity">

        <header class="ob-header">
            <h1>Business Activity</h1>
            <p>Review important business actions across bookings, fleet, and operations.</p>
        </header>

        <form method="GET" action="{{ route('superadmin.reports.activity') }}" class="oa-card oa-toolbar" id="baFilterForm" role="search">
            <label class="oa-search">
                <span class="ob-sr">Search business activity</span>
                <i data-lucide="search" aria-hidden="true"></i>
                <input type="text" name="search" value="{{ $search }}" placeholder="Search description or reference" autocomplete="off">
            </label>

            <select name="category" data-custom aria-label="Activity type">
                <option value="">All Activity</option>
                @foreach ($categories as $value => $label)
                    <option value="{{ $value }}" {{ $category === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>

            <select name="entity_type" data-custom aria-label="Record type">
                <option value="">All Records</option>
                @foreach ($entityTypes as $type)
                    <option value="{{ $type }}" {{ $entityType === $type ? 'selected' : '' }}>{{ trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $type)) }}</option>
                @endforeach
            </select>

            <select name="user_id" data-custom aria-label="User">
                <option value="">All Users</option>
                @foreach ($actors as $actor)
                    <option value="{{ $actor->id }}" {{ (string) $actorId === (string) $actor->id ? 'selected' : '' }}>{{ $actor->full_name }}</option>
                @endforeach
            </select>

            @include('superadmin.analytics._range', ['label' => $rangeLabel, 'from' => $rangeStart->toDateString(), 'to' => $rangeEnd->toDateString(), 'alignRight' => true])

            @if ($hasFilters)
                <a href="{{ route('superadmin.reports.activity') }}" class="oa-btn">Reset</a>
            @endif
        </form>

        <section class="oa-card oa-list-card" aria-labelledby="baTitle">
            <div class="oa-activity-head">
                <h2 class="oa-card-title" id="baTitle"><i data-lucide="list" aria-hidden="true"></i> Business Activity</h2>
                <span class="oa-activity-count">{{ number_format($logs->total()) }} {{ $logs->total() === 1 ? 'record' : 'records' }}</span>
            </div>

            @if ($logs->count() === 0)
                <div class="oa-activity-empty">
                    <strong>No business activity found.</strong>
                    Try adjusting the selected filters or date range.
                </div>
            @else
                <div class="oa-table-wrap">
                    <table class="oa-table oa-activity-table">
                        <colgroup>
                            <col style="width: 16%">
                            <col style="width: 22%">
                            <col style="width: 40%">
                            <col style="width: 22%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Date &amp; Time</th>
                                <th scope="col">Activity</th>
                                <th scope="col">Details</th>
                                <th scope="col">Performed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($logs as $log)
                                <tr>
                                    <td>
                                        {{ $log->created_at?->format('M j, Y') }}
                                        <span class="oa-sub">{{ $log->created_at?->format('g:i A') }}</span>
                                    </td>
                                    <td>
                                        <span class="oa-badge tone-{{ $tones[$log->category] ?? 'neutral' }}">{{ $log->activity_label }}</span>
                                        @if ($log->entity_label)
                                            <span class="oa-sub">{{ $log->entity_label }}{{ $log->reference ? ' · ' . $log->reference : '' }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @foreach ($log->activity_changes as $change)
                                            <div class="oa-change">
                                                @if ($change['label'] === null)
                                                    <span class="oa-change-value">{{ $pretty($change['value']) }}</span>
                                                @elseif (array_key_exists('old', $change))
                                                    <span class="oa-change-label">{{ $change['label'] }}:</span>
                                                    <span class="oa-change-old">{{ $pretty($change['old']) }}</span><span class="oa-change-arrow">→</span><span class="oa-change-value">{{ $pretty($change['new']) }}</span>
                                                @else
                                                    <span class="oa-change-label">{{ $change['label'] }}:</span>
                                                    <span class="oa-change-value">{{ $pretty($change['value']) }}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </td>
                                    <td>
                                        {{ $log->user->full_name ?? 'System' }}
                                        @if ($log->user?->role?->name)
                                            <span class="oa-sub">{{ $log->user->role->name }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @include('superadmin.partials._pager', ['paginator' => $logs, 'noun' => 'record'])
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/analytics.js') }}?v={{ filemtime(public_path('superadmin/js/analytics.js')) }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('baFilterForm');
            if (!form) return;

            // The dropdowns apply immediately; the date range applies from its Apply button.
            form.querySelectorAll('select').forEach(function (select) {
                select.addEventListener('change', function () {
                    form.requestSubmit();
                });
            });

            const searchInput = form.querySelector('input[name="search"]');
            let searchTimer;
            searchInput?.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    form.requestSubmit();
                }, 500);
            });
        });
    </script>
@endpush
