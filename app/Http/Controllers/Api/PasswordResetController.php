<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetOtpMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function sendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:255']);
        $email = strtolower(trim($validated['email']));

        $generic = response()->json(['success' => true, 'message' => 'If this email is registered, an OTP has been sent.']);

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
        $genericFail = response()->json(['success' => false, 'message' => 'Invalid OTP. Please try again.'], 422);

        $user = User::where('email', $email)->first();

        if (! $user || ! $this->isEligibleForPasswordReset($user) || ! $user->password_reset_otp_hash) {
            return $genericFail;
        }

        if (! $user->password_reset_otp_expires_at || now()->isAfter($user->password_reset_otp_expires_at)) {
            $this->clearOtpState($user);

            return response()->json(['success' => false, 'message' => 'OTP has expired. Please request a new one.'], 422);
        }

        if ($user->password_reset_attempts >= self::MAX_FAILED_ATTEMPTS) {
            $this->clearOtpState($user);

            return $genericFail;
        }

        if (! Hash::check($validated['otp'], $user->password_reset_otp_hash)) {
            $user->increment('password_reset_attempts');

            if ($user->fresh()->password_reset_attempts >= self::MAX_FAILED_ATTEMPTS) {
                $this->clearOtpState($user);

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'customer_password_reset_otp_locked',
                    'entity_type' => 'User',
                    'entity_id' => $user->id,
                    'description' => 'OTP invalidated after too many failed verification attempts.',
                ]);
            } else {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'customer_password_reset_otp_verify_failed',
                    'entity_type' => 'User',
                    'entity_id' => $user->id,
                ]);
            }

            return $genericFail;
        }

        $rawToken = bin2hex(random_bytes(32));

        $user->forceFill([
            'password_reset_otp_hash' => null,
            'password_reset_attempts' => 0,
            'password_reset_token_hash' => hash('sha256', $rawToken),
            'password_reset_token_expires_at' => now()->addMinutes(self::RESET_TOKEN_TTL_MINUTES),
        ])->save();

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
        $user = User::where('email', $email)->first();

        if (! $user || ! $this->isEligibleForPasswordReset($user) || ! $user->password_reset_token_hash) {
            return $genericFail;
        }

        if (! $user->password_reset_token_expires_at || now()->isAfter($user->password_reset_token_expires_at)) {
            $this->clearResetTokenState($user);

            return $genericFail;
        }

        if (! hash_equals($user->password_reset_token_hash, hash('sha256', $validated['reset_token']))) {
            return $genericFail;
        }

        $wasLocked = $user->status === 'locked';

        $user->forceFill([
            'password'                           => Hash::make($validated['password']),
            'password_reset_otp_hash'            => null,
            'password_reset_otp_expires_at'      => null,
            'password_reset_attempts'            => 0,
            'password_reset_resend_available_at' => null,
            'password_reset_token_hash'          => null,
            'password_reset_token_expires_at'    => null,
            ...($wasLocked ? ['status' => 'active', 'last_login_at' => now()] : []),
        ])->save();

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
            && blank($user->pending_delete_at);
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
