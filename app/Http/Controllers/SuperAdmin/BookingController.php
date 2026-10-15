<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\OwnerBookingPresenter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BookingController extends Controller
{
    public function __construct(private OwnerBookingPresenter $presenter)
    {
    }

    public function index(Request $request)
    {
        $filters = $this->resolveFilters($request);

        $query = Booking::query()->with(['customer', 'truckType']);

        if ($filters['search'] !== '') {
            $this->applySearch($query, $filters['search']);
        }

        if ($filters['status'] !== '') {
            $query->whereIn('status', OwnerBookingPresenter::STATUS_GROUPS[$filters['status']]['statuses']);
        }

        if ($filters['range']) {
            $query->whereBetween('created_at', $filters['range']);
        }

        $bookings = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(10)->withQueryString();

        $rows = $bookings->getCollection()->map(fn (Booking $booking) => [
            'booking' => $booking,
            'service' => $this->presenter->serviceSummary($booking),
            'amount' => $this->presenter->listAmount($booking),
            'status' => [
                'label' => $this->presenter->statusLabel($booking->status),
                'tone' => $this->presenter->statusTone($booking->status),
            ],
            'phone' => $this->presenter->formatPhone($booking->customer->phone ?? null),
        ]);

        $data = [
            'bookings' => $bookings,
            'rows' => $rows,
            'filters' => $filters,
            'hasFilters' => $filters['search'] !== '' || $filters['status'] !== '' || $filters['date'] !== 'all',
            'statusGroups' => OwnerBookingPresenter::STATUS_GROUPS,
            'datePresets' => OwnerBookingPresenter::DATE_PRESETS,
        ];

        if ($request->ajax()) {
            return view('superadmin.bookings._results', $data);
        }

        return view('superadmin.bookings.index', $data);
    }

    public function show($id)
    {
        return response()->json($this->presenter->details($this->presenter->findBooking($id)));
    }


    /**
     * Normalises the query string into the filters actually applied, so the
     * controls always show what the results reflect (an incomplete or reversed
     * custom range falls back to All dates rather than a silent default).
     */
    private function resolveFilters(Request $request): array
    {
        $status = (string) $request->input('status', '');
        $date = (string) $request->input('date', 'all');
        $from = null;
        $to = null;
        $range = null;

        if (! array_key_exists($status, OwnerBookingPresenter::STATUS_GROUPS)) {
            $status = '';
        }

        if (! array_key_exists($date, OwnerBookingPresenter::DATE_PRESETS)) {
            $date = 'all';
        }

        if ($date === 'custom') {
            $from = $this->parseDate($request->input('from'));
            $to = $this->parseDate($request->input('to'));

            if ($from && $to && $from->lte($to)) {
                $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
            } else {
                $date = 'all';
                $from = $to = null;
            }
        } elseif ($date !== 'all') {
            $range = [
                match ($date) {
                    '7d' => now()->subDays(6)->startOfDay(),
                    '30d' => now()->subDays(29)->startOfDay(),
                    default => now()->startOfDay(),
                },
                now()->endOfDay(),
            ];
        }

        $dateLabel = $date === 'custom'
            ? ($from->year === $to->year
                ? $from->format('M j') . ' – ' . $to->format('M j, Y')
                : $from->format('M j, Y') . ' – ' . $to->format('M j, Y'))
            : OwnerBookingPresenter::DATE_PRESETS[$date];

        return [
            'search' => trim((string) $request->input('search', '')),
            'status' => $status,
            'statusLabel' => $status !== '' ? OwnerBookingPresenter::STATUS_GROUPS[$status]['label'] : 'All statuses',
            'date' => $date,
            'dateLabel' => $dateLabel,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'range' => $range,
        ];
    }

    private function parseDate($value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** Case-insensitive match on booking number, customer, phone, or address. */
    private function applySearch(Builder $query, string $search): void
    {
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $digits = preg_replace('/\D+/', '', $search);

        // Phones are stored as +639XXXXXXXXX, so match without the 0 / 63 prefix.
        $phoneDigits = preg_replace('/^(63|0)/', '', $digits);

        $query->where(function (Builder $q) use ($like, $digits, $phoneDigits, $search) {
            $q->where('booking_code', 'ilike', $like)
                ->orWhere('pickup_address', 'ilike', $like)
                ->orWhere('dropoff_address', 'ilike', $like)
                ->orWhereHas('customer', function (Builder $customer) use ($like, $phoneDigits) {
                    $customer->where('full_name', 'ilike', $like);

                    if (strlen($phoneDigits) >= 3) {
                        $customer->orWhereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') like ?", ['%' . $phoneDigits . '%']);
                    }
                });

            // Bookings without a booking_code are shown by their padded id.
            if ($digits !== '' && strlen($digits) <= 9 && $digits === preg_replace('/\s+/', '', $search) && (int) $digits > 0) {
                $q->orWhere(fn (Builder $id) => $id->whereNull('booking_code')->where('id', (int) $digits));
            }
        });
    }
}
