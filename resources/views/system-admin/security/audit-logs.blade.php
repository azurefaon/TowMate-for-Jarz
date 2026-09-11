@extends('layouts.system-admin')

@section('title', 'Audit Logs')
@section('subtitle', 'Technical and security event history')

@section('content')
    <div class="sa-panel">
        <form method="GET" action="{{ route('system-admin.audit-logs.index') }}" class="sa-filters">
            <div class="sa-field">
                <label for="saLogSearch">Search</label>
                <input type="text" id="saLogSearch" name="search" value="{{ $search }}" placeholder="Description or reference">
            </div>
            <div class="sa-field">
                <label for="saLogCategory">Category</label>
                <select id="saLogCategory" name="category">
                    <option value="">All categories</option>
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}" @selected($category === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sa-field">
                <label for="saLogUser">User</label>
                <select id="saLogUser" name="user_id">
                    <option value="">All users</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}" @selected((string) $userId === (string) $u->id)>{{ $u->full_name ?: $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sa-field">
                <label for="saLogFrom">From</label>
                <input type="date" id="saLogFrom" name="from" value="{{ $fromInput }}">
            </div>
            <div class="sa-field">
                <label for="saLogTo">To</label>
                <input type="date" id="saLogTo" name="to" value="{{ $toInput }}">
            </div>
            <div class="sa-btn-row">
                <button type="submit" class="sa-btn sa-btn-primary">Apply</button>
                <a href="{{ route('system-admin.audit-logs.index') }}" class="sa-btn">Clear</a>
            </div>
        </form>

        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Category</th>
                        <th>Activity</th>
                        <th>User</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="sa-muted">{{ $log->created_at->format('M d, Y g:i A') }}</td>
                            <td>{{ $categories[$log->category] ?? ucfirst($log->category ?? 'System') }}</td>
                            <td>{{ $log->activity_label }}</td>
                            <td>{{ $log->user?->full_name ?: ($log->user?->name ?: 'System') }}</td>
                            <td class="sa-muted">{{ $log->description ?: ($log->reference ?: '—') }}</td>
                            <td class="sa-muted">{{ $log->ip_address ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="sa-table-empty">No audit log entries match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="sa-pagination">
            {{ $logs->links('vendor.pagination.owner-standard') }}
        </div>
    </div>
@endsection
