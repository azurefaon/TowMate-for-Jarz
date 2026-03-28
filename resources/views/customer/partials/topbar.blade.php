<div class="topbar">

    <div class="topbar-left">
        <h3>@yield('title', 'Dashboard')</h3>
    </div>

    <div class="topbar-right">

        {{-- NOTIFICATION --}}
        <div class="top-icon notification">
            <i data-lucide="bell"></i>
            <span class="notif-badge">3</span>
        </div>

        {{-- SETTINGS --}}
        <div class="top-icon">
            <i data-lucide="settings"></i>
        </div>

        {{-- LOGOUT --}}
        <form method="POST" action="{{ route('logout') }}" id="logoutForm">
            @csrf
            <button type="button" class="logout-btn" onclick="openLogoutModal()">
                <i data-lucide="log-out"></i>
                <span>LOGOUT</span>
            </button>
        </form>

    </div>

</div>
