<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Unit;
use App\Services\Finance\FinanceException;
use App\Services\Finance\JobCollectionService;
use App\Support\Finance\FinanceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * Owner > Finance > Jobs & Collections. Read-only over bookings, invoices and payment records, plus the
 * Owner-only action of recording how a job's verified collection is split between towing units.
 */
class JobCollectionController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;
    use ResolvesFinancePeriod;

    public function __construct(private readonly JobCollectionService $collections)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize(FinanceAccess::VIEW);

        [$from, $to] = $this->resolvePeriod($request);
        $filters = $this->filters($request);
        $jobs = $this->collections->jobs($from, $to, $filters);

        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($jobs->forPage($page, $perPage)->values(), $jobs->count(), $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('superadmin.finance.collections.index', [
            'jobs' => $paginator,
            'totals' => $this->collections->totals($jobs),
            'filters' => $filters,
            'from' => $from,
            'to' => $to,
            'periodLabel' => $this->periodLabel($from, $to),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'archived_at']),
            'activeUnits' => Unit::whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function split(Request $request, int $booking): RedirectResponse
    {
        $this->authorize(FinanceAccess::ALLOCATE_REVENUE);
        $model = Booking::findOrFail($booking);
        $data = $request->validate([
            'amounts' => ['required', 'array', 'max:5'],
            'amounts.*' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['amounts.required' => 'Enter the amount for each unit.']);

        try {
            $this->collections->recordSplit($request->user(), $model, $data['amounts'], $data['note'] ?? null);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()])->withInput($request->except('_token') + ['form' => 'split-' . $model->id]);
        }

        return back()->with('success', "Recorded the unit split for job {$model->booking_code}.");
    }

    private function filters(Request $request): array
    {
        $in = fn (string $key, array $allowed) => in_array($request->query($key), $allowed, true) ? $request->query($key) : null;

        return [
            'unit_id' => $request->filled('unit_id') && ctype_digit((string) $request->query('unit_id')) ? (int) $request->query('unit_id') : null,
            'booking' => mb_substr(trim((string) $request->query('booking', '')), 0, 40) ?: null,
            'status' => $in('status', array_merge(array_keys(JobCollectionService::COLLECTION_STATUSES), ['outstanding'])),
            'method' => $in('method', array_keys(Booking::PREFERRED_PAYMENT_METHODS)),
            'allocation' => $in('allocation', array_keys(JobCollectionService::ALLOCATION_STATUSES)),
            'basis' => $in('basis', ['service', 'payment']) ?? 'service',
        ];
    }
}
