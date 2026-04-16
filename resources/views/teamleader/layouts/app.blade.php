<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Team Leader Panel') - Jarz</title>

    @php
        $teamLeaderAppUrl = rtrim(config('app.url') ?: request()->getSchemeAndHttpHost(), '/');
        $teamLeaderAssetBaseUrl = $teamLeaderAppUrl . '/teamleader-assets';
        $teamLeaderDashboardCssPath = public_path('teamleader-assets/css/dashboard.css');
    @endphp

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    {{-- Team Leader base stylesheet --}}
    <link rel="stylesheet" type="text/css"
        href="{{ $teamLeaderAssetBaseUrl }}/css/dashboard.css?v={{ filemtime($teamLeaderDashboardCssPath) }}">

    @if (is_file($teamLeaderDashboardCssPath))
        <style>
            {!! file_get_contents($teamLeaderDashboardCssPath) !!}
        </style>
    @endif

    @stack('styles')
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --jarz-accent: #facc15;
            --jarz-bg: #f8fafc;
            --jarz-surface: #fef9c3;
            --jarz-text: #111827;
            --jarz-line: #e5e7eb;
        }

        body {
            background: linear-gradient(180deg, var(--jarz-bg) 0%, #f3f4f6 100%);
            color: var(--jarz-text);
        }

        .tl-sidebar,
        .tl-section-card,
        .tl-hero-card,
        .tl-user-card,
        .tl-profile-menu {
            border-color: var(--jarz-line) !important;
        }

        .tl-sidebar {
            background: #ffffff;
        }

        .tl-topbar {
            gap: 16px;
            flex-wrap: wrap;
        }

        .tl-topbar__actions {
            justify-content: flex-end;
        }

        .tl-topbar__actions .tl-user-card {
            margin-left: auto;
        }

        .tl-profile-dropdown {
            position: relative;
        }

        .tl-profile-dropdown summary {
            list-style: none;
            cursor: pointer;
        }

        .tl-profile-dropdown summary::-webkit-details-marker {
            display: none;
        }

        .tl-profile-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 10px);
            min-width: 180px;
            padding: 8px;
            border-radius: 14px;
            border: 1px solid var(--jarz-line);
            background: #fff;
            box-shadow: 0 18px 44px rgba(15, 23, 42, .12);
            z-index: 20;
        }

        .tl-profile-menu a,
        .tl-profile-menu button {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 0;
            background: transparent;
            padding: 10px 12px;
            border-radius: 10px;
            color: var(--jarz-text);
            text-decoration: none;
            cursor: pointer;
        }

        .tl-profile-menu a:hover,
        .tl-profile-menu button:hover {
            background: #f8fafc;
        }

        .tl-input-hint {
            display: block;
            margin-top: 8px;
            color: #64748b;
        }

        .tl-logout-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 60;
        }

        .tl-logout-modal.is-open {
            display: flex;
        }

        .tl-logout-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.52);
        }

        .tl-logout-card {
            position: relative;
            max-width: 420px;
            width: calc(100% - 24px);
            background: #fff;
            border-radius: 18px;
            padding: 24px;
            border: 1px solid var(--jarz-line);
            box-shadow: 0 24px 50px rgba(15, 23, 42, .16);
        }

        .tl-logout-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }

        .tl-logout-actions button {
            border: 0;
            border-radius: 10px;
            padding: 10px 14px;
            cursor: pointer;
        }

        .tl-logout-actions .secondary {
            background: #f3f4f6;
            color: var(--jarz-text);
        }

        .tl-logout-actions .primary {
            background: var(--jarz-accent);
            color: var(--jarz-text);
        }
    </style>
</head>

