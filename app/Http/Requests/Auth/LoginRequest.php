<?php

namespace App\Http\Requests\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TokenBucketRateLimiter;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    protected const MAX_FAILED_ATTEMPTS = 3;

    protected const LOCK_MINUTES = 15;

    protected int $resolvedRoleId = 0;

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'role' => Str::lower(trim((string) $this->input('role', ''))),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['nullable', Rule::in(['superadmin', 'dispatcher', 'teamleader', 'systemadmin'])],
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:128'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $user = $this->attemptPasswordAuthentication();

        Auth::guard('web')->login($user, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
        $this->tokenBucket()->clear($this->throttleKey());

        return $user;
    }

    public function selectedGuard(): string
    {
        return match ($this->resolvedRoleId ?: $this->requestedRoleId()) {
            1 => 'superadmin',
            2 => 'dispatcher',
            3 => 'teamleader',
            6 => 'systemadmin',
            default => 'web',
        };
    }

    public function redirectRoute(): string
    {
        return match ($this->resolvedRoleId ?: $this->requestedRoleId()) {
            1 => 'superadmin.dashboard',
            2 => 'admin.dashboard',
            3 => 'teamleader.dashboard',
            6 => 'system-admin.dashboard',
            default => 'dashboard',
        };
    }

    public function ensureIsNotRateLimited(): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), $this->maxAttempts())) {
            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($this->throttleKey());

            throw ValidationException::withMessages([
                'auth' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }

        if (! $this->tokenBucket()->attempt($this->throttleKey())) {
            $retryAfter = $this->tokenBucket()->retryAfter($this->throttleKey());

            throw ValidationException::withMessages([
                'auth' => trans('auth.throttle', [
                    'seconds' => $retryAfter,
                    'minutes' => ceil($retryAfter / 60),
                ]),
            ]);
        }
    }

    public function throttleKey(): string
    {
        $identifier = Str::lower((string) $this->input('email'));

        return Str::transliterate('staff-login|' . $identifier . '|' . $this->ip());
    }

    protected function requestedRoleId(): int
    {
        return match ($this->input('role')) {
            'superadmin' => 1,
            'dispatcher' => 2,
            'teamleader' => 3,
            'systemadmin' => 6,
            default => 0,
        };
    }

    protected function maxAttempts(): int
    {
        return 5;
    }

    protected function decaySeconds(): int
    {
        return 900;
    }

    private function tokenBucket(): TokenBucketRateLimiter
    {
        return new TokenBucketRateLimiter(
            maxTokens: 10,
            refillAmount: 5,
            refillEvery: 10,
            tokenCost: 5,
        );
    }

    protected function attemptPasswordAuthentication(): User
    {
        $email = (string) $this->input('email');
        $password = (string) $this->input('password');

        if ($email === '' || $password === '') {
            $this->throwInvalidCredentials();
        }

        $user = User::query()
            ->visibleToOperations()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->when(Schema::hasColumn('users', 'role_id'), fn($query) => $query->whereIn('role_id', [1, 2, 3, 6]))
            ->first();

        if (! $user) {
            $this->throwInvalidCredentials();
        }

        if (Schema::hasColumn('users', 'status') && $user->status !== 'active') {
            $this->throwInvalidCredentials();
        }

        if ($this->isAccountLocked($user)) {
            $this->throwAccountLocked($user);
        }

        if (! Hash::check($password, (string) $user->password)) {
            if ($this->registerFailedAttempt($user)) {
                $this->throwAccountLocked($user);
            }

            $this->throwInvalidCredentials();
        }

        $this->clearFailedAttempts($user);

        $this->resolvedRoleId = (int) ($user->role_id ?? 0);

        return $user;
    }

    protected function isAccountLocked(User $user): bool
    {
        return $user->locked_until !== null && now()->isBefore($user->locked_until);
    }

    protected function registerFailedAttempt(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $attempts = (int) $locked->failed_login_attempts + 1;
            $justLocked = $attempts >= self::MAX_FAILED_ATTEMPTS;

            $locked->forceFill([
                'failed_login_attempts' => $attempts,
                'last_failed_login_at' => now(),
                'locked_until' => $justLocked ? now()->addMinutes(self::LOCK_MINUTES) : null,
            ])->save();

            $user->failed_login_attempts = $attempts;
            $user->locked_until = $locked->locked_until;

            if ($justLocked) {
                try {
                    AuditLog::create([
                        'user_id' => $locked->id,
                        'action' => 'account_temporarily_locked',
                        'category' => 'security',
                        'entity_type' => 'User',
                        'entity_id' => $locked->id,
                        'reference' => $locked->email,
                        'description' => "Account locked for " . self::LOCK_MINUTES . " minutes after {$attempts} consecutive failed login attempts from IP " . $this->ip() . '.',
                    ]);
                } catch (\Throwable) {
                }
            }

            return $justLocked;
        });
    }

    protected function clearFailedAttempts(User $user): void
    {
        if ((int) $user->failed_login_attempts === 0 && $user->locked_until === null) {
            return;
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }

    protected function throwAccountLocked(User $user): never
    {
        session()->flash('login_locked', true);
        session()->flash('login_locked_email', $user->email);

        throw ValidationException::withMessages([
            'auth' => 'Too many unsuccessful sign-in attempts. Your sign-in access is temporarily locked. Verify your email to recover access.',
        ]);
    }

    protected function throwInvalidCredentials(): never
    {
        RateLimiter::hit($this->throttleKey(), $this->decaySeconds());

        try {
            AuditLog::create([
                'user_id'     => null,
                'action'      => 'failed_login',
                'entity_type' => 'User',
                'entity_id'   => null,
                'reference'   => (string) $this->input('email'),
                'description' => 'Failed login attempt from IP ' . $this->ip(),
            ]);
        } catch (\Throwable) {
        }

        throw ValidationException::withMessages([
            'auth' => 'Unable to sign in with the provided credentials.',
        ]);
    }
}
