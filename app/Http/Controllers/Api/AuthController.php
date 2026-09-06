<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\RegistrationOtpMail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const REGISTRATION_OTP_TTL_MINUTES = 5;
    private const REGISTRATION_OTP_MAX_FAILED_ATTEMPTS = 5;
    private const REGISTRATION_OTP_RESEND_COOLDOWN_SECONDS = 60;

    public function sendRegistrationOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:255|unique:users,email']);
        $email = strtolower(trim($validated['email']));

        $genericSent = response()->json(['success' => true, 'message' => 'OTP sent to your email. It expires in ' . self::REGISTRATION_OTP_TTL_MINUTES . ' minutes.']);

        $cacheKey = 'reg_otp_' . $email;
        $existing = Cache::get($cacheKey);

        if (is_array($existing) && now()->timestamp - (int) ($existing['last_sent_at'] ?? 0) < self::REGISTRATION_OTP_RESEND_COOLDOWN_SECONDS) {
            // Resend cooldown still active — do not reset attempts or expiry.
            return $genericSent;
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($cacheKey, [
            'hash' => Hash::make($otp),
            'attempts' => 0,
            'last_sent_at' => now()->timestamp,
        ], now()->addMinutes(self::REGISTRATION_OTP_TTL_MINUTES));

        try {
            Mail::to($email)->queue(new RegistrationOtpMail($otp));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Registration OTP mail failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to send OTP. Check your email address.'], 500);
        }

        AuditLog::create([
            'action' => 'customer_registration_otp_sent',
            'entity_type' => 'User',
            'description' => "Registration OTP requested for {$email}.",
        ]);

        return $genericSent;
    }

    public function verifyRegistrationOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|string|size:6',
        ]);

        $email = strtolower(trim($validated['email']));
        $genericFail = response()->json(['success' => false, 'message' => 'Invalid or expired OTP.'], 422);

        $cacheKey = 'reg_otp_' . $email;
        $record = Cache::get($cacheKey);

        if (! is_array($record) || ! isset($record['hash'])) {
            return $genericFail;
        }

        if (($record['attempts'] ?? 0) >= self::REGISTRATION_OTP_MAX_FAILED_ATTEMPTS) {
            Cache::forget($cacheKey);

            return $genericFail;
        }

        if (! Hash::check($validated['otp'], $record['hash'])) {
            $record['attempts'] = ($record['attempts'] ?? 0) + 1;

            if ($record['attempts'] >= self::REGISTRATION_OTP_MAX_FAILED_ATTEMPTS) {
                Cache::forget($cacheKey);

                AuditLog::create([
                    'action' => 'customer_registration_otp_locked',
                    'entity_type' => 'User',
                    'description' => "Registration OTP locked for {$email} after too many failed attempts.",
                ]);
            } else {
                // Preserve the OTP's remaining lifetime rather than restarting it.
                $remainingSeconds = max(1, self::REGISTRATION_OTP_TTL_MINUTES * 60 - (now()->timestamp - (int) ($record['last_sent_at'] ?? now()->timestamp)));
                Cache::put($cacheKey, $record, $remainingSeconds);

                AuditLog::create([
                    'action' => 'customer_registration_otp_verify_failed',
                    'entity_type' => 'User',
                    'description' => "Incorrect registration OTP entered for {$email}.",
                ]);
            }

            return $genericFail;
        }

        Cache::forget($cacheKey);
        Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

        AuditLog::create([
            'action' => 'customer_registration_otp_verified',
            'entity_type' => 'User',
            'description' => "Registration OTP verified for {$email}.",
        ]);

        return response()->json(['success' => true, 'message' => 'Email verified.']);
    }

    public function register(Request $request)
    {
        try {
            $data = $request->validate([
                'first_name'            => 'required|string|max:100',
                'last_name'             => 'required|string|max:100',
                'email'                 => 'required|email|max:255|unique:users,email',
                'phone'                 => 'required|string|max:30|unique:users,phone',
                'password'              => [
                    'required',
                    'string',
                    'confirmed',
                    Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised(),
                ],
                'password_confirmation' => 'required|string',
            ]);
        } catch (ValidationException $e) {
            // Flattened so the mobile client (which only reads a top-level
            // "message" string) sees the actual rule that failed instead of
            // Laravel's generic "The given data was invalid." envelope —
            // same pattern as PasswordResetController::resetPassword().
            return response()->json(['success' => false, 'message' => $e->validator->errors()->first()], 422);
        }

        $email = strtolower(trim($data['email']));
        if (! Cache::get('reg_verified_' . $email)) {
            return response()->json(['success' => false, 'message' => 'Email not verified. Please complete OTP verification first.'], 422);
        }
        Cache::forget('reg_verified_' . $email);

        $customerRoleId = DB::table('roles')->where('name', 'Customer')->value('id') ?? 5;

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
        $user->name = build_full_name($validated['first_name'], null, $validated['last_name']);
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
        try {
            $validated = $request->validate([
                'current_password'          => 'required|string',
                'new_password'              => [
                    'required',
                    'string',
                    'confirmed',
                    Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised(),
                ],
                'new_password_confirmation' => 'required|string',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->validator->errors()->first()], 422);
        }

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
