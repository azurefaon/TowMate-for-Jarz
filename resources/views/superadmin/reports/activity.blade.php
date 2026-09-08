@extends('layouts.superadmin')

@section('title', 'Business Activity')

@push('styles')
    <style>
        .reports-page {
            color: #111111;
        }

        .reports-page h1 {
            margin: 0 0 4px;
            font-size: 1.6rem;
            color: #111111;
        }

        .reports-page .subtitle {
            margin: 0 0 16px;
            color: #111111;
        }

        .reports-tabs {
            display: flex;
            gap: 22px;
            margin-bottom: 18px;
            padding: 6px 18px 0;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 4px 14px rgba(15, 23, 42, 0.06);
        }

        .reports-tabs a {
            padding: 12px 2px;
            border-bottom: 3px solid transparent;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.92rem;
            color: #6b7280;
            background: transparent;
        }

        .reports-tabs a:hover {
            color: #111111;
        }

        .reports-tabs a.active {
            color: #111111;
            font-weight: 700;
            border-bottom-color: #facc15;
        }

        .activity-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 20px;
            background: #ffffff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 4px 14px rgba(15, 23, 42, 0.06);
        }

        .activity-toolbar .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .activity-toolbar input[type="text"] {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 0.85rem;
            color: #111111;
            background: #ffffff;
        }

        .activity-toolbar input[type="text"] {
            min-width: 220px;
        }

        .activity-toolbar .clear-link {
            border: 1px solid #111111;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            background: #ffffff;
            color: #111111;
        }

        .activity-table-shell {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            background: #ffffff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 4px 14px rgba(15, 23, 42, 0.06);
        }

        table.activity-table {
            width: 100%;
            border-collapse: collapse;
        }

        table.activity-table thead th {
            background: #ffffff;
            color: #111111;
            text-align: left;
            padding: 12px 14px;
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            border-bottom: 2px solid #111111;
        }

        table.activity-table tbody td {
            padding: 12px 14px;
            font-size: 0.9rem;
            color: #111111;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        .category-badge {
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #111111;
        }

        .activity-empty {
            text-align: center;
            padding: 26px;
            font-weight: 600;
            color: #111111;
        }

        .activity-pagination {
            padding: 14px;
            border-top: 1px solid #e5e7eb;
        }

        .pagination-wrapper {
            display: flex;
            justify-content: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .pagination-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 8px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            color: #111111;
            text-decoration: none;
            background: #ffffff;
            transition: border-color 0.15s ease, background 0.15s ease;
        }

        a.pagination-btn:hover {
            border-color: #111111;
            background: #fef3c7;
        }

        .pagination-btn.active {
            background: #facc15;
            border-color: #facc15;
            color: #111111;
        }

        .pagination-btn.disabled {
            color: #9ca3af;
            cursor: default;
        }
    </style>
@endpush

@section('content')
    <div class="reports-page">

        <h1>Business Activity</h1>

        <div class="reports-tabs">
            <a href="{{ route('superadmin.reports.index') }}">Summary</a>
            <a href="{{ route('superadmin.reports.activity', request()->except('page')) }}" class="active">Activity Log</a>
        </div>

        <form class="activity-toolbar" method="GET" action="{{ route('superadmin.reports.activity') }}">
            <div class="filter-row">
                <div class="date-range-picker" data-range-picker>
                    <input type="date" name="from" data-role="from" value="{{ $fromInput }}"
                        onchange="this.form.submit()">
                    <input type="date" name="to" data-role="to" value="{{ $toInput }}"
                        onchange="this.form.submit()">
                </div>

                <input type="text" name="search" value="{{ $search }}"
                    placeholder="Search description or reference" onchange="this.form.submit()">

                <select name="entity_type" data-custom onchange="this.form.submit()">
                    <option value="">All records</option>
                    @foreach ($entityTypes as $type)
                        <option value="{{ $type }}" {{ $entityType === $type ? 'selected' : '' }}>
                            {{ $type }}</option>
                    @endforeach
                </select>

                <select name="user_id" data-custom onchange="this.form.submit()">
                    <option value="">All users</option>
                    @foreach ($actors as $actor)
                        <option value="{{ $actor->id }}"
                            {{ (string) $actorId === (string) $actor->id ? 'selected' : '' }}>
                            {{ $actor->full_name }}
                        </option>
                    @endforeach
                </select>

                <a href="{{ route('superadmin.reports.activity') }}" class="clear-link">Clear</a>
            </div>
        </form>

        <div class="activity-table-shell">
            <table class="activity-table">
                <thead>
                    <tr>
                        <th style="width:14%;">Date / Time</th>
                        <th style="width:14%;">User</th>
                        <th style="width:12%;">Action</th>
                        <th style="width:16%;">Record</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $row)
                        @php $log = $row->log; @endphp
                        <tr>
                            <td>{{ $log->created_at?->format('M j, Y') }}<br><span
                                    style="color:#374151;">{{ $log->created_at?->format('g:i A') }}</span></td>
                            <td>{{ $log->user->full_name ?? 'System' }}</td>
                            <td>
                                <span class="category-badge {{ $log->category }}">
                                    {{ $categories[$log->category] ?? ucfirst(str_replace('_', ' ', (string) $log->category)) }}
                                </span>
                            </td>
                            <td>{{ $log->entity_type }}{{ $log->reference ? ' - ' . $log->reference : '' }}</td>
                            <td>{{ $row->description }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="activity-empty">No activity recorded for this range.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($logs->hasPages())
                <div class="activity-pagination">
                    {{ $logs->onEachSide(0)->links('vendor.pagination.custom') }}
                </div>
            @endif
        </div>

    </div>
@endsection
