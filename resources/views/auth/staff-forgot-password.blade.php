@extends('auth.recovery-layout')

@section('title', 'Recover Your Account')
@section('subtitle', 'Recover Your Account')

@section('content')
    <p class="jarz-fp-copy">Enter your registered email address and we'll send you a verification code to reset your
        password.</p>

    @if (session('status'))
        <div class="auth-alert success">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="input-group jarz-fp-field">
            <label for="email">Email address</label>
            <div class="input-shell">
                <span class="input-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M4 7l8 6 8-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                            stroke-linejoin="round" />
                        <rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.8" />
                    </svg>
                </span>
                <input id="email" type="email" name="email" value="{{ old('email', $prefillEmail ?? '') }}"
                    placeholder="example@gmail.com" autocomplete="email" maxlength="255" required autofocus>
            </div>
            @error('email')
                <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <button type="submit" class="primary-btn">Send Verification Code</button>
    </form>

    <div class="jarz-fp-divider"></div>

    <a href="{{ route('login') }}" class="jarz-fp-back">&larr; Back to login</a>
@endsection
