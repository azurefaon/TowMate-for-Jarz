<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return $this->renderLogin('superadmin');
    }

    public function createDispatcher(): View
    {
        return $this->renderLogin('dispatcher');
    }

    public function createTeamLeader(): View
    {
        return $this->renderLogin('teamleader');
    }

    protected function renderLogin(string $role): View
    {
        $loginConfig = match ($role) {
            'dispatcher' => [
                'role' => 'dispatcher',
                'pageTitle' => 'Jarz Towing | Dispatcher',
                'heading' => 'Welcome back',
                'subtitle' => 'Sign in to continue.',
                'panelTitle' => 'Dispatcher Portal',
                'panelText' => 'Sign in to continue.',
            ],
            'teamleader' => [
                'role' => 'teamleader',
                'pageTitle' => 'Jarz Towing | Team Leader',
                'heading' => 'Welcome back',
                'subtitle' => 'Sign in to continue.',
                'panelTitle' => 'Team Leader Portal',
                'panelText' => 'Sign in to continue.',
            ],
            default => [
                'role' => 'superadmin',
                'pageTitle' => 'Jarz Towing | Admin',
                'heading' => 'Welcome back',
                'subtitle' => 'Sign in to continue.',
                'panelTitle' => 'Admin Portal',
                'panelText' => 'Sign in to continue.',
            ],
        };

        return view('auth.login', compact('loginConfig'));
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        try {
            $user = $request->authenticate();
        } catch (ValidationException $e) {
            $message = $e->errors()['auth'][0] ?? 'Invalid credentials';

            throw ValidationException::withMessages([
                'auth' => $message,
            ])->errorBag('login');
        }

        $request->session()->regenerate();

        foreach (['superadmin', 'dispatcher', 'teamleader'] as $guard) {
            if ($guard === $request->selectedGuard()) {
                Auth::guard($guard)->login($user, $request->boolean('remember'));
                Auth::shouldUse($guard);
                continue;
            }

            Auth::guard($guard)->logout();
        }

        if ((int) $user->role_id === 3) {
            app(TeamLeaderAvailabilityService::class)->markOnline($user);
        } elseif ((int) $user->role_id === 2) {
            Cache::put('dispatcher:presence:' . $user->id, now()->timestamp, now()->addHours(12));
        }

        return redirect()->intended(route($request->redirectRoute(), absolute: false));
    }


    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user() ?: Auth::guard('teamleader')->user() ?: Auth::guard('dispatcher')->user() ?: Auth::guard('superadmin')->user();

        if ((int) optional($user)->role_id === 3) {
            app(TeamLeaderAvailabilityService::class)->markOffline($user);
        } elseif ((int) optional($user)->role_id === 2) {
            Cache::forget('dispatcher:presence:' . $user->id);
        }

        foreach (['web', 'superadmin', 'dispatcher', 'teamleader'] as $guard) {
            Auth::guard($guard)->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $baseUrl = rtrim(config('app.url') ?: ($request->getSchemeAndHttpHost() . $request->getBaseUrl()), '/');

        return redirect()->to($baseUrl . '/login');
    }
}
