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

    // Web accounts that can sign in on the staff portal and therefore reset here:
    // Owner (1), Admin/Dispatcher (2) and System Admin (6). Team Leaders (3) use the
    // mobile app's reset and Customers (5) the customer app's.
    private const ELIGIBLE_ROLE_IDS = [1, 2, 6];

    // Shown for every syntactically valid email, registered or not.
    private const GENERIC_MESSAGE = 'If an account is associated with this email, a verification code will be sent.';
    private const INVALID_CODE_MESSAGE = 'Invalid or expired verification code. Request a new code if needed.';


    public function create(): View
    {
        // Set when a sign-in lock is shown (LoginRequest) or a legacy recovery link is opened. It lives
        // in the session for a few minutes and is consumed here, so the email never appears in a URL.
        $prefill = session()->pull('recovery_prefill');
        $email = is_array($prefill) && now()->isBefore($prefill['until'] ?? now()) ? (string) ($prefill['email'] ?? '') : '';

        return view('auth.staff-forgot-password', [
            'prefillEmail' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));

        $genericMessage = self::GENERIC_MESSAGE;

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
            'staff_pw_reset_code_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
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

        $genericMessage = self::GENERIC_MESSAGE;

        $rateKey = 'staff-pwreset-request:' . $email;
        if (RateLimiter::tooManyAttempts($rateKey, self::REQUEST_RATE_LIMIT)) {
            return back()->with('status', $genericMessage);
        }
        RateLimiter::hit($rateKey, self::REQUEST_RATE_DECAY_MINUTES * 60);

        $this->issueOtpIfEligible($email);

        session([
            'staff_pw_reset_last_sent_at' => now(),
            'staff_pw_reset_code_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
        ]);

        return back()->with('status', $genericMessage)->with('otp_resent', true);
    }

    /**
     * The single definition of "may reset a password here": active, not archived, anonymized or
     * pending deletion, and holding a web-staff role. Used when issuing a code and again when the
     * password is saved, so an account disabled mid-flow cannot finish a reset.
     */
    private function eligibleUsers(string $email)
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereNull('anonymized_at')
            ->whereNull('pending_delete_at')
            ->whereIn('role_id', self::ELIGIBLE_ROLE_IDS);
    }

    private function issueOtpIfEligible(string $email): void
    {
        $user = $this->eligibleUsers($email)->first();

        // The bcrypt hash is the one expensive step, so it runs for every request. Unknown and
        // ineligible emails then cost about the same CPU as real ones, without any artificial sleep.
        $otp = (string) random_int(100000, 999999);
        $otpHash = Hash::make($otp);

        if (! $user) {
            return;
        }

        StaffPasswordResetOtp::updateOrCreate(
            ['email' => $email],
            [
                'otp_hash' => $otpHash,
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
            'codeSecondsLeft' => $this->codeSecondsLeft(),
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

        // Counting attempts and flipping verified_at happen under a row lock so
        // concurrent guesses cannot exceed the cap and one code cannot verify twice.
        $verified = DB::transaction(function () use ($email, $validated) {
            $record = StaffPasswordResetOtp::where('email', $email)->lockForUpdate()->first();

            if (! $record || $record->verified_at || $record->isExpired()) {
                return false;
            }

            if ($record->failed_attempts >= self::MAX_FAILED_ATTEMPTS) {
                $record->delete();

                return false;
            }

            if (! Hash::check($validated['otp'], $record->otp_hash)) {
                $record->increment('failed_attempts');

                return false;
            }

            $record->update(['verified_at' => now()]);

            return true;
        });

        if (! $verified) {
            return back()->withErrors(['otp' => self::INVALID_CODE_MESSAGE]);
        }

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

        // Password::defaults() (AppServiceProvider) is the project-wide policy: 12+ characters,
        // upper and lower case, a number, a symbol, and not found in a known data breach.
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'password.required' => 'Enter a new password.',
            'password.confirmed' => 'The passwords do not match.',
            'password.min' => 'Use at least 12 characters.',
            'password.password.mixed' => 'Include both uppercase and lowercase letters.',
            'password.password.numbers' => 'Include at least one number.',
            'password.password.symbols' => 'Include at least one special character.',
            'password.password.uncompromised' => 'This password has appeared in a known data breach. Choose a different one.',
        ]);

        $newHash = Hash::make($validated['password']);

        // Lock order: otp row, then user row. Consuming the verified code is the
        // first write, so a second concurrent reset finds no code and fails.
        $user = DB::transaction(function () use ($email, $validated, $newHash) {
            $record = StaffPasswordResetOtp::where('email', $email)
                ->whereNotNull('verified_at')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                return null;
            }

            $user = $this->eligibleUsers($email)->lockForUpdate()->first();

            if (! $user) {
                return null;
            }

            if (Hash::check($validated['password'], $user->password)) {
                return 'same';
            }

            $consumed = StaffPasswordResetOtp::where('id', $record->id)
                ->whereNotNull('verified_at')
                ->delete();

            if ($consumed !== 1) {
                return null;
            }

            $user->forceFill([
                'password' => $newHash,
                'remember_token' => Str::random(60),
                'must_change_password' => false,
                // The lock is lifted only here, once the password has really been changed.
                'failed_login_attempts' => 0,
                'last_failed_login_at' => null,
                'locked_until' => null,
            ])->save();

            return $user;
        });

        if ($user === 'same') {
            return back()->withErrors([
                'password' => 'Your new password cannot be the same as your current password.',
            ]);
        }

        if (! $user) {
            $this->clearResetSession();

            return redirect()->route('password.request')
                ->withErrors(['email' => 'Please verify your email again to reset your password.']);
        }

        $user->tokens()->delete();
        $request->session()->regenerate();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        $this->clearResetSession();

        // The sign-in throttle for this email and address would otherwise still count the failed attempts.
        RateLimiter::clear(Str::transliterate('staff-login|' . Str::lower((string) $user->email) . '|' . $request->ip()));

        // Lets the next successful sign-in be audited as the first one after recovery.
        session([
            'login_recovered_email' => $user->email,
            'login_recovered_until' => now()->addMinutes(30),
        ]);

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
            'staff_pw_reset_code_expires_at',
        ]);
    }

    /**
     * Seconds the current code stays valid, for the page countdown only; verify() still enforces the
     * real expiry. It is read only from the session value recorded when this browser requested a code
     * (the same instant the stored code expires). Reading the stored record instead would let someone
     * tell a real account from an unknown one, for example after hitting the request rate limit.
     */
    private function codeSecondsLeft(): int
    {
        $expiresAt = session('staff_pw_reset_code_expires_at');

        if (! $expiresAt) {
            return 0;
        }

        return max(0, (int) floor(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($expiresAt), false)));
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
