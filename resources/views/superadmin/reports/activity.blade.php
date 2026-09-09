@extends('layouts.superadmin')

@section('title', 'Business Activity')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/business-activity.css') }}?v={{ filemtime(public_path('superadmin/css/business-activity.css')) }}">
@endpush

@section('content')
    <div class="ba-page">
        <div class="page-top">
            <div>
                <h1>Business Activity</h1>
                <p>Review important business actions across bookings, fleet, and operations.</p>
            </div>
        </div>

        <form method="GET" action="{{ route('superadmin.reports.activity') }}" class="ba-toolbar">
            <div class="ba-toolbar-left">
                <div class="ba-search">
                    <i data-lucide="search"></i>
                    <input type="text" name="search" value="{{ $search }}" placeholder="Search description or reference">
                </div>

                <select name="category" data-custom>
                    <option value="">All Activity</option>
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}" {{ $category === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="entity_type" data-custom>
                    <option value="">All Records</option>
                    @foreach ($entityTypes as $type)
                        <option value="{{ $type }}" {{ $entityType === $type ? 'selected' : '' }}>{{ trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $type)) }}</option>
                    @endforeach
                </select>

                <select name="user_id" data-custom>
                    <option value="">All Users</option>
                    @foreach ($actors as $actor)
                        <option value="{{ $actor->id }}" {{ (string) $actorId === (string) $actor->id ? 'selected' : '' }}>{{ $actor->full_name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ba-toolbar-right">
                <div class="ba-date">
                    <input type="date" name="from" value="{{ $fromInput }}">
                    <span class="ba-date-sep">–</span>
                    <input type="date" name="to" value="{{ $toInput }}">
                </div>

                <button type="submit" class="ba-apply-btn">Apply</button>

                @if ($category || $entityType || $actorId || $search || $fromInput || $toInput)
                    <a href="{{ route('superadmin.reports.activity') }}" class="ba-clear-link">Clear</a>
                @endif
            </div>
        </form>

        <div class="ba-section">
            <div class="ba-section-head">
                <h2>Business Activity</h2>
                <span class="ba-record-count">{{ number_format($logs->total()) }} {{ $logs->total() === 1 ? 'record' : 'records' }}</span>
            </div>

            <div class="ba-table-wrap">
                <table class="ba-table">
                    <colgroup>
                        <col style="width: 16%">
                        <col style="width: 20%">
                        <col style="width: 42%">
                        <col style="width: 22%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Activity</th>
                            <th>Details</th>
                            <th>Performed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td>
                                    <span class="ba-datetime-date">{{ $log->created_at?->format('M j, Y') }}</span>
                                    <span class="ba-datetime-time">{{ $log->created_at?->format('g:i A') }}</span>
                                </td>
                                <td>
                                    <span class="ba-cell-primary">{{ $log->activity_label }}</span>
                                    @if ($log->entity_label)
                                        <span class="ba-cell-secondary">{{ $log->entity_label }}{{ $log->reference ? ' · ' . $log->reference : '' }}</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="ba-changes">
                                        @foreach ($log->activity_changes as $change)
                                            <div class="ba-change">
                                                @if ($change['label'] === null)
                                                    <span class="ba-change-value">{{ $change['value'] }}</span>
                                                @elseif (array_key_exists('old', $change))
                                                    <span class="ba-change-label">{{ $change['label'] }}</span>
                                                    <div class="ba-change-diff">
                                                        <span class="ba-change-old">{{ $change['old'] }}</span>
                                                        <span class="ba-change-arrow">→</span>
                                                        <span class="ba-change-new">{{ $change['new'] }}</span>
                                                    </div>
                                                @else
                                                    <span class="ba-change-label">{{ $change['label'] }}</span>
                                                    <span class="ba-change-value">{{ $change['value'] }}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                                <td>
                                    <span class="ba-cell-primary">{{ $log->user->full_name ?? 'System' }}</span>
                                    @if ($log->user?->role?->name)
                                        <span class="ba-cell-secondary">{{ $log->user->role->name }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="ba-empty-row">
                                    No business activity found.
                                    <span class="ba-empty-hint">Try adjusting the selected filters or date range.</span>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="ba-pagination">
                {{ $logs->onEachSide(1)->links('vendor.pagination.owner-standard') }}
            </div>
        </div>
    </div>
@endsection
