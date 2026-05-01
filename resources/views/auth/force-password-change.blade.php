<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- A02: No caching of sensitive pages --}}
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="icon" href="{{ asset('admin/images/logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <title>TowMate | Change Password</title>
    <style>
        body,
        input,
        button,
        label,
        p,
        h1,
        h2,
        h3,
        div {
            font-family: sans-serif;
        }

        .fpc-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f4f4f4;
        }

        .fpc-card {
            background: #fff;
            border: 1px solid #ccc;
            padding: 36px 40px;
            width: 100%;
            max-width: 480px;
        }

        .fpc-title {
            font-size: 20px;
            font-weight: normal;
            color: #000;
            margin: 0 0 6px 0;
        }

        .fpc-subtitle {
            font-size: 13px;
            color: #555;
            margin: 0 0 24px 0;
            line-height: 1.5;
        }

        .fpc-field {
            margin-bottom: 18px;
        }

        .fpc-field label {
            display: block;
            font-size: 13px;
            color: #000;
            margin-bottom: 5px;
        }

        .fpc-field input {
            width: 100%;
            padding: 9px 11px;
            font-size: 14px;
            border: 1px solid #bbb;
            background: #fff;
            color: #000;
            outline: none;
            box-sizing: border-box;
        }

        .fpc-field input:focus {
            border-color: #555;
        }

        /* Suppress Edge's built-in password reveal button */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none !important;
        }

        .fpc-pw-wrap {
            position: relative;
        }

        .fpc-pw-wrap input[type="password"],
        .fpc-pw-wrap input[type="text"] {
            padding-right: 56px;
        }

        .fpc-pw-toggle {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            padding: 0 12px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 12px;
            color: #555;
            font-family: sans-serif;
            user-select: none;
        }

        .fpc-pw-toggle:hover {
            color: #000;
        }

        .fpc-error {
            font-size: 12px;
            color: #b91c1c;
            margin-top: 4px;
        }

        /* Password requirements checklist */
        .fpc-requirements {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 12px 14px;
            margin-bottom: 20px;
        }

        .fpc-requirements p {
            font-size: 12px;
            color: #444;
            margin: 0 0 8px 0;
            font-weight: normal;
        }

        .fpc-req-item {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 12px;
            color: #666;
            margin-bottom: 4px;
            line-height: 1.4;
        }

        .fpc-req-item .fpc-dot {
            width: 8px;
            height: 8px;
            border: 1.5px solid #999;
            background: #fff;
            flex-shrink: 0;
        }

        .fpc-req-item.fpc-met .fpc-dot {
            background: #166534;
            border-color: #166534;
        }

        .fpc-req-item.fpc-met {
            color: #166534;
        }

        .fpc-req-item.fpc-fail .fpc-dot {
            background: #b91c1c;
            border-color: #b91c1c;
        }

        .fpc-req-item.fpc-fail {
            color: #b91c1c;
        }

        .fpc-btn {
            width: 100%;
            padding: 11px;
            background: #000;
            color: #fff;
            font-size: 14px;
            border: none;
            cursor: pointer;
            font-family: sans-serif;
        }

        .fpc-btn:hover {
            background: #222;
        }

        .fpc-btn:disabled {
            background: #999;
            cursor: not-allowed;
        }

        .fpc-alert-error {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #b91c1c;
            font-size: 13px;
            padding: 10px 13px;
            margin-bottom: 18px;
        }

        .fpc-alert-info {
            background: #f0fdf4;
            border: 1px solid #86efac;
            color: #166534;
            font-size: 13px;
            padding: 10px 13px;
            margin-bottom: 18px;
        }
    </style>
</head>

<body>
    <div class="fpc-wrapper">
        <div class="fpc-card">

            <h1 class="fpc-title">Change Your Password</h1>
            <p class="fpc-subtitle">
                Your account requires a new password before you can continue.
                Choose a strong, unique password you have not used before.
            </p>

            {{-- Session status --}}
            @if (session('status'))
                <div class="fpc-alert-info">{{ session('status') }}</div>
            @endif

            {{-- Global validation errors --}}
            @if ($errors->any())
                <div class="fpc-alert-error">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('password.force-change') }}" autocomplete="off" id="fpcForm">
                @csrf

                <div class="fpc-field">
                    <label for="password">New Password</label>
                    <div class="fpc-pw-wrap">
                        <input type="password" id="password" name="password" required autocomplete="new-password"
                            autofocus oninput="evaluatePassword()">
                        <button type="button" class="fpc-pw-toggle" onclick="toggleFpcPw('password', this)"
                            tabindex="-1">Show</button>
                    </div>
                    @error('password')
                        <div class="fpc-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="fpc-field">
                    <label for="password_confirmation">Confirm New Password</label>
                    <div class="fpc-pw-wrap">
                        <input type="password" id="password_confirmation" name="password_confirmation" required
                            autocomplete="new-password" oninput="evaluatePassword()">
                        <button type="button" class="fpc-pw-toggle"
                            onclick="toggleFpcPw('password_confirmation', this)" tabindex="-1">Show</button>
                    </div>
                </div>

                {{-- Live requirements checklist --}}
                <div class="fpc-requirements" id="passwordRequirements">
                    <p>Password Requirements:</p>
                    <div class="fpc-req-item" id="req-length">
                        <span class="fpc-dot"></span>
                        At least 12 characters
                    </div>
                    <div class="fpc-req-item" id="req-upper">
                        <span class="fpc-dot"></span>
                        At least one uppercase letter
                    </div>
                    <div class="fpc-req-item" id="req-lower">
                        <span class="fpc-dot"></span>
                        At least one lowercase letter
                    </div>
                    <div class="fpc-req-item" id="req-number">
                        <span class="fpc-dot"></span>
                        At least one number
                    </div>
                    <div class="fpc-req-item" id="req-symbol">
                        <span class="fpc-dot"></span>
                        At least one special character
                    </div>
                    <div class="fpc-req-item" id="req-match">
                        <span class="fpc-dot"></span>
                        Passwords match
                    </div>
                </div>

                <button type="submit" class="fpc-btn" id="fpcSubmit" disabled>Set New Password</button>
            </form>
        </div>
    </div>

    <script>
        function toggleFpcPw(inputId, btn) {
            var input = document.getElementById(inputId);
            if (!input) return;
            var isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.textContent = isHidden ? 'Hide' : 'Show';
        }

        function setReq(id, met, touched) {
            var el = document.getElementById(id);
            el.classList.remove('fpc-met', 'fpc-fail');
            if (!touched) return;
            el.classList.add(met ? 'fpc-met' : 'fpc-fail');
        }

        function evaluatePassword() {
            var pw = document.getElementById('password').value;
            var conf = document.getElementById('password_confirmation').value;

            var len = pw.length >= 12;
            var upper = /[A-Z]/.test(pw);
            var lower = /[a-z]/.test(pw);
            var number = /[0-9]/.test(pw);
            var symbol = /[^A-Za-z0-9]/.test(pw);
            var match = pw.length > 0 && pw === conf;

            var touched = pw.length > 0;

            setReq('req-length', len, touched);
            setReq('req-upper', upper, touched);
            setReq('req-lower', lower, touched);
            setReq('req-number', number, touched);
            setReq('req-symbol', symbol, touched);
            setReq('req-match', match, conf.length > 0 || touched);

            var allMet = len && upper && lower && number && symbol && match;
            document.getElementById('fpcSubmit').disabled = !allMet;
        }
    </script>
</body>

</html>
