<?php

namespace App\Http\Requests\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TokenBucketRateLimiter;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
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
            'role' => ['nullable', Rule::in(['superadmin', 'dispatcher', 'teamleader'])],
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

        // Clear both limiters on successful login.
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
            default => 'web',
        };
    }

    public function redirectRoute(): string
    {
        return match ($this->resolvedRoleId ?: $this->requestedRoleId()) {
            1 => 'superadmin.dashboard',
            2 => 'admin.dashboard',
            3 => 'teamleader.dashboard',
            default => 'dashboard',
        };
    }

    public function ensureIsNotRateLimited(): void
    {
        // Hard lock: 5 failed attempts = locked for 15 minutes.
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

        // Token bucket: controls burst and sustained request rate.
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
            default => 0,
        };
    }

    protected function maxAttempts(): int
    {
        return 5;
    }

    protected function decaySeconds(): int
    {
        return 900; // 15-minute lockout after 5 failed attempts.
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
            ->when(Schema::hasColumn('users', 'role_id'), fn($query) => $query->whereIn('role_id', [1, 2, 3]))
            ->first();

        if (! $user || ! Hash::check($password, (string) $user->password)) {
            $this->throwInvalidCredentials();
        }

        if (Schema::hasColumn('users', 'status') && $user->status !== 'active') {
            $this->throwInvalidCredentials();
        }

        $this->resolvedRoleId = (int) ($user->role_id ?? 0);

        return $user;
    }

    protected function throwInvalidCredentials(): never
    {
        // Increment the hard-lock failure counter.
        RateLimiter::hit($this->throttleKey(), $this->decaySeconds());

        // Log failed login attempt to audit log (best-effort — never crash the login flow).
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
            // Non-fatal — proceed to return the validation error.
        }

        throw ValidationException::withMessages([
            'auth' => 'Invalid credentials',
        ]);
    }
}
