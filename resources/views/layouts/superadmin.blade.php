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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
                <a href="{{ route('superadmin.users.index') }}"
                    class="{{ request()->routeIs('superadmin.users.*') ? 'active' : '' }}">
                    <i data-lucide="users"></i>
                    <span>Manage Users</span>
                </a>
            </li>

            <li>
                <a href="{{ route('superadmin.unit-truck.index') }}"
                    class="{{ request()->routeIs('superadmin.unit-truck.*') ? 'active' : '' }}">
                    <i data-lucide="truck"></i>
                    <span>Units</span>
                </a>
            </li>

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
                    <div>
                        <strong>{{ auth()->user()->full_name ?? auth()->user()->name }}</strong>
                    </div>
                </summary>

                <div class="sa-profile-dropdown">
                    <a href="{{ route('profile.edit') }}">
                        <i data-lucide="settings"></i>
                        <span>Settings</span>
                    </a>
                    <button type="button" onclick="confirmLogout()">
                        <i data-lucide="log-out"></i>
                        <span>Logout</span>
                    </button>
                </div>
            </details>
        </div>

        @yield('content')

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

            Swal.fire({
                title: 'Logout from Jarz?',
                text: 'Your session will be securely closed.',
                icon: 'question',

                showCancelButton: true,

                confirmButtonText: 'Logout',
                cancelButtonText: 'Stay Logged In',

                background: '#ffffff',
                backdrop: 'rgba(15,23,42,0.6)',

                customClass: {
                    popup: 'towmate-logout-popup',
                    confirmButton: 'towmate-btn-logout',
                    cancelButton: 'towmate-btn-cancel'
                },

                buttonsStyling: false

            }).then((result) => {

                if (result.isConfirmed) {
                    document.getElementById('logout-form').submit();
                }

            });

        }
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
