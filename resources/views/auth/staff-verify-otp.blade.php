@extends('auth.recovery-layout')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/staff-verify-otp.css') }}">
@endpush

@section('title', 'Check Your Email')
@section('subtitle', 'Check Your Email')

@section('content')
    <p class="jarz-fp-copy">Enter the 6-digit verification code sent to:<br><strong>{{ $maskedEmail }}</strong></p>

    @if (session('status'))
        <div class="auth-alert success">{{ session('status') }}</div>
    @endif

    @error('otp')
        <div class="auth-alert error" role="alert">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('password.otp.verify') }}" id="otpForm">
        @csrf
        <input type="hidden" name="otp" id="otpValue">

        <div class="otp-boxes" id="otpBoxes">
            @for ($i = 0; $i < 6; $i++)
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box"
                    autocomplete="one-time-code" aria-label="Digit {{ $i + 1 }} of 6">
            @endfor
        </div>

        <p class="otp-timer" id="otpTimer">Code expires in {{ sprintf('%02d:%02d', intdiv($codeSecondsLeft, 60), $codeSecondsLeft % 60) }}</p>

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
@endsection

@push('scripts')
    <script>
        const boxes = Array.from(document.querySelectorAll('.otp-box'));
        const hiddenValue = document.getElementById('otpValue');

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

        // Remaining validity from the server, so a refresh does not restart the countdown.
        let secondsLeft = {{ (int) $codeSecondsLeft }};
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
@endpush
