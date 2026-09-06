<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\DispatcherNotification;
use App\Models\Quotation;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use App\Observers\AuditObserver;
use App\Observers\DispatcherNotificationObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

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
     * Rate limiters for the customer mobile app's unauthenticated OTP/reset
     * endpoints (registration OTP, password-reset OTP, password-reset
     * submit) — see OWASP audit A07:2025 / API4:2023 (zero throttling was
     * previously registered anywhere on routes/api.php).
     *
     * Keyed primarily by normalized email (not IP alone — shared NAT would
     * otherwise let one customer's throttle collide with another's) plus a
     * secondary, looser per-IP limit to blunt abuse spread across many
     * emails from a single source.
     */
    protected function registerCustomerOtpRateLimiters(): void
    {
        RateLimiter::for('customer-otp-send', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(3)->by('otp-send-email:' . $email),
                Limit::perMinute(10)->by('otp-send-ip:' . $request->ip()),
            ];
        });

        RateLimiter::for('customer-otp-verify', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(10)->by('otp-verify-email:' . $email),
                Limit::perMinute(20)->by('otp-verify-ip:' . $request->ip()),
            ];
        });

        RateLimiter::for('customer-password-reset-submit', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(10)->by('pwreset-submit-email:' . $email),
                Limit::perMinute(20)->by('pwreset-submit-ip:' . $request->ip()),
            ];
        });
    }

    /**
     * Rate limiters for Phase 2 of the OWASP remediation (A07:2025 / API4:2023
     * Unrestricted Resource Consumption, API6:2023 Unrestricted Access to
     * Sensitive Business Flows) — covers authenticated customer/Team Leader
     * mutation endpoints plus the public/authenticated geo and tracking
     * proxies that carry real Google Maps quota/cost exposure.
     *
     * All authenticated limiters key by user id first (falls back to IP only
     * if somehow unauthenticated) so one customer/TL's throttle can never
     * collide with another's; the two public ones key by IP since there is
     * no authenticated identity to key on.
     */
    protected function registerApiAbuseRateLimiters(): void
    {
        $byUser = function (Request $request, string $prefix): string {
            $key = $request->user()?->id ? 'user:' . $request->user()->id : 'ip:' . $request->ip();

            return $prefix . ':' . $key;
        };

        // Booking creation — a real business-state mutation with an upload
        // attached; low limit, keyed by customer.
        RateLimiter::for('customer-booking-create', fn(Request $r) => Limit::perMinute(5)->by($byUser($r, 'booking-create')));

        // Cancellation — low limit, one-way state transition.
        RateLimiter::for('customer-booking-cancel', fn(Request $r) => Limit::perMinute(8)->by($byUser($r, 'booking-cancel')));

        // Accept / reject / inquire / request-price-review share one limiter —
        // all are the same class of "respond to a live quotation" action.
        RateLimiter::for('customer-quotation-action', fn(Request $r) => Limit::perMinute(15)->by($byUser($r, 'quotation-action')));

        // Notification polling — generous; this is expected to be called
        // frequently by the app's own polling/refresh behavior.
        RateLimiter::for('customer-notifications', fn(Request $r) => Limit::perMinute(60)->by($byUser($r, 'notifications')));

        // Team Leader task lifecycle mutations (accept/status/return/complete/
        // claim-next) — higher than customer mutations since a real job
        // legitimately moves through several transitions in quick succession.
        RateLimiter::for('tl-task-mutate', fn(Request $r) => Limit::perMinute(30)->by($byUser($r, 'tl-task-mutate')));

        // Photo/signature uploads — bounded; a job needs at most a handful.
        RateLimiter::for('tl-upload', fn(Request $r) => Limit::perMinute(15)->by($byUser($r, 'tl-upload')));

        // Presence heartbeat — the app pings this frequently while on duty.
        RateLimiter::for('tl-presence', fn(Request $r) => Limit::perMinute(40)->by($byUser($r, 'tl-presence')));

        // Location updates — the controller itself already de-dupes writes
        // less than 10s apart (so a compliant client is ~6/min); this gives
        // ~5x headroom above that before it's treated as abuse.
        RateLimiter::for('tl-location', fn(Request $r) => Limit::perMinute(30)->by($byUser($r, 'tl-location')));

        // Authenticated mobile geo proxy (search/reverse/route/autocomplete/
        // place-details) — protects Google Maps quota/cost, generous enough
        // for normal address-typing/autocomplete interaction.
        RateLimiter::for('api-geo-proxy', fn(Request $r) => Limit::perMinute(30)->by($byUser($r, 'geo-proxy')));

        // Public (unauthenticated) web geo proxy used by the desktop booking
        // page — keyed by IP since there is no authenticated identity yet.
        // (The public booking-tracking and signed quotation-link routes in
        // routes/web.php already carry their own throttle:N,1 middleware —
        // nothing new needed there.)
        RateLimiter::for('public-geo-proxy', fn(Request $r) => Limit::perMinute(20)->by('public-geo-proxy:ip:' . $r->ip()));
    }

    protected function databaseReady(): bool
    {
        try {
            return Schema::hasTable('migrations');
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('brevo', function (array $config) {
            return (new BrevoTransportFactory)->create(
                new Dsn('brevo+api', 'default', $config['key'] ?? '')
            );
        });

        $this->registerCustomerOtpRateLimiters();
        $this->registerApiAbuseRateLimiters();

        $settings = [];

        if ($this->databaseReady()) {
            $settings = Schema::hasTable('system_settings')
                ? SystemSetting::allCached()->toArray()
                : [];
        }

        config([
            'towmate.settings' => $settings,
        ]);

        View::composer('layouts.superadmin', function ($view) {
            $pendingBookings = 0;

            if ($this->databaseReady() && Schema::hasTable('bookings')) {
                $pendingBookings = Booking::whereIn('status', ['requested', 'reviewed'])->count();
            }

            $view->with('pendingBookings', $pendingBookings);
        });

        View::composer('admin-dashboard.layouts.app', function ($view) {
            if (! $this->databaseReady() || ! Schema::hasTable('dispatcher_notifications')) {
                $view->with([
                    'dispatcherUnreadCount' => 0,
                    'dispatcherNotifications' => collect(),
                ]);

                return;
            }

            // Latest 5 shown in the dropdown (per design), unread count computed
            // independently from the full unread set (not just what's visible).
            $dispatcherNotifications = DispatcherNotification::with(['booking.customer', 'booking.truckType'])
                ->latest('id')
                ->take(5)
                ->get();

            $dispatcherUnreadCount = DispatcherNotification::unread()->count();

            $view->with(compact('dispatcherUnreadCount', 'dispatcherNotifications'));
        });

        Paginator::useBootstrapFive();

        foreach ([Booking::class, User::class, Unit::class, TruckType::class, VehicleType::class, Quotation::class, Customer::class] as $auditable) {
            $auditable::observe(AuditObserver::class);
        }

        Booking::observe(DispatcherNotificationObserver::class);

        $this->app->terminating(function () {
            AuditObserver::flush();
        });

        View::composer('*', function ($view) {
            if (! Auth::check() || ! $this->databaseReady() || ! Schema::hasTable('bookings')) {
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
