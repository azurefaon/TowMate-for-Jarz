<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;

class VerificationController extends Controller
{
    public function sendOtp(Request $request)
    {
        if (!auth()->check()) {
            return response()->json([
                'error' => 'User not authenticated'
            ], 401);
        }

        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'error' => 'User not found'
            ], 404);
        }

        $code = rand(1000, 9999);

        $user->update([
            'otp_code' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(5),
            'otp_attempts' => 0,
            'otp_last_sent_at' => now(),
        ]);

        try {
            Mail::to($user->email)->send(new \App\Mail\OtpMail($code, $user));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Mail failed: ' . $e->getMessage()
            ], 500);
        }

        return response()->json([
            'sent' => true
        ]);
    }

    public function verifyOtp(Request $request)
    {
        if (!auth()->check()) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        $request->validate([
            'code' => 'required|digits:4'
        ]);

        $user = auth()->user();

        if (!$user->otp_code) {
            return response()->json(['error' => 'No OTP found'], 400);
        }

        if (now()->gt($user->otp_expires_at)) {
            return response()->json(['error' => 'OTP expired'], 400);
        }

        if ($user->otp_attempts >= 5) {
            return response()->json(['error' => 'Too many attempts'], 429);
        }

        if (!Hash::check($request->code, $user->otp_code)) {
            $user->increment('otp_attempts');
            return response()->json(['error' => 'Invalid code'], 400);
        }

        $user->update([
            'email_verified_at' => now(),
            'otp_code' => null,
            'otp_plain_code' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
        ]);

        return response()->json(['success' => true]);
    }
}
