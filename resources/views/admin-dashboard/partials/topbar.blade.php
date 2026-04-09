@php
    $dispatcherNotifications = $dispatcherNotifications ?? collect();
    $dispatcherNotificationCount = $dispatcherNotificationCount ?? 0;
@endphp

<div class="topbar">
    <div class="topbar-copy">
        <h2>@yield('title', 'Dispatcher Panel')</h2>
    </div>

    <div class="topbar-actions">
        <span class="topbar-date">{{ now()->format('M d, Y') }}</span>

        <details class="notif-dropdown">
            <summary class="notif-button" aria-label="Open dispatcher notifications">
                <i data-lucide="bell"></i>
                <span id="dispatcherNotifCount" class="notif-count" @if ($dispatcherNotificationCount < 1) hidden @endif>
                    {{ $dispatcherNotificationCount }}
                </span>
            </summary>

            <div class="notif-menu">
                <div class="notif-menu-head">
                    <div>
                        <h4>Team Leader Notifications</h4>
                        <small>Recent bookings sent to the team leader queue</small>
                    </div>
                </div>

                <div class="notif-list" id="dispatcherNotifList">
                    @forelse ($dispatcherNotifications as $notification)
                        <div class="notif-item">
                            <span class="notif-dot"></span>
                            <div>
                                <strong>Booking #{{ $notification->id }} sent to team leader</strong>
                                <p>
                                    {{ $notification->customer->full_name ?? 'Customer' }}
                                    · {{ $notification->truckType->name ?? 'Tow request' }}
                                </p>
                                <small>{{ optional($notification->updated_at)->diffForHumans() ?? 'Just now' }}</small>
                            </div>
                        </div>
                    @empty
                        <div class="notif-empty">No team leader handoff notifications yet.</div>
                    @endforelse
                </div>
            </div>
        </details>
    </div>
</div>
