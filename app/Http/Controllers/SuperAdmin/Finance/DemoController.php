<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Finance\CommissionStatement;
use App\Models\Unit;
use App\Services\Finance\Demo\FinanceDemoGuard;
use App\Services\Finance\FinanceException;
use App\Support\Finance\FinanceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Owner > Finance > Demo. Exists ONLY on a demo installation (FinanceDemoGuard::enabled()); everywhere else it is 404.
 * Lists the demonstration scenarios, the walkthrough, and the reset control.
 */
class DemoController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public function index(): View
    {
        $this->authorize(FinanceAccess::VIEW);
        abort_unless(FinanceDemoGuard::enabled(), 404);

        $units = Unit::where('name', 'like', 'DEMO Unit %')->orderBy('name')->get();
        $rows = $units->map(fn (Unit $u) => [
            'unit' => $u,
            'bookings' => Booking::where('assigned_unit_id', $u->id)->orderBy('id')->get(['id', 'booking_code', 'group_code']),
            'statements' => CommissionStatement::where('unit_id', $u->id)->orderBy('id')->get(['id', 'reference_code', 'status']),
        ]);

        return view('superadmin.finance.demo.index', [
            'rows' => $rows,
            'week' => CarbonImmutable::now(config('finance.timezone'))->startOfWeek(CarbonImmutable::MONDAY)->subWeek(),
            'database' => FinanceDemoGuard::databaseName(),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->authorize(FinanceAccess::MANAGE_POLICIES);
        abort_unless(FinanceDemoGuard::enabled(), 404);
        $request->validate(['confirm' => ['required', 'in:RESET DEMO']], ['confirm.in' => 'Type RESET DEMO to confirm.']);

        try {
            FinanceDemoGuard::assertCanReset();
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        Artisan::call('finance:demo-reset', ['--force' => true]);
        Auth::logout();   // every table, including users and sessions, was rebuilt

        return redirect()->route('login')->with('status', 'The demo database was rebuilt. Sign in again with the demo Owner account.');
    }
}
