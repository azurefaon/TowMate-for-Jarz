<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="icon" href="{{ asset('admin/images/logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <title>Forgot Password</title>
</head>

<body class="login-page">

    <div class="login-wrapper">
        <div class="login-left">
            <div class="brand-section">
                <h1>Manage operations with confidence</h1>
                <p class="brand-copy">Use this system to manage booking requests, assign tow trucks, and track
                    operations as they happen. It’s designed to simplify coordination and give you better control over
                    daily workflows.</p>
                <p class="brand-note">Log in to access your dashboard and continue managing your operations efficiently.
                </p>
            </div>
        </div>

        <div class="login-right">
            <div class="login-card recovery-card">
                <div class="auth-header">
                    <span class="auth-kicker">Password recovery</span>
                    <h3>Request account access</h3>
                    <p>Enter your work email and the Super Admin will review your access request.</p>
                </div>

                @if (session('status'))
                    <div class="auth-alert success">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('password.email') }}" class="field-stack">
                    @csrf

                    <div class="input-group">
                        <label for="email">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required>

                        @error('email')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="input-group">
                        <label for="note">Reason or note (optional)</label>
                        <textarea id="note" name="note" rows="4"
                            placeholder="Add a short note so the Super Admin knows what you need.">{{ old('note') }}</textarea>
                    </div>

                    <button type="submit" class="primary-btn">
                        <span class="btn-label">Forgot Password Request</span>
                    </button>

                    <div class="form-footer recovery-footer">
                        <a href="{{ route('login') }}" class="forgot-link">Back to login</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>

</html>
