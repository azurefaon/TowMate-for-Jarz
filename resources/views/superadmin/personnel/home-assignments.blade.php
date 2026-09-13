@extends('layouts.superadmin')

@section('title', 'Home Assignments')

@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="{{ asset('admin/css/personnel.css') }}?v={{ filemtime(public_path('admin/css/personnel.css')) }}">
@endpush

@section('content')
    <div class="personnel-page">
        <div class="page-top">
            <div>
                <h1>Home Assignments</h1>
                <p>Normal/default roster composition per Unit. This is master configuration, not the Dispatcher's live operational view.</p>
            </div>
        </div>

        @include('superadmin.fleet._tabs')

        @if ($roster->isEmpty())
            <div class="table-card">
                <div class="empty-row">
                    <span class="empty-row-title">No units yet</span>
                    <span class="empty-row-hint">Add a Unit under Trucks to start assigning a Home roster.</span>
                </div>
            </div>
        @else
            <div class="roster-grid">
                @foreach ($roster as $entry)
                    <div class="roster-card">
                        <h3>{{ $entry['unit']->name }}</h3>

                        <div class="roster-row">
                            <span class="roster-row-label">Team Leader</span>
                            <span class="roster-row-value">
                                {{ $entry['team_leader']->full_name ?? '' }}
                                @if (! $entry['team_leader'])
                                    <span class="roster-empty">Not set</span>
                                @endif
                            </span>
                        </div>

                        <div class="roster-row">
                            <span class="roster-row-label">Driver</span>
                            <span class="roster-row-value">
                                {{ $entry['driver']->full_name ?? '' }}
                                @if (! $entry['driver'])
                                    <span class="roster-empty">Not set</span>
                                @endif
                            </span>
                        </div>

                        <div class="roster-row">
                            <span class="roster-row-label">Pahinante</span>
                            <span class="roster-row-value">
                                @if (count($entry['crew']) > 0)
                                    {{ implode(', ', $entry['crew']) }}
                                @else
                                    <span class="roster-empty">Not set</span>
                                @endif
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
