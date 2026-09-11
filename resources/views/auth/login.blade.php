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
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <title>{{ $loginConfig['pageTitle'] ?? 'TowMate | Sign In' }}</title>
</head>

<body class="login-page">
    @php
        $role = old('role', $loginConfig['role'] ?? 'superadmin');
        $isLocked = (bool) session('login_locked');
        $lockedEmail = (string) session('login_locked_email', '');
    @endphp

    <div class="login-shell">
        <div class="login-columns">
            <section class="login-left">
                <div class="login-left-content">
                    <img src="{{ asset('dispatcher/images/jarz-logo.png') }}" alt="Jarz Towing Services" class="brand-logo">

                    <h1 class="brand-heading">Towing operations<br>made <span class="brand-accent">simple.</span></h1>

                    <p class="brand-copy">Access TowMate for JARZ towing operations and staff services.</p>

                    <div class="app-section">
                        <span class="app-label">Get the mobile app</span>
                        <div class="app-row">
                            @if($apkExists)
                                <a href="{{ $apkUrl }}" download="TowMate.apk" class="app-btn app-btn-android">
                                    <svg class="app-btn-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.6 9.48l1.84-3.18a.5.5 0 00-.87-.5l-1.86 3.22a11.4 11.4 0 00-9.42 0L5.43 5.8a.5.5 0 10-.87.5L6.4 9.48A10.3 10.3 0 001 18h22a10.3 10.3 0 00-5.4-8.52zM7 15a1 1 0 110-2 1 1 0 010 2zm10 0a1 1 0 110-2 1 1 0 010 2z" /></svg>
                                    <span class="app-btn-text">
                                        <strong>Android</strong>
                                        <small>Download app</small>
                                    </span>
                                </a>
                            @endif
                            <span class="app-btn app-btn-ios" aria-disabled="true">
                                <svg class="app-btn-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.05 12.04c-.03-2.5 2.04-3.7 2.13-3.76-1.16-1.7-2.97-1.94-3.62-1.96-1.54-.16-3.02.9-3.8.9-.79 0-2-.88-3.29-.86-1.69.03-3.26.99-4.13 2.5-1.77 3.06-.45 7.6 1.26 10.08.84 1.22 1.83 2.58 3.14 2.53 1.26-.05 1.74-.81 3.27-.81 1.52 0 1.96.81 3.29.79 1.36-.02 2.22-1.22 3.05-2.45.96-1.4 1.35-2.76 1.37-2.83-.03-.01-2.63-1.01-2.67-4.13zM14.6 4.7c.7-.85 1.17-2.02 1.04-3.2-1 .04-2.23.68-2.95 1.5-.65.73-1.22 1.92-1.07 3.05 1.1.09 2.25-.56 2.98-1.35z" /></svg>
                                <span class="app-btn-text">
                                    <strong>iOS</strong>
                                    <small>Coming soon</small>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="login-right">
                <div class="login-form-wrap">
                    @if ($isLocked)
                        <div class="lock-block">
                            <h2>Sign-in temporarily locked</h2>
                            <p class="lock-message">Too many unsuccessful sign-in attempts. Your sign-in access is temporarily locked. Verify your email to recover access.</p>
                            <a href="{{ route('login.recover', ['email' => $lockedEmail]) }}" class="primary-btn lock-recover-btn">Recover access</a>
                            <a href="{{ route('login') }}" class="lock-back-link">&larr; Back to sign in</a>
                        </div>
                    @else
                        <div class="auth-header">
                            <h2>Welcome back</h2>
                            <p>Sign in to continue to TowMate.</p>
                        </div>

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
                                            placeholder="name@company.com" autocomplete="username" maxlength="150">
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
                                        <input id="password" type="password" name="password" placeholder="Enter your password"
                                            autocomplete="current-password" maxlength="128">
                                        <button type="button" class="toggle-password" id="togglePassword"
                                            aria-label="Show password">Show</button>
                                    </div>
                                    @error('password')
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            <div class="form-footer">
                                <a href="{{ route('password.request') }}" class="forgot-link" id="forgotPasswordLink">Forgot password?</a>
                            </div>

                            <button type="submit" class="primary-btn" id="loginButton">
                                <span class="btn-label">Sign in</span>
                                <span class="btn-loader" aria-hidden="true"></span>
                            </button>
                        </form>

                        <div class="authorized-note">
                            <span class="authorized-line"></span>
                            <span class="authorized-text">Authorized access only.</span>
                            <span class="authorized-line"></span>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>

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
