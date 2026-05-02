@php
    $roleId = auth()->user()->role_id ?? 0;
    $profileLayout = match ((int) $roleId) {
        1 => 'layouts.superadmin',
        2 => 'admin-dashboard.layouts.app',
        3 => 'teamleader.layouts.app',
        5 => 'customer.layouts.app',
        default => 'layouts.superadmin',
    };
@endphp

@extends($profileLayout)

@section('title', 'Account Settings')
@section('page_title', 'Account Settings')

@section('content')
    <style>
        .prof-page {
            max-width: 560px;
            margin: 0 auto;
            padding: 32px 20px 60px;
            font-family: inherit;
        }

        .prof-page-title {
            font-size: 1.15rem;
            color: #09090b;
            margin: 0 0 6px;
            letter-spacing: -0.01em;
        }

        .prof-page-sub {
            font-size: 0.82rem;
            color: #71717a;
            margin: 0 0 28px;
        }

        .prof-card {
            background: #fff;
            border: 1px solid #e4e4e7;
            border-radius: 14px;
            padding: 28px 28px 24px;
            margin-bottom: 20px;
        }

        .prof-card-head {
            padding-bottom: 18px;
            margin-bottom: 22px;
            border-bottom: 1px solid #f1f5f9;
        }

        .prof-card-head h2 {
            font-size: 0.9rem;
            color: #09090b;
            margin: 0 0 4px;
            letter-spacing: -0.01em;
        }

        .prof-card-head p {
            font-size: 0.8rem;
            color: #71717a;
            margin: 0;
            line-height: 1.55;
        }

        .prof-field {
            margin-bottom: 18px;
        }

        .prof-field:last-of-type {
            margin-bottom: 0;
        }

        .prof-label {
            display: block;
            font-size: 0.78rem;
            color: #52525b;
            margin-bottom: 6px;
            letter-spacing: 0.01em;
        }

        .prof-input {
            width: 100%;
            height: 42px;
            padding: 0 13px;
            border: 1px solid #d4d4d8;
            border-radius: 8px;
            background: #fff;
            font-size: 0.875rem;
            color: #09090b;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.15s;
            font-family: inherit;
        }

        .prof-input:focus {
            border-color: #09090b;
        }

        .prof-input.is-error {
            border-color: #ef4444;
        }

        .prof-error {
            font-size: 0.78rem;
            color: #ef4444;
            margin-top: 5px;
            display: block;
        }

        .prof-footer {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
        }

        .prof-save-btn {
            height: 40px;
            padding: 0 22px;
            background: #09090b;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            cursor: pointer;
            font-family: inherit;
            transition: background 0.15s;
        }

        .prof-save-btn:hover {
            background: #27272a;
        }

        .prof-saved-msg {
            font-size: 0.8rem;
            color: #16a34a;
        }

        @media (max-width: 600px) {
            .prof-card {
                padding: 20px 16px 18px;
            }
        }
    </style>

    <div class="prof-page">

        <h1 class="prof-page-title">Account Settings</h1>
        <p class="prof-page-sub">Manage your profile information and password.</p>

        {{-- ── Profile Information ── --}}
        <div class="prof-card">
            <div class="prof-card-head">
                <h2>Profile Information</h2>
                <p>Update your account's profile information and email address.</p>
            </div>

            <form id="send-verification" method="post" action="{{ route('verification.send') }}">@csrf</form>

            <form method="post" action="{{ route('profile.update') }}">
                @csrf
                @method('patch')

                <div class="prof-field">
                    <label class="prof-label" for="name">Name</label>
                    <input class="prof-input {{ $errors->has('name') ? 'is-error' : '' }}" id="name" name="name"
                        type="text" value="{{ old('name', $user->name) }}" required autocomplete="name">
                    @if ($errors->has('name'))
                        <span class="prof-error">{{ $errors->first('name') }}</span>
                    @endif
                </div>

                <div class="prof-field">
                    <label class="prof-label" for="email">Email</label>
                    <input class="prof-input {{ $errors->has('email') ? 'is-error' : '' }}" id="email" name="email"
                        type="email" value="{{ old('email', $user->email) }}" required autocomplete="username">
                    @if ($errors->has('email'))
                        <span class="prof-error">{{ $errors->first('email') }}</span>
                    @endif

                    @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && !$user->hasVerifiedEmail())
                        <span class="prof-error" style="color:#ca8a04;">
                            Email not verified.
                            <button form="send-verification"
                                style="background:none;border:none;padding:0;color:#ca8a04;text-decoration:underline;cursor:pointer;font-size:inherit;">
                                Resend verification.
                            </button>
                        </span>
                        @if (session('status') === 'verification-link-sent')
                            <span class="prof-saved-msg">Verification link sent.</span>
                        @endif
                    @endif
                </div>

                <div class="prof-footer">
                    <button type="submit" class="prof-save-btn">Save</button>
                    @if (session('status') === 'profile-updated')
                        <span class="prof-saved-msg">Saved successfully.</span>
                    @endif
                </div>
            </form>
        </div>

        {{-- ── Update Password ── --}}
        <div class="prof-card">
            <div class="prof-card-head">
                <h2>Update Password</h2>
                <p>Choose a strong password to keep your account secure.</p>
            </div>

            <form method="post" action="{{ route('password.update') }}">
                @csrf
                @method('put')

                <div class="prof-field">
                    <label class="prof-label" for="current_password">Current Password</label>
                    <input class="prof-input {{ $errors->updatePassword->has('current_password') ? 'is-error' : '' }}"
                        id="current_password" name="current_password" type="password" autocomplete="current-password">
                    @if ($errors->updatePassword->has('current_password'))
                        <span class="prof-error">{{ $errors->updatePassword->first('current_password') }}</span>
                    @endif
                </div>

                <div class="prof-field">
                    <label class="prof-label" for="new_password">New Password</label>
                    <input class="prof-input {{ $errors->updatePassword->has('password') ? 'is-error' : '' }}"
                        id="new_password" name="password" type="password" autocomplete="new-password">
                    @if ($errors->updatePassword->has('password'))
                        <span class="prof-error">{{ $errors->updatePassword->first('password') }}</span>
                    @endif
                </div>

                <div class="prof-field">
                    <label class="prof-label" for="password_confirmation">Confirm Password</label>
                    <input class="prof-input {{ $errors->updatePassword->has('password_confirmation') ? 'is-error' : '' }}"
                        id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
                    @if ($errors->updatePassword->has('password_confirmation'))
                        <span class="prof-error">{{ $errors->updatePassword->first('password_confirmation') }}</span>
                    @endif
                </div>

                <div class="prof-footer">
                    <button type="submit" class="prof-save-btn">Save</button>
                    @if (session('status') === 'password-updated')
                        <span class="prof-saved-msg">Password updated.</span>
                    @endif
                </div>
            </form>
        </div>

    </div>
@endsection
