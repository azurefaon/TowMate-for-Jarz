<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\StaffPasswordResetController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->middleware('guest')
        ->name('login');

    Route::get('dispatcher/login', [AuthenticatedSessionController::class, 'createDispatcher'])
        ->middleware('guest')
        ->name('dispatcher.login');

    Route::get('teamleader/login', [AuthenticatedSessionController::class, 'createTeamLeader'])
        ->middleware('guest')
        ->name('teamleader.login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // Staff (Dispatcher / Team Leader) password recovery — OTP-based.
    // Separate from the Customer mobile app's own OTP flow (Api\PasswordResetController)
    // and from the retired manual "access request" flow.
    Route::get('forgot-password', [StaffPasswordResetController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [StaffPasswordResetController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('forgot-password/verify', [StaffPasswordResetController::class, 'showVerify'])
        ->name('password.otp.show');

    Route::post('forgot-password/verify', [StaffPasswordResetController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('password.otp.verify');

    Route::post('forgot-password/resend', [StaffPasswordResetController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('password.otp.resend');

    Route::get('reset-password', [StaffPasswordResetController::class, 'showReset'])
        ->name('password.reset');

    Route::post('reset-password', [StaffPasswordResetController::class, 'reset'])
        ->middleware('throttle:6,1')
        ->name('password.store');

    Route::get('reset-password/success', [StaffPasswordResetController::class, 'success'])
        ->name('password.reset.success');
});

Route::middleware('auth')->group(function () {
    // Force password change on first login (OWASP A01, A07).
    // Exempt from the force.password.change middleware itself — declared before other protected routes.
    Route::get('password/change-required', [ForcePasswordChangeController::class, 'show'])
        ->name('password.force-change');

    Route::post('password/change-required', [ForcePasswordChangeController::class, 'update'])
        ->middleware('throttle:5,1')  // A07 — rate-limit to prevent brute-force
        ->name('password.force-change.update');

    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
