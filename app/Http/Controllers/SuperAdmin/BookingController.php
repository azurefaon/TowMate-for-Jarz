<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'status' => (string) $request->input('status', ''),
            'period' => (string) $request->input('period', 'today'),
        ];

        if (! in_array($filters['period'], ['today', 'week', 'month'], true)) {
            $filters['period'] = 'today';
        }

        $query = Booking::with([
            'customer',
            'truckType',
            'unit',
            'receipt',
        ]);

        $statsQuery = Booking::query();

        if ($filters['search'] !== '') {
            $search = $filters['search'];

            foreach ([$query, $statsQuery] as $builder) {
                $builder->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($q2) use ($search) {
                        $q2->where('full_name', 'like', '%' . $search . '%');
                    })
                        ->orWhere('booking_code', 'like', '%' . $search . '%')
                        ->orWhere('pickup_address', 'like', '%' . $search . '%')
                        ->orWhere('dropoff_address', 'like', '%' . $search . '%');
                });
            }
        }

        $periodStart = match ($filters['period']) {
            'week' => now()->subDays(6)->startOfDay(),
            'month' => now()->subDays(29)->startOfDay(),
            default => today()->startOfDay(),
        };

        $periodEnd = now()->endOfDay();

        $periodLabel = match ($filters['period']) {
            'week' => 'This Week',
            'month' => 'This Month',
            default => 'Today',
        };

        $periodDescription = match ($filters['period']) {
            'week' => 'Showing bookings from the last 7 days.',
            'month' => 'Showing bookings from the last 30 days.',
            default => 'Showing only today\'s bookings.',
        };

        $query->whereBetween('created_at', [$periodStart, $periodEnd]);
        $statsQuery->whereBetween('created_at', [$periodStart, $periodEnd]);

        if ($filters['status'] !== '') {
            if ($filters['status'] === 'active') {
                $query->whereIn('status', ['accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job']);
            } elseif ($filters['status'] === 'on_job') {
                $query->whereIn('status', ['accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job']);
            } elseif ($filters['status'] === 'scheduled') {
                $query->whereIn('status', ['scheduled', 'scheduled_confirmed']);
            } elseif ($filters['status'] === 'returned') {
                $query->whereNotNull('returned_at');
            } else {
                $query->where('status', $filters['status']);
            }
        }

        $stats = [
            'total' => (clone $statsQuery)->count(),
            'requested' => (clone $statsQuery)->where('status', 'requested')->count(),
            'active' => (clone $statsQuery)->whereIn('status', ['accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job'])->count(),
            'completed' => (clone $statsQuery)->where('status', 'completed')->count(),
        ];

        $bookings = $query->latest()->paginate(10)->withQueryString();

        return view('superadmin.bookings.index', compact('bookings', 'filters', 'stats', 'periodLabel', 'periodDescription'));
    }

    public function show($id)
    {
        $booking = Booking::with([
            'customer',
            'truckType',
            'unit',
            'receipt'
        ])
            ->where('booking_code', $id)
            ->orWhere('id', $id)
            ->firstOrFail();

        return response()->json([
            'booking_code' => $booking->job_code,
            'customer' => [
                'full_name' => $booking->customer->full_name ?? 'N/A',
            ],
            'truck_type' => [
                'name' => $booking->truckType->name ?? 'N/A',
            ],
            'unit' => $booking->unit ? [
                'name' => $booking->unit->name,
                'plate_number' => $booking->unit->plate_number,
            ] : null,
            'pickup_address' => $booking->pickup_address,
            'dropoff_address' => $booking->dropoff_address,
            'distance_km' => $booking->distance_km,
            'final_total' => $booking->final_total,
            'status' => $booking->status,
            'receipt' => $booking->receipt ? [
                'receipt_code' => $booking->receipt->receipt_code ?? $booking->receipt->receipt_number,
                'pdf_path' => $booking->receipt->pdf_path,
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        $customer = Auth::user()->customer;
        $admin = \App\Models\User::where('role_id', 1)->first();

        $truck = \App\Models\TruckType::findOrFail($request->truck_type_id);

        $distanceKm = (float) ($request->distance_km ?? 0);
        $kmIncrements = (int) floor($distanceKm / 4);
        $distanceCost = round($kmIncrements * 200.0, 2);
        $total = $distanceCost;

        Booking::create([
            'customer_id' => $customer->id,
            'truck_type_id' => $truck->id,
            'pickup_address' => $request->pickup_address,
            'dropoff_address' => $request->dropoff_address,
            'distance_km' => $distanceKm,
            'base_rate' => 0,
            'per_km_rate' => 0,
            'final_total' => $total,
            'status' => 'requested',
            'created_by_admin_id' => $admin->id,
        ]);

        return back();
    }
}
