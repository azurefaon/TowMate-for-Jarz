<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\UnitFundEntryRequest;
use App\Models\Finance\FundEntry;
use App\Models\Unit;
use App\Services\Finance\FinanceException;
use App\Services\Finance\UnitFundLedger;
use App\Services\Finance\UnitFundService;
use App\Support\Finance\FinanceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Owner > Finance > Unit Funds. Every write goes through UnitFundService, which re-checks Owner
 * authorization, idempotency and the balance rules inside a locked transaction.
 */
class UnitFundController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public function __construct(private readonly UnitFundService $funds, private readonly UnitFundLedger $ledger)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize(FinanceAccess::VIEW);

        [$from, $to] = $this->resolvePeriod($request);
        $unitId = $request->filled('unit_id') && ctype_digit((string) $request->query('unit_id'))
            ? (int) $request->query('unit_id') : null;
        if ($unitId !== null && ! Unit::whereKey($unitId)->exists()) {
            $unitId = null;
        }

        $rows = $this->ledger->summary($from, $to, $unitId);

        $history = FundEntry::query()
            ->with(['unit:id,name,plate_number', 'recorder:id,name,first_name,middle_name,last_name', 'voider:id,name,first_name,middle_name,last_name', 'correctedEntry:id,reference_code', 'correction:id,reference_code,corrects_entry_id'])
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $activeUnits = Unit::whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'plate_number']);
        $filterUnits = Unit::query()
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', FundEntry::select('unit_id')))
            ->orderBy('name')->get(['id', 'name', 'plate_number', 'archived_at']);

        return view('superadmin.finance.unit-funds.index', [
            'rows' => $rows,
            'totals' => $this->ledger->totals($rows),
            'history' => $history,
            'from' => $from,
            'to' => $to,
            'unitId' => $unitId,
            'activeUnits' => $activeUnits,
            'filterUnits' => $filterUnits,
            'unitsWithInitial' => FundEntry::recorded()->where('issue_kind', 'initial')->pluck('unit_id')->all(),
            'today' => now(config('finance.timezone'))->toDateString(),
            'issueKey' => old('form') === 'issue' ? old('idempotency_key', (string) Str::uuid()) : (string) Str::uuid(),
            'returnKey' => old('form') === 'return' ? old('idempotency_key', (string) Str::uuid()) : (string) Str::uuid(),
        ]);
    }

    public function storeIssuance(UnitFundEntryRequest $request): RedirectResponse
    {
        return $this->attempt($request, 'issue', function () use ($request) {
            $entry = $this->funds->issue($request->user(), $request->validated(), $request->validated('idempotency_key'));

            return "Recorded {$entry->typeLabel()} of " . \App\Support\Money::of($entry->amount)->format() . " ({$entry->reference_code}).";
        });
    }

    public function storeReturn(UnitFundEntryRequest $request): RedirectResponse
    {
        return $this->attempt($request, 'return', function () use ($request) {
            $entry = $this->funds->recordReturn($request->user(), $request->validated(), $request->validated('idempotency_key'));

            return 'Recorded returned funds of ' . \App\Support\Money::of($entry->amount)->format() . " ({$entry->reference_code}).";
        });
    }

    public function correct(UnitFundEntryRequest $request, int $entry): RedirectResponse
    {
        $original = FundEntry::findOrFail($entry);

        return $this->attempt($request, "entry-{$original->id}", function () use ($request, $original) {
            $replacement = $this->funds->correct($request->user(), $original, $request->validated(), $request->validated('reason'), $request->validated('idempotency_key'));

            return "Corrected {$original->reference_code}. The replacement entry is {$replacement->reference_code}.";
        });
    }

    public function void(Request $request, int $entry): RedirectResponse
    {
        $this->authorize(FinanceAccess::MANAGE_FUNDS);
        $original = FundEntry::findOrFail($entry);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Enter the reason for voiding this entry.']);

        try {
            $this->funds->void($request->user(), $original, $data['reason']);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()])->withInput(['form' => "entry-{$original->id}"]);
        }

        return back()->with('success', "Voided {$original->reference_code}. It stays in the history with the reason.");
    }

    private function attempt(Request $request, string $form, \Closure $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()])->withInput($request->except('_token') + ['form' => $form]);
        }

        return back()->with('success', $message);
    }

    /** Defaults to the current reporting week (Monday to Saturday, Asia/Manila). */
    private function resolvePeriod(Request $request): array
    {
        $tz = config('finance.timezone');
        $parse = function (?string $value) use ($tz): ?CarbonImmutable {
            if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $value, $tz)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $from = $parse($request->query('from'));
        $to = $parse($request->query('to'));

        if (! $from && ! $to) {
            $from = CarbonImmutable::now($tz)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
            $to = $from->addDays((int) config('finance.week_length_days') - 1);
            // On a Sunday (outside the Mon–Sat week) include today so Sunday movements are still visible by default.
            $today = CarbonImmutable::now($tz)->startOfDay();
            if ($today->greaterThan($to)) {
                $to = $today;
            }
        }
        $from ??= $to;
        $to ??= $from;
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }
}
