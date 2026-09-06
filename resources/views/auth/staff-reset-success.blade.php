<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{ asset('admin/css/auth-recovery.css') }}">
    <link rel="icon" href="{{ asset('admin/images/logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <title>Password Updated</title>
</head>

<body class="recovery-page">
    <div class="recovery-wrap">
        <div class="recovery-wordmark">Tow<span>Mate</span></div>

        <div class="recovery-card" style="text-align:center;">
            <div class="recovery-success-icon">&#10003;</div>
            <div class="recovery-header">
                <h1>Password updated</h1>
                <p>Your password has been changed successfully.<br>You can now sign in.</p>
            </div>

            <a href="{{ route('login') }}" class="recovery-btn" style="display:block;box-sizing:border-box;text-decoration:none;line-height:1.4;">Back to Login</a>
        </div>
    </div>
</body>

</html>
