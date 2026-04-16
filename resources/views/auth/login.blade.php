<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="icon" href="{{ asset('admin/images/logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <title>{{ $loginConfig['pageTitle'] ?? 'TowMate Login' }}</title>
</head>

<body class="login-page">
    @php
        $role = old('role', $loginConfig['role'] ?? 'superadmin');
    @endphp

    <div class="auth-shell">
        <aside class="auth-brand-panel">
            <div class="brand-top">
                <div class="brand-lockup">
                    <img src="{{ asset('admin/images/logo.png') }}" alt="Jarz Towing logo" class="brand-logo">
                    <div>
                        <h2>Jarz Towing</h2>
                        <p class="brand-subtitle">{{ $loginConfig['panelTitle'] ?? 'Portal' }}</p>
                    </div>
                </div>
            </div>

            <div class="brand-copy">
                <h1>Welcome back</h1>
                <p>{{ $loginConfig['panelText'] ?? 'Sign in to continue.' }}</p>
            </div>

            <div class="brand-showcase" aria-hidden="true">
                <div class="showcase-card">
                    <div class="showcase-card-header">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                    <div class="showcase-card-body">
                        <div class="showcase-lines">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                    </div>
                </div>
                <div class="showcase-dots">
                    <span class="is-active"></span>
                    <span></span>
                    <span></span>
                </div>
            </div>

            <div class="brand-meta">
                <span>{{ now()->format('M d, Y') }}</span>
                <span>{{ $loginConfig['panelTitle'] ?? 'TowMate' }}</span>
            </div>
        </aside>

        <main class="auth-panel">
            <section class="auth-card">
                <div class="auth-header">
                    <h3>{{ $loginConfig['heading'] ?? 'Welcome back' }}</h3>
                    <p>{{ $loginConfig['subtitle'] ?? 'Sign in to continue.' }}</p>
                </div>

                @if (session('status'))
                    <div class="auth-alert success">{{ session('status') }}</div>
                @endif

                @if ($errors->login->any())
                    <div class="auth-alert error">{{ $errors->login->first('auth') ?: $errors->login->first() }}</div>
                @endif

                <form method="POST" action="{{ route('login') }}" id="secureLoginForm" novalidate>
                    @csrf
                    <input type="hidden" name="role" id="roleInput" value="{{ $role }}">
                    <input type="hidden" name="login_method" value="password">

                    <div id="emailPasswordFields" class="field-stack">
                        <div class="input-group">
                            <label for="email">Email address</label>
                            <input id="email" type="email" name="email" value="{{ old('email') }}"
                                placeholder="name@towmate.com" autocomplete="username" maxlength="150">
                            @error('email')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="input-group">
                            <label for="password">Password</label>
                            <div class="password-wrap">
                                <input id="password" type="password" name="password" placeholder="Enter your password"
                                    autocomplete="current-password" maxlength="128">
                                <button type="button" class="toggle-password" id="togglePassword"
                                    aria-label="Show password">
                                    <i data-lucide="eye"></i>
                                </button>
                            </div>
                            @error('password')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>

                    </div>

                    <div class="form-footer">
                        <a href="{{ route('password.request') }}" class="forgot-link" id="forgotPasswordLink">Forgot
                            password?</a>
                    </div>

                    <button type="submit" class="primary-btn" id="loginButton">
                        <span class="btn-label">Log in</span>
                        <span class="btn-loader" aria-hidden="true"></span>
                    </button>
                </form>

                <div class="portal-links">
                    @if (($loginConfig['role'] ?? 'superadmin') !== 'superadmin')
                        <a href="{{ route('login') }}">Super Admin</a>
                    @endif
                    @if (($loginConfig['role'] ?? 'superadmin') !== 'dispatcher')
                        <a href="{{ route('dispatcher.login') }}">Dispatcher</a>
                    @endif
                    @if (($loginConfig['role'] ?? 'superadmin') !== 'teamleader')
                        <a href="{{ route('teamleader.login') }}">Team Leader</a>
                    @endif
                </div>
            </section>
        </main>
    </div>

    <script>
        const form = document.getElementById('secureLoginForm');
        const loginButton = document.getElementById('loginButton');
        const passwordInput = document.getElementById('password');
        const togglePassword = document.getElementById('togglePassword');

        togglePassword?.addEventListener('click', () => {
            const nextType = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = nextType;
            togglePassword.innerHTML = nextType === 'password' ?
                '<i data-lucide="eye"></i>' :
                '<i data-lucide="eye-off"></i>';
            window.lucide?.createIcons();
        });

        form?.addEventListener('submit', () => {
            loginButton.disabled = true;
            loginButton.classList.add('is-loading');
        });

        window.lucide?.createIcons();
    </script>
