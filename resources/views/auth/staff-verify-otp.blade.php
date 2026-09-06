<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{ asset('admin/css/auth-recovery.css') }}">
    <link rel="icon" href="{{ asset('admin/images/logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <title>Verify Code</title>
</head>

<body class="recovery-page">
    <div class="recovery-wrap">
        <div class="recovery-wordmark">Tow<span>Mate</span></div>

        <div class="recovery-card">
            <div class="recovery-header">
                <h1>Check your email</h1>
                <p>Enter the 6-digit verification code sent to:<br><strong>{{ $maskedEmail }}</strong></p>
            </div>

            @if (session('status'))
                <div class="recovery-alert success">{{ session('status') }}</div>
            @endif

            @error('otp')
                <div class="recovery-alert error">{{ $message }}</div>
            @enderror

            <form method="POST" action="{{ route('password.otp.verify') }}" id="otpForm">
                @csrf
                <input type="hidden" name="otp" id="otpValue">

                <div class="otp-boxes" id="otpBoxes">
                    @for ($i = 0; $i < 6; $i++)
                        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" autocomplete="one-time-code">
                    @endfor
                </div>

                <p class="otp-timer" id="otpTimer">Code expires in 04:59</p>

                <button type="submit" class="recovery-btn" id="verifyBtn">Verify Code</button>
            </form>

            <div class="recovery-resend">
                Didn't receive the code?
                <form method="POST" action="{{ route('password.otp.resend') }}" style="display:inline;">
                    @csrf
                    <button type="submit">Resend code</button>
                </form>
            </div>

            <a href="{{ route('password.request') }}" class="recovery-back">&larr; Change email</a>
        </div>
    </div>

    <script>
        // UX only — the server independently validates the OTP's format,
        // hash, expiry, and attempt count regardless of anything this does.
        const boxes = Array.from(document.querySelectorAll('.otp-box'));
        const hiddenValue = document.getElementById('otpValue');
        const form = document.getElementById('otpForm');

        function syncHiddenValue() {
            hiddenValue.value = boxes.map(b => b.value).join('');
        }

        boxes.forEach((box, index) => {
            box.addEventListener('input', () => {
                box.value = box.value.replace(/[^0-9]/g, '').slice(0, 1);
                if (box.value && index < boxes.length - 1) {
                    boxes[index + 1].focus();
                }
                syncHiddenValue();
            });

            box.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !box.value && index > 0) {
                    boxes[index - 1].focus();
                }
            });

            box.addEventListener('paste', (e) => {
                const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                if (pasted.length >= 1) {
                    e.preventDefault();
                    pasted.slice(0, 6).split('').forEach((digit, i) => {
                        if (boxes[i]) boxes[i].value = digit;
                    });
                    syncHiddenValue();
                    const nextEmpty = boxes.findIndex(b => !b.value);
                    (nextEmpty === -1 ? boxes[boxes.length - 1] : boxes[nextEmpty]).focus();
                }
            });
        });

        if (boxes[0]) boxes[0].focus();

        // Display-only countdown — the server is the authoritative expiry check.
        let secondsLeft = 5 * 60 - 1;
        const timerEl = document.getElementById('otpTimer');

        function renderTimer() {
            if (secondsLeft <= 0) {
                timerEl.textContent = 'Verification code expired. Request a new code.';
                timerEl.classList.add('expired');
                return;
            }
            const m = String(Math.floor(secondsLeft / 60)).padStart(2, '0');
            const s = String(secondsLeft % 60).padStart(2, '0');
            timerEl.textContent = `Code expires in ${m}:${s}`;
        }

        renderTimer();
        const interval = setInterval(() => {
            secondsLeft--;
            renderTimer();
            if (secondsLeft <= 0) clearInterval(interval);
        }, 1000);
    </script>
</body>

</html>
