{{--
    Dispatcher-facing badge for a customer's CURRENT risk status (informational only).
    Params: $customer (Customer|null), $prefix (optional string, e.g. "Current: ").
    Renders nothing when the customer has no risk flag.
--}}
@once
    <style>
        .cust-risk-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            line-height: 1.4;
            border: 1px solid #111827;
            background: #fff;
            color: #111827;
            vertical-align: middle;
        }
        .cust-risk-badge--low { background: #fef9c3; }
        .cust-risk-badge--medium, .cust-risk-badge--watchlist { background: #facc15; }
        .cust-risk-badge--high { background: #f97316; color: #fff; border-color: #c2410c; }
        .cust-risk-badge--blacklisted { background: #111827; color: #facc15; }
    </style>
@endonce
@if (!empty($customer) && $customer->has_risk_flag)
    <span class="cust-risk-badge cust-risk-badge--{{ strtolower($customer->risk_level) }}"
        data-customer-risk="{{ strtolower($customer->risk_level) }}"
        title="Customer's current risk status: {{ $customer->risk_status_label }}{{ filled($customer->risk_reason) ? ' — ' . $customer->risk_reason : '' }}">&#9873; {{ $prefix ?? '' }}{{ $customer->risk_status_label }}</span>
@endif
