<div class="top-controls">

    <div class="top-icons">
        <i data-lucide="bell"></i>
        <i data-lucide="settings"></i>
    </div>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="logout-btn">
            <i data-lucide="log-out"></i>
        </button>
    </form>

</div>


<!-- SIDEBAR (DESKTOP) -->
<div class="sidebar-menu">

    <p class="menu-label">MAIN</p>

    <a href="{{ route('customer.dashboard') }}" class="{{ request()->routeIs('customer.dashboard') ? 'active' : '' }}">
        <i data-lucide="layout-dashboard"></i>
        <span>Dashboard</span>
    </a>

    <p class="menu-label">SERVICES</p>

    <a href="{{ route('customer.book') }}" class="{{ request()->routeIs('customer.book') ? 'active' : '' }}">
        <i data-lucide="truck"></i>
        <span>Book</span>
    </a>

    <a href="{{ route('customer.track.index') }}" class="{{ request()->routeIs('customer.track*') ? 'active' : '' }}">
        <i data-lucide="map-pin"></i>
        <span>Track</span>
    </a>

    <a href="{{ route('customer.history') }}" class="{{ request()->routeIs('customer.history') ? 'active' : '' }}">
        <i data-lucide="history"></i>
        <span>History</span>
    </a>

    <p class="menu-label">SUPPORT</p>

    <a href="{{ route('customer.chat') }}" class="{{ request()->routeIs('customer.chat') ? 'active' : '' }}">
        <i data-lucide="message-circle"></i>
        <span>Chat</span>
    </a>

    <a href="{{ route('customer.help') }}" class="{{ request()->routeIs('customer.help') ? 'active' : '' }}">
        <i data-lucide="help-circle"></i>
        <span>Help</span>
    </a>

</div>


<!-- MOBILE NAV (ONLY ONE!) -->
<div class="mobile-nav">

    <a href="{{ route('customer.dashboard') }}"
        class="nav-item {{ request()->routeIs('customer.dashboard') ? 'active' : '' }}">
        <i data-lucide="home"></i>
        <span>Home</span>
    </a>

    <a href="{{ route('customer.book') }}" class="nav-item {{ request()->routeIs('customer.book') ? 'active' : '' }}">
        <i data-lucide="truck"></i>
        <span>Book</span>
    </a>

    <a href="{{ route('customer.track.index') }}"
        class="nav-item {{ request()->routeIs('customer.track*') ? 'active' : '' }}">
        <i data-lucide="map-pin"></i>
        <span>Track</span>
    </a>

    <a href="{{ route('customer.history') }}"
        class="nav-item {{ request()->routeIs('customer.history') ? 'active' : '' }}">
        <i data-lucide="history"></i>
        <span>History</span>
    </a>

</div>
