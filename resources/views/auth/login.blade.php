<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="icon" href="{{ asset('admin/images/logo.png') }}">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">

    <script src="https://unpkg.com/lucide@latest"></script>

    <title>Login</title>
</head>

<body>

    <div class="container">

        <div class="left">

            <div class="brand">
                <img src="{{ asset('admin/images/logo.png') }}" class="logo">
                <div>
                    <h2>Jarz</h2>
                    <p>Operations Control Panel</p>
                </div>
            </div>

            <img src="{{ asset('admin/images/GIF-towmate.gif') }}" class="illustration">

        </div>

        <div class="right">

            <div class="glass-card">

                <form id="loginForm" autocomplete="off" method="POST" action="{{ route('login') }}">
                    @csrf

                    <input type="text" style="display:none">
                    <input type="password" style="display:none">

                    <div id="emailLogin" class="auth-view">

                        <h1>Log In</h1>
                        <p class="subtitle">Login to your Jarz account</p>

                        <div class="input-group">
                            <label>Email</label>
                            <input type="email" name="email" value="{{ old('email') }}"
                                placeholder="Enter your Email" required maxlength="100" autocomplete="username"
                                inputmode="email" spellcheck="false">
                        </div>

                        <div class="input-group">

                            <label>Password</label>

                            <div class="password-group">
                                <input type="password" name="password" id="password" placeholder="Enter password"
                                    required maxlength="128" autocomplete="current-password">
                                <img src="{{ asset('admin/images/open-eye-icon.png') }}" class="eye"
                                    id="togglePassword" draggable="false">
                            </div>

                            <small class="error-text">
                                @if ($errors->login->any())
                                    {{ $errors->login->first() }}
                                @endif
                            </small>

                        </div>

                        <div class="login-options">
                            <a href="{{ route('password.request') }}" class="forgot-link">
                                Forgot password?
                            </a>
                        </div>

                        <button type="submit" id="loginBtn">

                            <span class="btn-text">Login</span>

                            <span class="tow-loading">
                                <img src="{{ asset('admin/images/towtruck-icon-login.png') }}" class="tow-truck">
                                <span class="road"></span>
                            </span>

                        </button>

                        <div class="signup-switch">
                            <button type="button" id="signupTab">Sign up</button>
                        </div>

                    </div>

                    <div id="phoneLogin" class="auth-view" style="display:none">

                        <h1>Phone Registration</h1>

                        <div class="back-option" id="backToOptions">
                            ← Back
                        </div>

                        <div class="input-group">
                            <label>Full Name</label>
                            <input type="text" name="full_name" placeholder="your name" maxlength="80"
                                pattern="^[A-Za-z\s]{2,80}$" title="Only letters and spaces allowed">
                        </div>

                        <div class="input-group">
                            <label>Phone Number</label>
                            <input type="tel" id="phoneInput" name="phone" placeholder="+63 912 345 6789"
                                maxlength="15" pattern="^\+?[0-9]{10,15}$" inputmode="numeric">
                        </div>

                        <div class="input-group" id="otpGroup" style="display:none">
                            <label>Enter OTP</label>
                            <input type="text" maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                                placeholder="6 digit code" autocomplete="one-time-code">
                        </div>

                        <button type="button" id="sendOtpBtn" class="otp-btn">
                            Send Verification OTP
                        </button>

                    </div>

                    <div id="registerOptions" class="auth-view" style="display:none">

                        <h1>Registration</h1>

                        <div class="signup-header">

                            <div class="back-option" id="backToLoginMain">
                                ← Back
                            </div>

                            <h2>Create your account</h2>
                            <p class="signup-sub">Choose how you want to create your Jarz account</p>

                        </div>

                        <div class="signup-methods">

                            <div class="signup-card" id="registerEmailBtn">

                                <div class="signup-icon">
                                    <i data-lucide="mail"></i>
                                </div>

                                <div class="signup-text">
                                    <h4>Email Registration</h4>
                                    <p>Create account using your email and password</p>
                                </div>

                            </div>

                            <div class="signup-card" id="registerPhoneBtn">

                                <div class="signup-icon">
                                    <i data-lucide="phone"></i>
                                </div>

                                <div class="signup-text">
                                    <h4>Phone Registration</h4>
                                    <p>Register quickly using your mobile number</p>
                                </div>

                            </div>

                        </div>

                    </div>

                </form>

                <form method="POST" action="{{ route('register') }}">
                    @csrf

                    <div id="registerEmail" class="auth-view" style="display:none">

                        <h1>Email Registration</h1>

                        <div class="back-option" id="backToRegisterOptions">
                            <i data-lucide="arrow-left"></i>
                        </div>

                        <div class="register-group">

                            <label>Full Name</label>

                            <div class="register-input">
                                <i data-lucide="user"></i>
                                <input type="text" name="name" placeholder="Your Name" required>
                            </div>

                        </div>

                        <div class="register-group">

                            <label>Email</label>

                            <div class="register-input">
                                <i data-lucide="mail"></i>
                                <input type="email" name="email" placeholder="your.email@email.com"
                                    maxlength="100" autocomplete="email" required>
                            </div>

                            @if ($errors->any())
                                <div style="color: #e74c3c; margin-bottom:10px;">
                                    @foreach ($errors->all() as $error)
                                        <div>{{ $error }}</div>
                                    @endforeach
                                </div>
                            @endif

                        </div>

                        <div class="register-group password-hint-wrapper">

                            <label>Password</label>

                            <div class="register-input password-box">

                                <i data-lucide="lock"></i>

                                <input type="password" id="registerPassword" name="password"
                                    placeholder="Create strong password" maxlength="128" autocomplete="new-password"
                                    required>

                                <img src="{{ asset('admin/images/open-eye-icon.png') }}" class="toggle-eye-img"
                                    id="toggleRegisterPass" draggable="false">

                            </div>

                            <div class="password-hint" id="passwordHint">

                                <h4>Password must following requirements:</h4>

                                <ul class="password-checklist">

                                    <li id="check-length">
                                        <span class="check-icon">○</span>
                                        8+ characters
                                    </li>

                                    <li id="check-upper">
                                        <span class="check-icon">○</span>
                                        One uppercase letter
                                    </li>

                                    <li id="check-lower">
                                        <span class="check-icon">○</span>
                                        One lowercase letter
                                    </li>

                                    <li id="check-number">
                                        <span class="check-icon">○</span>
                                        One number
                                    </li>

                                    <li id="check-special">
                                        <span class="check-icon">○</span>
                                        One special character
                                    </li>

                                </ul>

                            </div>

                        </div>

                        <div class="register-group">

                            <label>Confirm Password</label>

                            <div class="register-input password-box">

                                <i data-lucide="lock"></i>

                                <input type="password" id="confirmPassword" name="password_confirmation"
                                    placeholder="Confirm password" maxlength="128" autocomplete="new-password"
                                    required>

                                <img src="{{ asset('admin/images/open-eye-icon.png') }}" class="toggle-eye-img"
                                    id="toggleConfirmPass" draggable="false">

                            </div>

                        </div>

                        <button type="submit" class="register-btn">
                            Create Account
                        </button>

                    </div>
                </form>

            </div>
        </div>
    </div>



    <script src="{{ asset('admin/js/login.js') }}"></script>

    <script>
        lucide.createIcons();
    </script>

</body>

</html>
