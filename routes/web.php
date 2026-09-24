<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\Hash;
use App\Models\User;

use App\Models\TruckType;

use App\Http\Controllers\Api\BookingController;

use App\Http\Controllers\GeoController;
use App\Http\Controllers\ControlCenterController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\AndroidDownloadController;
use App\Http\Controllers\PublicSiteController;

use App\Http\Controllers\Admin\AvailableUnitsController;
use App\Http\Controllers\Admin\DashboardController as AdminController;
use App\Http\Controllers\Admin\DispatchController;
use App\Http\Controllers\Admin\DispatcherNotificationController;
use App\Http\Controllers\Admin\DriversController;
use App\Http\Controllers\Admin\JobsController;

use App\Http\Controllers\Customer\BookingController as CustomerBookingController;
use App\Http\Controllers\Customer\ChatController;
use App\Http\Controllers\Customer\DashboardController;
use App\Http\Controllers\Customer\HistoryController;
use App\Http\Controllers\Customer\TrackController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\PublicTrackController;

use App\Http\Controllers\SuperAdmin\BookingController as SuperAdminBookingController;
use App\Http\Controllers\SuperAdmin\CustomerAppContentController;
use App\Http\Controllers\SuperAdmin\MonitoringController;
use App\Http\Controllers\SuperAdmin\ReportsController;
use App\Http\Controllers\SuperAdmin\SystemSettingsController;
use App\Http\Controllers\SuperAdmin\TruckTypeController;
use App\Http\Controllers\SuperAdmin\UnitController;
use App\Http\Controllers\SuperAdmin\VehicleTypeController;

use App\Http\Controllers\SystemAdmin\AuditLogController as SystemAdminAuditLogController;
use App\Http\Controllers\SystemAdmin\DashboardController as SystemAdminDashboardController;
use App\Http\Controllers\SystemAdmin\DataProtectionController;
use App\Http\Controllers\SystemAdmin\ProfileController as SystemAdminProfileController;
use App\Http\Controllers\SystemAdmin\SecurityMonitorController;
use App\Http\Controllers\SystemAdmin\SystemSettingsController as SystemAdminSystemSettingsController;
use App\Http\Controllers\SystemAdmin\UserManagementController;

Route::get('/', [PublicSiteController::class, 'index'])->name('landing');

