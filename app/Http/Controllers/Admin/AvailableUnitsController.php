<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Unit;
use App\Services\UnitAvailabilityService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AvailableUnitsController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $unitsQuery = Unit::with(['truckType', 'driver.role', 'teamLeader.role']);

        if ($search !== '') {
            $unitsQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('plate_number', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhereHas('truckType', function ($truckTypeQuery) use ($search) {
                        $truckTypeQuery->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('teamLeader', function ($teamLeaderQuery) use ($search) {
                        $teamLeaderQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('full_name', 'like', "%{$search}%");
                    });
            });
        }

        $units = $unitsQuery
            ->orderByRaw("CASE 
                WHEN status = 'available' THEN 0
                WHEN status = 'on_job' THEN 1
                ELSE 2
            END")
            ->orderByRaw('CASE WHEN team_leader_id IS NULL THEN 1 ELSE 0 END')
            ->latest('updated_at')
            ->paginate(12)
            ->withQueryString();

        return view('admin-dashboard.pages.available-units', compact('units', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'plate_number' => 'required|string|max:50|unique:units,plate_number',
            'truck_type_id' => 'required|exists:truck_types,id',
            'status' => 'nullable|in:available,maintenance',
            'issue_note' => 'nullable|string|max:500',
        ]);

        $validated['plate_number'] = strtoupper(trim((string) $validated['plate_number']));
        $validated['status'] = $validated['status'] ?? 'available';

        if ($validated['status'] !== 'maintenance') {
            $validated['issue_note'] = null;
        }

        Unit::create($validated);

        return redirect()
            ->route('admin.available-units')
            ->with('success', 'Unit added successfully.');
    }

    /**
     * Dispatcher: take a truck out of service. Same protection as the Owner
     * toggle: the unit is locked, and an active job or a reservation (the
     * canonical UnitAvailabilityService rules, checked under the lock) refuses
     * the change. Nothing about the blocking booking is changed or released
     * here; the dispatcher resolves or reassigns it first.
     */
    public function markMaintenance(Request $request, Unit $unit, UnitAvailabilityService $availability)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'booking_id' => 'nullable|string',
        ]);

        $refuse = fn (string $message) => response()->json(['success' => false, 'message' => $message], 422);

        $outcome = DB::transaction(function () use ($unit, $validated, $availability, $refuse) {
            $locked = Unit::whereKey($unit->id)->lockForUpdate()->firstOrFail();

            if ($locked->archived_at) {
                return $refuse("{$locked->name} is archived, so its maintenance status cannot be changed.");
            }

            if ($locked->status === 'on_job') {
                return $refuse("{$locked->name} is currently on a job and cannot be marked for maintenance yet. Resolve or reassign the booking first.");
            }

            if ($hold = $availability->bookingHoldingUnit($locked)) {
                $code = $hold['booking']->job_code ?? $hold['booking']->booking_code;
                $why = $hold['type'] === 'job' ? "is on booking {$code}" : "is reserved by booking {$code}";

                return $refuse("{$locked->name} {$why}, so it cannot be marked for maintenance. Resolve or reassign that booking first, then try again.");
            }

            // Keep earlier notes; add the new reason beneath them with who and when.
            $reason = trim($validated['reason']);
            $previous = trim((string) $locked->issue_note);
            $entry = '[' . now()->format('Y-m-d H:i') . ' ' . (Auth::user()->name ?? 'Dispatcher') . '] ' . $reason;
            $note = $previous === '' ? $entry : (str_contains($previous, $reason) ? $previous : $previous . "\n" . $entry);

            $before = ['status' => $locked->status, 'issue_note' => $locked->issue_note];

            $locked->update(['status' => 'maintenance', 'issue_note' => $note]);

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'unit_marked_maintenance',
                'entity_type' => 'Unit',
                'entity_id' => $locked->id,
                'reference' => $locked->name,
                'description' => "{$locked->name} marked for maintenance by dispatcher: {$reason}",
                'old_value' => $before,
                'new_value' => ['status' => 'maintenance', 'issue_note' => $note],
            ]);

            Log::warning('Unit marked for maintenance from dispatch', [
                'unit_id' => $locked->id,
                'unit_name' => $locked->name,
                'reason' => $reason,
                'booking_id' => $validated['booking_id'] ?? null,
                'dispatcher_id' => Auth::id(),
            ]);

            return null;
        });

        return $outcome ?? response()->json([
            'success' => true,
            'message' => 'Unit marked for maintenance successfully.',
        ]);
    }
}
