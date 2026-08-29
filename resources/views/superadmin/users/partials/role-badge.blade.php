{{-- Expects $user (needs $user->role->name) --}}
@php
    $roleName = $user->role->name ?? 'N/A';
    $roleSlug = match ($roleName) {
        'Admin' => 'dispatcher',
        'Customer' => 'customer',
        'Team Leader' => 'team-leader',
        'Driver' => 'driver',
        default => 'default',
    };
    $roleLabel = $roleName === 'Admin' ? 'Dispatcher' : $roleName;
@endphp
<span class="role-badge role-{{ $roleSlug }}">
    @include('superadmin.users.partials.role-icon', ['roleSlug' => $roleSlug])
    {{ $roleLabel }}
</span>
