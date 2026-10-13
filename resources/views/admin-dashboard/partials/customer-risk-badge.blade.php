{{--
    Dispatcher-facing badge for a customer's CURRENT risk status (informational only).
    Params: $customer (Customer|null), $prefix (optional string, e.g. "Current: ").
    Renders nothing when the customer has no risk flag. Styles: dispatcher/css/customer-risk-badge.css
--}}
@if (!empty($customer) && $customer->has_risk_flag)
    <span class="cust-risk-badge cust-risk-badge--{{ strtolower($customer->risk_level) }}"
        data-customer-risk="{{ strtolower($customer->risk_level) }}"
        title="Customer's current risk status: {{ $customer->risk_status_label }}{{ filled($customer->risk_reason) ? ' — ' . $customer->risk_reason : '' }}">&#9873; {{ $prefix ?? '' }}{{ $customer->risk_status_label }}</span>
@endif
