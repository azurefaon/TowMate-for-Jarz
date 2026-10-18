<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'JARZ Owner')</title>
    <link rel="icon" type="image/png" href="{{ asset('dispatcher/images/jarz-logo.png') }}">
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

        .content {
            border-color: var(--jarz-line) !important;
        }

        .content {
            color: var(--jarz-text);
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
            padding: 5px 8px;
            background: transparent;
            border: none;
            border-radius: 8px;
        }

        .sa-profile-avatar {
            width: 34px;
            height: 34px;
            flex-shrink: 0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            color: #6b7280;
        }

        .sa-profile-avatar i {
            width: 18px;
            height: 18px;
        }

        .sa-profile-chevron {
            width: 14px;
            height: 14px;
            color: #9ca3af;
        }

        .sa-profile-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 10px);
            min-width: 220px;
            padding: 8px;
            background: #ffffff;
            border: 1px solid var(--jarz-line);
            border-radius: 12px;
            box-shadow: 0 12px 28px rgba(17, 24, 39, 0.12);
            z-index: 40;
        }

        .sa-profile-menu-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 8px 10px;
        }

        .sa-profile-menu-divider {
            border: none;
            border-top: 1px solid var(--jarz-line);
            margin: 4px 0 6px;
        }

        .sa-profile-dropdown a,
        .sa-profile-dropdown button {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--jarz-text);
            text-decoration: none;
            cursor: pointer;
            font-size: 0.86rem;
        }

        .sa-profile-dropdown a i,
        .sa-profile-dropdown button i {
            width: 16px;
            height: 16px;
            color: #6b7280;
        }

        .sa-profile-dropdown a:hover,
        .sa-profile-dropdown button:hover {
            background: #f3f4f6;
        }

        .sa-profile-meta strong,
        .sa-profile-menu-meta strong {
            display: block;
            font-size: 0.88rem;
            font-weight: 600;
            line-height: 1.2;
        }

        .sa-profile-menu-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
            min-width: 0;
        }

        .sa-profile-menu-meta small {
            display: block;
            margin-top: 2px;
            color: var(--jarz-muted);
            font-size: 0.76rem;
        }

        .sa-profile-dropdown .sa-logout-trigger {
            color: var(--jarz-danger);
        }

        .sa-profile-dropdown .sa-logout-trigger i {
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
    <script src="https://unpkg.com/lucide@1.59.0/dist/umd/lucide.min.js" integrity="sha384-4VQAGBhahgTiMVvXUqbh6qcs45zGzR/8znv7XORRfWkxZfzRbhLL5Uh+fSdvdqdM" crossorigin="anonymous"></script>
</head>

<body class="superadmin-shell">

    @php
        $sidebarGroups = [
            'Fleet' => [
                'label' => 'Fleet Management',
                'icon' => 'truck',
                'items' => [
                    ['label' => 'Truck Types & Rates', 'route' => 'superadmin.truck-types.index', 'active' => ['superadmin.truck-types.*', 'superadmin.truck-type-config.*']],
                    ['label' => 'Trucks', 'route' => 'superadmin.unit-truck.index', 'active' => ['superadmin.unit-truck.*', 'superadmin.units.*']],
                    ['label' => 'Vehicle Types', 'route' => 'superadmin.vehicle-types.index', 'active' => ['superadmin.vehicle-types.*', 'superadmin.vehicle-categories.*']],
                    ['label' => 'Personnel', 'route' => 'superadmin.personnel.index', 'active' => ['superadmin.personnel.*', 'superadmin.personnel-records.*']],
                    ['label' => 'Home Assignments', 'route' => 'superadmin.home-assignments.index', 'active' => ['superadmin.home-assignments.*']],
                ],
            ],
            'Reports' => [
                'label' => 'Reports & Revenue',
                'icon' => 'chart-column',
                'items' => [
                    ['label' => 'Revenue', 'route' => 'superadmin.revenue.index', 'active' => ['superadmin.revenue.*']],
                    ['label' => 'Reports', 'route' => 'superadmin.reports.index', 'active' => ['superadmin.reports.index', 'superadmin.reports.bookings', 'superadmin.reports.export*']],
                    ['label' => 'Activity Report', 'route' => 'superadmin.reports.activity', 'active' => ['superadmin.reports.activity*']],
                ],
            ],
        ];

        foreach ($sidebarGroups as $key => $group) {
            foreach ($group['items'] as $i => $item) {
                $sidebarGroups[$key]['items'][$i]['isActive'] = request()->routeIs(...$item['active']);
            }
            $sidebarGroups[$key]['isActive'] = collect($sidebarGroups[$key]['items'])->contains('isActive', true);
        }

        $sidebarLinks = [
            'dashboard' => request()->routeIs('superadmin.dashboard'),
            'bookings' => request()->routeIs('superadmin.bookings.*'),
            'monitoring' => request()->routeIs('superadmin.monitoring.*'),
            'settings' => request()->routeIs('superadmin.settings.*'),
        ];
    @endphp

    <aside class="sidebar" id="sidebar" aria-label="Owner navigation">

        <div class="sidebar-header">
            <img src="{{ asset('dispatcher/images/jarz-logo.png') }}" alt="JARZ Towing Services" class="sidebar-logo">

            <button type="button" class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()"
                aria-controls="sidebarNav" aria-expanded="true" aria-label="Collapse sidebar">
                <i data-lucide="panel-left-close" class="sidebar-toggle-close" aria-hidden="true"></i>
                <i data-lucide="panel-left-open" class="sidebar-toggle-open" aria-hidden="true"></i>
                <span class="sidebar-tooltip" id="sidebarToggleTooltip" aria-hidden="true">Collapse sidebar</span>
            </button>
        </div>

        <nav class="sidebar-nav" id="sidebarNav">
            <ul class="sidebar-menu">
                <li>
                    <a href="{{ route('superadmin.dashboard') }}"
                        class="sidebar-link {{ $sidebarLinks['dashboard'] ? 'active' : '' }}"
                        @if ($sidebarLinks['dashboard']) aria-current="page" @endif>
                        <span class="sidebar-icon"><i data-lucide="layout-dashboard" aria-hidden="true"></i></span>
                        <span class="sidebar-label">Dashboard</span>
                    </a>
                </li>
            </ul>

            <div class="sidebar-section">
                <h2 class="sidebar-section-title" id="sidebarSectionOperations">Operations</h2>
                <ul class="sidebar-menu" aria-labelledby="sidebarSectionOperations">
                    <li>
                        <a href="{{ route('superadmin.bookings.index') }}"
                            class="sidebar-link {{ $sidebarLinks['bookings'] ? 'active' : '' }}"
                            @if ($sidebarLinks['bookings']) aria-current="page" @endif>
                            <span class="sidebar-icon"><i data-lucide="clipboard-list" aria-hidden="true"></i></span>
                            <span class="sidebar-label">Bookings</span>
                            @if (isset($pendingBookings) && $pendingBookings > 0)
                                <span class="sidebar-count">{{ $pendingBookings }}<span class="sidebar-sr"> pending</span></span>
                            @endif
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('superadmin.monitoring.index') }}"
                            class="sidebar-link {{ $sidebarLinks['monitoring'] ? 'active' : '' }}"
                            @if ($sidebarLinks['monitoring']) aria-current="page" @endif>
                            <span class="sidebar-icon"><i data-lucide="activity" aria-hidden="true"></i></span>
                            <span class="sidebar-label">Operations Monitor</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="sidebar-section">
                <h2 class="sidebar-section-title" id="sidebarSectionManagement">Management</h2>
                <ul class="sidebar-menu" aria-labelledby="sidebarSectionManagement">
                    @foreach ($sidebarGroups as $key => $group)
                        <li class="sidebar-group {{ $group['isActive'] ? 'has-active' : '' }}">
                            <button type="button" class="sidebar-link sidebar-group-toggle"
                                id="sidebarGroup{{ $key }}Toggle"
                                aria-expanded="{{ $group['isActive'] ? 'true' : 'false' }}"
                                aria-controls="sidebarGroup{{ $key }}">
                                <span class="sidebar-icon"><i data-lucide="{{ $group['icon'] }}" aria-hidden="true"></i></span>
                                <span class="sidebar-label">{{ $group['label'] }}</span>
                                <span class="sidebar-chevron"><i data-lucide="chevron-down" aria-hidden="true"></i></span>
                            </button>

                            <div class="sidebar-subnav {{ $group['isActive'] ? 'is-open' : '' }}" id="sidebarGroup{{ $key }}">
                                <p class="sidebar-flyout-title" aria-hidden="true">{{ $group['label'] }}</p>
                                <ul class="sidebar-subnav-list" aria-labelledby="sidebarGroup{{ $key }}Toggle">
                                    @foreach ($group['items'] as $item)
                                        <li>
                                            <a href="{{ route($item['route']) }}"
                                                class="sidebar-sublink {{ $item['isActive'] ? 'active' : '' }}"
                                                @if ($item['isActive']) aria-current="page"@endif>{{ $item['label'] }}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </nav>

        <div class="sidebar-footer">
            <ul class="sidebar-menu">
                <li>
                    <a href="{{ route('superadmin.settings.index') }}"
                        class="sidebar-link {{ $sidebarLinks['settings'] ? 'active' : '' }}"
                        @if ($sidebarLinks['settings']) aria-current="page" @endif>
                        <span class="sidebar-icon"><i data-lucide="sliders-horizontal" aria-hidden="true"></i></span>
                        <span class="sidebar-label">Business Settings</span>
                    </a>
                </li>
            </ul>
        </div>

        <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none;">
            @csrf
        </form>

    </aside>

    <div id="sidebarOverlay"></div>

    <div class="content" id="content">

        <script>
            // Restore the desktop collapsed rail before first paint.
            (function () {
                try {
                    if (localStorage.getItem('jarzOwnerSidebarCollapsed') === '1') {
                        document.body.classList.add('sidebar-no-transition');
                        document.getElementById('sidebar').classList.add('collapsed', 'active');
                        document.getElementById('content').classList.add('expanded', 'shifted');
                    }
                } catch (e) {}
            })();
        </script>

        <div class="mobile-menu">
            <button id="menuToggle" class="menu-toggle">
                <i data-lucide="menu"></i>
            </button>
        </div>

        <div class="sa-topbar">
            <details class="sa-profile-menu">
                <summary class="sa-profile-trigger" aria-label="Open profile menu" aria-haspopup="true">
                    <span class="sa-profile-avatar">
                        <i data-lucide="user"></i>
                    </span>
                    <span class="sa-profile-meta">
                        <strong>{{ auth()->user()->full_name ?? auth()->user()->name }}</strong>
                    </span>
                    <i data-lucide="chevron-down" class="sa-profile-chevron"></i>
                </summary>

                <div class="sa-profile-dropdown">
                    <div class="sa-profile-menu-head">
                        <span class="sa-profile-avatar">
                            <i data-lucide="user"></i>
                        </span>
                        <span class="sa-profile-menu-meta">
                            <strong>{{ auth()->user()->full_name ?? auth()->user()->name }}</strong>
                            <small>Company Owner</small>
                        </span>
                    </div>

                    <hr class="sa-profile-menu-divider">

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

            try {
                localStorage.setItem('jarzOwnerSidebarCollapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
            } catch (e) {}

            syncSidebarCollapsedState();
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

        // Collapsed rail (desktop only): groups open as flyout menus instead of inline lists.
        const sidebarDesktopQuery = window.matchMedia('(min-width: 1025px)');
        const sidebarToggleBtn = document.getElementById('sidebarToggle');
        const sidebarGroupToggles = document.querySelectorAll('.sidebar-group-toggle');
        let openSidebarFlyout = null;

        function isSidebarRail() {
            return sidebar.classList.contains('collapsed') && sidebarDesktopQuery.matches;
        }

        function sidebarFlyoutLinks(panel) {
            return [...panel.querySelectorAll('.sidebar-sublink')];
        }

        function positionSidebarFlyout(toggle, panel) {
            const toggleRect = toggle.getBoundingClientRect();
            const sidebarRect = sidebar.getBoundingClientRect();
            const maxTop = window.innerHeight - panel.offsetHeight - 8;

            panel.style.left = (sidebarRect.right + 8) + 'px';
            panel.style.top = Math.max(8, Math.min(toggleRect.top, maxTop)) + 'px';
        }

        function closeSidebarFlyout(returnFocus) {
            if (!openSidebarFlyout) {
                return;
            }

            const { toggle, panel } = openSidebarFlyout;
            panel.classList.remove('is-flyout');
            panel.style.left = '';
            panel.style.top = '';
            toggle.setAttribute('aria-expanded', 'false');
            openSidebarFlyout = null;

            if (returnFocus) {
                toggle.focus();
            }
        }

        function openSidebarGroupFlyout(toggle, focusItem) {
            closeSidebarFlyout(false);

            const panel = document.getElementById(toggle.getAttribute('aria-controls'));
            if (!panel) {
                return;
            }

            panel.classList.add('is-flyout');
            toggle.setAttribute('aria-expanded', 'true');
            positionSidebarFlyout(toggle, panel);
            openSidebarFlyout = { toggle, panel };

            const links = sidebarFlyoutLinks(panel);
            if (focusItem === 'first') {
                links[0]?.focus();
            } else if (focusItem === 'last') {
                links[links.length - 1]?.focus();
            }
        }

        // Keeps aria state, the toggle label and rail tooltips in step with the current mode.
        function syncSidebarCollapsedState() {
            const collapsed = sidebar.classList.contains('collapsed');
            const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';

            closeSidebarFlyout(false);

            sidebarToggleBtn.setAttribute('aria-expanded', String(!collapsed));
            sidebarToggleBtn.setAttribute('aria-label', label);
            document.getElementById('sidebarToggleTooltip').textContent = label;

            sidebarGroupToggles.forEach((toggle) => {
                const panel = document.getElementById(toggle.getAttribute('aria-controls'));
                const inlineOpen = panel?.classList.contains('is-open') ?? false;
                toggle.setAttribute('aria-expanded', String(isSidebarRail() ? false : inlineOpen));
            });

            sidebar.querySelectorAll('.sidebar-link:not(.sidebar-group-toggle)').forEach((link) => {
                const text = link.querySelector('.sidebar-label')?.textContent.trim();
                if (isSidebarRail() && text) {
                    link.setAttribute('title', text);
                } else {
                    link.removeAttribute('title');
                }
            });
        }

        sidebarGroupToggles.forEach((toggle) => {
            const panel = document.getElementById(toggle.getAttribute('aria-controls'));

            toggle.addEventListener('click', (event) => {
                if (isSidebarRail()) {
                    if (openSidebarFlyout?.toggle === toggle) {
                        closeSidebarFlyout(false);
                    } else {
                        // event.detail is 0 for Enter/Space, so keyboard users land on the first item.
                        openSidebarGroupFlyout(toggle, event.detail === 0 ? 'first' : null);
                    }
                    return;
                }

                const isOpen = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', String(!isOpen));
                panel?.classList.toggle('is-open', !isOpen);
            });

            toggle.addEventListener('keydown', (event) => {
                if (!isSidebarRail()) {
                    return;
                }

                if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
                    event.preventDefault();
                    openSidebarGroupFlyout(toggle, 'first');
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    openSidebarGroupFlyout(toggle, 'last');
                } else if (event.key === 'Escape' && openSidebarFlyout?.toggle === toggle) {
                    closeSidebarFlyout(false);
                }
            });

            panel?.addEventListener('keydown', (event) => {
                if (!openSidebarFlyout || openSidebarFlyout.panel !== panel) {
                    return;
                }

                const links = sidebarFlyoutLinks(panel);
                const index = links.indexOf(document.activeElement);
                let next = null;

                if (event.key === 'ArrowDown') {
                    next = links[(index + 1) % links.length];
                } else if (event.key === 'ArrowUp') {
                    next = links[(index - 1 + links.length) % links.length];
                } else if (event.key === 'Home') {
                    next = links[0];
                } else if (event.key === 'End') {
                    next = links[links.length - 1];
                } else if (event.key === 'Escape' || event.key === 'ArrowLeft') {
                    event.preventDefault();
                    event.stopPropagation();
                    closeSidebarFlyout(true);
                    return;
                }

                if (next) {
                    event.preventDefault();
                    next.focus();
                }
            });

            // Tabbing out of the toggle/flyout pair closes it.
            [toggle, panel].forEach((el) => el?.addEventListener('focusout', (event) => {
                if (!openSidebarFlyout || openSidebarFlyout.toggle !== toggle) {
                    return;
                }
                const next = event.relatedTarget;
                if (next && (toggle.contains(next) || panel.contains(next))) {
                    return;
                }
                if (next) {
                    closeSidebarFlyout(false);
                }
            }));
        });

        document.addEventListener('pointerdown', (event) => {
            if (!openSidebarFlyout) {
                return;
            }
            const { toggle, panel } = openSidebarFlyout;
            if (!toggle.contains(event.target) && !panel.contains(event.target)) {
                closeSidebarFlyout(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && openSidebarFlyout) {
                closeSidebarFlyout(true);
            }
        });

        window.addEventListener('resize', () => closeSidebarFlyout(false));
        document.getElementById('sidebarNav').addEventListener('scroll', () => closeSidebarFlyout(false));
        sidebarDesktopQuery.addEventListener('change', syncSidebarCollapsedState);

        // Pressed (yellow) state for Enter/Space, matching the mouse-down state.
        sidebarToggleBtn.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                sidebarToggleBtn.classList.add('is-pressed');
            }
        });
        ['keyup', 'blur'].forEach((type) => sidebarToggleBtn.addEventListener(type, () => {
            sidebarToggleBtn.classList.remove('is-pressed');
        }));

        syncSidebarCollapsedState();
        requestAnimationFrame(() => requestAnimationFrame(() => {
            document.body.classList.remove('sidebar-no-transition');
        }));
    </script>

    <script src="{{ asset('superadmin/js/components.js') }}" defer></script>

    @include('partials.idle-timeout')

    @stack('scripts')

</body>

</html>
