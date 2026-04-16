<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="icon" href="{{ asset('admin/images/logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <title>{{ $loginConfig['pageTitle'] ?? 'TowMate Login' }}</title>
</head>

<body class="login-page">
    @php
        $role = old('role', $loginConfig['role'] ?? 'superadmin');
    @endphp

    <div class="login-wrapper">
        <!-- Left Side - Branding -->
        <div class="login-left">
            <div class="brand-section">
                <h1>Get Started</h1>
                <p class="brand-copy">Manage bookings, coordinate dispatch, and keep track of towing operations all in
                    one place. This system is
                    built to help you stay organized and respond quickly to every request.
                    Sign in to continue your work and stay updated throughout the day.</p>
                <p class="brand-note">Log in to access your dashboard and continue managing your operations efficiently.
                </p>
            </div>
        </div>

        <!-- Right Side - Form -->
        <div class="login-right">
            <div class="login-card">
                <div class="auth-header">
                    <h3>Sign in</h3>
                    <p>Enter your account details to continue.</p>
                </div>

                <div class="role-panel" id="rolePanel">
                    <p class="role-panel-title">Choose access</p>
                    <div class="role-options-inline">
                        <button type="button" class="role-btn" data-role="superadmin" data-label="Super Admin">
                            Super Admin
                        </button>
                        <button type="button" class="role-btn" data-role="dispatcher" data-label="Dispatcher">
                            Dispatcher
                        </button>
                        <button type="button" class="role-btn" data-role="teamleader" data-label="Team Leader">
                            Team Leader
                        </button>
                    </div>
                </div>

                @if (session('status'))
                    <div class="auth-alert success">{{ session('status') }}</div>
                @endif

                <form method="POST" action="{{ route('login') }}" id="secureLoginForm" novalidate>
                    @csrf
                    <input type="hidden" name="role" id="roleInput" value="{{ $role }}">
                    <input type="hidden" name="login_method" value="password">

                    <div id="emailPasswordFields" class="field-stack">
                        <div class="input-group">
                            <label for="email">Email</label>
                            <input id="email" type="email" name="email" value="{{ old('email') }}"
                                placeholder="name@towmate.com" autocomplete="username" maxlength="150">
                            @error('email')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="input-group">
                            <label for="password">Password</label>
                            <div class="password-wrap">
                                <input id="password" type="password" name="password" placeholder="Password"
                                    autocomplete="current-password" maxlength="128">
                                <button type="button" class="toggle-password" id="togglePassword"
                                    aria-label="Show password">Show</button>
                            </div>
                            @error('password')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                            @if ($errors->login->any())
                                <div class="auth-alert error" style="margin-top:8px;">
                                    {{ $errors->login->first('auth') ?: $errors->login->first() }}</div>
                            @endif
                        </div>
                    </div>

                    <div class="form-footer">
                        <a href="{{ route('password.request') }}" class="forgot-link" id="forgotPasswordLink">Forgot
                            Password Request</a>
                    </div>

                    <button type="submit" class="primary-btn" id="loginButton">
                        <span class="btn-label">Log in</span>
                        <span class="btn-loader" aria-hidden="true"></span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const roleInput = document.getElementById('roleInput');
        const roleButtons = document.querySelectorAll('.role-btn');
        const form = document.getElementById('secureLoginForm');
        const loginButton = document.getElementById('loginButton');
        const passwordInput = document.getElementById('password');
        const togglePassword = document.getElementById('togglePassword');

        function syncSelectedRoleButtons(activeRole) {
            roleButtons.forEach(btn => {
                btn.classList.toggle('is-active', btn.dataset.role === activeRole);
            });
        }

        const currentRole = roleInput.value || 'superadmin';
        syncSelectedRoleButtons(currentRole);

        // Role selection buttons
        roleButtons.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const role = btn.dataset.role;

                roleInput.value = role;
                syncSelectedRoleButtons(role);

                // Clear form fields when role changes
                document.getElementById('email').value = '';
                passwordInput.value = '';
            });
        });

        // Password toggle
        togglePassword?.addEventListener('click', () => {
            const nextType = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = nextType;
            togglePassword.textContent = nextType === 'password' ? 'Show' : 'Hide';
            togglePassword.setAttribute('aria-label', nextType === 'password' ? 'Show password' : 'Hide password');
        });

        // Form submission
        form?.addEventListener('submit', () => {
            loginButton.disabled = true;
            loginButton.classList.add('is-loading');
        });
    </script>
</body>

</html>
