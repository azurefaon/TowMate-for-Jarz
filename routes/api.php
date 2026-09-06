<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CustomerBookingController;
use App\Http\Controllers\Api\TeamLeader\TLAuthController;
use App\Http\Controllers\Api\TeamLeader\TLPresenceController;
use App\Http\Controllers\Api\TeamLeader\TLTaskController;
use App\Http\Controllers\Api\TeamLeader\TLLocationController;
use App\Http\Controllers\Api\CustomerQuotationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\CustomerContentController;
use App\Http\Controllers\GeoController;

Route::get('/test', function () {
    return response()->json(['message' => 'API working']);
});

Route::post('/register',                [AuthController::class, 'register']);
Route::post('/register/send-otp',       [AuthController::class, 'sendRegistrationOtp'])->middleware('throttle:customer-otp-send');
Route::post('/register/verify-otp',     [AuthController::class, 'verifyRegistrationOtp'])->middleware('throttle:customer-otp-verify');
Route::post('/login',                   [AuthController::class, 'login']);

Route::post('/password/forgot',     [PasswordResetController::class, 'sendOtp'])->middleware('throttle:customer-otp-send');
Route::post('/password/verify-otp', [PasswordResetController::class, 'verifyOtp'])->middleware('throttle:customer-otp-verify');
Route::post('/password/reset',      [PasswordResetController::class, 'resetPassword'])->middleware('throttle:customer-password-reset-submit');

Route::get('/v1/customer/content', [CustomerContentController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', function (Request $request) {
        return response()->json($request->user()->load('role'));
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('v1/profile',                        [AuthController::class, 'profile']);
    Route::post('v1/profile/update',               [AuthController::class, 'updateProfile']);
    Route::post('v1/profile/change-password',      [AuthController::class, 'changePassword']);
    Route::post('v1/profile/email/request-otp',    [AuthController::class, 'requestEmailChangeOtp']);
    Route::post('v1/profile/email/confirm',        [AuthController::class, 'confirmEmailChange']);

    Route::prefix('v1')->group(function () {
        Route::get('truck-types',  [CustomerBookingController::class, 'truckTypes']);
        Route::get('availability', [CustomerBookingController::class, 'availability']);
        Route::get('bookings/current', [CustomerBookingController::class, 'currentBooking']);
        Route::get('bookings/history', [CustomerBookingController::class, 'bookingHistory']);
        Route::post('bookings', [CustomerBookingController::class, 'createBooking'])->middleware('throttle:customer-booking-create');
        Route::get('bookings/{code}/detail', [CustomerBookingController::class, 'detail']);
        Route::get('bookings/{code}/receipt', [CustomerBookingController::class, 'receipt']);
        Route::post('bookings/{code}/cancel', [CustomerBookingController::class, 'cancelBooking'])->middleware('throttle:customer-booking-cancel');

        Route::middleware('throttle:api-geo-proxy')->group(function () {
            Route::get('geo/search', [GeoController::class, 'search']);
            Route::post('geo/route', [GeoController::class, 'route']);
            Route::get('geo/reverse', [GeoController::class, 'reverse']);
            Route::get('geo/autocomplete', [GeoController::class, 'autocomplete']);
            Route::get('geo/place-details', [GeoController::class, 'placeDetails']);
        });

        Route::middleware('throttle:customer-notifications')->group(function () {
            Route::get('notifications',            [NotificationController::class, 'index']);
            Route::post('notifications/mark-read', [NotificationController::class, 'markAllRead']);
            Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
        });

        Route::prefix('quotations')->group(function () {
            Route::get('pending',              [CustomerQuotationController::class, 'pending']);
            Route::post('{quotation}/accept',   [CustomerQuotationController::class, 'accept'])->middleware('throttle:customer-quotation-action');
            Route::post('{quotation}/reject',   [CustomerQuotationController::class, 'reject'])->middleware('throttle:customer-quotation-action');
            Route::post('{quotation}/inquire',  [CustomerQuotationController::class, 'inquire'])->middleware('throttle:customer-quotation-action');
            Route::post('{quotation}/request-price-review', [CustomerQuotationController::class, 'requestPriceReview'])->middleware('throttle:customer-quotation-action');
        });

        Route::apiResource('bookings', BookingController::class)->except(['store']);
        Route::get('/bookings/{booking}/track', [BookingController::class, 'show']);
    });

    Route::post('v1/team-leader/auth/change-password', [TLAuthController::class, 'changePassword']);

    Route::prefix('v1/team-leader')
        ->middleware(['tl', 'password_changed'])
        ->group(function () {
            Route::post('presence/ping',           [TLPresenceController::class, 'ping'])->middleware('throttle:tl-presence');
            Route::post('presence/offline',        [TLPresenceController::class, 'offline'])->middleware('throttle:tl-presence');
            Route::post('presence/away',           [TLPresenceController::class, 'away'])->middleware('throttle:tl-presence');
            Route::get('task',                    [TLTaskController::class, 'current']);
            Route::get('history',                 [TLTaskController::class, 'history']);
            Route::post('task/{booking}/accept',   [TLTaskController::class, 'accept'])->middleware('throttle:tl-task-mutate');
            Route::patch('task/{booking}/status',   [TLTaskController::class, 'updateStatus'])->middleware('throttle:tl-task-mutate');
            Route::post('task/{booking}/return',   [TLTaskController::class, 'returnTask'])->middleware('throttle:tl-task-mutate');
            Route::post('task/{booking}/photo',    [TLTaskController::class, 'uploadPhoto'])->middleware('throttle:tl-upload');
            Route::post('task/{booking}/complete', [TLTaskController::class, 'complete'])->middleware('throttle:tl-task-mutate');
            Route::post('group/{groupCode}/claim-next', [TLTaskController::class, 'claimNext'])->middleware('throttle:tl-task-mutate');
            Route::put('location',                [TLLocationController::class, 'update'])->middleware('throttle:tl-location');
        });
});
