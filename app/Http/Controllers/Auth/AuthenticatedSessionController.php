<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Mail\OtpMail;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
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
            throw ValidationException::withMessages([
                'auth' => 'Invalid credentials',
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
        }

        return redirect()->intended(route($request->redirectRoute(), absolute: false));
    }

    public function sendTeamLeaderOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^(09\d{9}|9\d{9}|639\d{9})$/'],
        ]);

        $normalizedPhone = preg_replace('/\D+/', '', (string) $validated['phone']);

        if (str_starts_with($normalizedPhone, '639')) {
            $normalizedPhone = '0' . substr($normalizedPhone, 2);
        } elseif (str_starts_with($normalizedPhone, '9') && strlen($normalizedPhone) === 10) {
            $normalizedPhone = '0' . $normalizedPhone;
        }

        if (Schema::hasColumn('users', 'phone')) {
            $user = User::query()
                ->where('role_id', 3)
                ->where('phone', $normalizedPhone)
                ->when(Schema::hasColumn('users', 'status'), fn($query) => $query->where('status', 'active'))
                ->first();

            if ($user && filled($user->email)) {
                $code = (string) random_int(100000, 999999);

                $user->forceFill([
                    'otp_code' => Hash::make($code),
                    'otp_plain_code' => app()->environment(['local', 'testing']) ? $code : null,
                    'otp_expires_at' => now()->addMinutes(5),
                    'otp_attempts' => 0,
                    'otp_last_sent_at' => now(),
                ])->save();

                try {
                    Mail::to($user->email)->send(new OtpMail($code, $user));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return response()->json([
            'message' => 'If the account is valid, a one-time code has been sent.',
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user() ?: Auth::guard('teamleader')->user() ?: Auth::guard('dispatcher')->user() ?: Auth::guard('superadmin')->user();

        if ((int) optional($user)->role_id === 3) {
            app(TeamLeaderAvailabilityService::class)->markOffline($user);
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
