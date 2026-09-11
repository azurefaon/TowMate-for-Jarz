@extends('layouts.system-admin')

@section('title', 'Security Monitor')
@section('subtitle', 'Login lockouts, failed attempts, and account recovery activity')

@section('content')
    <div class="sa-summary-row">
        <div class="sa-summary-tile">
            <strong>{{ $lockedStaffCount }}</strong>
            <span>Temporarily Locked Staff</span>
        </div>
        <div class="sa-summary-tile">
            <strong>{{ $failedLoginsToday }}</strong>
            <span>Failed Login Events Today</span>
        </div>
        <div class="sa-summary-tile">
            <strong>{{ $recoveriesTotal }}</strong>
            <span>Successful Account Recoveries</span>
        </div>
        <div class="sa-summary-tile">
            <strong>{{ $securityEventsToday }}</strong>
            <span>Security Events Today</span>
        </div>
    </div>

    @if ($lockedStaff->isNotEmpty())
        <div class="sa-panel">
            <div class="sa-panel-head">
                <h3>Currently Locked Accounts</h3>
                <p>Staff accounts temporarily locked after 3 consecutive failed sign-in attempts.</p>
            </div>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Role</th>
                            <th>Failed Attempts</th>
                            <th>Locked Until</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lockedStaff as $staff)
                            <tr>
                                <td>{{ $staff->full_name ?: $staff->name }} <span class="sa-muted">({{ $staff->email }})</span></td>
                                <td>{{ ($staff->role->name ?? '—') === 'Admin' ? 'Dispatcher' : ($staff->role->name ?? '—') }}</td>
                                <td>{{ $staff->failed_login_attempts }}</td>
                                <td class="sa-text-danger">{{ $staff->locked_until->format('M d, Y g:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="sa-panel">
        <div class="sa-panel-head">
            <h3>Security Events</h3>
            <p>Failed logins, temporary locks, and OTP recovery activity.</p>
        </div>
        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Event</th>
                        <th>Account</th>
                        <th>Role</th>
                        <th>IP Address</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr>
                            <td class="sa-muted">{{ $event->created_at->format('M d, Y g:i A') }}</td>
                            <td>{{ ucwords(str_replace('_', ' ', $event->action)) }}</td>
                            <td>{{ $event->user?->full_name ?: ($event->user?->name ?: ($event->reference ?: '—')) }}</td>
                            <td>{{ ($event->user?->role?->name ?? '—') === 'Admin' ? 'Dispatcher' : ($event->user?->role?->name ?? '—') }}</td>
                            <td class="sa-muted">{{ $event->ip_address ?: '—' }}</td>
                            <td class="sa-muted">{{ $event->description ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="sa-table-empty">No security events recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="sa-pagination">
            {{ $events->links('vendor.pagination.owner-standard') }}
        </div>
    </div>
@endsection
