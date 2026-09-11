<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'System Admin') | TowMate</title>
    <link rel="icon" type="image/png" href="{{ asset('dispatcher/images/jarz-logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('admin/css/system-admin.css') }}">
    @stack('styles')
</head>

<body class="sa-shell">
    @php
        $saUser = auth()->user();
    @endphp

    <div class="sa-sidebar" id="saSidebar">
        <div class="sa-sidebar-brand">
            <img src="{{ asset('dispatcher/images/jarz-logo.png') }}" alt="JARZ" class="sa-sidebar-logo">
            <div class="sa-sidebar-brand-text">
                TowMate
                <small>System Admin</small>
            </div>
        </div>

        <nav class="sa-sidebar-nav">
            <a href="{{ route('system-admin.dashboard') }}" class="sa-nav-link {{ request()->routeIs('system-admin.dashboard') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="3.5" width="7" height="7" rx="1" /><rect x="13.5" y="3.5" width="7" height="7" rx="1" /><rect x="3.5" y="13.5" width="7" height="7" rx="1" /><rect x="13.5" y="13.5" width="7" height="7" rx="1" /></svg>
                <span>Dashboard</span>
            </a>

            <div class="sa-nav-group-label">Administration</div>
            <a href="{{ route('system-admin.users.index') }}" class="sa-nav-link {{ request()->routeIs('system-admin.users.*') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.2" /><path d="M5 19.5c0-3.6 3.1-6.2 7-6.2s7 2.6 7 6.2" /></svg>
                <span>Users</span>
            </a>

            <div class="sa-nav-group-label">Security</div>
            <a href="{{ route('system-admin.security.monitor') }}" class="sa-nav-link {{ request()->routeIs('system-admin.security.monitor') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5 18.5 6.5v5.2c0 4.3-2.8 7.8-6.5 8.8-3.7-1-6.5-4.5-6.5-8.8V6.5Z" /><path d="M9.3 12 11 13.7 14.7 10" /></svg>
                <span>Security Monitor</span>
            </a>
            <a href="{{ route('system-admin.audit-logs.index') }}" class="sa-nav-link {{ request()->routeIs('system-admin.audit-logs.*') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3.5h9l3 3v14h-12Z" /><path d="M15 3.5v3h3" /><line x1="8.5" y1="12" x2="15.5" y2="12" /><line x1="8.5" y1="15.5" x2="15.5" y2="15.5" /></svg>
                <span>Audit Logs</span>
            </a>

            <div class="sa-nav-group-label">System</div>
            <a href="{{ route('system-admin.settings.index') }}" class="sa-nav-link {{ request()->routeIs('system-admin.settings.*') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3" /><path d="M19.4 13.5a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5v.2a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1h-.2a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3h.1a1.7 1.7 0 0 0 1-1.5v-.2a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.5 1h.2a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.6 1Z" /></svg>
                <span>System Settings</span>
            </a>
            <a href="{{ route('system-admin.maintenance.index') }}" class="sa-nav-link {{ request()->routeIs('system-admin.maintenance.*') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5c4.7 0 8.5 1.3 8.5 3v11c0 1.7-3.8 3-8.5 3s-8.5-1.3-8.5-3v-11c0-1.7 3.8-3 8.5-3Z" /><path d="M3.5 6.5c0 1.7 3.8 3 8.5 3s8.5-1.3 8.5-3" /><path d="M3.5 12c0 1.7 3.8 3 8.5 3s8.5-1.3 8.5-3" /></svg>
                <span>System Maintenance</span>
            </a>
        </nav>
    </div>

    <div class="sa-sidebar-overlay" id="saSidebarOverlay"></div>

    <div class="sa-main">
        <div class="sa-topbar">
            <button type="button" class="sa-menu-toggle" id="saMenuToggle" aria-label="Open navigation" aria-expanded="false" aria-controls="saSidebar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7" /><line x1="4" y1="12" x2="20" y2="12" /><line x1="4" y1="17" x2="20" y2="17" /></svg>
            </button>
            <div class="sa-topbar-title">
                <h1>@yield('title', 'System Admin')</h1>
                @hasSection('subtitle')
                    <p>@yield('subtitle')</p>
                @endif
            </div>

            <div class="sa-account" id="saAccountMenu">
                <button type="button" class="sa-account-trigger" id="saAccountTrigger" aria-haspopup="menu" aria-expanded="false">
                    <span class="sa-account-avatar">
                        @if ($saUser->profile_image)
                            <img src="{{ Storage::url($saUser->profile_image) }}" alt="">
                        @else
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><circle cx="12" cy="8" r="3.4" /><path d="M5.5 20 L6.8 14.8 L17.2 14.8 L18.5 20" /></svg>
                        @endif
                        <span class="sa-account-presence" aria-hidden="true"></span>
                    </span>
                    <span class="sa-account-name">{{ $saUser->full_name ?: $saUser->name }}</span>
                    <svg class="sa-account-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9 L12 15 L18 9" /></svg>
                </button>

                <div class="sa-account-dropdown" role="menu">
                    <a href="{{ route('system-admin.profile.edit') }}" class="sa-account-item" role="menuitem">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><circle cx="12" cy="8" r="3.4" /><path d="M5.5 20 L6.8 14.8 L17.2 14.8 L18.5 20" /></svg>
                        <span>Profile Settings</span>
                    </a>
                    <a href="{{ route('system-admin.profile.password.edit') }}" class="sa-account-item" role="menuitem">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5.5" y="11" width="13" height="9" rx="1.6" /><path d="M7 11 L7 8.2 A5 4.6 0 0 1 16.5 7" /><circle cx="12" cy="15.2" r="1.4" /></svg>
                        <span>Change Password</span>
                    </a>
                    <div class="sa-account-divider"></div>
                    <form method="POST" action="{{ route('logout') }}" class="sa-account-form">
                        @csrf
                        <button type="submit" class="sa-account-item" role="menuitem">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><polyline points="16 17 21 12 16 7" /><line x1="21" y1="12" x2="9" y2="12" /></svg>
                            <span>Logout</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="sa-content">
            @unless ($__env->yieldContent('noGlobalFlash') === 'true')
                @if (session('success'))
                    <div class="sa-flash sa-flash-success">{{ session('success') }}</div>
                @endif
                @if (session('error'))
                    <div class="sa-flash sa-flash-error">{{ session('error') }}</div>
                @endif
            @endunless

            @yield('content')
        </div>
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        const saSidebar = document.getElementById('saSidebar');
        const saOverlay = document.getElementById('saSidebarOverlay');
        const saMenuToggle = document.getElementById('saMenuToggle');

        function saOpenSidebar() {
            saSidebar.classList.add('is-open');
            saOverlay.classList.add('is-open');
            saMenuToggle.setAttribute('aria-expanded', 'true');
        }

        function saCloseSidebar() {
            saSidebar.classList.remove('is-open');
            saOverlay.classList.remove('is-open');
            saMenuToggle.setAttribute('aria-expanded', 'false');
        }

        saMenuToggle?.addEventListener('click', () => {
            saSidebar.classList.contains('is-open') ? saCloseSidebar() : saOpenSidebar();
        });

        saOverlay?.addEventListener('click', saCloseSidebar);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') saCloseSidebar();
        });

        const saAccountMenu = document.getElementById('saAccountMenu');
        const saAccountTrigger = document.getElementById('saAccountTrigger');

        function saCloseAccountMenu() {
            saAccountMenu?.classList.remove('is-open');
            saAccountTrigger?.setAttribute('aria-expanded', 'false');
        }

        saAccountTrigger?.addEventListener('click', (event) => {
            event.stopPropagation();
            const willOpen = !saAccountMenu.classList.contains('is-open');
            saAccountMenu.classList.toggle('is-open', willOpen);
            saAccountTrigger.setAttribute('aria-expanded', String(willOpen));
        });

        document.addEventListener('click', saCloseAccountMenu);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') saCloseAccountMenu();
        });
    </script>

    @stack('scripts')
</body>

</html>
