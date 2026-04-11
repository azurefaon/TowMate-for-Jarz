@php
    $customerName = auth()->user()->name ?? 'Customer';
@endphp

<div class="topbar">
    <div class="topbar-left">
        <h3>@yield('title', 'Dashboard')</h3>
    </div>

    <div class="topbar-right">
        <div class="top-icon notification">
            <i data-lucide="bell"></i>
            <span class="notif-badge">3</span>
        </div>

        <details class="customer-profile-menu">
            <summary class="customer-profile-card">
                <span class="customer-profile-avatar">{{ strtoupper(substr($customerName, 0, 1)) }}</span>
                <div>
                    <strong>{{ $customerName }}</strong>
                </div>
            </summary>

            <div class="customer-profile-dropdown">
                <a href="{{ route('profile.edit') }}">
                    <i data-lucide="settings"></i>
                    <span>Settings</span>
                </a>
                <button type="button" onclick="openCustomerLogoutModal()">
                    <i data-lucide="log-out"></i>
                    <span>Logout</span>
                </button>
            </div>
        </details>
    </div>
</div>