<body>
    @php
        $teamLeaderRootUrl = $teamLeaderAppUrl . '/teamleader';
        $teamLeaderDashboardUrl = $teamLeaderRootUrl . '/dashboard';
        $teamLeaderTasksUrl = $teamLeaderRootUrl . '/tasks';
        $teamLeaderActiveTask = auth()->check()
            ? \App\Models\Booking::query()
                ->where('assigned_team_leader_id', auth()->id())
                ->whereIn('status', ['assigned', 'on_the_way', 'in_progress', 'waiting_verification'])
                ->latest('updated_at')
                ->first()
            : null;
        $teamLeaderFocusLocked = filled($teamLeaderActiveTask);
        $teamLeaderFocusUrl = $teamLeaderFocusLocked
            ? route('teamleader.task.show', $teamLeaderActiveTask)
            : $teamLeaderDashboardUrl;
    @endphp

    <div class="tl-shell">
        <aside class="tl-sidebar">
            <a href="{{ $teamLeaderFocusUrl }}" class="tl-brand">
                <span class="tl-brand__logo">JR</span>
                <div>
                    <strong>Jarz</strong>
                    <small>Team Leader Panel</small>
                </div>
            </a>

            <nav class="tl-nav" aria-label="Team leader navigation">
                @if ($teamLeaderFocusLocked)
                    <a href="{{ $teamLeaderFocusUrl }}" class="tl-nav__link is-active">
                        <i data-lucide="crosshair"></i>
                        <span>Current Job Focus</span>
                    </a>
                @else
                    <a href="{{ $teamLeaderDashboardUrl }}"
                        class="tl-nav__link {{ request()->routeIs('teamleader.dashboard') ? 'is-active' : '' }}">
                        <i data-lucide="layout-dashboard"></i>
                        <span>Dashboard</span>
                    </a>

                    <a href="{{ $teamLeaderTasksUrl }}"
                        class="tl-nav__link {{ request()->routeIs('teamleader.tasks') || request()->routeIs('teamleader.bookings') || request()->routeIs('teamleader.task.*') ? 'is-active' : '' }}">
                        <i data-lucide="clipboard-list"></i>
                        <span>Tasks</span>
                    </a>
                @endif
            </nav>

        </aside>

        <main class="tl-main">
            <header class="tl-topbar">
                <div>
                    <p class="tl-eyebrow">Jarz Field Operations</p>
                    <h1>@yield('page_title', 'Team Leader Dashboard')</h1>
                    @if ($teamLeaderFocusLocked)
                        <small class="tl-input-hint">Focus mode is active. Complete or return the current job to unlock
                            navigation.</small>
                    @endif
                </div>

                <div class="tl-topbar__actions">
                    <details class="tl-profile-dropdown">
                        <summary class="tl-user-card">
                            <span
                                class="tl-user-card__avatar">{{ strtoupper(substr(auth()->user()->name ?? 'TL', 0, 1)) }}</span>
                            <div>
                                <strong>{{ auth()->user()->name ?? 'Team Leader' }}</strong>
                            </div>
                        </summary>

                        <div class="tl-profile-menu">
                            @unless ($teamLeaderFocusLocked)
                                <a href="{{ route('profile.edit') }}">
                                    <i data-lucide="settings"></i>
                                    <span>Settings</span>
                                </a>
                            @endunless
                            <button type="button" onclick="openTeamLeaderLogoutModal()">
                                <i data-lucide="log-out"></i>
                                <span>Logout</span>
                            </button>
                        </div>
                    </details>
                </div>
            </header>

            <section class="tl-page-content">
                @yield('content')
            </section>
        </main>
    </div>

    <div class="tl-logout-modal" id="teamLeaderLogoutModal" aria-hidden="true">
        <div class="tl-logout-backdrop" onclick="closeTeamLeaderLogoutModal()"></div>
        <div class="tl-logout-card" role="dialog" aria-modal="true" aria-labelledby="teamLeaderLogoutTitle">
            <h3 id="teamLeaderLogoutTitle">Sign out of Jarz?</h3>
            <p>Your current field session will close and you can securely sign back in anytime.</p>
            <div class="tl-logout-actions">
                <button type="button" class="secondary" onclick="closeTeamLeaderLogoutModal()">Stay here</button>
                <button type="button" class="primary" onclick="submitTeamLeaderLogout()">Logout</button>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('logout') }}" id="teamLeaderLogoutForm" style="display:none;">
        @csrf
    </form>

    <script>
        window.TeamLeaderConfig = {
            csrfToken: '{{ csrf_token() }}',
            tasksUrl: @json($teamLeaderTasksUrl),
            presencePingUrl: @json(route('teamleader.presence.ping')),
            presenceOfflineUrl: @json(route('teamleader.presence.offline'))
        };

        window.openTeamLeaderLogoutModal = function() {
            document.getElementById('teamLeaderLogoutModal')?.classList.add('is-open');
        };

        window.closeTeamLeaderLogoutModal = function() {
            document.getElementById('teamLeaderLogoutModal')?.classList.remove('is-open');
        };

        window.pingTeamLeaderPresence = function() {
            const pingUrl = window.TeamLeaderConfig?.presencePingUrl;

            if (!pingUrl) {
                return;
            }

            fetch(pingUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': window.TeamLeaderConfig?.csrfToken || '',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                keepalive: true
            }).catch(() => null);
        };

        window.teamLeaderGoOffline = function() {
            const offlineUrl = window.TeamLeaderConfig?.presenceOfflineUrl;

            if (!offlineUrl) {
                return;
            }

            if (navigator.sendBeacon) {
                const formData = new FormData();
                formData.append('_token', window.TeamLeaderConfig?.csrfToken || '');
                navigator.sendBeacon(offlineUrl, formData);
                return;
            }

            fetch(offlineUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': window.TeamLeaderConfig?.csrfToken || '',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                keepalive: true
            }).catch(() => null);
        };

        window.submitTeamLeaderLogout = function() {
            window.teamLeaderGoOffline();
            document.getElementById('teamLeaderLogoutForm')?.submit();
        };

        window.pingTeamLeaderPresence();
        window.setInterval(window.pingTeamLeaderPresence, 45000);
        window.addEventListener('focus', window.pingTeamLeaderPresence);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                window.pingTeamLeaderPresence();
            }
        });

        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    </script>

    @stack('scripts')
</body>

</html>
