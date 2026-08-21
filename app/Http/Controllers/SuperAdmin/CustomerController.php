<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'risk_level' => (string) $request->query('risk_level', ''),
            'customer_type' => (string) $request->query('customer_type', ''),
        ];

        $customers = Customer::query()
            ->withCount('bookings')
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $term = $filters['search'];
                $query->where(function ($q) use ($term) {
                    $q->where('full_name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->when($filters['risk_level'] !== '', fn ($query) => $query->where('risk_level', $filters['risk_level']))
            ->when($filters['customer_type'] !== '', fn ($query) => $query->where('customer_type', $filters['customer_type']))
            ->orderBy('full_name')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total' => Customer::count(),
            'blacklisted' => Customer::where('risk_level', 'blacklisted')->count(),
            'watchlist' => Customer::where('risk_level', 'watchlist')->count(),
        ];

        return view('superadmin.customers.index', compact('customers', 'filters', 'stats'));
    }

    public function show(Customer $customer)
    {
        $customer->load(['bookings' => function ($query) {
            $query->with(['truckType', 'unit'])->latest();
        }]);

        return view('superadmin.customers.show', compact('customer'));
    }
}
