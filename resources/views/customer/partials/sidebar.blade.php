<div class="sidebar">

    <div class="sidebar-brand">
        <img src="{{ asset('admin/images/logo.png') }}">
        <span>Jarz</span>
    </div>

    <div class="sidebar-menu">

        <p class="menu-title">MAIN</p>

        <a href="{{ route('customer.dashboard') }}"
            class="{{ request()->routeIs('customer.dashboard') ? 'active' : '' }}">
            <i data-lucide="layout-dashboard"></i>
            <span>Dashboard</span>
        </a>

        <p class="menu-title">SERVICES</p>

        <a href="{{ route('customer.book') }}">
            <i data-lucide="truck"></i>
            <span>Book</span>
        </a>

        <a id="trackBtn" href="{{ route('customer.track.index') }}"
            class="{{ request()->routeIs('customer.track*') ? 'active' : '' }}">

            <i data-lucide="map-pin"></i>
            <span>Track</span>

        </a>

        <a href="{{ route('customer.history') }}">
            <i data-lucide="history"></i>
            <span>History</span>
        </a>

        <p class="menu-title">SUPPORT</p>

        <a href="{{ route('customer.chat') }}">
            <i data-lucide="message-circle"></i>
            <span>Chat</span>
        </a>

        <a href="{{ route('customer.help') }}">
            <i data-lucide="help-circle"></i>
            <span>Help</span>
        </a>

    </div>

</div>
