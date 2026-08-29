<div class="table-scroll">
    <table class="modern-table">
        <thead>
            <tr>
                <th>User</th>
                <th>Role</th>
                <th>Status</th>
                <th>Auto-removes</th>
                <th>Reason</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($deletedUsers as $user)
                <tr>
                    <td data-label="User">
                        <div class="user-info">
                            <div class="avatar">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                    stroke-linejoin="round">
                                    <circle cx="12" cy="8.5" r="3.2" />
                                    <path d="M5.5 19.5 L6.8 14.5 L17.2 14.5 L18.5 19.5" />
                                </svg>
                            </div>
                            <div class="user-text">
                                <span class="user-name">{{ $user->name }}</span>
                                <small>{{ $user->email }}</small>
                            </div>
                        </div>
                    </td>
                    <td data-label="Role">
                        @include('superadmin.users.partials.role-badge', ['user' => $user])
                    </td>
                    <td data-label="Status">
                        <span class="status-badge pending">Pending Deletion</span>
                    </td>
                    <td data-label="Auto-removes">
                        @php
                            $purgeAt = $user->pending_delete_at->copy()->addDays($retentionDays);
                            $daysLeft = max(0, (int) floor(now()->diffInHours($purgeAt, false) / 24));
                        @endphp
                        <div class="countdown-cell">
                            <span class="countdown">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="4.5" y="5.5" width="15" height="14" rx="1.8" />
                                    <line x1="4.5" y1="9.5" x2="19.5" y2="9.5" />
                                    <line x1="8" y1="4" x2="8" y2="7" />
                                    <line x1="16" y1="4" x2="16" y2="7" />
                                </svg>
                                {{ $daysLeft > 0 ? $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left' : 'Less than 1 day left' }}
                            </span>
                            <small class="countdown-date">{{ $purgeAt->format('M d, Y') }}</small>
                        </div>
                    </td>
                    <td data-label="Reason">
                        {{ $user->pending_delete_reason ?? '—' }}
                    </td>
                    <td data-label="Actions">
                        <div class="action-group">
                            <form method="POST" action="{{ route('superadmin.users.restore-from-deleted', $user->id) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="action-btn cancel-btn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8 A7.5 7.5 0 1 1 5.5 16"/><path d="M5 4 L5 8.5 L9.5 8.5"/></svg>
                                    Cancel Deletion
                                </button>
                            </form>

                            <form method="POST" action="{{ route('superadmin.users.purge-now', $user->id) }}"
                                class="js-confirm-delete"
                                data-confirm-title="Delete this user permanently right now?"
                                data-confirm-message="This skips the remaining wait time and cannot be undone."
                                data-confirm-button="Delete Permanently">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="action-btn delete-btn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7.5 L19 7.5"/><path d="M9.5 7.5 L9.5 5 L14.5 5 L14.5 7.5"/><path d="M7 7.5 L7.8 19 L16.2 19 L17 7.5"/><line x1="10.3" y1="10.8" x2="10.3" y2="15.8"/><line x1="13.7" y1="10.8" x2="13.7" y2="15.8"/></svg>
                                    Delete Permanently
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty-state small-empty">
                            <h3>No users pending deletion</h3>
                            <p>Users queued for removal will appear here until they're permanently removed or
                                automatically purged.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="table-footer">
    <span class="table-count">{{ $deletedUsers->total() }} users pending deletion</span>
    {{ $deletedUsers->appends(request()->query())->links('vendor.pagination.users') }}
</div>
