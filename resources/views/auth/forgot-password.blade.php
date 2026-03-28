<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="icon" href="{{ asset('admin/images/logo.png') }}">

    <title>Forgot Password</title>
</head>

<body>

    <div class="container">

        <div class="right" style="width:100%">
            <div class="glass-card">

                <h1>Password Recovery</h1>

                <p class="subtitle">
                    Enter your email and we will send you a password reset link.
                </p>

                @if (session('status'))
                    <div class="success-message">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('password.email') }}">
                    @csrf

                    <div class="input-group">

                        <label>Email</label>

                        <input type="email" name="email" value="{{ old('email') }}" required>

                        @error('email')
                            <small class="error-text">{{ $message }}</small>
                        @enderror

                    </div>

                    <button type="submit">
                        Send Reset Link
                    </button>

                    <p class="create-account">
                        <a href="{{ route('login') }}">Back to Login</a>
                    </p>

                </form>

            </div>
        </div>

    </div>

</body>

</html>
