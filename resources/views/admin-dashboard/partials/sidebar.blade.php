@php
    $dispatcherUser = auth()->user();
    $dispatcherName = $dispatcherUser->full_name ?? ($dispatcherUser->name ?? 'Dispatcher');
@endphp

<aside class="sidebar">
    <div class="sidebar-shell">
        <div class="sidebar-logo">
            <img
                src="{{ asset('dispatcher/images/jarz-logo.png') }}"
                alt="JARZ Towing Services"
                class="sidebar-logo-img"
            >
        </div>

        <div class="menu-label">Operations</div>
        <nav class="menu">
            <a href="{{ route('admin.dashboard') }}"
                class="menu-item {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">

                <span>Dashboard</span>
            </a>

            {{-- <a href="{{ route('control-center.index') }}"
                class="menu-item {{ request()->routeIs('control-center.*') ? 'active' : '' }}">
                <span>Control Center</span>
            </a> --}}

            <a href="{{ route('admin.dispatch') }}"
                class="menu-item {{ request()->routeIs('admin.dispatch') ? 'active' : '' }}">
                <span>Dispatch Queue</span>
            </a>

            <a href="{{ route('admin.jobs') }}"
                class="menu-item {{ request()->routeIs('admin.jobs') ? 'active' : '' }}">
                <span>Active Jobs</span>
            </a>

            <a href="{{ route('admin.booking-history') }}"
                class="menu-item {{ request()->routeIs('admin.booking-history') ? 'active' : '' }}">
                <span>Booking History</span>
            </a>
        </nav>

        <div class="menu-label">Units</div>
        <nav class="menu">
            <a href="{{ route('admin.drivers') }}"
                class="menu-item {{ request()->routeIs('admin.drivers') ? 'active' : '' }}">

                <span>Units & Leaders</span>
            </a>

        </nav>

    </div>

    <form id="logoutForm" action="{{ route('logout') }}" method="POST" style="display:none;">
        @csrf
    </form>
</aside>
