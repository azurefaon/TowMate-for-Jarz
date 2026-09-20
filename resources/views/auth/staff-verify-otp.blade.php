<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="stylesheet" href="{{ asset('admin/css/staff-forgot-password.css') }}">
    <link rel="stylesheet" href="{{ asset('admin/css/staff-verify-otp.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('dispatcher/images/jarz-logo.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <title>Verify Code</title>
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
                <p class="jarz-fp-subtitle">Check Your Email</p>

                <div class="jarz-fp-divider"></div>

                <p class="jarz-fp-copy">Enter the 6-digit verification code sent to:<br><strong>{{ $maskedEmail }}</strong></p>

                @if (session('status'))
                    <div class="auth-alert success">{{ session('status') }}</div>
                @endif

                @error('otp')
                    <div class="auth-alert error">{{ $message }}</div>
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

                    <button type="submit" class="primary-btn" id="verifyBtn">Verify Code</button>
                </form>

                <div class="jarz-fp-divider"></div>

                <div class="jarz-verify-resend">
                    Didn't receive the code?
                    <form method="POST" action="{{ route('password.otp.resend') }}" class="jarz-verify-resend-form">
                        @csrf
                        <button type="submit" id="resendBtn" @if ($resendWaitSeconds > 0) disabled @endif>Resend code</button>
                    </form>
                </div>

                <a href="{{ route('password.request') }}" class="jarz-fp-back">&larr; Change email</a>
            </div>
        </div>
    </section>

    <script>
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

        let resendSecondsLeft = {{ (int) $resendWaitSeconds }};
        const resendBtn = document.getElementById('resendBtn');

        function renderResendState() {
            resendBtn.disabled = resendSecondsLeft > 0;
        }

        renderResendState();
        const resendInterval = setInterval(() => {
            if (resendSecondsLeft > 0) {
                resendSecondsLeft--;
                renderResendState();
                if (resendSecondsLeft <= 0) clearInterval(resendInterval);
            }
        }, 1000);
    </script>
</body>

</html>
