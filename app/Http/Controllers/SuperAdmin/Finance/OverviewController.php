<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\Finance\FinanceOverviewService;
use App\Support\Finance\FinanceAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Owner > Finance > Overview. Read-only; every figure comes from FinanceOverviewService. */
class OverviewController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;
    use ResolvesFinancePeriod;

    public function __construct(private readonly FinanceOverviewService $overview)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize(FinanceAccess::VIEW);

        [$from, $to] = $this->resolvePeriod($request);
        if ($from->diffInDays($to) > 366) {
            $to = $from->addDays(366);   // keeps the week-by-week draft calculation bounded
        }
        $unitId = $request->filled('unit_id') && ctype_digit((string) $request->query('unit_id')) ? (int) $request->query('unit_id') : null;

        return view('superadmin.finance.overview.index', [
            'o' => $this->overview->build($from, $to, $unitId),
            'from' => $from,
            'to' => $to,
            'periodLabel' => $this->periodLabel($from, $to),
            'unitId' => $unitId,
            'units' => Unit::orderBy('name')->get(['id', 'name', 'archived_at']),
        ]);
    }
}
