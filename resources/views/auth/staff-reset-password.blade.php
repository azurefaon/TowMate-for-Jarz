@extends('auth.recovery-layout')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/staff-reset-password.css') }}">
@endpush

@section('title', 'Set New Password')
@section('subtitle', 'Set New Password')

@section('content')
    <p class="jarz-fp-copy">Choose a new password for your account. You'll sign in with it right after.</p>

    @if ($errors->any() && ! $errors->has('password'))
        <div class="auth-alert error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.store') }}" id="resetForm" novalidate>
        @csrf

        <div class="field-stack">
            <div class="input-group">
                <label for="password">New password</label>
                <div class="input-shell">
                    <input id="password" type="password" name="password" autocomplete="new-password"
                        maxlength="128" required autofocus aria-describedby="pwChecklist">
                    <button type="button" class="toggle-password" data-toggle-for="password"
                        aria-label="Show new password">Show</button>
                </div>
                @foreach ($errors->get('password') as $message)
                    <span class="field-error" role="alert">{{ $message }}</span>
                @endforeach
            </div>

            <ul class="pw-checklist" id="pwChecklist" aria-live="polite">
                <li data-rule="length"><span class="pw-mark" aria-hidden="true"></span>12+ characters</li>
                <li data-rule="upper"><span class="pw-mark" aria-hidden="true"></span>Uppercase letter</li>
                <li data-rule="lower"><span class="pw-mark" aria-hidden="true"></span>Lowercase letter</li>
                <li data-rule="number"><span class="pw-mark" aria-hidden="true"></span>Number</li>
                <li data-rule="special"><span class="pw-mark" aria-hidden="true"></span>Special character</li>
                <li data-rule="match"><span class="pw-mark" aria-hidden="true"></span>Passwords match</li>
            </ul>

            <div class="input-group">
                <label for="password_confirmation">Confirm new password</label>
                <div class="input-shell">
                    <input id="password_confirmation" type="password" name="password_confirmation"
                        autocomplete="new-password" maxlength="128" required>
                    <button type="button" class="toggle-password" data-toggle-for="password_confirmation"
                        aria-label="Show password confirmation">Show</button>
                </div>
            </div>
        </div>

        <p class="pw-note">We also check new passwords against known data breaches when you save.</p>

        <button type="submit" class="primary-btn">Save New Password</button>
    </form>

    <div class="jarz-fp-divider"></div>

    <a href="{{ route('login') }}" class="jarz-fp-back">&larr; Back to login</a>
@endsection

@push('scripts')
    <script>
        // Mirrors Laravel's Password::min(12)->mixedCase()->numbers()->symbols(): the same Unicode
        // classes (\p{Lu}, \p{Ll}, \p{N}, and \p{Z}|\p{S}|\p{P} for symbols) and code-point length as
        // mb_strlen. It is guidance only; the server still decides, including the breach check.
        const rules = {
            length: v => Array.from(v).length >= 12,
            upper: v => /\p{Lu}/u.test(v),
            lower: v => /\p{Ll}/u.test(v),
            number: v => /\p{N}/u.test(v),
            special: v => /[\p{Z}\p{S}\p{P}]/u.test(v),
        };

        const password = document.getElementById('password');
        const confirmation = document.getElementById('password_confirmation');
        const items = Object.fromEntries(
            Array.from(document.querySelectorAll('#pwChecklist li')).map(li => [li.dataset.rule, li])
        );

        function setState(item, met, touched) {
            item.classList.toggle('is-met', met);
            item.classList.toggle('is-unmet', touched && !met);
        }

        function renderChecklist() {
            const value = password.value;
            const touched = value.length > 0;
            Object.entries(rules).forEach(([name, test]) => setState(items[name], test(value), touched));
            setState(items.match, confirmation.value.length > 0 && confirmation.value === value, confirmation.value.length > 0);
        }

        // A server error (reuse, breach, mismatch) describes the previous attempt; hide it once the user edits.
        password.addEventListener('input', () => {
            document.querySelectorAll('.field-error').forEach(el => { el.hidden = true; });
        });
        password.addEventListener('input', renderChecklist);
        confirmation.addEventListener('input', renderChecklist);
        renderChecklist();

        document.querySelectorAll('.toggle-password').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.toggleFor);
                const showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                btn.textContent = showing ? 'Show' : 'Hide';
                btn.setAttribute('aria-label', (showing ? 'Show ' : 'Hide ') + (btn.dataset.toggleFor === 'password' ? 'new password' : 'password confirmation'));
            });
        });
    </script>
@endpush
