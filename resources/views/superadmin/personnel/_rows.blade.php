@forelse ($personnel as $row)
    <tr>
        <td data-label="Personnel">
            <span class="cell-main">{{ $row['full_name'] }}</span>
            <span class="cell-sub">{{ $row['role_label'] }}</span>
        </td>

        <td data-label="Unit Assignment">
            <span class="cell-main">Regular: {{ $row['home_unit']->name ?? 'Not Assigned' }}</span>
            @if ($row['current_unit'] && (! $row['home_unit'] || $row['current_unit']->id !== $row['home_unit']->id))
                <span class="cell-sub pp-unit-diff">Current: {{ $row['current_unit']->name }}</span>
            @endif
        </td>

        <td data-label="Assignment Status">
            <span class="status-text status-{{ strtolower($row['assignment_status']) }}">{{ $row['assignment_status'] }}</span>
        </td>

        <td data-label="Action" class="u-actions-col">
            <button type="button" class="pp-manage-btn js-pp-manage"
                data-id="{{ $row['id'] }}"
                data-type="{{ $row['type'] }}"
                data-editable="{{ $row['editable'] ? '1' : '0' }}"
                data-first-name="{{ $row['model']->first_name }}"
                data-middle-name="{{ $row['model']->middle_name }}"
                data-last-name="{{ $row['model']->last_name }}"
                data-full-name="{{ $row['full_name'] }}"
                data-role-value="{{ $row['type'] === 'personnel' ? $row['model']->role : '' }}"
                data-role-label="{{ $row['role_label'] }}"
                data-reference="{{ $row['reference'] }}"
                data-status="{{ $row['status'] }}"
                data-home-unit-id="{{ $row['home_unit']->id ?? '' }}"
                data-current-unit-name="{{ $row['current_unit']->name ?? '' }}"
                data-assignment-status="{{ $row['assignment_status'] }}"
                data-update-url="{{ $row['type'] === 'personnel' ? route('superadmin.personnel-records.update', $row['id']) : '' }}"
                data-home-unit-url="{{ $row['type'] === 'personnel' ? route('superadmin.personnel-records.home-unit', $row['id']) : route('superadmin.personnel.home-unit', $row['id']) }}"
                data-toggle-url="{{ $row['type'] === 'personnel' ? route('superadmin.personnel-records.toggle', $row['id']) : route('superadmin.personnel.toggle', $row['id']) }}">
                Manage
            </button>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="4">
            <div class="empty-row">
                <span class="empty-row-title">No personnel found</span>
                <span class="empty-row-hint">Try adjusting your search or role filter, or add a new Driver or Crew Member.</span>
            </div>
        </td>
    </tr>
@endforelse
