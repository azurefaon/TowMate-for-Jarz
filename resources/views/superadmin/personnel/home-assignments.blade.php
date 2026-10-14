@extends('layouts.superadmin')

@section('title', 'Home Assignments')

@push('styles')
    {{-- Shared fleet page styles (header, table, modals, form fields). --}}
    <link rel="stylesheet" href="{{ asset('superadmin/css/truck-types.css') }}?v={{ filemtime(public_path('superadmin/css/truck-types.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/home-assignments.css') }}?v={{ filemtime(public_path('superadmin/css/home-assignments.css')) }}">
@endpush

@php
    $plateLabel = fn ($plate) => preg_replace('/^([A-Z]+)(\d+)$/', '$1 $2', strtoupper((string) $plate));

    // Everyone who can have a home unit, for the Edit Home Team dialog.
    $peopleData = $people->map(fn ($person) => [
        'key' => $person['key'],
        'name' => $person['name'],
        'role' => $person['role'],
        'home' => $person['home_unit_id'],
        'current' => $person['current_unit_id'],
        'active' => $person['active'],
        'url' => $person['type'] === 'account'
            ? route('superadmin.personnel.home-unit', $person['id'])
            : route('superadmin.personnel-records.home-unit', $person['id']),
    ])->values();
    $unitNames = $units->mapWithKeys(fn ($unit) => [$unit->id => $unit->name]);
@endphp

