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

/**
 * Web STAFF (Dispatcher / Team Leader) password recovery — OTP-based.
 *
 * Deliberately separate from:
 *  - Api\PasswordResetController (Customer mobile app's own OTP flow)
 *  - the old PasswordResetLinkController "manual access request" flow (retired)
 *  - NewPasswordController (stock Breeze token-reset scaffolding, was already
 *    dead/unreachable — nothing ever called Password::sendResetLink())
 *
 * Authorization model: server-side SESSION state only. There is no bearer
 * token and no email/user identifier ever accepted from request input on the
 * reset step — the account being reset is derived exclusively from
 * session('staff_pw_reset_verified_email'), which is only ever set here,
 * after a real OTP has been verified.
 */
class StaffPasswordResetController extends Controller
{
    private const OTP_TTL_MINUTES = 5;
    private const VERIFIED_AUTHORIZATION_TTL_MINUTES = 10;
    private const MAX_FAILED_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const REQUEST_RATE_LIMIT = 3;
    private const REQUEST_RATE_DECAY_MINUTES = 15;

    // ── Step 1: request an OTP ──────────────────────────────────────────

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
            // Still generic — do not reveal whether the throttle fired because
            // the account exists or not.
            session(['staff_pw_reset_email' => $email]);
            return redirect()->route('password.otp.show')->with('status', $genericMessage);
        }
        RateLimiter::hit($rateKey, self::REQUEST_RATE_DECAY_MINUTES * 60);

        $this->issueOtpIfEligible($email);

        // Always the same response shape regardless of whether the email
        // matched an eligible account — see OWASP audit (enumeration).
        session(['staff_pw_reset_email' => $email]);

        return redirect()->route('password.otp.show')->with('status', $genericMessage);
    }

    public function resend(Request $request): RedirectResponse
    {
        $email = session('staff_pw_reset_email');

        if (! $email) {
            return redirect()->route('password.request');
        }

        $genericMessage = 'If an account exists for that email, a new verification code has been sent.';

        $rateKey = 'staff-pwreset-request:' . $email;
        if (RateLimiter::tooManyAttempts($rateKey, self::REQUEST_RATE_LIMIT)) {
            return back()->with('status', $genericMessage);
        }

        $existing = StaffPasswordResetOtp::where('email', $email)->first();
        if ($existing && $existing->last_sent_at && $existing->last_sent_at->addSeconds(self::RESEND_COOLDOWN_SECONDS)->isFuture()) {
            return back()->with('status', $genericMessage);
        }

        RateLimiter::hit($rateKey, self::REQUEST_RATE_DECAY_MINUTES * 60);

        $this->issueOtpIfEligible($email);

        return back()->with('status', $genericMessage)->with('otp_resent', true);
    }

    /**
     * Eligible = a real Dispatcher/Team Leader account (same role scope the
     * old access-request flow used) that is ALSO actually able to log in —
     * mirrors LoginRequest::attemptPasswordAuthentication() exactly (status
     * must be 'active'; visibleToOperations() excludes archived_at/
     * anonymized_at) plus pending_delete_at, which login doesn't check but
     * a recovery flow must never bypass. Fixed under the OWASP Phase 2
     * remediation — previously only anonymized_at was excluded here, so an
     * inactive/archived/pending-deletion staff account could silently
     * receive a working OTP. The public response stays identical either
     * way (see store()/resend()) so this is never observable externally.
     */
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

    // ── Step 2: verify the OTP ───────────────────────────────────────────

    public function showVerify(): View|RedirectResponse
    {
        $email = session('staff_pw_reset_email');

        if (! $email) {
            return redirect()->route('password.request');
        }

        return view('auth.staff-verify-otp', [
            'maskedEmail' => $this->maskEmail($email),
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
            // Either no OTP exists, or this one was already consumed by an
            // earlier successful verification — one-time use, no re-verification.
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

        // Correct — mark verified, invalidate the OTP for further verification,
        // and grant a short-lived, session-bound authorization to reset the password.
        $record->update(['verified_at' => now()]);

        session([
            'staff_pw_reset_verified_email' => $email,
            'staff_pw_reset_verified_until' => now()->addMinutes(self::VERIFIED_AUTHORIZATION_TTL_MINUTES),
        ]);

        RateLimiter::clear($verifyRateKey);

        return redirect()->route('password.reset');
    }

    // ── Step 3: set a new password ───────────────────────────────────────

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

        // The account is derived ONLY from server-side session state — never
        // from any request input — so it cannot be redirected to another
        // email/user via a hidden field or tampered request body.
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

        // A07:2025 — reject reuse of the current password, same rule as
        // ForcePasswordChangeController's first-login flow.
        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors([
                'password' => 'Your new password cannot be the same as your current password.',
            ]);
        }

        // Only the password (+ must_change_password) changes. status /
        // archived_at / pending_delete_at / role_id are never touched here —
        // a restricted account stays restricted.
        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
            'must_change_password' => false,
        ])->save();

        // Old sessions/tokens must not survive a reset.
        $user->tokens()->delete();
        $request->session()->regenerate();

        // This browser's own session performing the reset was never
        // authenticated as $user (this flow is session-bound but not
        // Auth::login()'d), so its row carries no user_id and is untouched —
        // only OTHER already-authenticated sessions for this account (e.g. a
        // hijacked or forgotten browser session) are invalidated here.
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

    // ── Shared helpers ────────────────────────────────────────────────────

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
        ]);
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
