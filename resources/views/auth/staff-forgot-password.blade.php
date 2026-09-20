<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="stylesheet" href="{{ asset('admin/css/staff-forgot-password.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('dispatcher/images/jarz-logo.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <title>Forgot Password</title>
</head>

<body class="jarz-page">
    <nav class="jarz-nav jarz-nav--login">
        <div class="jarz-nav-inner">
            <a href="{{ route('login') }}" class="jarz-brand">
                <img src="{{ asset('dispatcher/images/jarz-logo.png') }}" alt="JARZ Towing Services" class="jarz-brand-logo">
                <span class="jarz-brand-name">JARZ Towing Services</span>
            </a>
            <div class="jarz-nav-links">
                <a href="{{ route('login') }}#about-us">About Us</a>
                <a href="{{ route('login') }}#our-services">Our Services</a>
            </div>
        </div>
    </nav>

    <section class="jarz-hero jarz-hero--fill">
        <div class="jarz-hero-overlay"></div>
        <div class="jarz-hero-inner">
            <div class="jarz-fp-card">
                <h1 class="jarz-fp-title">JARZ Towing Services</h1>
                <p class="jarz-fp-subtitle">Forgot Password</p>

                <div class="jarz-fp-divider"></div>

                <p class="jarz-fp-copy">Enter your registered email address and we'll send you a verification code to reset your password.</p>

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
                                    <path d="M4 7l8 6 8-6" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                    <rect x="3" y="5" width="18" height="14" rx="2"
                                        stroke="currentColor" stroke-width="1.8" />
                                </svg>
                            </span>
                            <input id="email" type="email" name="email" value="{{ old('email') }}"
                                placeholder="you@jarztowing.com" required autofocus>
                        </div>
                        @error('email')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <button type="submit" class="primary-btn">Send Verification Code</button>
                </form>

                <div class="jarz-fp-divider"></div>

                <a href="{{ route('login') }}" class="jarz-fp-back">&larr; Back to login</a>
            </div>
        </div>
    </section>
</body>

</html>
