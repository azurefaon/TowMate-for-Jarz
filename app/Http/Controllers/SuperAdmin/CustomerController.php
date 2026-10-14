<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\OwnerBookingPresenter;
use Illuminate\Http\Request;

/**
 * Owner → Customers. Read-only: it lists customers and shows one customer's
 * details and booking history. Risk status is set from the dispatcher side;
 * nothing here changes a customer or an account.
 */
class CustomerController extends Controller
{
    public const RISK_LEVELS = ['watchlist' => 'Watchlist', 'blacklisted' => 'Blacklisted'];

    public const CUSTOMER_TYPES = ['regular' => 'Regular', 'pwd' => 'PWD', 'senior' => 'Senior'];

    public function __construct(private OwnerBookingPresenter $presenter)
    {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $risk = (string) $request->query('risk_level', '');
        $type = (string) $request->query('customer_type', '');

        // Only known values are applied, so what the controls show is what the list reflects.
        $filters = [
            'search' => $search,
            'risk_level' => array_key_exists($risk, self::RISK_LEVELS) ? $risk : '',
            'customer_type' => array_key_exists($type, self::CUSTOMER_TYPES) ? $type : '',
        ];

        $customers = Customer::query()
            ->withCount('bookings')
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $like = '%' . addcslashes(mb_strtolower($filters['search']), '%_\\') . '%';
                // Phones are stored as +639XXXXXXXXX but typed as 0917…, so match the digits
                // without the 0 / 63 prefix (same rule as Owner → Bookings).
                $phoneDigits = preg_replace('/^(63|0)/', '', preg_replace('/\D+/', '', $filters['search']));

                $query->where(function ($q) use ($like, $phoneDigits) {
                    $q->whereRaw('LOWER(full_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$like]);

                    if (strlen($phoneDigits) >= 3) {
                        $q->orWhereRaw("regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') LIKE ?", ['%' . $phoneDigits . '%']);
                    }
                });
            })
            ->when($filters['risk_level'] !== '', fn ($query) => $query->whereRaw('LOWER(risk_level) = ?', [$filters['risk_level']]))
            ->when($filters['customer_type'] !== '', fn ($query) => $query->whereRaw('LOWER(customer_type) = ?', [$filters['customer_type']]))
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        $rows = $customers->getCollection()->map(fn (Customer $customer) => [
            'customer' => $customer,
            'phone' => $this->presenter->formatPhone($customer->phone),
            'risk' => $this->riskBadge($customer),
        ]);

        return view('superadmin.customers.index', [
            'customers' => $customers,
            'rows' => $rows,
            'filters' => $filters,
            'hasFilters' => $filters['search'] !== '' || $filters['risk_level'] !== '' || $filters['customer_type'] !== '',
            'riskLevels' => self::RISK_LEVELS,
            'customerTypes' => self::CUSTOMER_TYPES,
            'stats' => [
                'total' => Customer::count(),
                'watchlist' => Customer::whereRaw('LOWER(risk_level) = ?', ['watchlist'])->count(),
                'blacklisted' => Customer::whereRaw('LOWER(risk_level) = ?', ['blacklisted'])->count(),
            ],
        ]);
    }

    public function show(Customer $customer)
    {
        $bookings = $customer->bookings()
            ->with(['truckType', 'unit'])
            ->latest()
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        // Amounts come from the same presenter as Owner → Bookings, so grouped
        // and quoted bookings show exactly what that page shows (a dash when no
        // figure is confirmed yet) and nothing is added up here.
        $rows = $bookings->getCollection()->map(fn ($booking) => [
            'booking' => $booking,
            'amount' => $this->presenter->listAmount($booking),
            'status' => [
                'label' => $this->presenter->statusLabel($booking->status),
                'tone' => $this->presenter->statusTone($booking->status),
            ],
        ]);

        return view('superadmin.customers.show', [
            'customer' => $customer,
            'phone' => $this->presenter->formatPhone($customer->phone),
            'risk' => $this->riskBadge($customer),
            'bookings' => $bookings,
            'rows' => $rows,
            'bookingCount' => $bookings->total(),
            'type' => self::CUSTOMER_TYPES[strtolower((string) $customer->customer_type)] ?? 'Regular',
        ]);
    }

    /** @return array{label: string, tone: string} */
    private function riskBadge(Customer $customer): array
    {
        return match (strtolower((string) $customer->risk_level)) {
            'blacklisted' => ['label' => 'Blacklisted', 'tone' => 'black'],
            'watchlist' => ['label' => 'Watchlist', 'tone' => 'yellow'],
            default => ['label' => 'Clear', 'tone' => 'slate'],
        };
    }
}