Route::get('/download/android', [AndroidDownloadController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('download.android');

Route::get('/app', [AndroidDownloadController::class, 'landing'])
    ->name('app.download');

Route::get('/mobile-app/qr-code', [\App\Http\Controllers\SuperAdmin\SystemSettingsController::class, 'mobileAppQrCode'])
    ->name('public.mobile-app.qr-code');

Route::prefix('geo')
    ->name('geo.')
    ->middleware('throttle:public-geo-proxy')
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

Route::get('/dashboard', function () {
    $role = Auth::user()->role_id ?? 0;

    return match ($role) {
        1 => redirect('/superadmin/dashboard'),
        2 => redirect('/admin-dashboard'),
        3 => redirect('/login'),
        4 => redirect('/driver'),
        5 => redirect('/customer/dashboard'),
        6 => redirect()->route('system-admin.dashboard'),
        default => view('dashboard'),
    };
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';

Route::view('/driver', 'dashboard')
    ->middleware(['auth', 'role:4'])
    ->name('driver.dashboard');

Route::prefix('control-center')
    ->name('control-center.')
    ->middleware(['auth', 'role:1,2', 'force.password.change', 'touch.dispatcher.presence'])
    ->group(function () {
        Route::get('/', [ControlCenterController::class, 'index'])->name('index');
        Route::get('/live', [ControlCenterController::class, 'live'])->name('live');
    });

Route::prefix('admin-dashboard')
    ->name('admin.')
    ->middleware(['auth', 'role:2', 'force.password.change', 'touch.dispatcher.presence'])
    ->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('dashboard');
        Route::get('/live-overview', [AdminController::class, 'liveOverview'])->name('live-overview');
        Route::get('/notifications', [DispatcherNotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/mark-all-read', [DispatcherNotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
        Route::get('/notifications/{notification}/open', [DispatcherNotificationController::class, 'open'])->name('notifications.open');
        Route::get('/dispatch', [DispatchController::class, 'index'])->name('dispatch');
        Route::get('/pending-bookings-count', [DispatchController::class, 'pendingBookingsCount'])->name('pending-bookings-count');
        Route::get('/units/locations', [DispatchController::class, 'unitLocations'])->name('units.locations');

        Route::get('/drivers', [DriversController::class, 'index'])->name('drivers');
        Route::post('/drivers/{teamLeader}/assign-unit', [DriversController::class, 'assignUnit'])->name('drivers.assign-unit');
        Route::post('/drivers/{teamLeader}/remove-unit', [DriversController::class, 'removeUnit'])->name('drivers.remove-unit');
        Route::post('/drivers/{teamLeader}/update-status', [DriversController::class, 'updateStatus'])->name('drivers.update-status');

        Route::patch('/drivers/{teamLeader}/override', [DriversController::class, 'override'])->name('team-leaders.override');

        Route::get('/drivers/eligible-people', [DriversController::class, 'eligiblePeople'])->name('drivers.eligible-people');
        Route::post('/drivers/units/{unit}/assign-team-leader', [DriversController::class, 'assignTeamLeader'])->name('drivers.units.assign-team-leader');
        Route::post('/drivers/units/{unit}/return-team-leader', [DriversController::class, 'returnTeamLeader'])->name('drivers.units.return-team-leader');
        Route::post('/drivers/units/{unit}/remove-team-leader', [DriversController::class, 'removeTeamLeader'])->name('drivers.units.remove-team-leader');
        Route::post('/drivers/units/{unit}/assign-slot', [DriversController::class, 'assignSlot'])->name('drivers.units.assign-slot');
        Route::post('/drivers/units/{unit}/remove-slot', [DriversController::class, 'removeSlot'])->name('drivers.units.remove-slot');
        Route::post('/drivers/loans/{loan}/return', [DriversController::class, 'returnSlot'])->name('drivers.loans.return');
        Route::post('/drivers/units/{unit}/transfer-team', [DriversController::class, 'transferTeam'])->name('drivers.units.transfer-team');
        Route::post('/drivers/team-leaders/{teamLeader}/duty', [DriversController::class, 'setTeamLeaderDuty'])->name('drivers.team-leaders.duty');
        Route::post('/drivers/units/{unit}/slot-duty', [DriversController::class, 'setSlotDuty'])->name('drivers.units.slot-duty');

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
        Route::post('/booking/{booking}/reschedule', [DispatchController::class, 'rescheduleBooking'])->name('booking.reschedule');
        Route::post('/booking/{booking}/save-draft', [DispatchController::class, 'saveQuotationDraft'])->name('booking.save-draft');
        Route::get('/quotations/floating-panel', [DispatchController::class, 'floatingQuotationsPanel'])->name('quotations.floating-panel');
        Route::post('/booking/{booking}/service-fee', [DispatchController::class, 'applyServiceFee'])->name('booking.service-fee');
        Route::post('/booking/{booking}/mark-risk', [DispatchController::class, 'markCustomerRisk'])->name('booking.mark-risk');
        Route::get('/jobs', [JobsController::class, 'index'])->name('jobs');
        Route::post('/jobs/{booking}/confirm-payment', [JobsController::class, 'confirmPayment'])->name('jobs.confirm-payment');
        Route::get('/jobs/{booking}/reassign-options', [JobsController::class, 'reassignOptions'])->name('jobs.reassign-options');
        Route::post('/jobs/{booking}/reassign', [JobsController::class, 'reassign'])->name('jobs.reassign');
        Route::view('/jobs/mock-preview', 'admin-dashboard.pages.jobs-mock')->name('jobs.mock-preview');
        Route::get('/booking-history', [\App\Http\Controllers\Admin\BookingHistoryController::class, 'index'])->name('booking-history');
        Route::post('/booking/{id}/update-status', [DispatchController::class, 'updateStatus'])->name('booking.updateStatus');

        Route::prefix('quotations')->name('quotations.')->group(function () {
            Route::get('/{quotation}/details', [DispatchController::class, 'getQuotationDetails'])->name('details');
            Route::post('/{quotation}/send', [DispatchController::class, 'sendQuotation'])->name('send');
            Route::post('/{quotation}/cancel', [DispatchController::class, 'cancelQuotation'])->name('cancel');
            Route::patch('/{quotation}/update-price', [DispatchController::class, 'updateQuotationPrice'])->name('update-price');
            Route::post('/{quotation}/keep-price', [DispatchController::class, 'keepQuotationPrice'])->name('keep-price');
            Route::post('/{quotation}/adjust-price', [DispatchController::class, 'adjustQuotationPriceAfterReview'])->name('adjust-price');
            Route::patch('/{quotation}/extend', [DispatchController::class, 'extendQuotation'])->name('extend');
            Route::get('/{quotation}/response', [DispatchController::class, 'viewQuotationResponse'])->name('response');
            Route::post('/{quotation}/adjustments/{adjustment}/undo', [DispatchController::class, 'undoPriceAdjustment'])->name('adjustments.undo');
        });

        Route::prefix('invoices')->name('invoices.')->group(function () {
            Route::post('/{invoice}/void', [\App\Http\Controllers\Admin\InvoiceController::class, 'void'])->name('void');
        });
    });

Route::prefix('superadmin')
    ->name('superadmin.')
    ->middleware(['auth', 'role:1', 'force.password.change'])
    ->group(function () {
        Route::get('/dashboard', [SuperAdminController::class, 'index'])->name('dashboard');
        Route::get('/revenue', [ReportsController::class, 'revenue'])->name('revenue.index');
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportsController::class, 'export'])->name('reports.export');
        Route::get('/reports/export-pdf', [ReportsController::class, 'exportSummaryPdf'])->name('reports.export-pdf');
        Route::get('/reports/bookings', [ReportsController::class, 'bookings'])->name('reports.bookings');
        Route::get('/reports/activity', [ReportsController::class, 'activity'])->name('reports.activity');
        Route::get('/reports/activity/export', [ReportsController::class, 'exportActivityPdf'])->name('reports.activity.export');
        Route::get('/monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
        Route::get('/monitoring/live', [MonitoringController::class, 'live'])->name('monitoring.live');

        Route::patch('users/{id}/unlock', [UserManagementController::class, 'unlockCustomer'])->name('users.unlock');

        Route::resource('truck-types', TruckTypeController::class);
        Route::patch('truck-types/{truckType}/toggle', [TruckTypeController::class, 'toggleStatus'])->name('truck-types.toggle');
        Route::get('/truck-type-config/{name}',  [TruckTypeController::class, 'getConfig'])->name('truck-type-config.get');
        Route::post('/truck-type-config/{name}', [TruckTypeController::class, 'saveConfig'])->name('truck-type-config.save');

        Route::post('truck-types/{truckType}/vehicle-types/{vehicleType}/attach', [TruckTypeController::class, 'attachVehicleType'])->name('truck-types.vehicle-types.attach');
        Route::delete('truck-types/{truckType}/vehicle-types/{vehicleType}/detach', [TruckTypeController::class, 'detachVehicleType'])->name('truck-types.vehicle-types.detach');

        Route::get('truck-types-data',              [TruckTypeController::class, 'index'])->name('truck-types.data');
        Route::post('truck-types-data',             [TruckTypeController::class, 'store'])->name('truck-types.data.store');
        Route::put('truck-types-data/{truckType}',  [TruckTypeController::class, 'update'])->name('truck-types.data.update');
        Route::patch('truck-types-data/{truckType}/toggle', [TruckTypeController::class, 'toggleStatus'])->name('truck-types.data.toggle');
        Route::delete('truck-types-data/{truckType}',       [TruckTypeController::class, 'destroy'])->name('truck-types.data.destroy');

        Route::get('/csrf-token', fn() => response()->json(['token' => csrf_token()]))->name('csrf-token');

        Route::get('/vehicle-types', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'index'])->name('vehicle-types.index');
        Route::post('/vehicle-types', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'store'])->name('vehicle-types.store');
        Route::put('/vehicle-types/{vehicleType}', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'update'])->name('vehicle-types.update');
        Route::patch('/vehicle-types/{vehicleType}/toggle', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'toggleStatus'])->name('vehicle-types.toggle');
        Route::patch('/vehicle-types/reorder', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'saveOrder'])->name('vehicle-types.reorder');
        Route::delete('/vehicle-types/{vehicleType}', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'destroy'])->name('vehicle-types.destroy');

        Route::post('/vehicle-categories', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'storeCategory'])->name('vehicle-categories.store');
        Route::put('/vehicle-categories/{vehicleCategory}', [\App\Http\Controllers\SuperAdmin\VehicleTypeController::class, 'updateCategory'])->name('vehicle-categories.update');

        Route::get('/units', [UnitController::class, 'index'])->name('unit-truck.index');
        Route::get('/units/archived', [UnitController::class, 'archived'])->name('units.archived');
        Route::post('/units', [UnitController::class, 'store'])->name('units.store');
        Route::put('/units/{id}', [UnitController::class, 'update'])->name('units.update');
        Route::patch('/units/{id}/toggle',       [UnitController::class, 'toggle'])->name('units.toggle');
        Route::patch('/units/{id}/archive',      [UnitController::class, 'archive'])->name('units.archive');
        Route::patch('/units/{id}/restore',      [UnitController::class, 'restore'])->name('units.restore');
        Route::delete('/units/{id}/force-delete', [UnitController::class, 'forceDelete'])->name('units.force-delete');

        Route::get('/personnel', [\App\Http\Controllers\SuperAdmin\PersonnelController::class, 'index'])->name('personnel.index');
        Route::patch('/personnel/{person}/home-unit', [\App\Http\Controllers\SuperAdmin\PersonnelController::class, 'updateHomeUnit'])->name('personnel.home-unit');
        Route::patch('/personnel/{person}/toggle', [\App\Http\Controllers\SuperAdmin\PersonnelController::class, 'toggleEnabled'])->name('personnel.toggle');
        Route::post('/personnel-records', [\App\Http\Controllers\SuperAdmin\PersonnelController::class, 'store'])->name('personnel-records.store');
        Route::put('/personnel-records/{record}', [\App\Http\Controllers\SuperAdmin\PersonnelController::class, 'update'])->name('personnel-records.update');
        Route::patch('/personnel-records/{record}/home-unit', [\App\Http\Controllers\SuperAdmin\PersonnelController::class, 'updateRecordHomeUnit'])->name('personnel-records.home-unit');
        Route::patch('/personnel-records/{record}/toggle', [\App\Http\Controllers\SuperAdmin\PersonnelController::class, 'toggleRecordStatus'])->name('personnel-records.toggle');
        Route::get('/home-assignments', [\App\Http\Controllers\SuperAdmin\PersonnelController::class, 'homeAssignments'])->name('home-assignments.index');

        Route::get('/bookings', [SuperAdminBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{id}', [SuperAdminBookingController::class, 'show'])->name('bookings.show');

Route::get('/settings', [SystemSettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings/update', [SystemSettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/landing', [SystemSettingsController::class, 'updateLanding'])->name('settings.landing.update');
        Route::post('/settings/upload-apk', [SystemSettingsController::class, 'uploadApk'])->name('settings.upload-apk');
        Route::patch('/settings/mobile-app/toggle', [SystemSettingsController::class, 'toggleAppStatus'])->name('settings.mobile-app.toggle');
        Route::get('/settings/mobile-app/qr-code', [SystemSettingsController::class, 'mobileAppQrCode'])->name('settings.mobile-app.qr-code');

        Route::prefix('settings/customer-content')->name('settings.customer-content.')->group(function () {
            Route::post('/announcements', [CustomerAppContentController::class, 'announcementStore'])->name('announcements.store');
            Route::patch('/announcements/{announcement}', [CustomerAppContentController::class, 'announcementUpdate'])->name('announcements.update');
            Route::patch('/announcements/{announcement}/toggle', [CustomerAppContentController::class, 'announcementToggle'])->name('announcements.toggle');

            Route::post('/services', [CustomerAppContentController::class, 'serviceStore'])->name('services.store');
            Route::patch('/services/{service}', [CustomerAppContentController::class, 'serviceUpdate'])->name('services.update');
            Route::patch('/services/{service}/toggle', [CustomerAppContentController::class, 'serviceToggle'])->name('services.toggle');
            Route::patch('/services/{service}/move', [CustomerAppContentController::class, 'serviceMove'])->name('services.move');

            Route::post('/how-it-works', [CustomerAppContentController::class, 'howItWorksStore'])->name('how-it-works.store');
            Route::patch('/how-it-works/{step}', [CustomerAppContentController::class, 'howItWorksUpdate'])->name('how-it-works.update');
            Route::patch('/how-it-works/{step}/toggle', [CustomerAppContentController::class, 'howItWorksToggle'])->name('how-it-works.toggle');
            Route::patch('/how-it-works/{step}/move', [CustomerAppContentController::class, 'howItWorksMove'])->name('how-it-works.move');

            Route::post('/coverage-areas', [CustomerAppContentController::class, 'coverageAreaStore'])->name('coverage-areas.store');
            Route::patch('/coverage-areas/{coverageArea}', [CustomerAppContentController::class, 'coverageAreaUpdate'])->name('coverage-areas.update');
            Route::patch('/coverage-areas/{coverageArea}/toggle', [CustomerAppContentController::class, 'coverageAreaToggle'])->name('coverage-areas.toggle');
            Route::patch('/coverage-areas/{coverageArea}/move', [CustomerAppContentController::class, 'coverageAreaMove'])->name('coverage-areas.move');

            Route::post('/about', [CustomerAppContentController::class, 'aboutUpdate'])->name('about.update');
            Route::post('/support', [CustomerAppContentController::class, 'supportUpdate'])->name('support.update');
            Route::post('/images', [CustomerAppContentController::class, 'imagesUpdate'])->name('images.update');
        });

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

Route::prefix('system-admin')
    ->name('system-admin.')
    ->middleware(['auth', 'role:6', 'force.password.change'])
    ->group(function () {
        Route::get('/dashboard', [SystemAdminDashboardController::class, 'index'])->name('dashboard');

        Route::get('/profile', [SystemAdminProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [SystemAdminProfileController::class, 'update'])->name('profile.update');
        Route::get('/profile/password', [SystemAdminProfileController::class, 'editPassword'])->name('profile.password.edit');
        Route::put('/profile/password', [SystemAdminProfileController::class, 'updatePassword'])->name('profile.password.update');

        Route::get('users/archived', [UserManagementController::class, 'archived'])->name('users.archived');
        Route::get('users/deleted', [UserManagementController::class, 'deleted'])->name('users.deleted');
        Route::patch('users/{user}/archive', [UserManagementController::class, 'archive'])->name('users.archive');
        Route::patch('users/{id}/restore', [UserManagementController::class, 'restore'])->name('users.restore');
        Route::delete('users/{id}/queue-for-deletion', [UserManagementController::class, 'queueForDeletion'])->name('users.queue-for-deletion');
        Route::patch('users/{id}/restore-from-deleted', [UserManagementController::class, 'restoreFromDeleted'])->name('users.restore-from-deleted');
        Route::delete('users/{id}/purge-now', [UserManagementController::class, 'purgeNow'])->name('users.purge-now');
        Route::patch('users/{id}/toggle', [UserManagementController::class, 'toggleStatus'])->name('users.toggle');
        Route::resource('users', UserManagementController::class)->except(['show']);

        Route::get('/security/monitor', [SecurityMonitorController::class, 'index'])->name('security.monitor');

        Route::get('/audit-logs', [SystemAdminAuditLogController::class, 'index'])->name('audit-logs.index');

        Route::get('/settings', [SystemAdminSystemSettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings/update', [SystemAdminSystemSettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/upload-apk', [SystemAdminSystemSettingsController::class, 'uploadApk'])->name('settings.upload-apk');

        Route::get('/maintenance', [DataProtectionController::class, 'index'])->name('maintenance.index');
        Route::post('/maintenance/backups', [DataProtectionController::class, 'store'])->name('maintenance.backups.store');
        Route::get('/maintenance/backups/download', [DataProtectionController::class, 'download'])->name('maintenance.backups.download');
    });

Route::middleware(['auth', 'role:5'])
    ->prefix('customer')
    ->name('customer.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/book', function (\App\Services\BookingService $bookingService) {
            $classes   = ['light', 'medium', 'heavy'];
            $truckTypes = TruckType::where('status', 'active')->orderBy('base_rate')->get();

            $readyByClass = $bookingService->dispatchAvailability()['ready_by_class'];

            $classData = collect($classes)->mapWithKeys(function ($cls) use ($truckTypes, $readyByClass) {
                $group          = $truckTypes->where('class', $cls)->values();
                $availableUnits = (int) ($readyByClass[$cls] ?? 0);
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

