<div class="sidebar">

    <div class="sidebar-brand">
        <img src="{{ asset('admin/images/logo.png') }}">
        <span>Dispatch Hub</span>
    </div>

    <div class="menu">

        <p class="menu-label">MAIN</p>

        <a href="{{ route('admin.dashboard') }}" class="menu-item active">
            <i data-lucide="layout-dashboard"></i>
            <span>Dashboard</span>
        </a>

        <p class="menu-label">OPERATIONS</p>

        <a href="{{ route('admin.dispatch') }}" class="menu-item">
            <i data-lucide="inbox"></i>
            <span>Incoming Requests</span>
        </a>

        <a href="{{ route('admin.available-units') }}" class="menu-item">
            <i data-lucide="inbox"></i>
            <span>Available Units</span>
        </a>

        <a href="{{ route('admin.jobs') }}" class="menu-item">
            <i data-lucide="truck"></i>
            <span>Active Jobs</span>
        </a>

        <p class="menu-label">MANAGEMENT</p>

        <a href="#" class="menu-item">
            <i data-lucide="users"></i>
            <span>Drivers</span>
        </a>

        <a href="#" class="menu-item">
            <i data-lucide="map"></i>
            <span>Live Map</span>
        </a>

    </div>

    <form method="POST" action="{{ route('logout') }}" id="logoutForm">
        @csrf

        <button type="button" class="menu-item logout-btn" onclick="openLogoutModal()">
            <i data-lucide="log-out"></i>
            <span>Logout</span>
        </button>
    </form>

</div>
