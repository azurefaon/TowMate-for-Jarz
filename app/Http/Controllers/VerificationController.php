<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function sendOtp(Request $request)
    {
        return response()->json([
            'error' => 'OTP verification is no longer available.',
        ], 410);
    }

    public function verifyOtp(Request $request)
    {
        return response()->json([
            'error' => 'OTP verification is no longer available.',
        ], 410);
    }
}
