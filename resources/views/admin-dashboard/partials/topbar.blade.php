@php
    $dispatcherNotifications = $dispatcherNotifications ?? collect();
    $dispatcherUnreadCount = $dispatcherUnreadCount ?? 0;
    $dispatcherUser = auth()->user();
    $dispatcherName = $dispatcherUser->full_name ?? ($dispatcherUser->name ?? 'Dispatcher');
    $dispatcherRoleName = $dispatcherUser->role->name ?? 'Dispatcher';
@endphp

<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger-btn" id="hamburgerBtn" aria-label="Toggle navigation">
            <span></span>
            <span></span>
            <span></span>
        </button>
    </div>

    <div class="topbar-actions">
        <details class="notif-dropdown">
            <summary class="notif-button" aria-label="Notifications" aria-haspopup="true">
                <i data-lucide="bell"></i>
                @if ($dispatcherUnreadCount > 0)
                    <span id="dispatcherNotifCount" class="notif-count">{{ $dispatcherUnreadCount }}</span>
                @endif
            </summary>

            <div class="notif-menu">
                <div class="notif-menu-head">
                    <h4>Notifications</h4>
                    <button type="button" class="notif-mark-all" id="notifMarkAllRead">Mark all as read</button>
                </div>

                <div class="notif-list" id="dispatcherNotifList">
                    @forelse ($dispatcherNotifications as $notification)
                        @include('admin-dashboard.partials.notification-item', ['notification' => $notification])
                    @empty
                        <div class="notif-empty">No notifications yet.</div>
                    @endforelse
                </div>

                <a href="{{ route('admin.notifications.index') }}" class="notif-view-all">View all notifications</a>
            </div>
        </details>

        <details class="profile-dropdown">
            <summary class="profile-trigger" aria-label="Open profile menu" aria-haspopup="true">
                <span class="profile-avatar">
                    <i data-lucide="user"></i>
                </span>
                <span class="profile-meta">
                    <strong>{{ $dispatcherName }}</strong>
                </span>
                <i data-lucide="chevron-down" class="profile-chevron"></i>
            </summary>

            <div class="profile-menu">
                <div class="profile-menu-head">
                    <span class="profile-avatar">
                        <i data-lucide="user"></i>
                    </span>
                    <span class="profile-menu-meta">
                        <strong>{{ $dispatcherName }}</strong>
                        <small>{{ $dispatcherRoleName }}</small>
                    </span>
                </div>

                <hr class="profile-menu-divider">

                <a href="{{ route('profile.edit') }}">
                    <i data-lucide="settings"></i>
                    <span>Settings</span>
                </a>
                <button type="button" onclick="openLogoutModal()">
                    <i data-lucide="log-out"></i>
                    <span>Log out</span>
                </button>
            </div>
        </details>
    </div>
</div>
