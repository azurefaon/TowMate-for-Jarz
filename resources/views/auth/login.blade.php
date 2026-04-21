<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="icon" href="{{ asset('admin/images/logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <title>TowMate | Secure Login</title>
</head>

<body class="login-page">
    @php
        $role = old('role', $loginConfig['role'] ?? 'superadmin');
    @endphp

    <div class="login-wrapper">
        <section class="login-left">
            <div class="brand-lockup">
                <img src="{{ asset('admin/images/logo.png') }}" alt="Jarz Towing Services logo" class="brand-logo">
                <div class="brand-wordmark">Jarz <span>Towing Services</span></div>
            </div>
            {{-- 
            <div class="brand-badge">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 3l7 3v5c0 4.4-2.9 8.3-7 9.5C7.9 19.3 5 15.4 5 11V6l7-3z" stroke="currentColor"
                        stroke-width="1.8" />
                    <path d="M10.2 11.6l1.3 1.3 2.8-3.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
            </div> --}}

            <div class="brand-section">
                <h1>Welcome <span>Back!</span></h1>
                <p class="brand-copy">
                    Access TowMate’s control panel designed for authorized staff. Manage towing operations,
                    assign tasks, and monitor real-time activities from a centralized platform.
                </p>
            </div>

            <div class="brand-note-card">
                <div class="note-icon">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path
                            d="M12 2a5 5 0 00-5 5v3H6a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2v-6a2 2 0 00-2-2h-1V7a5 5 0 00-5-5z"
                            stroke="currentColor" stroke-width="1.8" />
                        <path d="M9 10V7a3 3 0 116 0v3" stroke="currentColor" stroke-width="1.8" />
                    </svg>
                </div>
                <div>
                    <strong>Access restricted to authorized TowMate staff.</strong>
                    <p>Access is determined by your assigned role after login.</p>
                </div>
            </div>
        </section>

        <section class="login-right">
            <div class="login-card">
                <div class="auth-header">
                    <h2>Login</h2>
                    <p>Enter your credentials to access your account.</p>
                </div>

                @if (session('status'))
                    <div class="auth-alert success">{{ session('status') }}</div>
                @endif

                <form method="POST" action="{{ route('login') }}" id="secureLoginForm" novalidate>
                    @csrf
                    <input type="hidden" name="role" id="roleInput" value="{{ $role }}">

                    <div class="field-stack">
                        <div class="input-group">
                            <label for="email" class="sr-only">Email</label>
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
                                    placeholder="Enter your email" autocomplete="username" maxlength="150">
                            </div>
                            @error('email')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="input-group">
                            <label for="password" class="sr-only">Password</label>
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
                            @if ($errors->login->any())
                                <div class="auth-alert error">
                                    {{ $errors->login->first('auth') ?: $errors->login->first() }}
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="form-footer">
                        <a href="{{ route('password.request') }}" class="forgot-link" id="forgotPasswordLink">Forgot
                            password?</a>
                    </div>

                    <button type="submit" class="primary-btn" id="loginButton">
                        <span class="btn-label">Sign In</span>
                        <span class="btn-loader" aria-hidden="true"></span>
                    </button>
                </form>
            </div>
        </section>
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
