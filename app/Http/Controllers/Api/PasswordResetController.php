<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetOtpMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    private const OTP_TTL_MINUTES = 5;
    private const RESET_TOKEN_TTL_MINUTES = 10;
    private const MAX_FAILED_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 60;

    // Shown for every syntactically valid email, registered or not.
    private const GENERIC_MESSAGE = "If an account uses this email, we'll send password reset instructions. Please check your inbox.";
    private const INVALID_CODE_MESSAGE = 'Invalid or expired code. Please try again or request a new one.';

    public function sendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:255']);
        $email = strtolower(trim($validated['email']));

        $generic = response()->json(['success' => true, 'message' => self::GENERIC_MESSAGE]);

        $user = User::where('email', $email)->first();

        if (! $user || ! $this->isEligibleForPasswordReset($user)) {
            return $generic;
        }

        if ($user->password_reset_resend_available_at && now()->isBefore($user->password_reset_resend_available_at)) {
            return $generic;
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'password_reset_otp_hash' => Hash::make($otp),
            'password_reset_otp_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
            'password_reset_attempts' => 0,
            'password_reset_resend_available_at' => now()->addSeconds(self::RESEND_COOLDOWN_SECONDS),
            'password_reset_token_hash' => null,
            'password_reset_token_expires_at' => null,
        ])->save();

        Mail::to($user->email)->queue(new PasswordResetOtpMail($user, $otp));

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'customer_password_reset_otp_sent',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        return $generic;
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|string|size:6',
        ]);

        $email = strtolower(trim($validated['email']));

        // One safe response for unknown account, ineligible account, wrong,
        // expired, locked-out or already-consumed codes.
        $genericFail = response()->json(['success' => false, 'message' => self::INVALID_CODE_MESSAGE], 422);

        $rawToken = bin2hex(random_bytes(32));

        // The user row is locked while the code is checked, the attempt counter
        // is changed and the code is consumed, so concurrent requests cannot
        // both redeem one code or bypass the attempt cap.
        [$status, $user] = DB::transaction(function () use ($email, $validated, $rawToken) {
            $user = User::where('email', $email)->lockForUpdate()->first();

            if (! $user || ! $this->isEligibleForPasswordReset($user) || ! $user->password_reset_otp_hash) {
                return ['fail', null];
            }

            if (! $user->password_reset_otp_expires_at || now()->isAfter($user->password_reset_otp_expires_at)) {
                $this->clearOtpState($user);

                return ['fail', null];
            }

            if ($user->password_reset_attempts >= self::MAX_FAILED_ATTEMPTS) {
                $this->clearOtpState($user);

                return ['fail', null];
            }

            if (! Hash::check($validated['otp'], $user->password_reset_otp_hash)) {
                $user->increment('password_reset_attempts');
                $locked = $user->fresh()->password_reset_attempts >= self::MAX_FAILED_ATTEMPTS;

                if ($locked) {
                    $this->clearOtpState($user);
                }

                return [$locked ? 'locked' : 'wrong', $user];
            }

            // Compare-and-swap: only the request that still sees the code wins.
            $consumed = User::where('id', $user->id)
                ->where('password_reset_otp_hash', $user->password_reset_otp_hash)
                ->update([
                    'password_reset_otp_hash' => null,
                    'password_reset_attempts' => 0,
                    'password_reset_token_hash' => hash('sha256', $rawToken),
                    'password_reset_token_expires_at' => now()->addMinutes(self::RESET_TOKEN_TTL_MINUTES),
                ]);

            return $consumed === 1 ? ['verified', $user] : ['fail', null];
        });

        if ($status === 'locked') {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'customer_password_reset_otp_locked',
                'entity_type' => 'User',
                'entity_id' => $user->id,
                'description' => 'OTP invalidated after too many failed verification attempts.',
            ]);
        } elseif ($status === 'wrong') {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'customer_password_reset_otp_verify_failed',
                'entity_type' => 'User',
                'entity_id' => $user->id,
            ]);
        }

        if ($status !== 'verified') {
            return $genericFail;
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'customer_password_reset_otp_verified',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        return response()->json(['success' => true, 'reset_token' => $rawToken]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $genericFail = response()->json(['success' => false, 'message' => 'Invalid or expired reset session.'], 422);

        try {
            $validated = $request->validate([
                'email'                 => 'required|email',
                'reset_token'           => 'required|string',
                'password'              => [
                    'required',
                    'confirmed',
                    Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised(),
                ],
                'password_confirmation' => 'required|string',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->validator->errors()->first()], 422);
        }

        $email = strtolower(trim($validated['email']));
        $newHash = Hash::make($validated['password']);

        $user = DB::transaction(function () use ($email, $validated, $newHash) {
            $user = User::where('email', $email)->lockForUpdate()->first();

            if (! $user || ! $this->isEligibleForPasswordReset($user) || ! $user->password_reset_token_hash) {
                return null;
            }

            if (! $user->password_reset_token_expires_at || now()->isAfter($user->password_reset_token_expires_at)) {
                $this->clearResetTokenState($user);

                return null;
            }

            if (! hash_equals($user->password_reset_token_hash, hash('sha256', $validated['reset_token']))) {
                return null;
            }

            // Compare-and-swap: consuming the token is the first write, so a
            // concurrent request that already used it affects zero rows.
            $consumed = User::where('id', $user->id)
                ->where('password_reset_token_hash', $user->password_reset_token_hash)
                ->update([
                    'password_reset_token_hash' => null,
                    'password_reset_token_expires_at' => null,
                ]);

            if ($consumed !== 1) {
                return null;
            }

            $user->forceFill([
                'password'                           => $newHash,
                'password_reset_otp_hash'            => null,
                'password_reset_otp_expires_at'      => null,
                'password_reset_attempts'            => 0,
                'password_reset_resend_available_at' => null,
                'password_reset_token_hash'          => null,
                'password_reset_token_expires_at'    => null,
                ...($user->status === 'locked' ? ['status' => 'active', 'last_login_at' => now()] : []),
            ])->save();

            return $user;
        });

        if (! $user) {
            return $genericFail;
        }

        $user->tokens()->delete();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'customer_password_reset_completed',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'description' => 'Password reset via mobile OTP flow.',
        ]);

        return response()->json(['success' => true, 'message' => 'Password reset successfully. You can now log in.']);
    }

    private function isEligibleForPasswordReset(User $user): bool
    {
        return in_array($user->status, ['active', 'locked'], true)
            && blank($user->archived_at)
            && blank($user->anonymized_at)
            && blank($user->pending_delete_at)
            && !$user->isGoogleAccount();
    }

    private function clearOtpState(User $user): void
    {
        $user->forceFill([
            'password_reset_otp_hash' => null,
            'password_reset_otp_expires_at' => null,
            'password_reset_attempts' => 0,
        ])->save();
    }

    private function clearResetTokenState(User $user): void
    {
        $user->forceFill([
            'password_reset_token_hash' => null,
            'password_reset_token_expires_at' => null,
        ])->save();
    }
}
