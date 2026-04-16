<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming account access request for superadmin review.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $email = strtolower(trim((string) $validated['email']));

        $user = User::with('role')
            ->where('email', $email)
            ->whereNull('archived_at')
            ->whereHas('role', function ($query) {
                $query->whereNotIn('name', ['Super Admin', 'Customer', 'Driver']);
            })
            ->first();

        if ($user) {
            $user->forceFill([
                'password_request_status' => 'pending',
                'password_requested_at' => now(),
                'password_request_note' => filled($validated['note'] ?? null)
                    ? trim((string) $validated['note'])
                    : null,
                'password_request_resolved_at' => null,
            ])->save();

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'password_help_requested',
                'entity_type' => 'User',
                'entity_id' => $user->id,
            ]);
        }

        return back()
            ->with('status', 'If the email matches a managed account, the Super Admin has received the account access request.');
    }
}
