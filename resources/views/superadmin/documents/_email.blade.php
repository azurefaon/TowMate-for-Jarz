<div class="dv-block">
    <div class="dv-label">EMAIL DELIVERY</div>
    <div style="margin-top: 12px"><span class="dv-badge" style="background: {{ $email['bg'] }}">{{ $email['label'] }}</span></div>
    <div class="dv-email-detail">{{ $email['detail'] }}</div>
    @if ($email['recipient'])
        <div class="dv-email-to">To {{ $email['recipient'] }}</div>
    @endif
</div>
