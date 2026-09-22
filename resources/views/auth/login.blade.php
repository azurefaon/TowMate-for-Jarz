<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('dispatcher/images/jarz-logo.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <title>{{ $loginConfig['pageTitle'] ?? 'TowMate | Sign In' }}</title>
</head>

<body class="jarz-page">
    @php
        $role = old('role', $loginConfig['role'] ?? 'superadmin');
        $isLocked = (bool) session('login_locked');
        $lockedEmail = (string) session('login_locked_email', '');
    @endphp

    @include('public.partials.nav', ['active' => 'login'])

    <section class="jarz-hero" id="top">
        <div class="jarz-hero-overlay"></div>
        <div class="jarz-hero-inner">
            <div class="jarz-login-card">
                @if ($isLocked)
                    <div class="lock-block">
                        <h2>Sign-in temporarily locked</h2>
                        <p class="lock-message">Too many unsuccessful sign-in attempts. Your sign-in access is
                            temporarily locked. Verify your email to recover access.</p>
                        <a href="{{ route('login.recover', ['email' => $lockedEmail]) }}"
                            class="primary-btn lock-recover-btn">Recover access</a>
                        <a href="{{ route('login') }}" class="lock-back-link">&larr; Back to sign in</a>
                    </div>
                @else
                    <p class="jarz-login-label">Staff Login</p>

                    @if (session('status'))
                        <div class="auth-alert success">{{ session('status') }}</div>
                    @endif

                    @if ($errors->login->any())
                        <div class="auth-alert error">
                            {{ $errors->login->first('auth') ?: $errors->login->first() }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login') }}" id="secureLoginForm" novalidate>
                        @csrf
                        <input type="hidden" name="role" id="roleInput" value="{{ $role }}">

                        <div class="field-stack">
                            <div class="input-group">
                                <label for="email">Email address</label>
                                <div class="input-shell">
                                    <span class="input-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none">
                                            <path d="M4 7l8 6 8-6" stroke="currentColor" stroke-width="1.8"
                                                stroke-linecap="round" stroke-linejoin="round" />
                                            <rect x="3" y="5" width="18" height="14" rx="2"
                                                stroke="currentColor" stroke-width="1.8" />
                                        </svg>
                                    </span>
                                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                                        placeholder="example@gmail.com" autocomplete="username" maxlength="150">
                                </div>
                                @error('email')
                                    <span class="field-error">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="input-group">
                                <label for="password">Password</label>
                                <div class="input-shell password-shell">
                                    <span class="input-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none">
                                            <path d="M8 10V7a4 4 0 118 0v3" stroke="currentColor" stroke-width="1.8"
                                                stroke-linecap="round" />
                                            <rect x="5" y="10" width="14" height="10" rx="2"
                                                stroke="currentColor" stroke-width="1.8" />
                                        </svg>
                                    </span>
                                    <input id="password" type="password" name="password"
                                        placeholder="Enter your password" autocomplete="current-password"
                                        maxlength="128">
                                    <button type="button" class="toggle-password" id="togglePassword"
                                        aria-label="Show password">Show</button>
                                </div>
                                @error('password')
                                    <span class="field-error">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>

                        <div class="form-footer">
                            <a href="{{ route('password.request') }}" class="forgot-link"
                                id="forgotPasswordLink">Forgot password?</a>
                        </div>

                        <button type="submit" class="primary-btn" id="loginButton">
                            <span class="btn-label">Sign in</span>
                            <span class="btn-loader" aria-hidden="true"></span>
                        </button>
                    </form>

                    <div class="authorized-note">
                        <span class="authorized-text">Authorized access only.</span>
                    </div>
                @endif
            </div>
        </div>
    </section>

    @include('public.partials.footer')

    <script>
        const form = document.getElementById('secureLoginForm');
        const loginButton = document.getElementById('loginButton');
        const passwordInput = document.getElementById('password');
        const togglePassword = document.getElementById('togglePassword');

        togglePassword?.addEventListener('click', () => {
            const nextType = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = nextType;
            togglePassword.textContent = nextType === 'password' ? 'Show' : 'Hide';
            togglePassword.setAttribute('aria-label', nextType === 'password' ? 'Show password' : 'Hide password');
        });

        form?.addEventListener('submit', () => {
            loginButton.disabled = true;
            loginButton.classList.add('is-loading');
        });
    </script>
</body>

</html>
