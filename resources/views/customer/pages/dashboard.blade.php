@extends('customer.layouts.app')

@section('title', 'Dashboard')

@section('content')

    <div class="welcome-card">
        <div class="welcome-top">
            <h1>Welcome back, {{ auth()->user()->name }}</h1>
        </div>

        <div class="welcome-email">
            <span class="email-text">{{ auth()->user()->email }}</span>

            @if (!auth()->user()->email_verified_at)
                <button id="openOtp" class="badge-unverified clickable">
                    <i data-lucide="alert-circle"></i>
                    <span id="verifyText">Not Verified</span>
                </button>
            @else
                <button id="verifyBadge" class="badge-verified">
                    <i data-lucide="check-circle"></i>
                    <span id="verifyText">Verified</span>
                </button>
            @endif
        </div>
    </div>

    <div id="otpModal" class="otp-modal hidden">
        <div class="otp-card">
            <div class="otp-header">
                <i data-lucide="mail"></i>
                <h3>Verify your email</h3>
                <p>Enter the 4 digit code sent to</p>
                <strong>{{ auth()->user()->email }}</strong>
            </div>

            <div class="otp-inputs">
                <input type="text" maxlength="1" class="otp-input">
                <input type="text" maxlength="1" class="otp-input">
                <input type="text" maxlength="1" class="otp-input">
                <input type="text" maxlength="1" class="otp-input">
            </div>

            <div class="otp-error" id="otpError"></div>

            <div class="resend-wrapper">
                <button id="resendBtn" disabled>Resend OTP</button>
                <span id="countdown">(60s)</span>
            </div>

            <button class="confirm-btn" id="confirmBtn">
                <span id="btnText">Confirm email</span>
                <span id="loader" class="loader hidden"></span>
            </button>
        </div>
    </div>

    <div id="successModal" class="otp-modal hidden">
        <div class="otp-card success">
            <i data-lucide="check-circle"></i>
            <h3>Verified</h3>
            <p>You have successfully verified your account</p>
            <button onclick="location.reload()">Continue</button>
        </div>
    </div>

    <div class="dashboard-grid">

        <div class="active-booking-card">
            <div class="banner-left">
                <i data-lucide="truck"></i>
                <div>
                    <h3>Active Booking</h3>
                    <p>No active booking yet</p>
                </div>
            </div>

            @if ($activeBooking)
                <a href="{{ route('customer.track', $activeBooking->id) }}" class="track-btn">
                    Track Now →
                </a>
            @else
                <span class="track-btn disabled">No Active Booking</span>
            @endif
        </div>

        <div class="side-stats">
            <div class="stat-card blue">
                <i data-lucide="calendar"></i>
                <div>
                    <strong>{{ $totalBookings }}</strong>
                    <span>Total Bookings</span>
                </div>
            </div>

            <div class="stat-card green">
                <i data-lucide="truck"></i>
                <div>
                    <strong>{{ $activeBookings }}</strong>
                    <span>Active Jobs</span>
                </div>
            </div>
        </div>

        <div class="bottom-stats">
            <div class="stat-card orange">
                <i data-lucide="credit-card"></i>
                <div>
                    <strong>₱{{ number_format($totalSpent, 2) }}</strong>
                    <span>Total Spent</span>
                </div>
            </div>

            <div class="stat-card purple">
                <i data-lucide="message-circle"></i>
                <div>
                    <strong>0</strong>
                    <span>Messages</span>
                </div>
            </div>
        </div>

    </div>

    <h3 class="section-title">Quick Actions</h3>

    <div class="quick-grid">

        <a href="{{ route('customer.book') }}" class="quick-card blue">
            <i data-lucide="truck"></i>
            <h4>Book Towing</h4>
            <p>Request a towing service</p>
            <span>View →</span>
        </a>

        @if ($activeBooking)
            <a href="{{ route('customer.track', $activeBooking->id) }}" class="quick-card green">
            @else
                <div class="quick-card green disabled">
        @endif

        <i data-lucide="map-pin"></i>
        <h4>Track Current Booking</h4>
        <p>Crew on the way</p>
        <span>View →</span>

        @if ($activeBooking)
            </a>
        @else
    </div>
    @endif

    <a href="{{ route('customer.history') }}" class="quick-card purple">
        <i data-lucide="history"></i>
        <h4>Booking History</h4>
        <p>View past services</p>
        <span>View →</span>
    </a>

    <a href="{{ route('customer.chat') }}" class="quick-card orange">
        <i data-lucide="message-circle"></i>
        <h4>Chat Support</h4>
        <p>Get instant help</p>
        <span>View →</span>
    </a>

    <a href="{{ route('customer.help') }}" class="quick-card gray">
        <i data-lucide="help-circle"></i>
        <h4>Help Guide</h4>
        <p>Learn how to use the system</p>
        <span>View →</span>
    </a>

    </div>

    <h3 class="section-title">Recent Activity</h3>

    <div class="activity-card">

        @if (isset($activities) && count($activities) > 0)
            <div class="activity-list">
                @foreach ($activities as $activity)
                    <div class="activity-item">
                        <div class="activity-left">
                            <i data-lucide="{{ $activity->icon }}"></i>
                            <div>
                                <p><strong>{{ $activity->title }}</strong></p>
                                <span>{{ $activity->description }}</span>
                            </div>
                        </div>

                        <span class="badge {{ $activity->status }}">
                            {{ ucfirst($activity->status) }}
                        </span>
                    </div>
                @endforeach
            </div>
        @else
            <div class="activity-empty">
                <i data-lucide="inbox"></i>
                <h4>No activity yet</h4>
                <p>Your bookings, payments, and updates will appear here.</p>
            </div>
        @endif

    </div>

    <div id="logoutModal" class="logout-modal hidden">

        <div class="logout-card">

            <h3>Confirm Logout</h3>
            <p>Are you sure you want to logout?</p>

            <div class="logout-actions">
                <button class="cancel-btn" onclick="closeLogoutModal()">Cancel</button>
                <button class="confirm-btn" onclick="submitLogout()">Yes, Logout</button>
            </div>

        </div>

    </div>

    <script>
        lucide.createIcons();
    </script>

    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="{{ asset('customer/js/verify.js') }}"></script>
    <script src="{{ asset('customer/js/dashboard.js') }}"></script>

@endsection
