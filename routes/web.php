<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Models\TruckType;

use App\Http\Controllers\LandingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\TeamLeaderController;
use App\Http\Controllers\VerificationController;

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

use App\Http\Controllers\SuperAdmin\AuditLogController;
use App\Http\Controllers\SuperAdmin\BookingController as SuperAdminBookingController;
use App\Http\Controllers\SuperAdmin\SystemSettingsController;
use App\Http\Controllers\SuperAdmin\TruckTypeController;
use App\Http\Controllers\SuperAdmin\UnitController;
use App\Http\Controllers\SuperAdmin\UserManagementController;

Route::get('/', [LandingController::class, 'index'])->name('landing');

Route::get('/book', function () {
    $truckTypes = TruckType::all();

    return view('landing.form', compact('truckTypes'));
})->name('landing.book');

Route::post('/book', [CustomerBookingController::class, 'landingStore'])
    ->name('landing.book.store');

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
    ->middleware(['auth', 'role:3'])
    ->group(function () {
        Route::redirect('/', '/teamleader/dashboard');
        Route::get('/dashboard', [TeamLeaderController::class, 'dashboard'])->name('dashboard');
        Route::get('/tasks', [TeamLeaderController::class, 'tasks'])->name('tasks');
        Route::get('/bookings', [TeamLeaderController::class, 'tasks'])->name('bookings');
        Route::get('/task/{booking}', [TeamLeaderController::class, 'showTask'])->name('task.show');

        Route::post('/task/{booking}/accept', [TeamLeaderController::class, 'acceptTask'])
            ->middleware('throttle:20,1')
            ->name('task.accept');

        Route::post('/task/{booking}/driver', [TeamLeaderController::class, 'saveDriver'])
            ->middleware('throttle:20,1')
            ->name('task.driver');

        Route::post('/task/{booking}/note', [TeamLeaderController::class, 'autosaveNote'])
            ->middleware('throttle:30,1')
            ->name('task.note');

        Route::post('/task/{booking}/proceed', [TeamLeaderController::class, 'proceedToLocation'])
            ->middleware('throttle:20,1')
            ->name('task.proceed');

        Route::post('/task/{booking}/start', [TeamLeaderController::class, 'startTask'])
            ->middleware('throttle:20,1')
            ->name('task.start');

        Route::post('/task/{booking}/complete', [TeamLeaderController::class, 'completeTask'])
            ->middleware('throttle:10,1')
            ->name('task.complete');

        Route::post('/task/{booking}/return', [TeamLeaderController::class, 'returnTask'])
            ->middleware('throttle:10,1')
            ->name('task.return');

        Route::get('/task/{booking}/status', [TeamLeaderController::class, 'taskStatus'])
            ->middleware('throttle:30,1')
            ->name('task.status');

        Route::post('/presence/ping', [TeamLeaderController::class, 'heartbeat'])
            ->middleware('throttle:60,1')
            ->name('presence.ping');

        Route::post('/presence/offline', [TeamLeaderController::class, 'goOffline'])
            ->middleware('throttle:60,1')
            ->name('presence.offline');

        Route::post('/tasks/{booking}/start', [TeamLeaderController::class, 'startTask'])
            ->middleware('throttle:20,1')
            ->name('tasks.start');

        Route::post('/tasks/{booking}/confirm-completion', [TeamLeaderController::class, 'confirmCompletion'])
            ->middleware('throttle:10,1')
            ->name('tasks.confirm');

        Route::get('/tasks/{booking}/status', [TeamLeaderController::class, 'taskStatus'])
            ->middleware('throttle:30,1')
            ->name('tasks.status');
    });

