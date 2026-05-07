<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CustomerBookingController;
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
        Route::get('truck-types', [CustomerBookingController::class, 'truckTypes']);
        Route::get('bookings/current', [CustomerBookingController::class, 'currentBooking']);
        Route::get('bookings/history', [CustomerBookingController::class, 'bookingHistory']);
        Route::post('bookings', [CustomerBookingController::class, 'createBooking']);

        // Geo — reuse existing controller, exposed over sanctum-authenticated API
        Route::get('geo/search', [GeoController::class, 'search']);
        Route::post('geo/route', [GeoController::class, 'route']);
        Route::get('geo/reverse', [GeoController::class, 'reverse']);

        // Legacy resource (index/show/update/destroy still go here)
        Route::apiResource('bookings', BookingController::class)->except(['store']);
        Route::get('/bookings/{booking}/track', [BookingController::class, 'show']);
    });
});
