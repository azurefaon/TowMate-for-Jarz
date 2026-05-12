<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') - Jarz Operations</title>
    <link rel="icon" href="{{ asset('admin/images/logo.png') }}">

    <link rel="stylesheet" href="{{ asset('dispatcher/css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('dispatcher/css/sidebar.css') }}">
    <link rel="stylesheet" href="{{ asset('dispatcher/css/dispatch.css') }}">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    @stack('styles')
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --jarz-accent: #FACC15;
            --jarz-bg: #ffffff;
            --jarz-surface: #ffffff;
            --jarz-text: #111111;
            --jarz-line: #e5e7eb;
        }

        body {
            background: #ffffff;
            color: var(--jarz-text);
        }

        .sidebar,
        .topbar,
        .chart-card,
        .actions-card,
        .activity-card,
        .stat-card,
        .units-table-card,
        .jobs-stat-card,
        .job-card {
            border-color: var(--jarz-line) !important;
        }

        .topbar {
            position: sticky;
            top: 12px;
            z-index: 1200;
            overflow: visible;
            background: #ffffff;
            backdrop-filter: none;
            border-bottom: 0;
        }

        @media (max-width: 768px) {
            .sidebar-open .topbar {
                z-index: 1;
            }
        }

        .topbar-copy h2 {
            color: var(--jarz-text);
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            z-index: 1250;
        }

        .topbar-date {
            color: var(--jarz-text);
            opacity: 0.78;
        }

        .main-content,
        .page-content {
            overflow: visible;
        }

        .notif-dropdown,
        .profile-dropdown {
            position: relative;
            z-index: 1300;
        }

        .profile-dropdown summary {
            list-style: none;
        }

        .profile-dropdown summary::-webkit-details-marker {
            display: none;
        }

        .profile-trigger {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 12px;
            background: #fff;
            cursor: pointer;
        }

        .profile-avatar {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            border: 1px solid #000;
            border-radius: 5px;
            justify-content: center;
            background: var(--jarz-surface);
            color: var(--jarz-text);
        }

        .profile-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
        }

        .profile-meta small {
            color: rgba(48, 56, 65, 0.68);
        }

        .profile-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 10px);
            min-width: 180px;
            padding: 8px;
            border: 1px solid #000;
            background: #fff;
            z-index: 20;
        }

        .profile-menu a,
        .profile-menu button {
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

        .profile-menu a:hover,
        .profile-menu button:hover {
            background: var(--jarz-surface);
        }
    </style>
</head>

<body>

    <div class="dispatcher-wrapper">

        @include('admin-dashboard.partials.sidebar')

        <div class="main-content">

            @include('admin-dashboard.partials.topbar')

            <div class="page-content">
                @yield('content')
            </div>

        </div>

    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="logout-modal" id="logoutModal" aria-hidden="true">
        <div class="logout-backdrop" onclick="closeLogoutModal()"></div>
        <div class="logout-box" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
            <div class="logout-icon-wrap">
                <span class="logout-icon-badge">
                    {{-- <i data-lucide="log-out"></i> --}}
                </span>
                <button type="button" class="logout-close" onclick="closeLogoutModal()"
                    aria-label="Close logout dialog">×</button>
            </div>

            <h3 id="logoutTitle">Sign out of Jarz operations?</h3>
            <p>Your current session will end and you will return to the secure login page.</p>

            <div class="logout-actions">
                <button type="button" class="cancel-btn" onclick="closeLogoutModal()">Stay here</button>
                <button type="button" class="confirm-btn" onclick="submitLogout()">Logout now</button>
            </div>
        </div>
    </div>

    <script>
        window.PusherConfig = {
            key: '{{ config("reverb.apps.apps.0.key") }}',
            wsHost: '{{ config("reverb.apps.apps.0.options.host", "localhost") }}',
            wsPort: {{ (int) config("reverb.apps.apps.0.options.port", 8080) }},
            wssPort: {{ (int) config("reverb.apps.apps.0.options.port", 8080) }},
            forceTLS: {{ config("reverb.apps.apps.0.options.scheme", "https") === "https" ? "true" : "false" }},
        };

        window.dispatcherNotifications = {
            add(payload = {}) {
                const list = document.getElementById('dispatcherNotifList');
                const countNode = document.getElementById('dispatcherNotifCount');

                if (!list) {
                    return;
                }

                list.querySelector('.notif-empty')?.remove();

                const item = document.createElement('div');
                item.className = 'notif-item';
                item.innerHTML = `
                    <span class="notif-dot"></span>
                    <div>
                        <strong>${payload.title || 'Team leader update'}</strong>
                        <p>${payload.body || 'A booking has been handed off to the team leader queue.'}</p>
                        <small>${payload.time || 'Just now'}</small>
                    </div>
                `;

                list.prepend(item);

                if (countNode) {
                    const nextCount = (parseInt(countNode.textContent || '0', 10) || 0) + 1;
                    countNode.textContent = nextCount;
                    countNode.hidden = false;
                }
            }
        };

        window.openLogoutModal = function() {
            const modal = document.getElementById('logoutModal');
            if (!modal) {
                return;
            }

            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        };

        window.closeLogoutModal = function() {
            const modal = document.getElementById('logoutModal');
            if (!modal) {
                return;
            }

            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
        };

        window.submitLogout = function() {
            document.getElementById('logoutForm')?.submit();
        };

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                window.closeLogoutModal();
            }
        });

        if (typeof lucide !== "undefined") {
            lucide.createIcons();
        }

        // ── Mobile sidebar toggle ──
        (function() {
            const hamburgerBtn = document.getElementById('hamburgerBtn');
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');

            function openSidebar() {
                sidebar.classList.add('show');
                overlay.classList.add('show');
                document.body.classList.add('sidebar-open');
                document.body.style.overflow = 'hidden';
            }

            function closeSidebar() {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
                document.body.classList.remove('sidebar-open');
                document.body.style.overflow = '';
            }

            if (hamburgerBtn) {
                hamburgerBtn.addEventListener('click', function() {
                    sidebar.classList.contains('show') ? closeSidebar() : openSidebar();
                });
            }

            if (overlay) {
                overlay.addEventListener('click', closeSidebar);
            }
        })();
    </script>

    @stack('scripts')

</body>

</html>
