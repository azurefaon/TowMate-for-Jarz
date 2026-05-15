<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
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

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully.',
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

        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive. Please contact support.',
            ], 403);
        }

        // Revoke previous tokens so only one session is active at a time
        $user->tokens()->delete();

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
                'email'      => $user->email,
                'phone'      => $user->phone,
                'role'       => $user->role?->name ?? 'Customer',
                'duty_class' => $user->duty_class,
            ],
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
