<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\StaffPasswordResetOtpMail;
use App\Models\AuditLog;
use App\Models\StaffPasswordResetOtp;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;


class StaffPasswordResetController extends Controller
{
    private const OTP_TTL_MINUTES = 5;
    private const VERIFIED_AUTHORIZATION_TTL_MINUTES = 10;
    private const MAX_FAILED_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 300;
    private const REQUEST_RATE_LIMIT = 3;
    private const REQUEST_RATE_DECAY_MINUTES = 15;


    public function create(): View
    {
        return view('auth.staff-forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));

        $genericMessage = 'If an account exists for that email, a verification code has been sent.';

        $rateKey = 'staff-pwreset-request:' . $email;
        if (RateLimiter::tooManyAttempts($rateKey, self::REQUEST_RATE_LIMIT)) {
            session(['staff_pw_reset_email' => $email]);
            return redirect()->route('password.otp.show')->with('status', $genericMessage);
        }
        RateLimiter::hit($rateKey, self::REQUEST_RATE_DECAY_MINUTES * 60);

        $this->issueOtpIfEligible($email);

        session([
            'staff_pw_reset_email' => $email,
            'staff_pw_reset_last_sent_at' => now(),
        ]);

        return redirect()->route('password.otp.show')->with('status', $genericMessage);
    }

    public function resend(Request $request): RedirectResponse
    {
        $email = session('staff_pw_reset_email');

        if (! $email) {
            return redirect()->route('password.request');
        }

        $remaining = $this->resendWaitSeconds();
        if ($remaining > 0) {
            return back()->withErrors(['otp' => 'Please wait before requesting a new code.']);
        }

        $genericMessage = 'If an account exists for that email, a new verification code has been sent.';

        $rateKey = 'staff-pwreset-request:' . $email;
        if (RateLimiter::tooManyAttempts($rateKey, self::REQUEST_RATE_LIMIT)) {
            return back()->with('status', $genericMessage);
        }
        RateLimiter::hit($rateKey, self::REQUEST_RATE_DECAY_MINUTES * 60);

        $this->issueOtpIfEligible($email);

        session(['staff_pw_reset_last_sent_at' => now()]);

        return back()->with('status', $genericMessage)->with('otp_resent', true);
    }

    private function issueOtpIfEligible(string $email): void
    {
        $user = User::where('email', $email)
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereNull('anonymized_at')
            ->whereNull('pending_delete_at')
            ->whereHas('role', function ($query) {
                $query->whereNotIn('id', [1, 4, 5]); // exclude Owner, Driver, Customer
            })
            ->first();

        if (! $user) {
            return;
        }

        $otp = (string) random_int(100000, 999999);

        StaffPasswordResetOtp::updateOrCreate(
            ['email' => $email],
            [
                'otp_hash' => Hash::make($otp),
                'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
                'verified_at' => null,
                'failed_attempts' => 0,
                'last_sent_at' => now(),
            ]
        );

        Mail::to($user->email)->queue(new StaffPasswordResetOtpMail($user, $otp));

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'staff_password_reset_otp_sent',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);
    }


    public function showVerify(): View|RedirectResponse
    {
        $email = session('staff_pw_reset_email');

        if (! $email) {
            return redirect()->route('password.request');
        }

        return view('auth.staff-verify-otp', [
            'maskedEmail' => $this->maskEmail($email),
            'resendWaitSeconds' => $this->resendWaitSeconds(),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $email = session('staff_pw_reset_email');

        if (! $email) {
            return redirect()->route('password.request');
        }

        $validated = $request->validate([
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);

        $verifyRateKey = 'staff-pwreset-verify:' . $email;
        if (RateLimiter::tooManyAttempts($verifyRateKey, 15)) {
            return back()->withErrors(['otp' => 'Too many attempts. Please wait a moment and try again.']);
        }
        RateLimiter::hit($verifyRateKey, 60);

        $record = StaffPasswordResetOtp::where('email', $email)->first();

        if (! $record || $record->verified_at) {
            return back()->withErrors(['otp' => 'Verification code expired. Request a new code.']);
        }

        if ($record->isExpired()) {
            return back()->withErrors(['otp' => 'Verification code expired. Request a new code.']);
        }

        if ($record->failed_attempts >= self::MAX_FAILED_ATTEMPTS) {
            $record->delete();
            return back()->withErrors(['otp' => 'Too many incorrect attempts. Request a new code.']);
        }

        if (! Hash::check($validated['otp'], $record->otp_hash)) {
            $record->increment('failed_attempts');
            return back()->withErrors(['otp' => 'Incorrect verification code.']);
        }

        $record->update(['verified_at' => now()]);

        session([
            'staff_pw_reset_verified_email' => $email,
            'staff_pw_reset_verified_until' => now()->addMinutes(self::VERIFIED_AUTHORIZATION_TTL_MINUTES),
        ]);

        RateLimiter::clear($verifyRateKey);

        return redirect()->route('password.reset');
    }

    public function showReset(): View|RedirectResponse
    {
        if (! $this->hasValidResetAuthorization()) {
            return redirect()->route('password.request')
                ->withErrors(['email' => 'Please verify your email again to reset your password.']);
        }

        return view('auth.staff-reset-password');
    }

    public function reset(Request $request): RedirectResponse
    {
        if (! $this->hasValidResetAuthorization()) {
            return redirect()->route('password.request')
                ->withErrors(['email' => 'Please verify your email again to reset your password.']);
        }

        $email = session('staff_pw_reset_verified_email');

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->clearResetSession();
            return redirect()->route('password.request')
                ->withErrors(['email' => 'Please verify your email again to reset your password.']);
        }

        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors([
                'password' => 'Your new password cannot be the same as your current password.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
            'must_change_password' => false,
        ])->save();

        $user->tokens()->delete();
        $request->session()->regenerate();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        StaffPasswordResetOtp::where('email', $email)->delete();
        $this->clearResetSession();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'staff_password_reset_completed',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'description' => 'Password reset via web OTP flow.',
        ]);

        return redirect()->route('password.reset.success');
    }

    public function success(): View
    {
        return view('auth.staff-reset-success');
    }

    private function hasValidResetAuthorization(): bool
    {
        $email = session('staff_pw_reset_verified_email');
        $until = session('staff_pw_reset_verified_until');

        return $email && $until && now()->isBefore($until);
    }

    private function clearResetSession(): void
    {
        session()->forget([
            'staff_pw_reset_email',
            'staff_pw_reset_verified_email',
            'staff_pw_reset_verified_until',
            'staff_pw_reset_last_sent_at',
        ]);
    }

    private function resendWaitSeconds(): int
    {
        $lastSentAt = session('staff_pw_reset_last_sent_at');

        if (! $lastSentAt) {
            return 0;
        }

        $elapsed = now()->diffInSeconds(\Illuminate\Support\Carbon::parse($lastSentAt), true);

        return max(0, self::RESEND_COOLDOWN_SECONDS - $elapsed);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($local === '') {
            return $email;
        }

        $visible = mb_substr($local, 0, 1);

        return $visible . str_repeat('*', max(mb_strlen($local) - 1, 3)) . '@' . $domain;
    }
}
