<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
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
        $settings = SystemSetting::allCached()->toArray();

        config([
            'towmate.settings' => $settings
        ]);

        View::composer('layouts.superadmin', function ($view) {
            $pendingBookings = Booking::where('status', 'requested')->count();

            $view->with('pendingBookings', $pendingBookings);
        });
        Paginator::useBootstrapFive();

        View::composer('*', function ($view) {
            if (!auth()->check()) return;

            $user = auth()->user();

            // get ONLY active booking
            $activeBooking = Booking::where('customer_id', $user->id)
                ->whereIn('status', ['requested', 'assigned', 'on_job'])
                ->orderByRaw("
                                CASE 
                                    WHEN status = 'on_job' THEN 1
                                    WHEN status = 'assigned' THEN 2
                                    WHEN status = 'requested' THEN 3
                                END
                            ")->latest()->first();

            $view->with('activeBooking', $activeBooking);

            // fallback for testing UI
            if (!$activeBooking) {
                $activeBooking = Booking::where('customer_id', auth()->id())
                    ->latest()
                    ->first();
            }

            $view->with('activeBooking', $activeBooking);
        });
    }
}
