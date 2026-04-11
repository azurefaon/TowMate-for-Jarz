@php
    $dispatcherUser = auth()->user();
    $dispatcherName = $dispatcherUser->full_name ?? ($dispatcherUser->name ?? 'Dispatcher');
@endphp

<aside class="sidebar">
    <div class="sidebar-shell">
        <div class="sidebar-brand">
            <img src="{{ asset('admin/images/logo.png') }}" alt="Jarz Logo">
            <div class="brand-copy">
                <span>Jarz Dispatch</span>
                <small>Operations panel</small>
            </div>
        </div>

        <div class="menu-label">Operations</div>
        <nav class="menu">
            <a href="{{ route('admin.dashboard') }}"
                class="menu-item {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                <i data-lucide="layout-dashboard"></i>
                <span>Dashboard</span>
            </a>

            <a href="{{ route('admin.dispatch') }}"
                class="menu-item {{ request()->routeIs('admin.dispatch') ? 'active' : '' }}">
                <i data-lucide="clipboard-check"></i>
                <span>Dispatch Queue</span>
            </a>

            <a href="{{ route('admin.jobs') }}"
                class="menu-item {{ request()->routeIs('admin.jobs') ? 'active' : '' }}">
                <i data-lucide="briefcase-business"></i>
                <span>Active Jobs</span>
            </a>
        </nav>

        <div class="menu-label">Fleet</div>
        <nav class="menu">
            <a href="{{ route('admin.drivers') }}"
                class="menu-item {{ request()->routeIs('admin.drivers') ? 'active' : '' }}">
                <i data-lucide="users-round"></i>
                <span>Team Leaders</span>
            </a>

            <a href="{{ route('admin.available-units') }}"
                class="menu-item {{ request()->routeIs('admin.available-units') ? 'active' : '' }}">
                <i data-lucide="truck"></i>
                <span>Available Units</span>
            </a>
        </nav>

    </div>

    <form id="logoutForm" action="{{ route('logout') }}" method="POST" style="display:none;">
        @csrf
    </form>
</aside>
