<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\RegistrationOtpMail;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    public function sendRegistrationOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:255|unique:users,email']);
        $email = strtolower(trim($validated['email']));

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put('reg_otp_' . $email, $otp, now()->addMinutes(10));

        try {
            Mail::to($email)->queue(new RegistrationOtpMail($otp));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Registration OTP mail failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to send OTP. Check your email address.'], 500);
        }

        return response()->json(['success' => true, 'message' => 'OTP sent to your email. It expires in 10 minutes.']);
    }

    public function verifyRegistrationOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|string|size:6',
        ]);

        $email  = strtolower(trim($validated['email']));
        $cached = Cache::get('reg_otp_' . $email);

        if (! $cached || $cached !== $validated['otp']) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired OTP.'], 422);
        }

        Cache::forget('reg_otp_' . $email);
        Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

        return response()->json(['success' => true, 'message' => 'Email verified.']);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name'            => 'required|string|max:100',
            'last_name'             => 'required|string|max:100',
            'email'                 => 'required|email|max:255|unique:users,email',
            'phone'                 => 'required|string|max:30|unique:users,phone',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        // Require email verification via OTP before creating account
        $email = strtolower(trim($data['email']));
        if (! Cache::get('reg_verified_' . $email)) {
            return response()->json(['success' => false, 'message' => 'Email not verified. Please complete OTP verification first.'], 422);
        }
        Cache::forget('reg_verified_' . $email);

        $customerRoleId = DB::table('roles')->where('name', 'Customer')->value('id') ?? 5;

        // Compute full name explicitly — users.name is NOT NULL
        $fullName = trim($data['first_name'] . ' ' . $data['last_name']);

        $user = User::create([
            'name'       => $fullName,
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'email'      => strtolower(trim($data['email'])),
            'phone'      => $data['phone'],
            'password'   => $data['password'],
            'role_id'    => $customerRoleId,
            'status'     => 'active',
        ]);

        // Create customer profile — wrap so a profile failure doesn't block login
        try {
            Customer::create([
                'user_id'    => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'full_name'  => $user->name ?: $fullName,
                'email'      => $user->email,
                'phone'      => $data['phone'],
            ]);
        } catch (\Throwable $e) {
            // Profile creation failure is non-fatal for auth — log and continue
            \Illuminate\Support\Facades\Log::warning('Customer profile creation failed for user ' . $user->id . ': ' . $e->getMessage());
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully.',
            'data'    => [
                'token' => $token,
                'user'  => [
                    'id'                   => $user->id,
                    'name'                 => $user->name,
                    'email'                => $user->email,
                    'phone'                => $user->phone,
                    'role'                 => $user->role?->name ?? 'Customer',
                    'duty_class'           => $user->duty_class,
                    'must_change_password' => false,
                ],
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        // PostgreSQL is case-sensitive — always compare against lowercase stored email
        $email = strtolower(trim($request->email));

        $user = User::with('role')->where('email', $email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($user->status === 'locked') {
            return response()->json([
                'success' => false,
                'message' => 'Your account was locked due to inactivity. Reset your password to reactivate it.',
            ], 423);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive. Please contact support.',
            ], 403);
        }

        // Revoke previous tokens so only one session is active at a time
        $user->tokens()->delete();

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => [
                'token' => $token,
                'user'  => [
                    'id'                   => $user->id,
                    'name'                 => $user->name,
                    'email'                => $user->email,
                    'phone'                => $user->phone,
                    'role'                 => $user->role?->name ?? 'Customer',
                    'duty_class'           => $user->duty_class,
                    'must_change_password' => (bool) $user->must_change_password,
                ],
            ],
        ]);
    }

    public function profile(Request $request)
    {
        $user = $request->user()->load('role');

        return response()->json([
            'success' => true,
            'data'    => [
                'id'         => $user->id,
                'name'       => $user->name,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'phone'      => $user->phone,
                'role'       => $user->role?->name ?? 'Customer',
                'duty_class' => $user->duty_class,
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'phone'      => [
                'nullable',
                'string',
                'max:20',
                \Illuminate\Validation\Rule::unique('users', 'phone')->ignore($user->id),
            ],
        ]);

        $user->first_name = $validated['first_name'];
        $user->last_name  = $validated['last_name'];
        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'];
        }
        $user->save();

        $customer = Customer::where('user_id', $user->id)->first();
        if ($customer) {
            $customer->first_name = $validated['first_name'];
            $customer->last_name  = $validated['last_name'];
            if (array_key_exists('phone', $validated)) {
                $customer->phone = $validated['phone'];
            }
            $customer->save();
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'name'       => $user->name,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'phone'      => $user->phone,
            ],
        ]);
    }

    public function requestEmailChangeOtp(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        $newEmail = strtolower(trim($validated['email']));

        if ($newEmail === strtolower((string) $user->email)) {
            return response()->json(['success' => false, 'message' => 'That is already your current email.'], 422);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put('email_change_otp_' . $user->id, ['otp' => $otp, 'email' => $newEmail], now()->addMinutes(10));

        try {
            Mail::to($newEmail)->queue(new \App\Mail\EmailChangeOtpMail($otp));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Email change OTP mail failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to send confirmation code.'], 500);
        }

        return response()->json(['success' => true, 'message' => 'Confirmation code sent to your new email. It expires in 10 minutes.']);
    }

    public function confirmEmailChange(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|string|size:6',
        ]);

        $newEmail = strtolower(trim($validated['email']));
        $cached   = Cache::get('email_change_otp_' . $user->id);

        if (! $cached || $cached['otp'] !== $validated['otp'] || $cached['email'] !== $newEmail) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired code.'], 422);
        }

        if (User::where('email', $newEmail)->where('id', '!=', $user->id)->exists()) {
            Cache::forget('email_change_otp_' . $user->id);
            return response()->json(['success' => false, 'message' => 'That email is already in use.'], 422);
        }

        $user->email = $newEmail;
        $user->save();

        $customer = Customer::where('user_id', $user->id)->first();
        if ($customer) {
            $customer->email = $newEmail;
            $customer->save();
        }

        Cache::forget('email_change_otp_' . $user->id);

        return response()->json(['success' => true, 'message' => 'Email updated successfully.', 'data' => ['email' => $user->email]]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password'          => 'required|string',
            'new_password'              => 'required|string|min:8|confirmed',
            'new_password_confirmation' => 'required|string',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out',
        ]);
    }
}
