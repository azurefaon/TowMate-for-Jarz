<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Pagination\Paginator;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\SystemSetting;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $settings = Schema::hasTable('system_settings')
            ? SystemSetting::allCached()->toArray()
            : [];

        config([
            'towmate.settings' => $settings,
        ]);

        View::composer('layouts.superadmin', function ($view) {
            $pendingBookings = Schema::hasTable('bookings')
                ? Booking::whereIn('status', ['requested', 'reviewed'])->count()
                : 0;

            $view->with('pendingBookings', $pendingBookings);
        });

        View::composer('admin-dashboard.layouts.app', function ($view) {
            if (!Schema::hasTable('bookings')) {
                $view->with([
                    'dispatcherNotificationCount' => 0,
                    'dispatcherNotifications' => collect(),
                ]);

                return;
            }

            $dispatcherStatuses = ['reviewed', 'quoted', 'quotation_sent', 'confirmed', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification'];

            $dispatcherNotifications = Booking::with(['customer', 'truckType', 'assignedTeamLeader', 'unit.teamLeader'])
                ->whereIn('status', $dispatcherStatuses)
                ->latest('updated_at')
                ->take(6)
                ->get();

            $dispatcherNotificationCount = Booking::whereIn('status', $dispatcherStatuses)
                ->whereDate('updated_at', today())
                ->count();

            $view->with(compact('dispatcherNotificationCount', 'dispatcherNotifications'));
        });

        Paginator::useBootstrapFive();

        View::composer('*', function ($view) {
            if (!Auth::check() || !Schema::hasTable('bookings')) {
                return;
            }

            $user = Auth::user();
            $customerId = null;

            if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'user_id')) {
                $customerId = optional($user->customer)->id;
            }

            if (! $customerId && Schema::hasTable('customers')) {
                $customerId = Customer::query()
                    ->when(Schema::hasColumn('customers', 'user_id'), function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->when(filled($user->email ?? null), function ($query) use ($user) {
                        $query->orWhere('email', $user->email);
                    })
                    ->value('id');
            }

            if (! $customerId) {
                $view->with('activeBooking', null);
                return;
            }

            $activeBooking = Booking::where('customer_id', $customerId)
                ->whereIn('status', ['requested', 'reviewed', 'quoted', 'quotation_sent', 'confirmed', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job'])
                ->orderByRaw("
                                CASE
                                    WHEN status = 'waiting_verification' THEN 1
                                    WHEN status = 'in_progress' THEN 2
                                    WHEN status = 'on_the_way' THEN 3
                                    WHEN status = 'on_job' THEN 4
                                    WHEN status = 'assigned' THEN 5
                                    WHEN status = 'confirmed' THEN 6
                                    WHEN status = 'quotation_sent' THEN 7
                                    WHEN status = 'quoted' THEN 8
                                    WHEN status = 'reviewed' THEN 9
                                    WHEN status = 'accepted' THEN 10
                                    WHEN status = 'requested' THEN 11
                                END
                            ")
                ->latest('updated_at')
                ->first();

            if (! $activeBooking) {
                $activeBooking = Booking::where('customer_id', $customerId)
                    ->latest()
                    ->first();
            }

            $view->with('activeBooking', $activeBooking);
        });
    }
}
