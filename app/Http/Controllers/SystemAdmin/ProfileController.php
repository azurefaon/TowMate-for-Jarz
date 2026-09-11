<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('system-admin.profile.edit', [
            'user' => $request->user(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $oldImage = $user->profile_image;

        $user->first_name = $validated['first_name'];
        $user->middle_name = $validated['middle_name'] ?? null;
        $user->last_name = $validated['last_name'];

        if ($request->hasFile('profile_image')) {
            $user->profile_image = $request->file('profile_image')->store('avatars', 'public');
        }

        $user->save();

        if ($request->hasFile('profile_image') && $oldImage) {
            Storage::disk('public')->delete($oldImage);
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => $request->hasFile('profile_image') ? 'profile_image_updated' : 'profile_updated',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'description' => 'System Admin updated their own profile.',
        ]);

        return redirect()->route('system-admin.profile.edit')->with('success', 'Profile updated successfully.');
    }

    public function editPassword(Request $request): View
    {
        return view('system-admin.profile.password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);

        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors([
                'password' => 'Your new password cannot be the same as your current password.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        $user->tokens()->delete();
        $request->session()->regenerate();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'password_changed',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'description' => 'System Admin changed their own password.',
        ]);

        return redirect()->route('system-admin.profile.edit')->with('success', 'Password updated successfully.');
    }
}
