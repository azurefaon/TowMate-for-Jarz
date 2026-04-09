<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Pagination\Paginator;
use App\Models\Booking;
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
                ? Booking::where('status', 'requested')->count()
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

            $dispatcherNotifications = Booking::with(['customer', 'truckType'])
                ->where('status', 'accepted')
                ->latest('updated_at')
                ->take(5)
                ->get();

            $dispatcherNotificationCount = Booking::where('status', 'accepted')
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

            $activeBooking = Booking::where('customer_id', $user->id)
                ->whereIn('status', ['requested', 'assigned', 'on_job'])
                ->orderByRaw("
                                CASE 
                                    WHEN status = 'on_job' THEN 1
                                    WHEN status = 'assigned' THEN 2
                                    WHEN status = 'requested' THEN 3
                                END
                            ")
                ->latest()
                ->first();

            if (!$activeBooking) {
                $activeBooking = Booking::where('customer_id', Auth::id())
                    ->latest()
                    ->first();
            }

            $view->with('activeBooking', $activeBooking);
        });
    }
}
