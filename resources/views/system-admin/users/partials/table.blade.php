<div class="table-scroll">
    <table class="modern-table">
        <thead>
            <tr>
                <th>User</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created At</th>
                <th>Last Updated</th>
                <th class="u-actions-col">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse($users as $user)
                <tr>
                    @php
                        $presence = null;
                        if ((int) $user->role_id === 3) {
                            $presence = ($busyTeamLeaderIds ?? collect())->contains((int) $user->id)
                                ? 'busy'
                                : (app(\App\Services\TeamLeaderAvailabilityService::class)->isOnline($user) ? 'online' : 'offline');
                        } elseif ((int) $user->role_id === 2) {
                            $presence = \Illuminate\Support\Facades\Cache::has('dispatcher:presence:' . $user->id) ? 'online' : 'offline';
                        }

                        $dispatcherOnline = (int) $user->role_id === 2 && $presence === 'online';
                        $roleLabel = ($user->role->name ?? null) === 'Admin' ? 'Dispatcher' : ($user->role->name ?? '—');
                    @endphp
                    <td data-label="User">
                        <div class="user-info">
                            <div class="ua-avatar">
                                @if ($user->profile_image)
                                    <img src="{{ Storage::url($user->profile_image) }}" alt="">
                                @else
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
                                        <circle cx="12" cy="8.5" r="3.2"/>
                                        <path d="M5.5 19.5 L6.8 14.5 L17.2 14.5 L18.5 19.5"/>
                                    </svg>
                                @endif
                                @if ($presence)
                                    <span class="presence-dot presence-{{ $presence }}" title="{{ ucfirst($presence) }}"></span>
                                @endif
                            </div>

                            <div class="user-text">
                                <span class="user-name">
                                    {{ $user->name }}
                                    @if ($user->id === auth()->id())
                                        <span class="ua-self-tag">(you)</span>
                                    @endif
                                </span>
                                <small>{{ $user->email }}</small>
                            </div>
                        </div>
                    </td>

                    <td data-label="Role">
                        <span class="ua-role-text">{{ $roleLabel }}</span>
                    </td>

                    <td data-label="Status">
                        <span class="ua-status-text ua-status-{{ $user->status }}">{{ ucfirst($user->status) }}</span>
                    </td>

                    <td data-label="Created At">{{ $user->created_at->format('M d, Y') }}</td>
                    <td data-label="Last Updated">{{ $user->updated_at->diffForHumans() }}</td>

                    <td data-label="Actions" class="u-actions-col">
                        @if (($user->role->name ?? null) === 'Customer')
                            <div class="action-group">
                                @if ($user->status === 'locked')
                                    <form method="POST"
                                        action="{{ route('superadmin.users.unlock', $user->id) }}"
                                        style="display:inline;">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="action-btn activate-btn"
                                            title="Unlock this account">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M7 11 L7 8.2 A5 4.6 0 0 1 16.5 7"/><rect x="5.5" y="11" width="13" height="9" rx="1.6"/><circle cx="12" cy="15.2" r="1.4"/></svg>
                                            Unlock
                                        </button>
                                    </form>
                                @else
                                    <form method="POST"
                                        action="{{ route('system-admin.users.toggle', $user->id) }}"
                                        style="display:inline;">
                                        @csrf
                                        @method('PATCH')
                                        @if ($user->status === 'active')
                                            <button type="submit" class="action-btn deactivate-btn"
                                                title="Set user inactive">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><circle cx="12" cy="12" r="7.2"/><line x1="12" y1="12" x2="12" y2="7.3"/></svg>
                                                Inactive
                                            </button>
                                        @else
                                            <button type="submit" class="action-btn activate-btn"
                                                title="Set user active">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M6 12.5 L10 16.5 L18 7.5"/></svg>
                                                Active
                                            </button>
                                        @endif
                                    </form>
                                @endif

                                <form method="POST"
                                    action="{{ route('system-admin.users.archive', $user) }}"
                                    class="js-confirm-action" data-confirm-title="Move user to archive?"
                                    data-confirm-message="<strong>{{ $user->name }}</strong> will be moved to the archive panel."
                                    data-confirm-button="Move to Archive" data-require-reason="true" style="display:inline;">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="action-btn archive-btn"
                                        title="Move user to archive">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M4 6.5 L20 6.5 L20 9 L4 9 Z"/><path d="M5 9 L5 18.5 L19 18.5 L19 9"/><line x1="10" y1="12.5" x2="14" y2="12.5"/></svg>
                                        Archive
                                    </button>
                                </form>

                                <form method="POST"
                                    action="{{ route('system-admin.users.queue-for-deletion', $user->id) }}"
                                    class="js-confirm-action" data-confirm-title="Delete this user?"
                                    data-confirm-message="<strong>{{ $user->name }}</strong> will be moved to Pending Deletion and permanently removed after the retention period, unless the deletion is cancelled before then."
                                    data-confirm-button="Delete" data-confirm-variant="danger" data-require-reason="true" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="action-btn delete-btn"
                                        title="Move user to Pending Deletion">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M5 7.5 L19 7.5"/><path d="M9.5 7.5 L9.5 5 L14.5 5 L14.5 7.5"/><path d="M7 7.5 L7.8 19 L16.2 19 L17 7.5"/><line x1="10.3" y1="10.8" x2="10.3" y2="15.8"/><line x1="13.7" y1="10.8" x2="13.7" y2="15.8"/></svg>
                                        Delete
                                    </button>
                                </form>
                            </div>
                        @else
                            <div class="ua-actions">
                                <a href="{{ route('system-admin.users.edit', $user->id) }}"
                                    class="ua-edit-btn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M14.2 5.3 L18.7 9.8 L8.5 20 L4.3 20.2 L4.5 16 Z"/><line x1="12.6" y1="6.9" x2="17.1" y2="11.4"/></svg>
                                    Edit
                                </a>

                                @if ($user->id !== auth()->id())
                                    <div class="u-menu">
                                        <button type="button" class="u-menu-trigger" aria-haspopup="menu"
                                            aria-expanded="false" aria-label="More actions for {{ $user->name }}">
                                            <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                                        </button>

                                        <div class="u-menu-dropdown" role="menu">
                                            <form method="POST"
                                                action="{{ route('system-admin.users.toggle', $user->id) }}"
                                                class="u-menu-form">
                                                @csrf
                                                @method('PATCH')
                                                @if ($user->status === 'active')
                                                    <button type="submit" class="u-menu-item" role="menuitem"
                                                        {{ $dispatcherOnline ? 'disabled' : '' }}
                                                        title="{{ $dispatcherOnline ? 'Dispatcher is online' : '' }}">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><circle cx="12" cy="12" r="7.2"/><line x1="12" y1="12" x2="12" y2="7.3"/></svg>
                                                        <span>Deactivate</span>
                                                    </button>
                                                @else
                                                    <button type="submit" class="u-menu-item u-menu-item--positive" role="menuitem">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M6 12.5 L10 16.5 L18 7.5"/></svg>
                                                        <span>Activate</span>
                                                    </button>
                                                @endif
                                            </form>

                                            <form method="POST"
                                                action="{{ route('system-admin.users.archive', $user) }}"
                                                class="u-menu-form js-confirm-action" data-confirm-title="Move user to archive?"
                                                data-confirm-message="<strong>{{ $user->name }}</strong> will be moved to the archive panel."
                                                data-confirm-button="Move to Archive" data-require-reason="true">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="u-menu-item" role="menuitem"
                                                    {{ $dispatcherOnline ? 'disabled' : '' }}
                                                    title="{{ $dispatcherOnline ? 'Dispatcher is online' : '' }}">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M4 6.5 L20 6.5 L20 9 L4 9 Z"/><path d="M5 9 L5 18.5 L19 18.5 L19 9"/><line x1="10" y1="12.5" x2="14" y2="12.5"/></svg>
                                                    <span>Archive</span>
                                                </button>
                                            </form>

                                            <div class="u-menu-divider"></div>

                                            <form method="POST"
                                                action="{{ route('system-admin.users.queue-for-deletion', $user->id) }}"
                                                class="u-menu-form js-confirm-action" data-confirm-title="Delete this user?"
                                                data-confirm-message="<strong>{{ $user->name }}</strong> will be moved to Pending Deletion and permanently removed after the retention period, unless the deletion is cancelled before then."
                                                data-confirm-button="Delete" data-confirm-variant="danger" data-require-reason="true">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="u-menu-item u-menu-item--danger" role="menuitem"
                                                    {{ $dispatcherOnline ? 'disabled' : '' }}
                                                    title="{{ $dispatcherOnline ? 'Dispatcher is online' : '' }}">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M5 7.5 L19 7.5"/><path d="M9.5 7.5 L9.5 5 L14.5 5 L14.5 7.5"/><path d="M7 7.5 L7.8 19 L16.2 19 L17 7.5"/><line x1="10.3" y1="10.8" x2="10.3" y2="15.8"/><line x1="13.7" y1="10.8" x2="13.7" y2="15.8"/></svg>
                                                    <span>Delete</span>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <h3>No users found</h3>
                            <p>Try adjusting the search filters or add a new team member.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="ua-table-footer">
    {{ $users->appends(request()->query())->links('vendor.pagination.owner-standard') }}
</div>
