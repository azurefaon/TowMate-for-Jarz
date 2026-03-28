<link rel="stylesheet" href="{{ asset('customer/css/mobile-navbar.css') }}">

<div class="mobile-nav-container">

    <div class="fab-menu" id="fabMenu">

        {{-- HOME --}}
        <a href="{{ route('customer.dashboard') }}"
            class="fab-item {{ request()->routeIs('customer.dashboard') ? 'active' : '' }}">
            <i data-lucide="home"></i>
            <span>Home</span>
        </a>

        {{-- BOOK --}}
        <a href="{{ route('customer.book') }}" class="fab-item {{ request()->routeIs('customer.book') ? 'active' : '' }}">
            <i data-lucide="plus-circle"></i>
            <span>Book</span>
        </a>

        {{-- CHAT --}}
        <a href="{{ route('customer.chat') }}"
            class="fab-item {{ request()->routeIs('customer.chat') ? 'active' : '' }}">
            <i data-lucide="message-circle"></i>
            <span>Chat</span>
        </a>

        {{-- ✅ FIXED TRACK (IMPORTANT) --}}
        <a href="{{ route('customer.track.index') }}"
            class="fab-item {{ request()->routeIs('customer.track*') ? 'active' : '' }}">
            <i data-lucide="map-pin"></i>
            <span>Track</span>
        </a>

        {{-- HISTORY --}}
        <a href="{{ route('customer.history') }}"
            class="fab-item {{ request()->routeIs('customer.history') ? 'active' : '' }}">
            <i data-lucide="clock"></i>
            <span>History</span>
        </a>

        {{-- PROFILE --}}
        <a href="{{ route('profile.edit') }}"
            class="fab-item {{ request()->routeIs('profile.edit') ? 'active' : '' }}">
            <i data-lucide="user"></i>
            <span>Profile</span>
        </a>

    </div>

    {{-- FAB BUTTON --}}
    <div class="fab-main" id="fabMain">
        <i data-lucide="menu"></i>
    </div>

</div>
