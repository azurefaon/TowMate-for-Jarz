<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'JARZ Owner')</title>
    <link rel="icon" type="image/png" href="{{ asset('admin/images/logo.png') }}">
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/panel.css') }}?v={{ filemtime(public_path('superadmin/css/panel.css')) }}">
    <style>
        :root {
            --jarz-accent: #facc15;
            --jarz-bg: #ffffff;
            --jarz-sidebar: #171717;
            --jarz-text: #171717;
            --jarz-line: #e5e5e5;
            --jarz-muted: #525252;
            --jarz-success: #15803d;
            --jarz-danger: #b91c1c;
        }

        body.superadmin-shell {
            background: var(--jarz-bg);
            color: var(--jarz-text);
        }

        .sidebar,
        .content {
            border-color: var(--jarz-line) !important;
        }

        .sidebar {
            background: var(--jarz-sidebar);
        }

        .sidebar li a,
        .sidebar li button {
            color: #ffffff;
        }

        .sidebar li button.sidebar-group-toggle {
            color: #e5e5e5;
        }

        .sidebar .sidebar-section {
            color: #a3a3a3;
        }

        .content {
            color: var(--jarz-text);
        }

        .sidebar li a:hover,
        .sidebar li button:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
        }

        .sidebar li a.active,
        .sidebar li button.active {
            background: var(--jarz-accent);
            color: #171717;
        }

        .sidebar li a.active .nav-chip,
        .sidebar li button.active .nav-chip {
            color: #171717;
        }

        .badge {
            background: var(--jarz-accent);
            color: #171717;
        }

        .sa-topbar {
            display: flex;
            justify-content: flex-end;
            margin: 0 0 16px;
            position: sticky;
            top: 0;
            z-index: 140;
            padding: 0 0 8px;
            background: #ffffff;
        }

        .sa-profile-menu {
            position: relative;
            z-index: 150;
        }

        .sa-profile-menu summary {
            list-style: none;
            cursor: pointer;
        }

        .sa-profile-menu summary::-webkit-details-marker {
            display: none;
        }

        .sa-profile-trigger {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px;
            background: transparent;
            border: none;
        }

        .sa-profile-avatar {
            width: 36px;
            height: 36px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--jarz-accent);
            color: #171717;
            font-weight: 600;
        }

        .sa-profile-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            min-width: 180px;
            padding: 6px;
            background: #ffffff;
            border: 1px solid var(--jarz-line);
            z-index: 40;
        }

        .sa-profile-dropdown a,
        .sa-profile-dropdown button {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border: 0;
            background: transparent;
            color: var(--jarz-text);
            text-decoration: none;
            cursor: pointer;
        }

        .sa-profile-dropdown a:hover,
        .sa-profile-dropdown button:hover {
            background: #f5f5f5;
        }

        .sa-profile-meta strong {
            display: block;
            font-size: 0.92rem;
            font-weight: 500;
            line-height: 1.2;
        }

        .sa-profile-meta small {
            display: block;
            margin-top: 2px;
            color: var(--jarz-muted);
            font-size: 0.76rem;
        }

        .sa-profile-dropdown .sa-logout-trigger {
            color: var(--jarz-danger);
        }

        .sa-profile-dropdown .sa-logout-trigger:hover {
            background: #fdf1f1;
        }

        .sa-logout-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1800;
        }

        .sa-logout-modal.is-open {
            display: flex;
        }

        .sa-logout-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(23, 23, 23, 0.55);
        }

        .sa-logout-card {
            position: relative;
            width: min(420px, calc(100% - 24px));
            padding: 22px;
            background: #fff;
            border: 1px solid var(--jarz-line);
        }

        .sa-logout-card-head {
            display: flex;
            justify-content: flex-end;
            align-items: flex-start;
            margin-bottom: 6px;
        }

        .sa-logout-close {
            width: 36px;
            height: 36px;
            border: 0;
            background: #f5f5f5;
            color: var(--jarz-muted);
            cursor: pointer;
        }

        .sa-logout-close:hover {
            background: #e5e5e5;
            color: #171717;
        }

        .sa-logout-card h3 {
            margin: 0 0 8px;
            font-size: 1.15rem;
            font-weight: 600;
            color: #171717;
        }

        .sa-logout-card p {
            margin: 0;
            color: var(--jarz-muted);
            line-height: 1.55;
        }

        .sa-logout-actions {
            margin-top: 18px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .sa-logout-actions button {
            border: 0;
            padding: 10px 14px;
            font-weight: 500;
            cursor: pointer;
        }

        .sa-logout-actions .secondary {
            background: #ffffff;
            color: #171717;
            border: 1px solid var(--jarz-line);
        }

        .sa-logout-actions .primary {
            background: #171717;
            color: #fff;
        }

        .sa-logout-actions .primary:hover {
            background: #000000;
        }

        @media (max-width: 768px) {
            .sa-topbar {
                position: static;
                padding-bottom: 10px;
            }

            .sa-profile-menu {
                width: 100%;
            }

            .sa-profile-trigger {
                width: 100%;
                justify-content: flex-start;
            }

            .sa-profile-dropdown {
                left: 0;
                right: auto;
                width: min(100%, 220px);
            }
        }
    </style>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body class="superadmin-shell">

    @php
        $sidebarChevron = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"></polyline></svg>';

        $businessGroupActive = request()->routeIs('superadmin.revenue.*')
            || request()->routeIs('superadmin.reports.index')
            || request()->routeIs('superadmin.reports.bookings')
            || request()->routeIs('superadmin.reports.export*')
            || request()->routeIs('superadmin.bookings.*');

        $fleetGroupActive = request()->routeIs('superadmin.unit-truck.*')
            || request()->routeIs('superadmin.units.*')
            || request()->routeIs('superadmin.truck-types.*')
            || request()->routeIs('superadmin.vehicle-types.*');

        $oversightGroupActive = request()->routeIs('superadmin.monitoring.*')
            || request()->routeIs('superadmin.reports.activity*');

        $managementGroupActive = request()->routeIs('superadmin.settings.*');
    @endphp

    <div class="sidebar" id="sidebar">

        <ul>

            <div class="brand">
                <img src="{{ asset('dispatcher/images/jarz-logo.png') }}" alt="JARZ" class="brand-logo">
            </div>

            <li>
                <a href="{{ route('superadmin.dashboard') }}" title="Dashboard"
                    class="{{ request()->routeIs('superadmin.dashboard') ? 'active' : '' }}">
                    <span class="nav-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                            <path d="M4 15.5 A8 8 0 0 1 20 15.5" />
                            <line x1="12" y1="15.5" x2="16" y2="10.5" />
                            <circle cx="12" cy="15.5" r="1.1" fill="currentColor" stroke="none"/>
                        </svg>
                    </span>
                    <span>Dashboard</span>
                </a>
            </li>

            <div class="sidebar-divider"></div>

            <li>
                <button type="button" class="sidebar-group-toggle" title="Business"
                    aria-expanded="{{ $businessGroupActive ? 'true' : 'false' }}" aria-controls="sidebarGroupBusiness">
                    <span class="nav-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
                            <rect x="3.5" y="8" width="17" height="11" rx="1.2" />
                            <path d="M9 8 L9 6 A1.5 1.5 0 0 1 10.5 4.5 L13.5 4.5 A1.5 1.5 0 0 1 15 6 L15 8" />
                            <line x1="3.5" y1="12.5" x2="20.5" y2="12.5" />
                        </svg>
                    </span>
                    <span>Business</span>
                    <span class="sidebar-group-chevron">{!! $sidebarChevron !!}</span>
                </button>

                <div class="sidebar-subnav {{ $businessGroupActive ? 'is-open' : '' }}" id="sidebarGroupBusiness">
                    <ul class="sidebar-subnav-list">
                        <li>
                            <a href="{{ route('superadmin.revenue.index') }}" title="Revenue"
                                class="{{ request()->routeIs('superadmin.revenue.*') ? 'active' : '' }}">
                                <span>Revenue</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('superadmin.reports.index') }}" title="Reports"
                                class="{{ request()->routeIs('superadmin.reports.index') || request()->routeIs('superadmin.reports.bookings') || request()->routeIs('superadmin.reports.export*') ? 'active' : '' }}">
                                <span>Reports</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('superadmin.bookings.index') }}" title="Bookings"
                                class="{{ request()->routeIs('superadmin.bookings.*') ? 'active' : '' }}">
                                <span>Bookings</span>

                                @if (isset($pendingBookings) && $pendingBookings > 0)
                                    <span class="badge">{{ $pendingBookings }}</span>
                                @endif
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <div class="sidebar-divider"></div>

            <li>
                <a href="{{ route('superadmin.truck-types.index') }}" title="Fleet Management"
                    class="{{ $fleetGroupActive ? 'active' : '' }}">
                    <span class="nav-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
                            <path d="M3 16 L3 10 L11 10 L11 16" />
                            <path d="M11 12 L16 12 L19 15.2 L19 16" />
                            <line x1="3" y1="16" x2="20" y2="16" />
                            <circle cx="7" cy="18" r="1.6" />
                            <circle cx="16" cy="18" r="1.6" />
                        </svg>
                    </span>
                    <span>Fleet Management</span>
                </a>
            </li>

            <div class="sidebar-divider"></div>

            <li>
                <button type="button" class="sidebar-group-toggle" title="Oversight"
                    aria-expanded="{{ $oversightGroupActive ? 'true' : 'false' }}" aria-controls="sidebarGroupOversight">
                    <span class="nav-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
                            <rect x="3.5" y="5" width="17" height="11" rx="1" />
                            <line x1="9" y1="19" x2="15" y2="19" />
                            <line x1="12" y1="16" x2="12" y2="19" />
                        </svg>
                    </span>
                    <span>Oversight</span>
                    <span class="sidebar-group-chevron">{!! $sidebarChevron !!}</span>
                </button>

                <div class="sidebar-subnav {{ $oversightGroupActive ? 'is-open' : '' }}" id="sidebarGroupOversight">
                    <ul class="sidebar-subnav-list">
                        <li>
                            <a href="{{ route('superadmin.monitoring.index') }}" title="Operations Monitor"
                                class="{{ request()->routeIs('superadmin.monitoring.*') ? 'active' : '' }}">
                                <span>Operations Monitor</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('superadmin.reports.activity') }}" title="Business Activity"
                                class="{{ request()->routeIs('superadmin.reports.activity*') ? 'active' : '' }}">
                                <span>Business Activity</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <div class="sidebar-divider"></div>

            <li>
                <button type="button" class="sidebar-group-toggle" title="Management"
                    aria-expanded="{{ $managementGroupActive ? 'true' : 'false' }}" aria-controls="sidebarGroupManagement">
                    <span class="nav-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
                            <path d="M12 3.5 L18.5 7.2 L18.5 14.8 L12 18.5 L5.5 14.8 L5.5 7.2 Z" />
                            <circle cx="12" cy="11" r="2.2" />
                            <line x1="12" y1="3.5" x2="12" y2="6" />
                        </svg>
                    </span>
                    <span>Management</span>
                    <span class="sidebar-group-chevron">{!! $sidebarChevron !!}</span>
                </button>

                <div class="sidebar-subnav {{ $managementGroupActive ? 'is-open' : '' }}" id="sidebarGroupManagement">
                    <ul class="sidebar-subnav-list">
                        <li>
                            <a href="{{ route('superadmin.settings.index') }}" title="Business Settings"
                                class="{{ request()->routeIs('superadmin.settings.*') ? 'active' : '' }}">
                                <span>Business Settings</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none;">
                @csrf
            </form>

        </ul>
    </div>

    <div id="sidebarOverlay"></div>

    <div class="content" id="content">

        <div class="mobile-menu">
            <button id="menuToggle" class="menu-toggle">
                <i data-lucide="menu"></i>
            </button>
        </div>

        <div class="sa-topbar">
            <details class="sa-profile-menu">
                <summary class="sa-profile-trigger">
                    <span class="sa-profile-avatar">{{ strtoupper(substr(auth()->user()->name ?? 'S', 0, 1)) }}</span>
                    <div class="sa-profile-meta">
                        <strong>{{ auth()->user()->full_name ?? auth()->user()->name }}</strong>
                        <small>Company Owner</small>
                    </div>
                </summary>

                <div class="sa-profile-dropdown">
                    <a href="{{ route('profile.edit') }}">
                        <i data-lucide="settings"></i>
                        <span>Settings</span>
                    </a>
                    <button type="button" class="sa-logout-trigger" onclick="confirmLogout()">
                        <i data-lucide="log-out"></i>
                        <span>Logout</span>
                    </button>
                </div>
            </details>
        </div>

        @yield('content')

        <div class="sa-logout-modal" id="superadminLogoutModal" aria-hidden="true">
            <div class="sa-logout-backdrop" onclick="closeSuperadminLogoutModal()"></div>
            <div class="sa-logout-card" role="dialog" aria-modal="true" aria-labelledby="superadminLogoutTitle">
                <div class="sa-logout-card-head">
                    <button type="button" class="sa-logout-close" onclick="closeSuperadminLogoutModal()"
                        aria-label="Close sign out dialog">×</button>
                </div>

                <h3 id="superadminLogoutTitle">Sign out of the control panel?</h3>
                <p>Your session will close securely, and you can sign back in anytime.</p>

                <div class="sa-logout-actions">
                    <button type="button" class="secondary" onclick="closeSuperadminLogoutModal()">Stay here</button>
                    <button type="button" class="primary" onclick="submitSuperadminLogout()">Log out</button>
                </div>
            </div>
        </div>

    </div>

    <script>
        lucide.createIcons();

        function toggleSidebar() {
            const sidebar = document.getElementById("sidebar");
            const content = document.getElementById("content");

            sidebar.classList.toggle("active");
            content.classList.toggle("shifted");

            sidebar.classList.toggle('collapsed');
            content.classList.toggle('expanded');
        }
        const menuBtn = document.getElementById("menuToggle");
        const sidebar = document.getElementById("sidebar");
        const overlay = document.getElementById("sidebarOverlay");

        if (menuBtn) {

            menuBtn.addEventListener("click", () => {
                sidebar.classList.add("open");
                overlay.classList.add("show");
            });

            overlay.addEventListener("click", () => {
                sidebar.classList.remove("open");
                overlay.classList.remove("show");
            });

        }
    </script>

    <script>
        document.querySelectorAll(".sidebar a").forEach(link => {
            link.addEventListener("click", () => {
                sidebar.classList.remove("open");
                overlay.classList.remove("show");
            });
        });

        menuBtn.addEventListener("click", () => {
            sidebar.classList.add("open");
            overlay.classList.add("show");
            document.body.style.overflow = "hidden";
        });

        overlay.addEventListener("click", () => {
            sidebar.classList.remove("open");
            overlay.classList.remove("show");
            document.body.style.overflow = "";
        });

        function confirmLogout() {
            openSuperadminLogoutModal();
        }

        function openSuperadminLogoutModal() {
            const modal = document.getElementById('superadminLogoutModal');
            if (!modal) {
                return;
            }

            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeSuperadminLogoutModal() {
            const modal = document.getElementById('superadminLogoutModal');
            if (!modal) {
                return;
            }

            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');

            if (!sidebar.classList.contains('open')) {
                document.body.style.overflow = '';
            }
        }

        function submitSuperadminLogout() {
            document.getElementById('logout-form')?.submit();
        }

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                closeSuperadminLogoutModal();
            }
        });

        document.querySelectorAll('.sidebar-group-toggle').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const panel = document.getElementById(toggle.getAttribute('aria-controls'));
                const isOpen = toggle.getAttribute('aria-expanded') === 'true';

                toggle.setAttribute('aria-expanded', String(!isOpen));
                panel?.classList.toggle('is-open', !isOpen);
            });
        });
    </script>

    <script src="{{ asset('superadmin/js/components.js') }}" defer></script>

    @stack('scripts')

</body>

</html>
