<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\CommissionDeduction;
use App\Models\Unit;
use App\Services\Finance\CommissionCalculator;
use App\Services\Finance\CommissionDeductionService;
use App\Services\Finance\CommissionEligibility;
use App\Services\Finance\FinanceException;
use App\Support\Finance\FinanceAccess;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Owner > Finance > Commission (Draft). Shows the calculation for one Monday–Saturday week and why it cannot be
 * approved yet. Approval and payouts are not part of this page (Phases 6 and 7). Deductions are recorded here.
 */
class CommissionController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public function __construct(private readonly CommissionCalculator $calculator, private readonly CommissionDeductionService $deductions)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize(FinanceAccess::VIEW);

        $tz = config('finance.timezone');
        $week = null;
        if (is_string($request->query('week')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('week'))) {
            try {
                $week = CarbonImmutable::createFromFormat('Y-m-d', $request->query('week'), $tz)->startOfWeek(CarbonImmutable::MONDAY);
            } catch (\Throwable) {
                $week = null;
            }
        }
        $week ??= CarbonImmutable::now($tz)->startOfWeek(CarbonImmutable::MONDAY);
        $unitId = $request->filled('unit_id') && ctype_digit((string) $request->query('unit_id')) ? (int) $request->query('unit_id') : null;

        $draft = $this->calculator->draft($week, $unitId);
        $bookingIds = collect($draft['lines'])->pluck('booking_id')
            ->merge(collect($draft['blocked_jobs'])->map(fn ($b) => $b['job']['anchor']->id));

        return view('superadmin.finance.commission.index', [
            'draft' => $draft,
            'eligibility' => self::eligibility($draft['lines']),
            'week' => $week,
            'unitId' => $unitId,
            'units' => Unit::orderBy('name')->get(['id', 'name', 'archived_at']),
            'deductions' => CommissionDeduction::with(['booking:id,booking_code', 'unit:id,name'])
                ->whereIn('booking_id', $bookingIds->all())->orderByDesc('id')->get(),
            'deductionKey' => old('form') === 'deduction' ? old('idempotency_key', (string) Str::uuid()) : (string) Str::uuid(),
        ]);
    }

    public function storeDeduction(Request $request): RedirectResponse
    {
        $this->authorize(FinanceAccess::MANAGE_DEDUCTIONS);
        $data = $request->validate([
            'booking_code' => ['required', 'string', 'max:40'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'deduction_type' => ['required', Rule::in(array_keys(CommissionDeduction::TYPES))],
            'amount' => ['required', 'string', 'max:20'],
            'description' => ['required', 'string', 'max:2000'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $booking = \App\Models\Booking::where('booking_code', trim($data['booking_code']))->first();
        if (! $booking) {
            return back()->withErrors(['booking_code' => 'No job has that booking code.'])->withInput($request->except('_token') + ['form' => 'deduction']);
        }

        try {
            $deduction = $this->deductions->record($request->user(), ['booking_id' => $booking->id] + $data, $data['idempotency_key']);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()])->withInput($request->except('_token') + ['form' => 'deduction']);
        }

        return back()->with('success', "Recorded deduction {$deduction->reference_code}. It reduces the sharing base once you approve it.");
    }

    public function approveDeduction(Request $request, int $deduction): RedirectResponse
    {
        $this->authorize(FinanceAccess::MANAGE_DEDUCTIONS);
        $model = CommissionDeduction::findOrFail($deduction);

        try {
            $this->deductions->approve($request->user(), $model);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        return back()->with('success', "Approved deduction {$model->reference_code}.");
    }

    public function voidDeduction(Request $request, int $deduction): RedirectResponse
    {
        $this->authorize(FinanceAccess::MANAGE_DEDUCTIONS);
        $model = CommissionDeduction::findOrFail($deduction);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->deductions->void($request->user(), $model, $data['reason']);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        return back()->with('success', "Voided deduction {$model->reference_code}.");
    }

    /**
     * Eligible vs not-payable draft commission, per line, unit and person, read from the calculator's lines (no
     * recalculation). Only completed, fully paid jobs are eligible (same rule as Driver Records); other lines stay
     * visible with their state and their shares count only as a non-payable estimate.
     */
    public static function eligibility(array $lines): array
    {
        $out = ['states' => [], 'units' => [], 'people' => [], 'eligible' => Money::zero(), 'estimate' => Money::zero()];
        foreach ($lines as $i => $line) {
            $state = CommissionEligibility::forLine($line);
            $eligible = $state === CommissionEligibility::ELIGIBLE;
            $bucket = $eligible ? 'eligible' : 'estimate';
            $out['states'][$i] = $state;
            $out[$bucket] = $out[$bucket]->plus($line['crew_allocated']);

            $u = &$out['units'][$line['unit_id']];
            $u ??= ['eligible' => Money::zero(), 'estimate' => Money::zero(), 'eligible_jobs' => 0, 'other_jobs' => 0];
            $u[$bucket] = $u[$bucket]->plus($line['crew_allocated']);
            $u[$eligible ? 'eligible_jobs' : 'other_jobs']++;
            unset($u);

            foreach ($line['roles'] as $row) {
                foreach ($row['recipients'] as $r) {
                    $p = &$out['people'][$r['recipient_key']];
                    $p ??= ['name' => $r['name'], 'roles' => [], 'eligible' => Money::zero(), 'estimate' => Money::zero(), 'eligible_jobs' => [], 'other_jobs' => []];
                    $p[$bucket] = $p[$bucket]->plus($r['amount']);
                    $p['roles'][$row['label']] = true;
                    $p[$eligible ? 'eligible_jobs' : 'other_jobs'][$line['job_key']] = true;
                    unset($p);
                }
            }
        }
        foreach ($out['people'] as &$p) {
            $p['role_list'] = implode(', ', array_keys($p['roles']));
            $p['eligible_jobs'] = count($p['eligible_jobs']);
            $p['other_jobs'] = count($p['other_jobs']);
        }
        unset($p);
        uasort($out['people'], fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }
}
