@extends('auth.recovery-layout')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/staff-reset-password.css') }}">
@endpush

@section('title', 'Password Updated')
@section('subtitle', 'Password Updated')

@section('content')
    <div class="pw-success-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none">
            <path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"
                stroke-linejoin="round" />
        </svg>
    </div>

    <p class="jarz-fp-copy pw-success-copy">Your password has been changed. For your security, sign in again with your
        new password.</p>

    <a href="{{ route('login') }}" class="primary-btn">Return to Login</a>
@endsection
