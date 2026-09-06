<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{ asset('admin/css/auth-recovery.css') }}">
    <link rel="icon" href="{{ asset('admin/images/logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <title>Forgot Password</title>
</head>

<body class="recovery-page">
    <div class="recovery-wrap">
        <div class="recovery-wordmark">Tow<span>Mate</span></div>

        <div class="recovery-card">
            <div class="recovery-header">
                <h1>Forgot Password</h1>
                <p>Enter your registered email address and we'll send you a verification code.</p>
            </div>

            @if (session('status'))
                <div class="recovery-alert success">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf

                <div class="recovery-field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
                    @error('email')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>

                <button type="submit" class="recovery-btn">Send Verification Code</button>
            </form>

            <a href="{{ route('login') }}" class="recovery-back">&larr; Back to login</a>
        </div>
    </div>
</body>

</html>
