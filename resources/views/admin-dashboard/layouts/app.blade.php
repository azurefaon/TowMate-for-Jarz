<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title')</title>

    <link rel="stylesheet" href="{{ asset('dispatcher/css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('dispatcher/css/sidebar.css') }}">
    <link rel="stylesheet" href="{{ asset('dispatcher/css/dispatch.css') }}">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    @stack('styles')
    <script src="https://unpkg.com/lucide@latest"></script>
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

    <div class="logout-modal" id="logoutModal" aria-hidden="true">
        <div class="logout-backdrop" onclick="closeLogoutModal()"></div>
        <div class="logout-box" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
            <div class="logout-icon-wrap">
                <span class="logout-icon-badge">
                    <i data-lucide="shield-power"></i>
                </span>
                <button type="button" class="logout-close" onclick="closeLogoutModal()"
                    aria-label="Close logout dialog">×</button>
            </div>

            <h3 id="logoutTitle">Sign out of dispatcher panel?</h3>
            <p>Your current session will end and you will return to the login page.</p>

            <div class="logout-actions">
                <button type="button" class="cancel-btn" onclick="closeLogoutModal()">Stay here</button>
                <button type="button" class="confirm-btn" onclick="submitLogout()">Logout now</button>
            </div>
        </div>
    </div>

    <script>
        window.PusherConfig = {
            key: '{{ config('broadcasting.connections.pusher.key', 'local') }}',
            cluster: '{{ config('broadcasting.connections.pusher.options.cluster', 'mt1') }}'
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
    </script>

    @stack('scripts')

</body>

</html>
