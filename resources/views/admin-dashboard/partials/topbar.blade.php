@php
    $dispatcherNotifications = $dispatcherNotifications ?? collect();
    $dispatcherNotificationCount = $dispatcherNotificationCount ?? 0;
    $dispatcherUser = auth()->user();
    $dispatcherName = $dispatcherUser->full_name ?? ($dispatcherUser->name ?? 'Dispatcher');
@endphp

<div class="topbar">
    <div class="topbar-copy">
        <h2>@yield('title', 'Dispatcher Panel')</h2>
    </div>

    <div class="topbar-actions">
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
                        <h4>Update Notifications</h4>
                    </div>
                </div>

                <div class="notif-list" id="dispatcherNotifList">
                    @forelse ($dispatcherNotifications as $notification)
                        @php
                            $teamLeaderName =
                                optional($notification->assignedTeamLeader)->name ??
                                (optional(optional($notification->unit)->teamLeader)->name ?? 'a team leader');

                            [$headline, $detail] = match ($notification->status) {
                                'reviewed' => [
                                    "Booking {$notification->job_code} has a negotiation request",
                                    'The customer asked dispatch to review a counter-offer or adjustment.',
                                ],
                                'quoted' => [
                                    "Booking {$notification->job_code} quotation sent",
                                    'Waiting for the customer to accept the price or request changes.',
                                ],
                                'confirmed' => [
                                    "Booking {$notification->job_code} quotation approved",
                                    'Customer approval is complete and the booking is ready for dispatch.',
                                ],
                                'accepted' => [
                                    "Booking {$notification->job_code} sent to team leader queue",
                                    'Waiting for a team leader to take this job.',
                                ],
                                'assigned' => [
                                    "Booking {$notification->job_code} taken by {$teamLeaderName}",
                                    'The dispatcher handoff has been accepted.',
                                ],
                                'on_the_way' => [
                                    "Booking {$notification->job_code} is now on the way",
                                    "{$teamLeaderName} is heading to the pickup location.",
                                ],
                                'in_progress', 'on_job' => [
                                    "Booking {$notification->job_code} towing is active",
                                    'The towing operation is currently in progress.',
                                ],
                                'waiting_verification' => [
                                    "Booking {$notification->job_code} is awaiting customer confirmation",
                                    'Waiting for the customer to verify job completion.',
                                ],
                                default => [
                                    "Booking {$notification->job_code} updated",
                                    'The job status was updated by the field team.',
                                ],
                            };
                        @endphp
                        <div class="notif-item">
                            <span class="notif-dot"></span>
                            <div>
                                <strong>{{ $headline }}</strong>
                                <p>
                                    {{ $notification->customer->full_name ?? 'Customer' }}
                                    · {{ $notification->truckType->name ?? 'Tow request' }}
                                    · {{ $detail }}
                                </p>
                                <small>{{ optional($notification->updated_at)->diffForHumans() ?? 'Just now' }}</small>
                            </div>
                        </div>
                    @empty
                        <div class="notif-empty">No dispatch updates yet.</div>
                    @endforelse
                </div>
            </div>
        </details>

        <details class="profile-dropdown">
            <summary class="profile-trigger" aria-label="Open profile menu">
                <span class="profile-avatar">{{ strtoupper(substr($dispatcherName, 0, 1)) }}</span>
                <span class="profile-meta">
                    <strong>{{ $dispatcherName }}</strong>
                </span>
            </summary>

            <div class="profile-menu">
                <a href="{{ route('profile.edit') }}">
                    <i data-lucide="settings"></i>
                    <span>Settings</span>
                </a>
                <button type="button" onclick="openLogoutModal()">
                    <i data-lucide="log-out"></i>
                    <span>Logout</span>
                </button>
            </div>
        </details>
    </div>
</div>
