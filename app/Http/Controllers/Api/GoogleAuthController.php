<?php

namespace App\Http\Controllers\Api;

use App\Contracts\GoogleIdTokenVerifier;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    private const PENDING_TTL_MINUTES = 15;

    public function __construct(private readonly GoogleIdTokenVerifier $verifier)
    {
    }

    public function authenticate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => 'required|string',
        ]);

        $claims = $this->verifier->verify($validated['id_token']);

        if (!$claims) {
            return response()->json(['success' => false, 'message' => 'Google sign-in could not be verified. Please try again.'], 422);
        }

        $sub = (string) $claims['sub'];
        $email = strtolower(trim((string) $claims['email']));

        $existing = User::with('role')->where('google_sub', $sub)->where('auth_provider', 'google')->first();

        if ($existing) {
            return $this->issueSessionFor($existing);
        }

        if (User::where('email', $email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'An account already exists for this email. Sign in with your password first.',
            ], 409);
        }

        $firstName = (string) ($claims['given_name'] ?? '');
        $lastName = (string) ($claims['family_name'] ?? '');
        $completionToken = Str::random(48);

        Cache::put('google_pending_' . $completionToken, [
            'sub' => $sub,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ], now()->addMinutes(self::PENDING_TTL_MINUTES));

        AuditLog::create([
            'action' => 'customer_google_signup_started',
            'entity_type' => 'User',
            'description' => "Google identity verified, phone completion pending for {$email}.",
        ]);

        return response()->json([
            'success' => true,
            'needs_phone' => true,
            'completion_token' => $completionToken,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ], 202);
    }

    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'completion_token' => 'required|string',
            'phone' => ['required', 'string', 'regex:/^\+639\d{9}$/'],
        ]);

        $cacheKey = 'google_pending_' . $validated['completion_token'];
        $pending = Cache::get($cacheKey);

        if (!is_array($pending) || empty($pending['sub']) || empty($pending['email'])) {
            return response()->json(['success' => false, 'message' => 'This Google sign-in has expired. Please start again.'], 422);
        }

        if (
            User::where('google_sub', $pending['sub'])->where('auth_provider', 'google')->exists()
            || User::where('email', $pending['email'])->exists()
        ) {
            Cache::forget($cacheKey);

            return response()->json(['success' => false, 'message' => 'An account already exists for this email. Sign in with your password first.'], 409);
        }

        if (User::where('phone', $validated['phone'])->exists()) {
            return response()->json(['success' => false, 'message' => 'This phone number is already in use.'], 422);
        }

        $customerRoleId = DB::table('roles')->where('name', 'Customer')->value('id') ?? 5;
        $fullName = trim($pending['first_name'] . ' ' . $pending['last_name']);

        try {
            $user = DB::transaction(function () use ($pending, $validated, $customerRoleId, $fullName) {
                $user = User::create([
                    'name' => $fullName !== '' ? $fullName : $pending['email'],
                    'first_name' => $pending['first_name'] !== '' ? $pending['first_name'] : 'Customer',
                    'last_name' => $pending['last_name'],
                    'email' => $pending['email'],
                    'phone' => $validated['phone'],
                    'password' => null,
                    'auth_provider' => 'google',
                    'google_sub' => $pending['sub'],
                    'role_id' => $customerRoleId,
                    'status' => 'active',
                ]);

                try {
                    Customer::create([
                        'user_id' => $user->id,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'full_name' => $user->name,
                        'email' => $user->email,
                        'phone' => $validated['phone'],
                    ]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Customer profile creation failed for Google user ' . $user->id . ': ' . $e->getMessage());
                }

                return $user;
            });
        } catch (QueryException $e) {
            return response()->json(['success' => false, 'message' => 'An account already exists for this email or phone number.'], 409);
        }

        Cache::forget($cacheKey);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'customer_google_signup_completed',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'description' => "Google customer account created for {$user->email}.",
        ]);

        return $this->issueSessionFor($user->load('role'), 201);
    }

    private function issueSessionFor(User $user, int $status = 200): JsonResponse
    {
        if ($user->status === 'locked') {
            return response()->json(['success' => false, 'message' => 'Your account was locked due to inactivity. Reset your password to reactivate it.'], 423);
        }

        if ($user->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Account is inactive. Please contact support.'], 403);
        }

        $user->tokens()->delete();
        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role?->name ?? 'Customer',
                    'duty_class' => $user->duty_class,
                    'must_change_password' => false,
                ],
            ],
        ], $status);
    }
}
