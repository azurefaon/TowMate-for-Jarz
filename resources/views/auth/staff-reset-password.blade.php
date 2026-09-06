<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{ asset('admin/css/auth-recovery.css') }}">
    <link rel="icon" href="{{ asset('admin/images/logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <title>Create New Password</title>
</head>

<body class="recovery-page">
    <div class="recovery-wrap">
        <div class="recovery-wordmark">Tow<span>Mate</span></div>

        <div class="recovery-card">
            <div class="recovery-header">
                <h1>Create New Password</h1>
                <p>Choose a secure password for your account.</p>
            </div>

            @if ($errors->any() && !$errors->has('password') && !$errors->has('password_confirmation'))
                <div class="recovery-alert error">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('password.store') }}">
                @csrf

                <div class="recovery-field">
                    <label for="password">New password</label>
                    <div class="password-field-shell">
                        <input id="password" type="password" name="password" required autofocus>
                        <button type="button" class="password-toggle" data-toggle-for="password">Show</button>
                    </div>
                    @error('password')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="recovery-field">
                    <label for="password_confirmation">Confirm new password</label>
                    <div class="password-field-shell">
                        <input id="password_confirmation" type="password" name="password_confirmation" required>
                        <button type="button" class="password-toggle" data-toggle-for="password_confirmation">Show</button>
                    </div>
                    @error('password_confirmation')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>

                <button type="submit" class="recovery-btn">Reset Password</button>
            </form>
        </div>
    </div>

    <script>
        document.querySelectorAll('.password-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.toggleFor);
                const showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                btn.textContent = showing ? 'Show' : 'Hide';
            });
        });
    </script>
</body>

</html>
