<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ForcePasswordChangeController extends Controller
{
    private function dashboardRouteFor(\App\Models\User $user): string
    {
        return match ((int) $user->role_id) {
            1 => 'superadmin.dashboard',
            2 => 'admin.dashboard',
            6 => 'system-admin.dashboard',
            default => 'dashboard',
        };
    }

    public function show(Request $request)
    {
        if (! $request->user()->must_change_password) {
            return redirect()->intended(route($this->dashboardRouteFor($request->user())));
        }

        return response()
            ->view('auth.force-password-change')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function update(Request $request)
    {
        $user = $request->user();

        if (! $user->must_change_password) {
            return redirect()->intended(route($this->dashboardRouteFor($user)));
        }

        $request->validate([
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ],
        ], [
            'password.required'     => 'A new password is required.',
            'password.confirmed'    => 'The password confirmation does not match.',
            'password.min'          => 'Password must be at least 12 characters.',
        ]);

        if (Hash::check($request->password, $user->password)) {
            return back()->withErrors([
                'password' => 'Your new password cannot be the same as your current password.',
            ]);
        }

        $user->password           = Hash::make($request->password);
        $user->must_change_password = false;
        $user->save();

        $user->tokens()->delete();
        $request->session()->regenerate();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'password_changed',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'description' => 'User changed their password on first login.',
        ]);

        return redirect()->intended(route($this->dashboardRouteFor($user)))
            ->with('status', 'Your password has been updated successfully.');
    }
}
