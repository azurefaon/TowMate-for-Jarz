<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\TruckType;

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\VerificationController;

use App\Http\Controllers\LandingController;

use App\Http\Controllers\Admin\DashboardController as AdminController;
use App\Http\Controllers\Admin\DispatchController;

use App\Http\Controllers\Customer\DashboardController;
use App\Http\Controllers\Customer\HistoryController;
use App\Http\Controllers\Customer\TrackController;
use App\Http\Controllers\Customer\BookingController as CustomerBookingController;
use App\Http\Controllers\TeamLeaderController;

use App\Http\Controllers\SuperAdmin\UserManagementController;
use App\Http\Controllers\SuperAdmin\TruckTypeController;
use App\Http\Controllers\SuperAdmin\UnitController;
use App\Http\Controllers\SuperAdmin\BookingController as SuperAdminBookingController;
use App\Http\Controllers\SuperAdmin\AuditLogController;
use App\Http\Controllers\SuperAdmin\SystemSettingsController;

// Route::get('/', fn() => redirect('/login'));

Route::get('/book', function () {
    $truckTypes = TruckType::all();
    return view('landing.form', compact('truckTypes'));
})->name('landing.book');

// =================================================================================

Route::get('/', [LandingController::class, 'index'])->name('landing');


Route::post('/book', [CustomerBookingController::class, 'landingStore'])
    ->name('landing.book.store');

Route::get('/dashboard', function () {
    $role = Auth::user()->role_id;

    if ($role == 1) return redirect('/superadmin');
    if ($role == 2) return redirect('/admin-dashboard');
    if ($role == 3) return redirect('/teamleader');
    if ($role == 5) return redirect()->route('customer.dashboard');

    return view('dashboard');
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';

Route::get('/teamleader', fn() => view('teamleader.dashboard'))->middleware(['auth', 'role:3']);
Route::get('/teamleader/bookings', [TeamLeaderController::class, 'index'])->middleware(['auth', 'role:3'])->name('teamleader.bookings');
Route::get('/driver', fn() => view('driver.dashboard'))->middleware(['auth', 'role:4']);

Route::get(
    '/admin/pending-bookings-count',
    fn() =>
    \App\Models\Booking::where('status', 'requested')->count()
);

Route::prefix('admin-dashboard')
    ->name('admin.')
    ->middleware(['auth', 'role:2'])
    ->group(function () {

        Route::get('/', [AdminController::class, 'index'])->name('dashboard');

        Route::get('/available-units', [UnitController::class, 'available'])
            ->name('available-units');

        Route::get('/dispatch', [DispatchController::class, 'index'])->name('dispatch');

        Route::post('/booking/{booking}/assign', [DispatchController::class, 'assignBooking'])
            ->name('booking.assign');

        Route::get('/jobs', function () {
            return 'Jobs Page';
        })->name('jobs');
    });

Route::prefix('superadmin')->name('superadmin.')->middleware(['auth', 'role:1'])->group(function () {

    Route::get('/', [SuperAdminController::class, 'index'])->name('dashboard');

    Route::resource('users', UserManagementController::class);
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

    Route::post('/settings/landing', [SystemSettingsController::class, 'updateLanding'])
        ->name('settings.landing.update');

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
            'weekBookings' => $weekBookings
        ]);
    })->name('dashboard.stats');
});

Route::post('/send-otp', [VerificationController::class, 'sendOtp'])->middleware(['auth', 'throttle:3,1']);
Route::post('/verify-otp', [VerificationController::class, 'verifyOtp'])->middleware(['auth', 'throttle:5,1']);

Route::middleware(['auth', 'role:5'])->prefix('customer')->name('customer.')->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('/book', function () {
        $truckTypes = TruckType::all();
        return view('customer.pages.book', compact('truckTypes'));
    })->name('book');

    Route::post('/book', [CustomerBookingController::class, 'store'])
        ->name('book.store');

    Route::get('/track', [TrackController::class, 'index'])
        ->name('track.index');

    Route::get('/track/{id}', [TrackController::class, 'show'])
        ->name('track');

    Route::get('/history', [HistoryController::class, 'index'])
        ->name('history');

    Route::get('/chat', function () {
        return view('customer.pages.chat');
    })->name('chat');

    Route::get('/help', function () {
        return view('customer.pages.help');
    })->name('help');
});


Route::post('/logout', function (Request $request) {
    Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect()->route('login');
})->name('logout');
