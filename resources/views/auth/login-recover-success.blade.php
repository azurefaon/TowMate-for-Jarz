<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{ asset('admin/css/login-recover.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('dispatcher/images/jarz-logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <title>Access Recovered</title>
</head>

<body class="recovery-page">
    <div class="recovery-wrap">
        <div class="recovery-wordmark">Tow<span>Mate</span></div>

        <div class="recovery-card" style="text-align:center;">
            <div class="recovery-success-icon">&#10003;</div>
            <div class="recovery-header">
                <h1>Access recovered</h1>
                <p>Your sign-in access has been restored.<br>Sign in with your password to continue.</p>
            </div>

            <a href="{{ route('login') }}" class="recovery-btn" style="display:block;box-sizing:border-box;text-decoration:none;line-height:1.4;">Back to sign in</a>
        </div>
    </div>
</body>

</html>
