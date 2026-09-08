@php
    $teamRows = [];

    $leaderName = $unit->teamLeader?->full_name ?? $unit->teamLeader?->name;
    $leaderInvalid = $unit->teamLeader && (
        ($unit->teamLeader->role?->name !== 'Team Leader')
        || $unit->teamLeader->archived_at
    );

    if ($leaderName) {
        $teamRows[] = ['name' => $leaderName, 'role' => 'Team Leader', 'warning' => $leaderInvalid];
    }

    if ($unit->driver_id) {
        $driverName = $unit->driver?->full_name ?? $unit->driver?->name;
        if ($driverName) {
            $teamRows[] = ['name' => $driverName, 'role' => 'Driver'];
        }
    } else {
        $driver1LoanIn = $loansInBySlot->get($unit->id . ':driver_1');
        $driver1LoanOut = $loansOutBySlot->get($unit->id . ':driver_1');

        if ($unit->driver_name) {
            $teamRows[] = [
                'name' => $unit->driver_name,
                'role' => 'Driver',
                'meta' => $driver1LoanIn ? 'Borrowed from ' . ($driver1LoanIn->fromUnit->name ?? '-') : null,
            ];
        } elseif ($driver1LoanOut) {
            $teamRows[] = ['name' => 'On Transfer', 'role' => 'Driver', 'muted' => true];
        }
    }

    $driver2LoanIn = $loansInBySlot->get($unit->id . ':driver_2');
    $driver2LoanOut = $loansOutBySlot->get($unit->id . ':driver_2');

    if ($unit->driver_2_name) {
        $teamRows[] = [
            'name' => $unit->driver_2_name,
            'role' => 'Driver',
            'meta' => $driver2LoanIn ? 'Borrowed from ' . ($driver2LoanIn->fromUnit->name ?? '-') : null,
        ];
    } elseif ($driver2LoanOut) {
        $teamRows[] = ['name' => 'On Transfer', 'role' => 'Driver', 'muted' => true];
    }

    foreach (['crew_member_1', 'crew_member_2'] as $slotKey) {
        $column = $slotKey . '_name';
        $loanIn = $loansInBySlot->get($unit->id . ':' . $slotKey);
        $loanOut = $loansOutBySlot->get($unit->id . ':' . $slotKey);

        if ($unit->{$column}) {
            $teamRows[] = [
                'name' => $unit->{$column},
                'role' => 'Crew',
                'meta' => $loanIn ? 'Borrowed from ' . ($loanIn->fromUnit->name ?? '-') : null,
            ];
        } elseif ($loanOut) {
            $teamRows[] = ['name' => 'On Transfer', 'role' => 'Crew', 'muted' => true];
        }
    }
@endphp

<div class="team-stack">
    @forelse ($teamRows as $row)
        <div class="team-row">
            <span class="team-name {{ ! empty($row['muted']) ? 'is-muted' : '' }}"
                @if (! empty($row['meta'])) title="{{ $row['meta'] }}" @endif>{{ $row['name'] }}</span>
            <span class="team-role">{{ $row['role'] }}</span>
            @if (! empty($row['warning']))
                <span class="team-warning" title="This person's account is not an active Team Leader.">&#9888;</span>
            @endif
        </div>
    @empty
        <span class="not-assigned">Unassigned</span>
    @endforelse
</div>
