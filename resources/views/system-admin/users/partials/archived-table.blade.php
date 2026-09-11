<div class="table-scroll">
    <table class="modern-table">
        <thead>
            <tr>
                <th>User</th>
                <th>Role</th>
                <th>Status</th>
                <th>Archived</th>
                <th>Reason</th>
                <th class="u-actions-col">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($archivedUsers as $user)
                @php
                    $roleLabel = ($user->role->name ?? null) === 'Admin' ? 'Dispatcher' : ($user->role->name ?? '—');
                @endphp
                <tr>
                    <td data-label="User">
                        <div class="user-info">
                            <div class="ua-avatar">
                                @if ($user->profile_image)
                                    <img src="{{ Storage::url($user->profile_image) }}" alt="">
                                @else
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                        stroke-linejoin="round">
                                        <circle cx="12" cy="8.5" r="3.2" />
                                        <path d="M5.5 19.5 L6.8 14.5 L17.2 14.5 L18.5 19.5" />
                                    </svg>
                                @endif
                            </div>
                            <div class="user-text">
                                <span class="user-name">{{ $user->name }}</span>
                                <small>{{ $user->email }}</small>
                            </div>
                        </div>
                    </td>
                    <td data-label="Role">
                        <span class="ua-role-text">{{ $roleLabel }}</span>
                    </td>
                    <td data-label="Status">
                        <span class="ua-status-text ua-status-archived">Archived</span>
                    </td>
                    <td data-label="Archived">
                        {{ optional($user->archived_at)->format('M d, Y h:i A') ?? '—' }}
                    </td>
                    <td data-label="Reason">
                        {{ $user->archived_reason ?? '—' }}
                    </td>
                    <td data-label="Actions" class="u-actions-col">
                        <div class="u-menu">
                            <button type="button" class="u-menu-trigger" aria-haspopup="menu"
                                aria-expanded="false" aria-label="More actions for {{ $user->name }}">
                                <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                            </button>

                            <div class="u-menu-dropdown" role="menu">
                                <form method="POST" action="{{ route('system-admin.users.restore', $user->id) }}" class="u-menu-form">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="u-menu-item u-menu-item--positive" role="menuitem">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M5 8 A7.5 7.5 0 1 1 5.5 16"/><path d="M5 4 L5 8.5 L9.5 8.5"/></svg>
                                        <span>Restore</span>
                                    </button>
                                </form>

                                <div class="u-menu-divider"></div>

                                <form method="POST"
                                    action="{{ route('system-admin.users.queue-for-deletion', $user->id) }}"
                                    class="u-menu-form js-confirm-action" data-confirm-title="Delete this user?"
                                    data-confirm-message="<strong>{{ $user->name }}</strong> will be moved to Pending Deletion and permanently removed after the retention period, unless the deletion is cancelled before then."
                                    data-confirm-button="Delete" data-confirm-variant="danger"
                                    data-require-reason="true">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="u-menu-item u-menu-item--danger" role="menuitem">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><path d="M5 7.5 L19 7.5"/><path d="M9.5 7.5 L9.5 5 L14.5 5 L14.5 7.5"/><path d="M7 7.5 L7.8 19 L16.2 19 L17 7.5"/><line x1="10.3" y1="10.8" x2="10.3" y2="15.8"/><line x1="13.7" y1="10.8" x2="13.7" y2="15.8"/></svg>
                                        <span>Delete</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty-state small-empty">
                            <h3>No archived users</h3>
                            <p>Hidden accounts will appear here for easy restore.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="ua-table-footer">
    {{ $archivedUsers->appends(request()->query())->links('vendor.pagination.owner-standard') }}
</div>
