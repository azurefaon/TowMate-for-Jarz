<style>
.fleet-tabs {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 20px;
    border-bottom: 1px solid #e5e5e5;
}
.fleet-tabs a {
    padding: 11px 18px;
    font-size: 0.95rem;
    font-weight: 500;
    color: #525252;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: color 0.12s, border-color 0.12s;
}
.fleet-tabs a:hover { color: #171717; }
.fleet-tabs a.is-active {
    color: #171717;
    border-bottom-color: #171717;
    font-weight: 500;
}
</style>

<div class="fleet-tabs">
    <a href="{{ route('superadmin.truck-types.index') }}"
        class="{{ request()->routeIs('superadmin.truck-types.*') ? 'is-active' : '' }}">
        Truck Types &amp; Rates
    </a>
    <a href="{{ route('superadmin.unit-truck.index') }}"
        class="{{ request()->routeIs('superadmin.unit-truck.*') ? 'is-active' : '' }}">
        Trucks
    </a>
    <a href="{{ route('superadmin.vehicle-types.index') }}"
        class="{{ request()->routeIs('superadmin.vehicle-types.*') ? 'is-active' : '' }}">
        Vehicle Types
    </a>
</div>
