@extends('layouts.superadmin')

@section('title', 'Audit Logs')

@push('styles')
    <style>
        .audit-shell {
            display: grid;
            gap: 18px;
        }

        .audit-hero,
        .audit-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.05);
        }

        .audit-hero h1,
        .audit-card h3 {
            margin: 0 0 6px;
        }

        .audit-hero p {
            margin: 0;
            color: #64748b;
        }

        .audit-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
        }

        .audit-stat {
            border-radius: 16px;
            padding: 16px;
            background: linear-gradient(135deg, #fffbe6 0%, #ffffff 100%);
            border: 1px solid #fde68a;
        }

        .audit-stat small {
            display: block;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .audit-stat strong {
            font-size: 28px;
            color: #111827;
        }

        .audit-table-wrap {
            overflow-x: auto;
        }

        .audit-table {
            width: 100%;
            border-collapse: collapse;
        }

        .audit-table th,
        .audit-table td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        .audit-table th {
            background: #f8fafc;
            font-size: 12px;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: #64748b;
        }

        .audit-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            background: #f3f4f6;
            color: #374151;
        }

        .audit-empty {
            padding: 28px 16px;
            text-align: center;
            color: #64748b;
        }

        .audit-muted {
            color: #6b7280;
            font-size: 13px;
        }
    </style>
@endpush

@section('content')
    <div class="audit-shell">
        <section class="audit-hero">
            <h1>Audit Logs</h1>
            <p>Review system activity, booking-related events, and operational changes in one place.</p>
        </section>

        <section class="audit-stats">
            <div class="audit-stat">
                <small>Total Logs</small>
                <strong>{{ $totalLogs }}</strong>
            </div>
            <div class="audit-stat">
                <small>Failed Logins</small>
                <strong>{{ $failedLogins }}</strong>
            </div>
            <div class="audit-stat">
                <small>Booking Actions</small>
                <strong>{{ $jobActions }}</strong>
            </div>
            <div class="audit-stat">
                <small>System Changes</small>
                <strong>{{ $systemChanges }}</strong>
            </div>
        </section>

        <section class="audit-card">
            <div style="margin-bottom: 12px;">
                <h3>Recent Activity</h3>
                <p class="audit-muted">Newest audit entries across the platform.</p>
            </div>

            <div class="audit-table-wrap">
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td>
                                    <strong>{{ optional($log->user)->full_name ?: (optional($log->user)->name ?: 'System') }}</strong>
                                </td>
                                <td>
                                    <span class="audit-badge">{{ str($log->action)->replace('_', ' ')->title() }}</span>
                                </td>
                                <td>
                                    {{ $log->entity_type ?: 'General' }}
                                    @if ($log->entity_id)
                                        <div class="audit-muted">#{{ $log->entity_id }}</div>
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ optional($log->created_at)->format('M d, Y') }}</strong>
                                    <div class="audit-muted">{{ optional($log->created_at)->format('g:i A') }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="audit-empty">No audit logs available yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 16px;">
                {{ $logs->links('vendor.pagination.custom') }}
            </div>
        </section>
    </div>
@endsection
