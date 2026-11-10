<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Finance\JobPersonnel;
use App\Models\Unit;
use App\Services\Finance\JobCollectionService;
use App\Services\Finance\JobCrewService;
use App\Services\Finance\FinanceException;
use App\Support\Finance\FinanceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Owner > Finance > Job Crew. Records who actually worked each completed job, per towing unit and performed role.
 * Supporting booking information is shown but never turned into a record automatically.
 */
class JobCrewController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public function __construct(private readonly JobCollectionService $collections, private readonly JobCrewService $crew)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize(FinanceAccess::VIEW);

        $week = $this->week($request);
        $unitId = $request->filled('unit_id') && ctype_digit((string) $request->query('unit_id')) ? (int) $request->query('unit_id') : null;
        $jobs = $this->collections->jobs($week, $week->addDays((int) config('finance.week_length_days') - 1), ['basis' => 'service', 'unit_id' => $unitId]);

        $rows = $jobs->map(function (array $job) {
            $current = JobPersonnel::current()->whereIn('booking_id', collect($job['members'])->pluck('id'))->get();
            $units = [];
            foreach ($this->crew->jobUnitIds($job) as $id) {
                $people = $current->where('unit_id', $id);
                $roles = $people->pluck('commission_role')->filter()->unique()->values()->all();
                $units[] = [
                    'unit_id' => $id,
                    'name' => Unit::find($id)?->name ?? "Unit #{$id}",
                    'people' => $people->count(),
                    'roles' => $roles,
                    'needs_review' => $people->where('needs_review', true)->count() + $people->whereNull('commission_role')->count(),
                ];
            }

            return ['job' => $job, 'units' => $units, 'settled' => $this->crew->settledStatements($job)->isNotEmpty()];
        });

        return view('superadmin.finance.crew.index', [
            'rows' => $rows,
            'week' => $week,
            'unitId' => $unitId,
            'units' => Unit::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(int $booking): View
    {
        $this->authorize(FinanceAccess::VIEW);
        $job = $this->collections->jobFor(Booking::findOrFail($booking));
        $records = $this->crew->records($job);

        return view('superadmin.finance.crew.show', [
            'job' => $job,
            'current' => $records->whereNull('superseded_at')->groupBy(fn ($r) => (int) $r->unit_id),
            'history' => $records->whereNotNull('superseded_at')->sortByDesc('superseded_at')->values(),
            'unitOptions' => Unit::whereIn('id', $this->crew->jobUnitIds($job))->orderBy('name')->pluck('name', 'id'),
            'allUnits' => Unit::orderBy('name')->pluck('name', 'id'),
            'participants' => $this->crew->participantOptions(),
            'supporting' => $this->crew->supportingInfo($job),
            'settled' => $this->crew->settledStatements($job),
            'canManage' => FinanceAccess::isOwner(request()->user()),
        ]);
    }

    public function store(Request $request, int $booking): RedirectResponse
    {
        $this->authorize(FinanceAccess::MANAGE_CREW);
        $data = $this->validated($request);

        try {
            $row = $this->crew->record($request->user(), Booking::findOrFail($booking), $data);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()])->withInput();
        }

        return back()->with('success', "Recorded {$row->name_snapshot} as {$row->roleLabel()}.");
    }

    public function correct(Request $request, int $entry): RedirectResponse
    {
        $this->authorize(FinanceAccess::MANAGE_CREW);
        $data = $this->validated($request) + $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $row = $this->crew->correct($request->user(), JobPersonnel::findOrFail($entry), $data, $data['reason']);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        return back()->with('success', "Corrected the crew record. {$row->name_snapshot} is now recorded as {$row->roleLabel()}; the earlier record stays in the history.");
    }

    public function remove(Request $request, int $entry): RedirectResponse
    {
        $this->authorize(FinanceAccess::MANAGE_CREW);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $row = $this->crew->remove($request->user(), JobPersonnel::findOrFail($entry), $data['reason']);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        return back()->with('success', "Removed {$row->name_snapshot} from this job. The record stays in the history.");
    }

    public function confirm(Request $request, int $entry): RedirectResponse
    {
        $this->authorize(FinanceAccess::MANAGE_CREW);

        try {
            $row = $this->crew->confirm($request->user(), JobPersonnel::findOrFail($entry));
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        return back()->with('success', "Confirmed {$row->name_snapshot} as {$row->roleLabel()}.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'participant' => ['required', 'string', 'regex:/^(personnel|user):\d+$/'],
            'commission_role' => ['required', Rule::in(array_keys(JobPersonnel::ROLE_LABELS))],
            'is_substitute' => ['nullable', 'boolean'],
            'borrowed_from_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function week(Request $request): CarbonImmutable
    {
        $tz = config('finance.timezone');
        $value = $request->query('week');
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $value, $tz)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
            } catch (\Throwable) {
            }
        }

        return CarbonImmutable::now($tz)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }
}