@section('content')
    <div class="tt-page ha-page" id="homeAssignmentsPage" data-index-url="{{ route('superadmin.home-assignments.index') }}"
        data-save-url="{{ route('superadmin.home-assignments.update', '__ID__') }}">
        <header class="tt-header">
            <div>
                <h1>Home Assignments</h1>
                <p>The usual team for each unit. Dispatchers can change today's team in Units &amp; Leaders without changing anyone's home unit.</p>
            </div>
        </header>

        <div class="tt-flash tt-flash--success" role="status" data-save-summary hidden>
            <i data-lucide="circle-check" aria-hidden="true"></i>
            <span></span>
        </div>

        <form class="tt-filters" id="homeFilters" method="GET" action="{{ route('superadmin.home-assignments.index') }}" role="search">
            <label class="tt-search">
                <span class="tt-sr">Search units and people</span>
                <i data-lucide="search" aria-hidden="true"></i>
                <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search unit or person" autocomplete="off">
            </label>

            <label class="tt-select ha-select">
                <span class="tt-sr">Show</span>
                <select name="show">
                    <option value="" @selected($filters['show'] === '')>All units ({{ $searchedCount }})</option>
                    <option value="missing" @selected($filters['show'] === 'missing')>Missing team leader or driver ({{ $missingCount }})</option>
                </select>
                <i data-lucide="chevron-down" aria-hidden="true"></i>
            </label>

            <noscript><button type="submit" class="tt-btn">Apply</button></noscript>
        </form>

        <div class="tt-card" id="homeResults" aria-live="polite">
            {{-- Option counts depend on the search, so they travel with the results. --}}
            <template data-show-options>
                <option value="">All units ({{ $searchedCount }})</option>
                <option value="missing">Missing team leader or driver ({{ $missingCount }})</option>
            </template>

            @if ($roster->isEmpty())
                <div class="tt-state">
                    @if ($hasFilters)
                        @php $allComplete = $filters['show'] === 'missing' && $filters['search'] === ''; @endphp
                        <span class="tt-state-icon"><i data-lucide="{{ $allComplete ? 'circle-check' : 'search-x' }}" aria-hidden="true"></i></span>
                        <p class="tt-state-title">{{ $allComplete ? 'Every unit has a team leader and a driver' : 'No units match these filters' }}</p>
                        <p class="tt-state-text">{{ $allComplete ? 'Nothing is missing right now.' : 'Try a different unit, plate, or name.' }}</p>
                        <a href="{{ route('superadmin.home-assignments.index') }}" class="tt-btn" data-clear-filters>Clear filters</a>
                    @else
                        <span class="tt-state-icon"><i data-lucide="truck" aria-hidden="true"></i></span>
                        <p class="tt-state-title">No units yet</p>
                        <p class="tt-state-text">Add a truck under Trucks to give it a home team.</p>
                        <a href="{{ route('superadmin.unit-truck.index') }}" class="tt-btn">Go to Trucks</a>
                    @endif
                </div>
            @else
                <div class="tt-table-wrap">
                    <table class="tt-table ha-table">
                        <caption class="tt-sr">Home team for each unit</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="ha-col-unit">Unit</th>
                                <th scope="col">Team leaders</th>
                                <th scope="col">Drivers</th>
                                <th scope="col">Crew members</th>
                                <th scope="col" class="tt-col-actions"><span class="tt-sr">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($roster as $entry)
                                @php $unit = $entry['unit']; @endphp
                                <tr>
                                    <th scope="row" class="ha-col-unit">
                                        <span class="tt-main">{{ $unit->name }}</span>
                                        <span class="tt-sub">{{ $plateLabel($unit->plate_number) }}{{ $unit->truckType ? ' · ' . $unit->truckType->name : '' }}</span>
                                    </th>
                                    @foreach (['team_leaders' => ['has_active_leader', 'team leader'], 'drivers' => ['has_active_driver', 'driver']] as $key => [$flag, $noun])
                                        <td>
                                            @foreach ($entry[$key] as $person)
                                                <span class="ha-person">{{ $person['name'] }}@unless ($person['active'])<span class="ha-tag">Inactive</span>@endunless</span>
                                            @endforeach
                                            @if ($entry[$key]->isEmpty())
                                                <span class="ha-missing"><i data-lucide="circle-alert" aria-hidden="true"></i>Not set</span>
                                            @elseif (! $entry[$flag])
                                                <span class="ha-missing"><i data-lucide="circle-alert" aria-hidden="true"></i>No active {{ $noun }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td>
                                        @forelse ($entry['crew'] as $person)
                                            <span class="ha-person">{{ $person['name'] }}@unless ($person['active'])<span class="ha-tag">Inactive</span>@endunless</span>
                                        @empty
                                            <span class="tt-muted">None</span>
                                        @endforelse
                                    </td>
                                    <td class="tt-col-actions">
                                        <button type="button" class="tt-btn tt-btn--sm" data-edit-team aria-haspopup="dialog"
                                            data-unit-id="{{ $unit->id }}" data-unit-name="{{ $unit->name }}"
                                            data-team-token="{{ $teamTokens[$unit->id] ?? '' }}"
                                            aria-label="Edit home team for {{ $unit->name }}">Edit</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <footer class="tt-footer">
                    <p class="tt-count">Showing {{ $roster->count() }} of {{ $totalUnits }} {{ $totalUnits === 1 ? 'unit' : 'units' }}</p>
                </footer>
            @endif
        </div>
    </div>

    {{-- Edit home team --}}
    <div class="tt-modal ha-modal" id="teamModal" hidden>
        <div class="tt-modal-backdrop" data-close-modal></div>
        <div class="tt-dialog ha-dialog" role="dialog" aria-modal="true" aria-labelledby="haTitle" aria-describedby="haDesc">
            <header class="tt-dialog-head">
                <div>
                    <h2 id="haTitle">Edit home team</h2>
                    <p id="haDesc">Choose who normally works on this unit. Each person has one home unit, so choosing someone moves their home unit here.</p>
                </div>
                <button type="button" class="tt-icon-btn tt-dialog-close" data-close-modal aria-label="Close">
                    <i data-lucide="x" aria-hidden="true"></i>
                </button>
            </header>

            <div class="tt-dialog-body">
                <div class="ha-result" role="alert" tabindex="-1" data-result hidden></div>

                <label class="tt-search ha-filter">
                    <span class="tt-sr">Find a person</span>
                    <i data-lucide="search" aria-hidden="true"></i>
                    <input type="search" data-person-filter placeholder="Find a person" autocomplete="off">
                </label>

                @foreach (['team_leader' => ['Team leaders', 'Needed for dispatch. A team leader leads their home unit: adding one moves them onto this unit, and removing one takes them off it. A team leader on another truck today can’t be changed here until a dispatcher returns them.'], 'driver' => ['Drivers', 'Needed for dispatch.'], 'crew' => ['Crew members', 'Optional.']] as $role => [$legend, $help])
                    <fieldset class="tt-section ha-group" data-role-group="{{ $role }}" aria-describedby="haHelp{{ $role }}">
                        <legend>{{ $legend }}</legend>
                        <p class="tt-help" id="haHelp{{ $role }}">{{ $help }}</p>
                        <ul class="ha-options" data-options></ul>
                        <p class="tt-error" data-role-error hidden></p>
                    </fieldset>
                @endforeach
            </div>

            <footer class="tt-dialog-foot ha-foot">
                <p class="ha-pending" data-pending aria-live="polite"></p>
                <button type="button" class="tt-btn" data-close-modal>Cancel</button>
                <button type="button" class="tt-btn tt-btn--primary" data-save>Save</button>
            </footer>
        </div>
    </div>

    <script type="application/json" id="homeTeamData">@json(['people' => $peopleData, 'units' => $unitNames])</script>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/home-assignments.js') }}?v={{ filemtime(public_path('superadmin/js/home-assignments.js')) }}" defer></script>
@endpush
