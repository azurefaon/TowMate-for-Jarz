<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\LoginUnlockOtpMail;
use App\Models\AuditLog;
use App\Models\LoginUnlockOtp;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class LoginUnlockController extends Controller
{
    private const OTP_TTL_MINUTES = 5;
    private const MAX_FAILED_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const REQUEST_RATE_LIMIT = 3;
    private const REQUEST_RATE_DECAY_MINUTES = 15;
    private const RECOVERED_LOGIN_WINDOW_MINUTES = 30;

    public function create(Request $request): View
    {
        $email = strtolower(trim((string) $request->query('email', session('login_locked_email', ''))));

        return view('auth.login-recover', [
            'email' => $email,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));

        $genericMessage = 'If that account is locked, a verification code has been sent to its email address.';

        $rateKey = 'login-unlock-request:' . $email;
        if (RateLimiter::tooManyAttempts($rateKey, self::REQUEST_RATE_LIMIT)) {
            session(['login_recover_email' => $email]);
            return redirect()->route('login.recover.verify')->with('status', $genericMessage);
        }
        RateLimiter::hit($rateKey, self::REQUEST_RATE_DECAY_MINUTES * 60);

        $this->issueOtpIfEligible($email);

        session(['login_recover_email' => $email]);

        return redirect()->route('login.recover.verify')->with('status', $genericMessage);
    }

    public function resend(Request $request): RedirectResponse
    {
        $email = session('login_recover_email');

        if (! $email) {
            return redirect()->route('login.recover');
        }

        $genericMessage = 'If that account is locked, a new verification code has been sent.';

        $rateKey = 'login-unlock-request:' . $email;
        if (RateLimiter::tooManyAttempts($rateKey, self::REQUEST_RATE_LIMIT)) {
            return back()->with('status', $genericMessage);
        }

        $existing = LoginUnlockOtp::where('email', $email)->first();
        if ($existing && $existing->last_sent_at && $existing->last_sent_at->addSeconds(self::RESEND_COOLDOWN_SECONDS)->isFuture()) {
            return back()->with('status', $genericMessage);
        }

        RateLimiter::hit($rateKey, self::REQUEST_RATE_DECAY_MINUTES * 60);

        $this->issueOtpIfEligible($email);

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
                $query->whereIn('id', [1, 2, 3, 6]);
            })
            ->first();

        if (! $user) {
            return;
        }

        $otp = (string) random_int(100000, 999999);

        LoginUnlockOtp::updateOrCreate(
            ['email' => $email],
            [
                'otp_hash' => Hash::make($otp),
                'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
                'verified_at' => null,
                'failed_attempts' => 0,
                'last_sent_at' => now(),
            ]
        );

        Mail::to($user->email)->queue(new LoginUnlockOtpMail($user, $otp));

        try {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'unlock_otp_sent',
                'category' => 'security',
                'entity_type' => 'User',
                'entity_id' => $user->id,
            ]);
        } catch (\Throwable) {
        }
    }

    public function showVerify(): View|RedirectResponse
    {
        $email = session('login_recover_email');

        if (! $email) {
            return redirect()->route('login.recover');
        }

        return view('auth.login-recover-verify', [
            'maskedEmail' => $this->maskEmail($email),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $email = session('login_recover_email');

        if (! $email) {
            return redirect()->route('login.recover');
        }

        $validated = $request->validate([
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);

        $verifyRateKey = 'login-unlock-verify:' . $email;
        if (RateLimiter::tooManyAttempts($verifyRateKey, 15)) {
            return back()->withErrors(['otp' => 'Too many attempts. Please wait a moment and try again.']);
        }
        RateLimiter::hit($verifyRateKey, 60);

        $record = LoginUnlockOtp::where('email', $email)->first();

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

            $this->auditUnlockFailure($email);

            return back()->withErrors(['otp' => 'Incorrect verification code.']);
        }

        $record->update(['verified_at' => now()]);
        RateLimiter::clear($verifyRateKey);

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            try {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'account_unlocked_via_email',
                    'category' => 'security',
                    'entity_type' => 'User',
                    'entity_id' => $user->id,
                    'description' => 'Sign-in lock cleared via email verification.',
                ]);
            } catch (\Throwable) {
            }

            session([
                'login_recovered_email' => $user->email,
                'login_recovered_until' => now()->addMinutes(self::RECOVERED_LOGIN_WINDOW_MINUTES),
            ]);
        }

        session()->forget('login_recover_email');

        return redirect()->route('login.recover.success');
    }

    public function success(): View
    {
        return view('auth.login-recover-success');
    }

    private function auditUnlockFailure(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return;
        }

        try {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'unlock_otp_failed',
                'category' => 'security',
                'entity_type' => 'User',
                'entity_id' => $user->id,
                'description' => 'Incorrect account-recovery verification code entered.',
            ]);
        } catch (\Throwable) {
        }
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
