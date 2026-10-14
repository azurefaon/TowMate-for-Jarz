@extends('layouts.superadmin')

@section('title', 'Customers')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/customers.css') }}?v={{ filemtime(public_path('superadmin/css/customers.css')) }}">
@endpush

@section('content')
    <div class="ob-page" id="ownerCustomers">

        <header class="ob-header">
            <h1>Customers</h1>
            <p>Every customer on record, with booking count and risk status</p>
        </header>

        <div class="oc-stats" aria-label="Customer totals">
            <div class="oc-stat"><strong>{{ number_format($stats['total']) }}</strong><span>Total customers</span></div>
            <div class="oc-stat"><strong>{{ number_format($stats['watchlist']) }}</strong><span>Watchlist</span></div>
            <div class="oc-stat"><strong>{{ number_format($stats['blacklisted']) }}</strong><span>Blacklisted</span></div>
        </div>

        <form class="ob-filters oc-filters" method="GET" action="{{ route('superadmin.customers.index') }}" role="search">
            <label class="ob-search">
                <span class="ob-sr">Search customers</span>
                <i data-lucide="search" aria-hidden="true"></i>
                <input type="search" name="search" value="{{ $filters['search'] }}"
                    placeholder="Search name, phone or email" autocomplete="off">
            </label>

            <label class="oc-field">
                <span class="ob-sr">Risk level</span>
                <select name="risk_level">
                    <option value="">All risk levels</option>
                    @foreach ($riskLevels as $value => $label)
                        <option value="{{ $value }}" @selected($filters['risk_level'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="oc-field">
                <span class="ob-sr">Customer type</span>
                <select name="customer_type">
                    <option value="">All customer types</option>
                    @foreach ($customerTypes as $value => $label)
                        <option value="{{ $value }}" @selected($filters['customer_type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <button type="submit" class="ob-btn oc-submit">Apply</button>

            @if ($hasFilters)
                <a href="{{ route('superadmin.customers.index') }}" class="ob-clear">Clear filters</a>
            @endif
        </form>

        <div class="ob-card">
            @if ($customers->count() === 0)
                <div class="ob-state">
                    @if ($hasFilters)
                        <span class="ob-state-icon"><i data-lucide="search-x" aria-hidden="true"></i></span>
                        <p class="ob-state-title">No customers match these filters</p>
                        <p class="ob-state-text">Try a different search, risk level, or customer type.</p>
                        <a href="{{ route('superadmin.customers.index') }}" class="ob-btn">Clear filters</a>
                    @else
                        <span class="ob-state-icon"><i data-lucide="users" aria-hidden="true"></i></span>
                        <p class="ob-state-title">No customers yet</p>
                        <p class="ob-state-text">Customers appear here once they sign up or book.</p>
                    @endif
                </div>
            @else
                <div class="ob-table-wrap">
                    <table class="ob-table oc-table">
                        <thead>
                            <tr>
                                <th scope="col">Customer</th>
                                <th scope="col">Contact</th>
                                <th scope="col">Type</th>
                                <th scope="col">Risk</th>
                                <th scope="col">Bookings</th>
                                <th scope="col">Joined</th>
                                <th scope="col"><span class="ob-sr">Details</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php $customer = $row['customer']; @endphp
                                <tr class="ob-row">
                                    <td>
                                        <a class="oc-name" href="{{ route('superadmin.customers.show', $customer) }}">{{ $customer->full_name ?: '—' }}</a>
                                    </td>
                                    <td>
                                        <span class="ob-main">{{ $row['phone'] ?: '—' }}</span>
                                        @if ($customer->email)
                                            <span class="ob-sub">{{ $customer->email }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $customerTypes[strtolower((string) $customer->customer_type)] ?? 'Regular' }}</td>
                                    <td><span class="ob-badge tone-{{ $row['risk']['tone'] }}">{{ $row['risk']['label'] }}</span></td>
                                    <td>{{ number_format($customer->bookings_count) }}</td>
                                    <td>{{ $customer->created_at?->format('M j, Y') ?? '—' }}</td>
                                    <td class="ob-open">
                                        <a href="{{ route('superadmin.customers.show', $customer) }}" aria-label="View {{ $customer->full_name }}"><i data-lucide="chevron-right" aria-hidden="true"></i></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @include('superadmin.customers._pager', ['paginator' => $customers, 'noun' => 'customer'])
            @endif
        </div>
    </div>
@endsection
