<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Services\UnitAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    private const PLATE_NUMBER_REGEX = '/^(?:[A-HJ-NP-Z]{3}[0-9]{4}|[A-HJ-NP-Z]{3}[0-9]{3}|[A-HJ-NP-Z]{2}[0-9]{5})$/';
    private const PLATE_NUMBER_ERROR = 'Plate number must be 3 letters + 4 digits, 3 letters + 3 digits, or 2 letters + 5 digits, without the letters I or O.';

    protected function normalizePlateNumber(?string $plateNumber): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string) $plateNumber)));
    }

    /** Owner-facing readiness states, in the same precedence as the dispatcher's Units & Leaders page. */
    public const READINESS = [
        'available' => 'Available',
        'on_job' => 'On job',
        'reserved' => 'Reserved',
        'maintenance' => 'Maintenance',
        'not_ready' => 'Not ready',
    ];

    /** Plain-language labels for the UnitAvailabilityService reason codes that block readiness. */
    protected const READINESS_REASONS = [
        'no_team_leader' => 'No team leader',
        'team_leader_inactive' => "Team leader's account is inactive",
        'team_leader_duty_unavailable' => 'Team leader off duty',
        'no_driver' => 'No driver',
        'driver_inactive' => 'Driver is inactive',
        'driver_duty_unavailable' => 'Driver off duty',
    ];

    public function index(Request $request, UnitAvailabilityService $availability)
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => array_key_exists((string) $request->query('status'), self::READINESS) ? (string) $request->query('status') : '',
        ];

        $units = Unit::with(['teamLeader.role', 'driver', 'truckType'])
            ->whereNull('archived_at')
            ->when($filters['search'] !== '', fn ($query) => $this->applySearch($query, $filters['search']))
            ->latest()
            ->orderByDesc('id')
            ->get();

        // Readiness comes from the shared availability service, so the status
        // filter and its counts are applied after evaluation.
        $rows = $availability->evaluateMany($units)
            ->map(fn (array $row) => $this->presentReadiness($row))
            ->values();

        $filtered = $filters['status'] !== '' ? $rows->where('state', $filters['status'])->values() : $rows;

        $perPage = 10;
        $page = max(1, (int) $request->query('page', 1));
        $trucks = new LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // A filter change or archive can leave the requested page past the end.
        if ($trucks->isEmpty() && $page > 1) {
            return redirect($trucks->url(max(1, $trucks->lastPage())));
        }

        return view('superadmin.unit-truck.index', [
            'trucks' => $trucks,
            'filters' => $filters,
            'hasFilters' => $filters['search'] !== '' || $filters['status'] !== '',
            'statusCounts' => $rows->countBy('state'),
            'statusTotal' => $rows->count(),
            'readiness' => self::READINESS,
            'truckTypes' => TruckType::where('status', 'active')->orderBy('name')->get(),
            'nextUnitName' => $this->nextUnitName(),
            'archivedCount' => Unit::whereNotNull('archived_at')->count(),
        ]);
    }

    /** Case-insensitive match on unit name, plate (spaces ignored), or team leader name. */
    protected function applySearch($query, string $search): void
    {
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $plateLike = '%' . addcslashes(preg_replace('/\s+/', '', $search), '%_\\') . '%';

        $query->where(fn ($q) => $q
            ->where('name', 'ilike', $like)
            ->orWhere('plate_number', 'ilike', $plateLike)
            ->orWhereHas('teamLeader', fn ($leader) => $leader->where(fn ($l) => $l
                ->where('name', 'ilike', $like)
                ->orWhereRaw("concat_ws(' ', first_name, middle_name, last_name) ilike ?", [$like])
                ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$like]))));
    }

    /**
     * Turns one UnitAvailabilityService row into what the owner sees. Only the
     * service's own reason codes are used; nothing here adds a condition.
     */
    protected function presentReadiness(array $row): array
    {
        $unit = $row['unit'];
        $reasons = collect($row['reasons']);
        $blockers = $reasons
            ->filter(fn ($code) => isset(self::READINESS_REASONS[$code]))
            ->map(fn ($code) => self::READINESS_REASONS[$code])
            ->values();

        $detail = null;
        $also = $blockers;

        if ($reasons->contains('maintenance')) {
            $state = 'maintenance';
            $detail = $unit->issue_note ?: null;
        } elseif ($reasons->contains('busy')) {
            $state = 'on_job';
            $detail = $row['active_booking'] ? 'Booking ' . $row['active_booking']->job_code : 'Team leader is on another job';
        } elseif ($reasons->contains('reserved')) {
            $state = 'reserved';
            $detail = 'Held for booking ' . $row['reservation']->job_code;
        } elseif ($row['available']) {
            $state = 'available';
        } else {
            $state = 'not_ready';
            $detail = $blockers->map(fn ($text, $i) => $i === 0 ? $text : lcfirst($text))->join(', ');
            $also = collect();
        }

        $leader = $unit->teamLeader;
        $hasLeader = ! $reasons->contains('no_team_leader');

        return [
            'unit' => $unit,
            'state' => $state,
            'label' => self::READINESS[$state],
            'detail' => $detail,
            'also' => $also->isNotEmpty() ? 'Also: ' . $also->map(fn ($text) => lcfirst($text))->join(', ') : null,
            'leader' => $hasLeader ? ($leader?->full_name ?: $leader?->name) : null,
            // A linked account that is no longer an active team leader.
            'leader_record' => ! $hasLeader && $leader ? ($leader->full_name ?: $leader->name) : null,
            'leader_presence' => $hasLeader ? $row['presence'] : null,
            'leader_duty' => $row['team_leader_duty'],
            'leader_inactive' => $reasons->contains('team_leader_inactive'),
            'driver' => ($unit->driver?->full_name ?: $unit->driver_name) ?: null,
            'driver_duty' => $row['driver_duty'],
            'driver_inactive' => $reasons->contains('driver_inactive'),
            'driver_2' => $unit->driver_2_name ?: null,
            'crew' => array_values(array_filter([$unit->crew_member_1_name, $unit->crew_member_2_name], 'filled')),
            // Legacy names with no Personnel record behind them.
            'unlinked' => collect(Unit::SLOT_COLUMNS)
                ->filter(fn ($column, $slot) => $unit->slotIsLegacy($slot))
                ->map(fn ($column) => $unit->{$column})
                ->values()
                ->all(),
            'locked_reason' => $this->lockedReason($row),
        ];
    }

    /**
     * Mirrors the toggle()/archive() guards so the menu never offers what the
     * server refuses. The server re-checks live bookings before acting.
     */
    protected function lockedReason(array $row): ?string
    {
        $unit = $row['unit'];
        $job = $row['active_booking'];

        if ($job && app(UnitAvailabilityService::class)->bookingHoldsUnit($job, $unit)) {
            return "It is on booking {$job->job_code}.";
        }

        if ($row['reservation']) {
            return "Booking {$row['reservation']->job_code} has it reserved.";
        }

        return $unit->status === 'on_job' ? 'It is assigned to an active job.' : null;
    }

    public function archived(Request $request)
    {
        $archivedUnits = Unit::with(['teamLeader.role', 'truckType'])
            ->whereNotNull('archived_at')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(fn($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('plate_number', 'like', "%{$search}%"));
            })
            ->latest('archived_at')
            ->paginate(10)
            ->withQueryString();

        return view('superadmin.unit-truck.archived', compact('archivedUnits'));
    }

    protected function nextUnitName(): string
    {
        $prefix = 'JARZ';

        $max = Unit::where('name', 'like', $prefix . ' %')
            ->get(['name'])
            ->map(fn($u) => (int) trim(str_replace($prefix, '', $u->name)))
            ->max();

        return $prefix . ' ' . (($max ?? 0) + 1);
    }

    public function store(Request $request)
    {
        $validated = $this->validateTruck($request, [
            'plate_number' => ['required', 'string', 'max:50', 'regex:' . self::PLATE_NUMBER_REGEX, 'unique:units,plate_number'],
            'truck_type_id' => 'required|exists:truck_types,id',
            'status' => 'nullable|in:available,maintenance',
            'issue_note' => 'nullable|string|max:500',
        ]);

        $validated['name'] = $this->nextUnitName();
        $validated['status'] = $validated['status'] ?? 'available';

        if ($validated['status'] !== 'maintenance') {
            $validated['issue_note'] = null;
        }

        $unit = Unit::create($validated);

        return $this->backToList()->with('success', "{$unit->name} added.");
    }

    public function update(Request $request, $id)
    {
        $unit = Unit::findOrFail($id);

        $validated = $this->validateTruck($request, [
            'plate_number' => ['required', 'string', 'max:50', 'regex:' . self::PLATE_NUMBER_REGEX, Rule::unique('units', 'plate_number')->ignore($unit->id)],
            'truck_type_id' => 'required|exists:truck_types,id',
        ]);

        $unit->update([
            'plate_number'  => $validated['plate_number'],
            'truck_type_id' => $validated['truck_type_id'],
        ]);

        return $this->backToList()->with('success', "{$unit->name} updated.");
    }

    /**
     * Validates a copy with the plate normalised (upper case, no spaces), so a
     * failed save sends back exactly what the owner typed.
     */
    protected function validateTruck(Request $request, array $rules): array
    {
        $data = $request->all();
        $data['plate_number'] = $this->normalizePlateNumber($request->input('plate_number'));

        return Validator::make($data, $rules, [
            'plate_number.required' => 'Enter a plate number.',
            'plate_number.regex' => self::PLATE_NUMBER_ERROR,
            'plate_number.unique' => 'Another truck already uses this plate number.',
            'truck_type_id.required' => 'Choose a truck type.',
            'truck_type_id.exists' => 'Choose a truck type from the list.',
        ])->validate();
    }

    /** Return to the list page the owner came from, keeping search, filter, and page. */
    protected function backToList(): RedirectResponse
    {
        $index = route('superadmin.unit-truck.index');
        $previous = url()->previous();

        return redirect(str_starts_with($previous, $index) && ! str_starts_with($previous, $index . '/') ? $previous : $index);
    }

    public function toggle($id, UnitAvailabilityService $availability)
    {
        return DB::transaction(function () use ($id, $availability) {
            $unit = Unit::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($unit->status === 'on_job') {
                return back()->with('error', 'This truck cannot be placed under maintenance while it is assigned to an active job.');
            }

            $nextStatus = $unit->status === 'maintenance' ? 'available' : 'maintenance';

            // Returning to service is never blocked; going into maintenance is
            // checked against live bookings right before the write.
            if ($nextStatus === 'maintenance' && $hold = $availability->bookingHoldingUnit($unit)) {
                return back()->with('error', $this->heldMessage($unit, $hold, 'go to maintenance'));
            }

            $unit->update([
                'status' => $nextStatus,
                'issue_note' => $nextStatus === 'available' ? null : $unit->issue_note,
            ]);

            return back()->with('success', $nextStatus === 'maintenance' ? "{$unit->name} moved to maintenance." : "{$unit->name} is back in service.");
        });
    }

    public function archive($id, UnitAvailabilityService $availability): RedirectResponse
    {
        return DB::transaction(function () use ($id, $availability) {
            $unit = Unit::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($unit->status === 'on_job') {
                return back()->with('error', 'Cannot archive a unit that is currently on a job.');
            }

            if ($hold = $availability->bookingHoldingUnit($unit)) {
                return back()->with('error', $this->heldMessage($unit, $hold, 'be archived'));
            }

            $unit->update(['archived_at' => now()]);

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'unit_archived',
                'entity_type' => 'Unit',
                'entity_id' => $unit->id,
                'reference' => $unit->name,
                'description' => "Moved unit {$unit->name} to archive.",
            ]);

            return $this->backToList()->with('success', "{$unit->name} moved to archived trucks.");
        });
    }

    /** @param array{type: string, booking: \App\Models\Booking} $hold */
    protected function heldMessage(Unit $unit, array $hold, string $action): string
    {
        return $hold['type'] === 'job'
            ? "{$unit->name} can't {$action} while it is on booking {$hold['booking']->job_code}."
            : "{$unit->name} can't {$action} while booking {$hold['booking']->job_code} has it reserved.";
    }

    public function restore($id): RedirectResponse
    {
        $unit = Unit::findOrFail($id);

        $unit->update(['archived_at' => null]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'unit_restored',
            'entity_type' => 'Unit',
            'entity_id' => $unit->id,
            'reference' => $unit->name,
            'description' => "Restored unit {$unit->name} from archive.",
        ]);

        return redirect()->route('superadmin.units.archived')
            ->with('success', 'Unit restored successfully.');
    }

    public function forceDelete($id): RedirectResponse
    {
        return DB::transaction(function () use ($id) {
            $unit = Unit::whereKey($id)->lockForUpdate()->firstOrFail();

            if (! $unit->archived_at) {
                return back()->with('error', 'Only archived units can be permanently deleted.');
            }

            $hasActiveBooking = Booking::where(function ($q) use ($unit) {
                    $q->where('assigned_unit_id', $unit->id)->orWhere('selected_unit_id', $unit->id);
                })
                ->whereNotIn('status', Booking::TERMINAL_STATUSES)
                ->exists();

            if ($hasActiveBooking) {
                return back()->with('error', 'This unit is still referenced by an active booking and cannot be permanently deleted.');
            }

            $hasActiveLoan = UnitCrewLoan::where(function ($q) use ($unit) {
                    $q->where('from_unit_id', $unit->id)->orWhere('to_unit_id', $unit->id);
                })
                ->whereNull('returned_at')
                ->exists();

            if ($hasActiveLoan) {
                return back()->with('error', 'This unit has an active crew transfer (borrowed or lent out). Return it first before deleting.');
            }

            $hasIncompleteAssignedSnapshot = Booking::where('assigned_unit_id', $unit->id)
                ->where(function ($q) {
                    $q->whereNull('assigned_unit_name')->orWhereNull('assigned_unit_plate_number');
                })
                ->exists();

            $hasIncompleteSelectedSnapshot = Booking::where('selected_unit_id', $unit->id)
                ->where(function ($q) {
                    $q->whereNull('selected_unit_name')->orWhereNull('selected_unit_plate_number');
                })
                ->exists();

            if ($hasIncompleteAssignedSnapshot || $hasIncompleteSelectedSnapshot) {
                return back()->with('error', 'This unit has historical bookings missing a saved name/plate snapshot and cannot be permanently deleted without losing history.');
            }

            $reference = $unit->name;
            $entityId = $unit->id;

            $unit->delete();

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'unit_permanently_deleted',
                'entity_type' => 'Unit',
                'entity_id' => $entityId,
                'reference' => $reference,
                'description' => "Permanently deleted unit {$reference} from archive.",
            ]);

            return redirect()->route('superadmin.units.archived')
                ->with('success', 'Unit permanently deleted.');
        });
    }

}
