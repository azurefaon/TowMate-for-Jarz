<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Jarz Super Admin')</title>
    <link rel="icon" type="image/png" href="{{ asset('admin/images/logo.png') }}">
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/panel.css') }}">
    <style>
        :root {
            --jarz-accent: #facc15;
            --jarz-bg: #f8fafc;
            --jarz-surface: #fef9c3;
            --jarz-text: #111827;
            --jarz-line: #e5e7eb;
        }

        body.superadmin-shell {
            background: linear-gradient(180deg, var(--jarz-bg) 0%, #f3f4f6 100%);
            color: var(--jarz-text);
        }

        .sidebar,
        .content,
        .sa-profile-trigger,
        .sa-profile-dropdown {
            border-color: var(--jarz-line) !important;
        }

        .sidebar {
            background: #fff;
        }

        .sidebar .brand-text,
        .sidebar .sidebar-section,
        .sidebar li a,
        .sidebar li button,
        .content {
            color: var(--jarz-text);
        }

        .sidebar li a.active,
        .sidebar li a:hover,
        .sidebar li button:hover {
            background: var(--jarz-surface);
        }

        .badge {
            background: var(--jarz-accent);
            color: var(--jarz-text);
        }

        .sa-topbar {
            display: flex;
            justify-content: flex-end;
            margin: 0 0 16px;
            position: sticky;
            top: 0;
            z-index: 140;
            padding: 0 0 8px;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.98) 0%, rgba(248, 250, 252, 0.92) 100%);
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
            padding: 8px 12px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid var(--jarz-line);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
        }

        .sa-profile-avatar {
            width: 36px;
            height: 36px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--jarz-accent);
            color: var(--jarz-text);
            font-weight: 700;
        }

        .sa-profile-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            min-width: 180px;
            padding: 8px;
            border-radius: 14px;
            background: #fff;
            border: 1px solid var(--jarz-line);
            box-shadow: 0 18px 40px rgba(48, 56, 65, .12);
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
            border-radius: 10px;
            background: transparent;
            color: var(--jarz-text);
            text-decoration: none;
            cursor: pointer;
        }

        .sa-profile-dropdown a:hover,
        .sa-profile-dropdown button:hover {
            background: #f8fafc;
        }

        .sa-profile-meta strong {
            display: block;
            font-size: 0.95rem;
            line-height: 1.2;
        }

        .sa-profile-meta small {
            display: block;
            margin-top: 2px;
            color: #64748b;
            font-size: 0.76rem;
        }

        .sa-profile-dropdown .sa-logout-trigger {
            color: #b91c1c;
        }

        .sa-profile-dropdown .sa-logout-trigger:hover {
            background: #fef2f2;
            color: #991b1b;
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
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
        }

        .sa-logout-card {
            position: relative;
            width: min(420px, calc(100% - 24px));
            padding: 22px;
            border-radius: 20px;
            background: #fff;
            border: 1px solid var(--jarz-line);
            box-shadow: 0 30px 70px rgba(15, 23, 42, 0.22);
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
            border-radius: 10px;
            background: #f8fafc;
            color: #64748b;
            cursor: pointer;
        }

        .sa-logout-close:hover {
            background: #eef2f7;
            color: #0f172a;
        }

        .sa-logout-card h3 {
            margin: 0 0 8px;
            font-size: 1.2rem;
            color: #0f172a;
        }

        .sa-logout-card p {
            margin: 0;
            color: #64748b;
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
            border-radius: 12px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .sa-logout-actions .secondary {
            background: #f8fafc;
            color: #0f172a;
            border: 1px solid #e2e8f0;
        }

        .sa-logout-actions .primary {
            background: #111827;
            color: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18);
        }

        .sa-logout-actions .primary:hover {
            background: #1f2937;
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

    <div class="sidebar" id="sidebar">

        <ul>

            <div class="brand">
                <img src="{{ asset('admin/images/logo.png') }}" alt="Jarz Logo">
                <span class="brand-text">Jarz</span>
            </div>

            <li class="collapse-item">
                <button onclick="toggleSidebar()">
                    <i data-lucide="chevrons-left"></i>
                    <span>Toggle Menu</span>
                </button>
            </li>

            <!-- MAIN SECTION -->
            <div class="sidebar-section">MAIN</div>

            <li>
                <a href="{{ route('superadmin.dashboard') }}"
                    class="{{ request()->routeIs('superadmin.dashboard') ? 'active' : '' }}">
                    <i data-lucide="layout-dashboard"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li>
                <a href="{{ route('control-center.index') }}"
                    class="{{ request()->routeIs('control-center.*') ? 'active' : '' }}">
                    <i data-lucide="radar"></i>
                    <span>Control Center</span>
                </a>
            </li>

            <li>
                <a href="{{ route('superadmin.monitoring.index') }}"
                    class="{{ request()->routeIs('superadmin.monitoring.*') ? 'active' : '' }}">
                    <i data-lucide="activity"></i>
                    <span>Monitoring</span>
                </a>
            </li>

            <!-- OPERATIONS SECTION -->
            <div class="sidebar-divider"></div>
            <div class="sidebar-section">OPERATIONS</div>

            <li>
                <a href="{{ route('superadmin.bookings.index') }}"
                    class="{{ request()->routeIs('superadmin.bookings.*') ? 'active' : '' }}">
                    <i data-lucide="clipboard-list"></i>
                    <span>Bookings</span>

                    @if (isset($pendingBookings) && $pendingBookings > 0)
                        <span class="badge">{{ $pendingBookings }}</span>
                    @endif
                </a>
            </li>

            <!-- FLEET MANAGEMENT SECTION -->
            <div class="sidebar-divider"></div>
            <div class="sidebar-section">FLEET MANAGEMENT</div>

            <li>
                <a href="{{ route('superadmin.unit-truck.index') }}"
                    class="{{ request()->routeIs('superadmin.unit-truck.*') ? 'active' : '' }}">
                    <i data-lucide="truck"></i>
                    <span>Units Overview</span>
                </a>
            </li>

            <li>
                <a href="{{ route('superadmin.truck-types.index') }}"
                    class="{{ request()->routeIs('superadmin.truck-types.*') ? 'active' : '' }}">
                    <i data-lucide="package"></i>
                    <span>Truck Types</span>
                </a>
            </li>

            <li>
                <a href="{{ route('superadmin.vehicle-types.index') }}"
                    class="{{ request()->routeIs('superadmin.vehicle-types.*') ? 'active' : '' }}">
                    <i data-lucide="car"></i>
                    <span>Vehicle Types</span>
                </a>
            </li>

            <!-- USER MANAGEMENT SECTION -->
            <div class="sidebar-divider"></div>
            <div class="sidebar-section">USER MANAGEMENT</div>

            <li>
                <a href="{{ route('superadmin.users.index') }}"
                    class="{{ request()->routeIs('superadmin.users.*') ? 'active' : '' }}">
                    <i data-lucide="users"></i>
                    <span>Manage Users</span>
                </a>
            </li>

            <!-- SYSTEM SECTION -->
            <div class="sidebar-divider"></div>
            <div class="sidebar-section">SYSTEM</div>

            <li>
                <a href="{{ route('superadmin.audit.logs') }}"
                    class="{{ request()->routeIs('superadmin.audit.logs') ? 'active' : '' }}">
                    <i data-lucide="shield-check"></i>
                    <span>Audit Logs</span>
                </a>
            </li>

            <li>
                <a href="{{ route('superadmin.settings.index') }}"
                    class="{{ request()->routeIs('superadmin.settings.*') ? 'active' : '' }}">
                    <i data-lucide="settings"></i>
                    <span>System Settings</span>
                </a>
            </li>

            <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none;">
                @csrf
            </form>

        </ul>
    </div>

    <div id="sidebarOverlay"></div>

    <div class="content" id="content">

        {{-- SUCCESS TOAST --}}
        @if (session('success'))
            <div id="successPopup" class="success-popup">
                <div class="success-box">
                    <div class="checkmark-circle">
                        <div class="background"></div>
                        <div class="checkmark draw"></div>
                    </div>

                    <h3>Success</h3>
                    <p>{{ session('success') }}</p>
                </div>
            </div>
        @endif

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
                        <small>Super Admin</small>
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
    </script>

    @stack('scripts')

    <script>
        setTimeout(() => {
            const popup = document.getElementById('successPopup');
            if (popup) {
                popup.style.opacity = "0";
                popup.style.transition = "0.3s";
                setTimeout(() => popup.remove(), 300);
            }
        }, 2500);
    </script>

</body>

</html>
