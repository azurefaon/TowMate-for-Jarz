<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetOtpMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class PasswordResetController extends Controller
{
    public function sendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:255']);

        $user = User::where('email', $validated['email'])->first();

        // Always return success to prevent email enumeration
        if (! $user) {
            return response()->json(['success' => true, 'message' => 'If this email is registered, an OTP has been sent.']);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->update([
            'password_reset_otp'            => $otp,
            'password_reset_otp_expires_at' => now()->addMinutes(10),
            'password_reset_token'          => null,
        ]);

        try {
            Mail::to($user->email)->send(new PasswordResetOtpMail($user, $otp));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to send OTP email. Please try again.'], 500);
        }

        return response()->json(['success' => true, 'message' => 'OTP sent to your email.']);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|string|size:6',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || $user->password_reset_otp !== $validated['otp']) {
            return response()->json(['success' => false, 'message' => 'Invalid OTP. Please try again.'], 422);
        }

        if (! $user->password_reset_otp_expires_at || now()->isAfter($user->password_reset_otp_expires_at)) {
            return response()->json(['success' => false, 'message' => 'OTP has expired. Please request a new one.'], 422);
        }

        $token = bin2hex(random_bytes(32));

        $user->update([
            'password_reset_otp'            => null,
            'password_reset_otp_expires_at' => now()->addMinutes(10),
            'password_reset_token'          => $token,
        ]);

        return response()->json(['success' => true, 'reset_token' => $token]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'                 => 'required|email',
            'reset_token'           => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || $user->password_reset_token !== $validated['reset_token']) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired reset session.'], 422);
        }

        if (! $user->password_reset_otp_expires_at || now()->isAfter($user->password_reset_otp_expires_at)) {
            return response()->json(['success' => false, 'message' => 'Reset session has expired. Please start over.'], 422);
        }

        $user->update([
            'password'                      => Hash::make($validated['password']),
            'password_reset_otp'            => null,
            'password_reset_otp_expires_at' => null,
            'password_reset_token'          => null,
        ]);

        return response()->json(['success' => true, 'message' => 'Password reset successfully. You can now log in.']);
    }
}
