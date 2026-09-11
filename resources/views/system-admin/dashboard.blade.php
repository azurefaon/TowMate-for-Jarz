@extends('layouts.system-admin')

@section('title', 'Dashboard')
@section('subtitle', 'System administration overview')

@section('content')
    <div class="sa-summary-row">
        <div class="sa-summary-tile">
            <strong>{{ $stats['active'] }}</strong>
            <span>Active Staff Accounts</span>
        </div>
        <div class="sa-summary-tile">
            <strong>{{ $stats['inactive'] }}</strong>
            <span>Inactive Staff Accounts</span>
        </div>
        <div class="sa-summary-tile">
            <strong>{{ $stats['archived'] }}</strong>
            <span>Archived Accounts</span>
        </div>
        <div class="sa-summary-tile">
            <strong>{{ $stats['locked'] }}</strong>
            <span>Temporarily Locked Accounts</span>
        </div>
    </div>

    <div class="sa-panel">
        <div class="sa-panel-head">
            <h3>Security Activity</h3>
            <p>Recent login and account-recovery events.</p>
        </div>
        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Event</th>
                        <th>Account</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($securityEvents as $event)
                        <tr>
                            <td class="sa-muted">{{ $event->created_at->format('M d, Y g:i A') }}</td>
                            <td>{{ ucwords(str_replace('_', ' ', $event->action)) }}</td>
                            <td>{{ $event->user?->full_name ?: ($event->user?->name ?: ($event->reference ?: '—')) }}</td>
                            <td class="sa-muted">{{ $event->ip_address ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="sa-table-empty">No security events recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="sa-panel">
        <div class="sa-panel-head">
            <h3>Recent Administration</h3>
            <p>Recent account creation, status, and lifecycle changes.</p>
        </div>
        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Event</th>
                        <th>Account</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentAdministration as $event)
                        <tr>
                            <td class="sa-muted">{{ $event->created_at->format('M d, Y g:i A') }}</td>
                            <td>{{ ucwords(str_replace('_', ' ', $event->action)) }}</td>
                            <td>{{ $event->reference ?: '—' }}</td>
                            <td class="sa-muted">{{ $event->user?->full_name ?: ($event->user?->name ?: 'System') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="sa-table-empty">No administration activity recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
