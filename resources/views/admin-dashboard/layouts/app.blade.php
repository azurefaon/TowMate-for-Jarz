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
            top: 0;
            z-index: 1200;
            overflow: visible;
            background: #ffffff;
            backdrop-filter: none;
            border-bottom: 2px solid #111827;
        }

        @media (max-width: 768px) {
            .sidebar-open .topbar {
                z-index: 1;
            }
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            z-index: 1250;
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
            padding: 5px 8px;
            background: transparent;
            border-radius: 8px;
            cursor: pointer;
        }

        .profile-avatar {
            width: 34px;
            height: 34px;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #f3f4f6;
            color: #6b7280;
        }

        .profile-avatar i {
            width: 18px;
            height: 18px;
        }

        .profile-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
        }

        .profile-meta strong {
            font-size: 0.88rem;
            font-weight: 600;
        }

        .profile-chevron {
            width: 14px;
            height: 14px;
            color: #9ca3af;
        }

        .profile-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 10px);
            min-width: 220px;
            padding: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 12px 28px rgba(17, 24, 39, 0.12);
            z-index: 20;
        }

        .profile-menu-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 8px 10px;
        }

        .profile-menu-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
            min-width: 0;
        }

        .profile-menu-meta strong {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--jarz-text);
        }

        .profile-menu-meta small {
            font-size: 0.76rem;
            color: #6b7280;
        }

        .profile-menu-divider {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 4px 0 6px;
        }

        .profile-menu a,
        .profile-menu button {
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

        .profile-menu a i,
        .profile-menu button i {
            width: 16px;
            height: 16px;
            color: #6b7280;
        }

        .profile-menu a:hover,
        .profile-menu button:hover {
            background: #f3f4f6;
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

                if (!list) {
                    return;
                }

                list.querySelector('.notif-empty')?.remove();

                const item = document.createElement('a');
                item.href = payload.url || '#';
                item.className = 'notif-item';
                item.dataset.unread = 'true';
                item.innerHTML = `
                    <span class="notif-state" aria-hidden="true">✕</span>
                    <span class="sr-only">Unread.</span>
                    <span class="notif-body">
                        <span class="notif-top">
                            <strong>${payload.title || 'Booking update'}</strong>
                            <span class="notif-time">${payload.time || 'Just now'}</span>
                        </span>
                        <span class="notif-line">${payload.body || ''}</span>
                    </span>
                `;

                list.prepend(item);

                this.refreshBadge(1);
            },

            refreshBadge(delta) {
                const bellButton = document.querySelector('.notif-button');
                let countNode = document.getElementById('dispatcherNotifCount');

                const current = countNode ? (parseInt(countNode.textContent || '0', 10) || 0) : 0;
                const next = Math.max(0, current + delta);

                if (next === 0) {
                    countNode?.remove();
                    return;
                }

                if (!countNode && bellButton) {
                    countNode = document.createElement('span');
                    countNode.id = 'dispatcherNotifCount';
                    countNode.className = 'notif-count';
                    bellButton.appendChild(countNode);
                }

                if (countNode) {
                    countNode.textContent = next;
                }
            }
        };

        (function() {
            const markAllBtn = document.getElementById('notifMarkAllRead');

            if (!markAllBtn) {
                return;
            }

            markAllBtn.addEventListener('click', function() {
                fetch('{{ route('admin.notifications.mark-all-read') }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            Accept: 'application/json',
                        },
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function() {
                        document.querySelectorAll('#dispatcherNotifList .notif-item[data-unread="true"]').forEach(function(item) {
                            item.dataset.unread = 'false';
                            const state = item.querySelector('.notif-state');
                            if (state) {
                                state.textContent = '✓';
                            }
                            const label = item.querySelector('.sr-only');
                            if (label) {
                                label.textContent = 'Read.';
                            }
                        });

                        document.getElementById('dispatcherNotifCount')?.remove();
                    })
                    .catch(function() {});
            });
        })();

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
