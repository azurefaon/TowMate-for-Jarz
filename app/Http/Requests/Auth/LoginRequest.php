<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
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
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'phone' => preg_replace('/\D+/', '', (string) $this->input('phone')),
            'role' => Str::lower(trim((string) $this->input('role', 'superadmin'))),
            'login_method' => Str::lower(trim((string) $this->input('login_method', 'password'))),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['superadmin', 'dispatcher', 'teamleader'])],
            'login_method' => ['nullable', Rule::in(['password', 'otp'])],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'password' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'regex:/^(09\d{9}|9\d{9}|639\d{9})$/'],
            'otp' => ['nullable', 'digits:6'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $user = $this->loginMethod() === 'otp'
            ? $this->attemptOtpAuthentication()
            : $this->attemptPasswordAuthentication();

        Auth::guard('web')->login($user, $this->boolean('remember'));
        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    public function selectedGuard(): string
    {
        return match ($this->requestedRoleId()) {
            1 => 'superadmin',
            2 => 'dispatcher',
            3 => 'teamleader',
            default => 'web',
        };
    }

    public function redirectRoute(): string
    {
        return match ($this->requestedRoleId()) {
            1 => 'superadmin.dashboard',
            2 => 'admin.dashboard',
            3 => 'teamleader.dashboard',
            default => 'dashboard',
        };
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'auth' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        $identifier = $this->loginMethod() === 'otp'
            ? $this->normalizedPhone()
            : Str::lower((string) $this->input('email'));

        return Str::transliterate($this->input('role') . '|' . $this->loginMethod() . '|' . $identifier . '|' . $this->ip());
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

    protected function loginMethod(): string
    {
        $role = $this->input('role');

        if ($role !== 'teamleader') {
            return 'password';
        }

        return $this->input('login_method', 'password') === 'otp' ? 'otp' : 'password';
    }

    protected function attemptPasswordAuthentication(): User
    {
        $email = (string) $this->input('email');
        $password = (string) $this->input('password');

        if ($email === '' || $password === '') {
            $this->throwInvalidCredentials();
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('role_id', $this->requestedRoleId())
            ->first();

        if (! $user || ! Hash::check($password, (string) $user->password)) {
            $this->throwInvalidCredentials();
        }

        if (Schema::hasColumn('users', 'status') && $user->status !== 'active') {
            $this->throwInvalidCredentials();
        }

        return $user;
    }

    protected function attemptOtpAuthentication(): User
    {
        if ($this->requestedRoleId() !== 3 || ! Schema::hasColumn('users', 'phone')) {
            $this->throwInvalidCredentials();
        }

        $phone = $this->normalizedPhone();
        $otp = trim((string) $this->input('otp'));

        if ($phone === '' || $otp === '') {
            $this->throwInvalidCredentials();
        }

        $user = User::query()
            ->where('role_id', 3)
            ->where('phone', $phone)
            ->first();

        if (! $user) {
            $this->throwInvalidCredentials();
        }

        if (Schema::hasColumn('users', 'status') && $user->status !== 'active') {
            $this->throwInvalidCredentials();
        }

        if (blank($user->otp_code) || blank($user->otp_expires_at) || now()->gt($user->otp_expires_at)) {
            $this->throwInvalidCredentials();
        }

        if ((int) ($user->otp_attempts ?? 0) >= 5) {
            $this->throwInvalidCredentials();
        }

        if (! Hash::check($otp, (string) $user->otp_code)) {
            $user->increment('otp_attempts');
            $this->throwInvalidCredentials();
        }

        $user->forceFill([
            'otp_code' => null,
            'otp_plain_code' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
        ])->save();

        return $user;
    }

    protected function normalizedPhone(): string
    {
        $phone = preg_replace('/\D+/', '', (string) $this->input('phone'));

        if (Str::startsWith($phone, '639')) {
            return '0' . substr($phone, 2);
        }

        if (Str::startsWith($phone, '9') && strlen($phone) === 10) {
            return '0' . $phone;
        }

        return $phone;
    }

    protected function throwInvalidCredentials(): never
    {
        RateLimiter::hit($this->throttleKey());

        throw ValidationException::withMessages([
            'auth' => 'Invalid credentials',
        ]);
    }
}
