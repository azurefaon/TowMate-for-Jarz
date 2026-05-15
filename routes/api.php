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
use App\Http\Controllers\GeoController;

Route::get('/test', function () {
    return response()->json(['message' => 'API working']);
});

// Public auth routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', function (Request $request) {
        return response()->json($request->user()->load('role'));
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('v1/profile', [AuthController::class, 'profile']);

    Route::prefix('v1')->group(function () {
        // Customer-specific routes — must be defined before apiResource to avoid wildcard collisions
        Route::get('truck-types',  [CustomerBookingController::class, 'truckTypes']);
        Route::get('availability', [CustomerBookingController::class, 'availability']);
        Route::get('bookings/current', [CustomerBookingController::class, 'currentBooking']);
        Route::get('bookings/history', [CustomerBookingController::class, 'bookingHistory']);
        Route::post('bookings', [CustomerBookingController::class, 'createBooking']);
        Route::get('bookings/{code}/detail', [CustomerBookingController::class, 'detail']);

        // Geo — reuse existing controller, exposed over sanctum-authenticated API
        Route::get('geo/search', [GeoController::class, 'search']);
        Route::post('geo/route', [GeoController::class, 'route']);
        Route::get('geo/reverse', [GeoController::class, 'reverse']);

        // Customer quotation routes (in-app flow)
        Route::prefix('quotations')->group(function () {
            Route::get('pending',              [CustomerQuotationController::class, 'pending']);
            Route::post('{quotation}/accept',  [CustomerQuotationController::class, 'accept']);
            Route::post('{quotation}/reject',  [CustomerQuotationController::class, 'reject']);
        });

        // Legacy resource (index/show/update/destroy still go here)
        Route::apiResource('bookings', BookingController::class)->except(['store']);
        Route::get('/bookings/{booking}/track', [BookingController::class, 'show']);
    });

    // Team Leader — password change (no password_changed guard, that's the point)
    Route::post('v1/team-leader/auth/change-password', [TLAuthController::class, 'changePassword']);

    // Team Leader — task & location (guarded by role + password_changed)
    Route::prefix('v1/team-leader')
        ->middleware(['tl', 'password_changed'])
        ->group(function () {
            Route::post('presence/ping',           [TLPresenceController::class, 'ping']);
            Route::post('presence/offline',        [TLPresenceController::class, 'offline']);
            Route::get('task',                    [TLTaskController::class, 'current']);
            Route::post('task/{booking}/accept',   [TLTaskController::class, 'accept']);
            Route::patch('task/{booking}/status',   [TLTaskController::class, 'updateStatus']);
            Route::post('task/{booking}/return',   [TLTaskController::class, 'returnTask']);
            Route::post('task/{booking}/photo',    [TLTaskController::class, 'uploadPhoto']);
            Route::post('task/{booking}/complete', [TLTaskController::class, 'complete']);
            Route::post('task/{booking}/resend-otp', [TLTaskController::class, 'resendOtp']);
            Route::put('location',                [TLLocationController::class, 'update']);
        });
});
