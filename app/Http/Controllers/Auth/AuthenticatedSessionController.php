<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        try {
            $request->authenticate();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'email' => 'Invalid email or password',
            ])->errorBag('login');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ((int) optional($user)->role_id === 3) {
            app(TeamLeaderAvailabilityService::class)->markOffline($user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        $baseUrl = rtrim(config('app.url') ?: ($request->getSchemeAndHttpHost() . $request->getBaseUrl()), '/');

        if (filled($user?->role_id)) {
            return redirect()->to($baseUrl . '/login');
        }

        return redirect('/');
    }
}
