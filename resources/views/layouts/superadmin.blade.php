<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>TowMate</title>
    <link rel="stylesheet" href="{{ asset('admin/css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('admin/css/users.css') }}">
    <link rel="stylesheet" href="{{ asset('admin/css/truck-types.css') }}">
    <link rel="stylesheet" href="{{ asset('admin/css/unit-truck.css') }}">
    <link rel="stylesheet" href="{{ asset('admin/css/bookings.css') }}">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" type="image/png" href="{{ asset('admin/images/logo.png') }}">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body>

    <div class="sidebar" id="sidebar">

        <ul>

            <div class="brand">
                <img src="{{ asset('admin/images/logo.png') }}" alt="TowMate Logo">
                <span class="brand-text">TowMate</span>
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
                <a href="{{ route('superadmin.bookings.index') }}">
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

            <div class="sidebar-profile">
                <div class="profile-info">
                    <img src="{{ asset('admin/images/logo.png') }}">
                    <div class="profile-text">
                        <strong>{{ auth()->user()->full_name }}</strong>
                        <small>Super Admin</small>
                    </div>
                </div>
            </div>

            <li>
                <button onclick="confirmLogout()">
                    <i data-lucide="log-out"></i>
                    <span>Logout</span>
                </button>
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
                title: 'Logout from TowMate?',
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
