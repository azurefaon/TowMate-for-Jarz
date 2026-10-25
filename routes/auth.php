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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    // Public sign-up creates Customer accounts only; cap it per IP so it cannot be used to mass-create accounts.
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:10,60');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::get('dispatcher/login', [AuthenticatedSessionController::class, 'createDispatcher'])
        ->name('dispatcher.login');

    Route::get('teamleader/login', [AuthenticatedSessionController::class, 'createTeamLeader'])
        ->name('teamleader.login');

    Route::get('system-admin/login', [AuthenticatedSessionController::class, 'createSystemAdmin'])
        ->name('system-admin.login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

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

    // Retired unlock-only recovery. Every legacy entry point now lands on the password reset flow,
    // the only path that clears a sign-in lock, and only after a successful password change.
    Route::get('login/recover', function (Request $request) {
        $email = trim((string) $request->query('email', ''));

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            session(['recovery_prefill' => ['email' => $email, 'until' => now()->addMinutes(10)]]);
        }

        return redirect()->route('password.request');
    })->name('login.recover');

    Route::match(['get', 'post'], 'login/recover/{path?}', fn () => redirect()->route('password.request'))
        ->where('path', '.*');
});

Route::middleware('auth')->group(function () {
    Route::get('password/change-required', [ForcePasswordChangeController::class, 'show'])
        ->name('password.force-change');

    Route::post('password/change-required', [ForcePasswordChangeController::class, 'update'])
        ->middleware('throttle:5,1')
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
