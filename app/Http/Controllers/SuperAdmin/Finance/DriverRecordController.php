<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\Finance\DriverRecordService;
use App\Support\Finance\FinanceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Owner > Finance > Driver Records: jobs and vehicles towed per week, and each person's history. Read-only. */
class DriverRecordController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;
    use ResolvesFinancePeriod;

    public function __construct(private readonly DriverRecordService $records)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize(FinanceAccess::VIEW);

        if ($request->filled('from') || $request->filled('to')) {
            [$from, $to] = $this->resolvePeriod($request);
            if ($from->diffInDays($to) > 366) {
                $to = $from->addDays(366);
            }
        } else {
            // Default: the last eight Monday–Saturday weeks, including this one.
            $monday = CarbonImmutable::now(config('finance.timezone'))->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
            $from = $monday->subWeeks(7);
            $to = $monday->addDays(5);
        }
        $unitId = ctype_digit((string) $request->query('unit_id')) ? (int) $request->query('unit_id') : null;
        $role = is_string($request->query('role')) ? $request->query('role') : 'driver';
        $data = $this->records->build($from, $to, $unitId, $role);
        $person = is_string($request->query('person')) ? ($data['people'][$request->query('person')] ?? null) : null;

        return view('superadmin.finance.driver-records.index', [
            'd' => $data,
            'person' => $person,
            'from' => $from,
            'to' => $to,
            'periodLabel' => $this->periodLabel($from, $to),
            'unitId' => $unitId,
            'units' => Unit::orderBy('name')->get(['id', 'name', 'archived_at']),
            'query' => array_filter(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'unit_id' => $unitId, 'role' => $data['role']]),
        ]);
    }
}
