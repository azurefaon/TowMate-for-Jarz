@extends('layouts.superadmin')

@section('content')
    <link rel="stylesheet" href="{{ asset('admin/css/audit-logs.css') }}">

    <div class="audit-page-top">

        <div>
            <h1>Audit Logs</h1>
            <p>Monitor all system activities and security events</p>
        </div>

    </div>


    <div class="audit-card-container">

        <div class="audit-card">
            <h3>Total Logs Today</h3>
            <p>{{ $totalLogs }}</p>
        </div>

        <div class="audit-card">
            <h3>Failed Login Attempts</h3>
            <p class="audit-red">{{ $failedLogins }}</p>
        </div>

        <div class="audit-card">
            <h3>Job Actions Logged</h3>
            <p class="audit-blue">{{ $jobActions }}</p>
        </div>

        <div class="audit-card">
            <h3>System Changes</h3>
            <p class="audit-purple">{{ $systemChanges }}</p>
        </div>

    </div>


    <div class="audit-table-card">

        <div class="audit-table-header">

            <h3>Filter Audit Logs</h3>

            <form method="GET">

                <input type="text" name="search" placeholder="Search by user name, action, or record ID..."
                    value="{{ request('search') }}">

                <button type="submit" class="audit-search-btn">
                    Search
                </button>

            </form>

        </div>


        <table class="audit-table">

            <thead>

                <tr>
                    <th>User</th>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>Record ID</th>
                    <th>Date</th>
                </tr>

            </thead>

            <tbody>

                @foreach ($logs as $log)
                    <tr>

                        <td>{{ $log->user->name ?? 'System' }}</td>

                        <td>{{ $log->action }}</td>

                        <td>{{ $log->entity_type }}</td>

                        <td>{{ $log->entity_id }}</td>

                        <td>{{ $log->created_at->format('M d, Y H:i') }}</td>

                    </tr>
                @endforeach

            </tbody>

        </table>


        <div class="audit-pagination">
            {{ $logs->onEachSide(1)->links() }}
        </div>

    </div>
@endsection
