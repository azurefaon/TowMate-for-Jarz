<div class="table-scroll">
    <table class="modern-table">
        <thead>
            <tr>
                <th>User</th>
                <th>Role</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Updated</th>
                <th>Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse($users as $user)
                <tr>
                    @php
                        $presence = null;
                        if ((int) $user->role_id === 3) {
                            // Team Leader: blue while on a live job, otherwise green/gray by presence ping.
                            $presence = ($busyTeamLeaderIds ?? collect())->contains((int) $user->id)
                                ? 'busy'
                                : (app(\App\Services\TeamLeaderAvailabilityService::class)->isOnline($user) ? 'online' : 'offline');
                        } elseif ((int) $user->role_id === 2) {
                            // Dispatcher: no job to be "busy" with, just online/offline.
                            $presence = \Illuminate\Support\Facades\Cache::has('dispatcher:presence:' . $user->id) ? 'online' : 'offline';
                        }

                        // Used further down to disable Inactive/Archive/Delete while this
                        // dispatcher is actively online, same rule as before the presence-dot change.
                        $dispatcherOnline = (int) $user->role_id === 2 && $presence === 'online';
                    @endphp
                    <td data-label="User">
                        <div class="user-info">
                            <div class="avatar">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
                                    <circle cx="12" cy="8.5" r="3.2"/>
                                    <path d="M5.5 19.5 L6.8 14.5 L17.2 14.5 L18.5 19.5"/>
                                </svg>
                                @if ($presence)
                                    <span class="presence-dot presence-{{ $presence }}" title="{{ ucfirst($presence) }}"></span>
                                @endif
                            </div>

                            <div class="user-text">
                                <span class="user-name">{{ $user->name }}</span>
                                <small>{{ $user->email }}</small>
                                @if ($user->password_request_status === 'pending')
                                    <span class="request-pill">Password request pending</span>
                                @endif
                            </div>
                        </div>
                    </td>

                    <td data-label="Role">
                        @include('superadmin.users.partials.role-badge', ['user' => $user])
                    </td>

                    <td data-label="Status">
                        <span class="status-badge {{ $user->status }}">{{ ucfirst($user->status) }}</span>
                        @if ($user->id === auth()->id())
                            <small class="self-tag">You</small>
                        @endif
                    </td>

                    <td data-label="Joined">{{ $user->created_at->format('M d, Y') }}</td>
                    <td data-label="Updated">{{ $user->updated_at->diffForHumans() }}</td>

                    <td data-label="Actions">
                        @if (($user->role->name ?? null) === 'Customer')
                            <div class="action-group">
                                @if ($user->status === 'locked')
                                    {{-- Unlock --}}
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
                                    {{-- Active / Inactive toggle --}}
                                    <form method="POST"
                                        action="{{ route('superadmin.users.toggle', $user->id) }}"
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

                                {{-- Archive --}}
                                <form method="POST"
                                    action="{{ route('superadmin.users.archive', $user) }}"
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

                                {{-- Schedule Deletion: independent of Archive, moves straight to Pending Deletion --}}
                                <form method="POST"
                                    action="{{ route('superadmin.users.queue-for-deletion', $user->id) }}"
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
                            <div class="action-group">
                                <a href="{{ route('superadmin.users.edit', $user->id) }}"
                                    class="action-btn edit-btn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M14.2 5.3 L18.7 9.8 L8.5 20 L4.3 20.2 L4.5 16 Z"/><line x1="12.6" y1="6.9" x2="17.1" y2="11.4"/></svg>
                                    Edit
                                </a>

                                @if ($user->id !== auth()->id())
                                    {{-- Active / Inactive toggle --}}
                                    <form method="POST"
                                        action="{{ route('superadmin.users.toggle', $user->id) }}"
                                        style="display:inline;">
                                        @csrf
                                        @method('PATCH')
                                        @if ($user->status === 'active')
                                            <button type="submit" class="action-btn deactivate-btn"
                                                {{ $dispatcherOnline ? 'disabled' : '' }}
                                                title="{{ $dispatcherOnline ? 'Dispatcher is online' : 'Set user inactive' }}">
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

                                    {{-- Archive / Remove --}}
                                    <form method="POST"
                                        action="{{ route('superadmin.users.archive', $user) }}"
                                        class="js-confirm-action" data-confirm-title="Move user to archive?"
                                        data-confirm-message="<strong>{{ $user->name }}</strong> will be moved to the archive panel."
                                        data-confirm-button="Move to Archive" data-require-reason="true" style="display:inline;">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="action-btn archive-btn"
                                            {{ $dispatcherOnline ? 'disabled' : '' }}
                                            title="{{ $dispatcherOnline ? 'Dispatcher is online' : 'Move user to archive' }}">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M4 6.5 L20 6.5 L20 9 L4 9 Z"/><path d="M5 9 L5 18.5 L19 18.5 L19 9"/><line x1="10" y1="12.5" x2="14" y2="12.5"/></svg>
                                            Archive
                                        </button>
                                    </form>

                                    {{-- Schedule Deletion: independent of Archive, moves straight to Pending Deletion --}}
                                    <form method="POST"
                                        action="{{ route('superadmin.users.queue-for-deletion', $user->id) }}"
                                        class="js-confirm-action" data-confirm-title="Delete this user?"
                                        data-confirm-message="<strong>{{ $user->name }}</strong> will be moved to Pending Deletion and permanently removed after the retention period, unless the deletion is cancelled before then."
                                        data-confirm-button="Delete" data-confirm-variant="danger" data-require-reason="true" style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="action-btn delete-btn"
                                            {{ $dispatcherOnline ? 'disabled' : '' }}
                                            title="{{ $dispatcherOnline ? 'Dispatcher is online' : 'Move user to Pending Deletion' }}">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M5 7.5 L19 7.5"/><path d="M9.5 7.5 L9.5 5 L14.5 5 L14.5 7.5"/><path d="M7 7.5 L7.8 19 L16.2 19 L17 7.5"/><line x1="10.3" y1="10.8" x2="10.3" y2="15.8"/><line x1="13.7" y1="10.8" x2="13.7" y2="15.8"/></svg>
                                            Delete
                                        </button>
                                    </form>

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

<div class="table-footer">
    <span class="table-count">
        Showing {{ $users->count() }} of {{ $users->total() }} users
    </span>
    {{ $users->appends(request()->query())->links('vendor.pagination.users') }}
</div>
