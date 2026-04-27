<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use Illuminate\Support\Facades\Schema;

class TrackController extends Controller
{
    public function index()
    {
        $customerId = $this->resolveCustomerId();

        if (! $customerId) {
            abort(403, 'No customer account found.');
        }

        $bookings = Booking::where('customer_id', $customerId)
            ->where(function ($q) {
                $q->whereNotIn('status', ['completed', 'cancelled'])
                  ->orWhere(function ($q2) {
                      $q2->where('status', 'completed')
                         ->where('updated_at', '>=', now()->subHours(24));
                  });
            })
            ->with(['truckType', 'unit.driver', 'assignedTeamLeader'])
            ->latest('updated_at')
            ->get();

        return view('customer.pages.track', compact('bookings'));
    }

    public function show($id)
    {
        $customerId = $this->resolveCustomerId();

        if (! $customerId) {
            abort(403, 'No customer account found.');
        }

        $booking = Booking::where(function ($query) use ($id) {
            $query->where('booking_code', $id)
                ->orWhere('id', $id);
        })
            ->where('customer_id', $customerId)
            ->where(function ($q) {
                $q->whereNotIn('status', ['cancelled'])
                  ->where(function ($q2) {
                      $q2->whereNotIn('status', ['completed'])
                         ->orWhere('updated_at', '>=', now()->subHours(24));
                  });
            })
            ->with(['truckType', 'unit.driver', 'assignedTeamLeader'])
            ->first();

        if (! $booking) {
            return redirect()->route('customer.track.index')
                ->with('error', 'Booking not found or no longer available.');
        }

        $truckTypes = TruckType::query()->orderBy('name')->get();

        return view('customer.pages.track-show', compact('booking', 'truckTypes'));
    }

    protected function resolveCustomerId(): ?int
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        if (Schema::hasColumn('customers', 'user_id') && $user->customer) {
            return $user->customer->id;
        }

        return Customer::query()
            ->when(Schema::hasColumn('customers', 'user_id'), function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->when(filled($user->email ?? null), function ($query) use ($user) {
                $query->orWhere('email', $user->email);
            })
            ->value('id');
    }
}
