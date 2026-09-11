<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{ asset('admin/css/login-recover.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('dispatcher/images/jarz-logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <title>Recover Access</title>
</head>

<body class="recovery-page">
    <div class="recovery-wrap">
        <div class="recovery-wordmark">Tow<span>Mate</span></div>

        <div class="recovery-card">
            <div class="recovery-header">
                <h1>Recover access</h1>
                <p>Your sign-in access was temporarily locked after several unsuccessful attempts. Confirm your email address and we'll send you a verification code to restore access.</p>
            </div>

            @if (session('status'))
                <div class="recovery-alert success">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('login.recover.send') }}">
                @csrf

                <div class="recovery-field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autofocus>
                    @error('email')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>

                <button type="submit" class="recovery-btn">Send verification code</button>
            </form>

            <a href="{{ route('login') }}" class="recovery-back">&larr; Back to sign in</a>
        </div>
    </div>
</body>

</html>
