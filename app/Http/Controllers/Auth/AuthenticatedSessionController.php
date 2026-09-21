<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AndroidReleaseService;
use App\Services\AuditLogService;
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

    public function createSystemAdmin(): View
    {
        return $this->renderLogin('systemadmin');
    }

    protected function renderLogin(string $role): View
    {
        $loginConfig = match ($role) {
            'dispatcher' => [
                'role' => 'dispatcher',
                'pageTitle' => 'Jarz Towing | Dispatcher Sign In',
                'heading' => 'Welcome back',
                'subtitle' => 'Sign in to manage dispatch operations.',
            ],
            'teamleader' => [
                'role' => 'teamleader',
                'pageTitle' => 'Jarz Towing | Team Leader Sign In',
                'heading' => 'Welcome back',
                'subtitle' => 'Sign in to manage your assignments.',
            ],
            'systemadmin' => [
                'role' => 'systemadmin',
                'pageTitle' => 'Jarz Towing | System Admin Sign In',
                'heading' => 'Welcome back',
                'subtitle' => 'Sign in to manage system administration.',
            ],
            default => [
                'role' => 'superadmin',
                'pageTitle' => 'Jarz Towing | Admin Sign In',
                'heading' => 'Welcome back',
                'subtitle' => 'Sign in to manage TowMate operations.',
            ],
        };

        $androidActive = AndroidReleaseService::isActive();
        $apkExists = AndroidReleaseService::currentExists();
        $apkUrl    = ($apkExists && $androidActive) ? route('download.android') : null;
        $apkSizeMb = $apkExists ? AndroidReleaseService::metadata()['size_mb'] : null;

        $iosDistributionUrl = null;

        return view('auth.login', compact('loginConfig', 'apkExists', 'androidActive', 'apkUrl', 'apkSizeMb', 'iosDistributionUrl'));
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        try {
            $user = $request->authenticate();
        } catch (ValidationException $e) {
            $message = $e->errors()['auth'][0] ?? 'Unable to sign in with the provided credentials.';

            throw ValidationException::withMessages([
                'auth' => $message,
            ])->errorBag('login');
        }

        $request->session()->regenerate();
        $request->session()->forget('url.intended');

        foreach (['superadmin', 'dispatcher', 'teamleader', 'systemadmin'] as $guard) {
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
            Cache::put('dispatcher:presence:' . $user->id, now()->timestamp, 300);
        }

        app(AuditLogService::class)->logLogin($user, $request, $request->selectedGuard());

        $this->auditRecoveredLoginIfApplicable($user, $request);

        return redirect()->route($request->redirectRoute());
    }

    protected function auditRecoveredLoginIfApplicable(User $user, Request $request): void
    {
        $recoveredEmail = $request->session()->pull('login_recovered_email');
        $recoveredUntil = $request->session()->pull('login_recovered_until');

        if (! $recoveredEmail || ! $recoveredUntil) {
            return;
        }

        if (strtolower($recoveredEmail) !== strtolower((string) $user->email) || now()->isAfter($recoveredUntil)) {
            return;
        }

        try {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'login_success_after_recovery',
                'category' => 'security',
                'entity_type' => 'User',
                'entity_id' => $user->id,
                'description' => 'First successful sign-in after account-recovery verification.',
            ]);
        } catch (\Throwable) {
        }
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user()
            ?: Auth::guard('teamleader')->user()
            ?: Auth::guard('dispatcher')->user()
            ?: Auth::guard('superadmin')->user()
            ?: Auth::guard('systemadmin')->user();

        if ((int) optional($user)->role_id === 3) {
            app(TeamLeaderAvailabilityService::class)->markOffline($user);
        } elseif ((int) optional($user)->role_id === 2) {
            Cache::forget('dispatcher:presence:' . $user->id);
        }

        if ($user) {
            app(AuditLogService::class)->logLogout($user, $request);
        }

        foreach (['web', 'superadmin', 'dispatcher', 'teamleader', 'systemadmin'] as $guard) {
            Auth::guard($guard)->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $baseUrl = rtrim(config('app.url') ?: ($request->getSchemeAndHttpHost() . $request->getBaseUrl()), '/');

        return redirect()->to($baseUrl . '/login');
    }
}