Route::get('/teamleader/verification/{booking}/{decision}', [TeamLeaderController::class, 'respondToVerification'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('teamleader.verification.respond');

Route::view('/driver', 'dashboard')
    ->middleware(['auth', 'role:4'])
    ->name('driver.dashboard');

Route::prefix('admin-dashboard')
    ->name('admin.')
    ->middleware(['auth', 'role:2'])
    ->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('dashboard');
        Route::get('/live-overview', [AdminController::class, 'liveOverview'])->name('live-overview');
        Route::get('/dispatch', [DispatchController::class, 'index'])->name('dispatch');
        Route::get('/pending-bookings-count', [DispatchController::class, 'pendingBookingsCount'])->name('pending-bookings-count');
        Route::get('/drivers', [DriversController::class, 'index'])->name('drivers');
        Route::post('/drivers/{teamLeader}/assign-unit', [DriversController::class, 'assignUnit'])->name('drivers.assign-unit');
        Route::get('/available-units', [AvailableUnitsController::class, 'index'])->name('available-units');
        Route::post('/booking/{booking}/assign', [DispatchController::class, 'assignBooking'])->name('booking.assign');
        Route::get('/jobs', [JobsController::class, 'index'])->name('jobs');

        Route::post('/booking/{id}/update-status', [DispatchController::class, 'updateStatus'])
            ->name('booking.updateStatus');
    });

Route::prefix('superadmin')
    ->name('superadmin.')
    ->middleware(['auth', 'role:1'])
    ->group(function () {
        Route::get('/dashboard', [SuperAdminController::class, 'index'])->name('dashboard');

        Route::get('users/archived', [UserManagementController::class, 'archived'])->name('users.archived');
        Route::patch('users/{user}/archive', [UserManagementController::class, 'archive'])->name('users.archive');
        Route::patch('users/{id}/restore', [UserManagementController::class, 'restore'])->name('users.restore');
        Route::resource('users', UserManagementController::class)->except(['show']);
        Route::patch('users/{id}/toggle', [UserManagementController::class, 'toggleStatus'])->name('users.toggle');

        Route::resource('truck-types', TruckTypeController::class);
        Route::patch('truck-types/{truckType}/toggle', [TruckTypeController::class, 'toggleStatus'])->name('truck-types.toggle');

        Route::get('/units', [UnitController::class, 'index'])->name('unit-truck.index');
        Route::post('/units', [UnitController::class, 'store'])->name('units.store');
        Route::put('/units/{id}', [UnitController::class, 'update'])->name('units.update');
        Route::patch('/units/{id}/toggle', [UnitController::class, 'toggle'])->name('units.toggle');

        Route::get('/bookings', [SuperAdminBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{id}', [SuperAdminBookingController::class, 'show'])->name('bookings.show');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit.logs');

        Route::get('/settings', [SystemSettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings/update', [SystemSettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/landing', [SystemSettingsController::class, 'updateLanding'])->name('settings.landing.update');

        Route::get('/dashboard-stats', function () {
            $todayBookings = \App\Models\Booking::whereDate('created_at', today())->count();

            $completedToday = \App\Models\Booking::where('status', 'completed')
                ->whereDate('completed_at', today())
                ->count();

            $cancelledToday = \App\Models\Booking::where('status', 'cancelled')
                ->whereDate('created_at', today())
                ->count();

            $weekBookings = \App\Models\Booking::selectRaw('DAYOFWEEK(created_at) as day, count(*) as total')
                ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->groupBy('day')
                ->orderBy('day')
                ->pluck('total')
                ->values();

            return response()->json([
                'todayBookings' => $todayBookings,
                'completedToday' => $completedToday,
                'cancelledToday' => $cancelledToday,
                'weekBookings' => $weekBookings,
            ]);
        })->name('dashboard.stats');
    });

Route::middleware(['auth', 'role:5'])
    ->prefix('customer')
    ->name('customer.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/book', function () {
            $truckTypes = TruckType::all();

            return view('customer.pages.book', compact('truckTypes'));
        })->name('book');

        Route::post('/book', [CustomerBookingController::class, 'store'])->name('book.store');
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

Route::post('/send-otp', [VerificationController::class, 'sendOtp'])->middleware(['auth', 'throttle:3,1']);
Route::post('/verify-otp', [VerificationController::class, 'verifyOtp'])->middleware(['auth', 'throttle:5,1']);
