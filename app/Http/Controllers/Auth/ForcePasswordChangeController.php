<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ForcePasswordChangeController extends Controller
{
    /**
     * Show the forced password-change form.
     * Cache-Control headers are set here (OWASP A02 — no caching of sensitive pages).
     */
    public function show(Request $request)
    {
        // Extra guard: if the flag is already cleared, send them to their dashboard.
        if (! $request->user()->must_change_password) {
            return redirect()->intended(route('dashboard'));
        }

        return response()
            ->view('auth.force-password-change')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Process the new password.
     * OWASP checklist:
     *  A01 — must_change_password flag gates access via middleware
     *  A02 — bcrypt via Laravel Hash::make, never plaintext stored
     *  A03 — Eloquent ORM, no raw SQL
     *  A07 — Strong password policy, rate-limited route (throttle:5,1),
     *         session regenerated after change, cannot reuse the same password
     *  A09 — Audit log recorded
     */
    public function update(Request $request)
    {
        $user = $request->user();

        // Belt-and-suspenders: still guard even if middleware is misconfigured.
        if (! $user->must_change_password) {
            return redirect()->intended(route('dashboard'));
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

        // A07 — Prevent reuse of the same password the admin assigned.
        if (Hash::check($request->password, $user->password)) {
            return back()->withErrors([
                'password' => 'Your new password cannot be the same as your current password.',
            ]);
        }

        $user->password           = Hash::make($request->password);
        $user->must_change_password = false;
        $user->save();

        // A07 — Regenerate session ID to prevent session fixation.
        $request->session()->regenerate();

        // A09 — Audit trail.
        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'password_changed',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'description' => 'User changed their password on first login.',
        ]);

        return redirect()->intended(route('dashboard'))
            ->with('status', 'Your password has been updated successfully.');
    }
}
