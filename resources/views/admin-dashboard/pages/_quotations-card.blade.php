@php
    $pendingQuotations = $allQuotations->where('status', 'pending');
    $sentQuotations = $allQuotations->where('status', 'sent');
    $negotiatingQuotations = $allQuotations->where('status', 'negotiating');
    $totalActive = $pendingQuotations->count() + $sentQuotations->count() + $negotiatingQuotations->count();

    // Auto-select the most urgent tab
    if ($negotiatingQuotations->count() > 0) {
        $defaultTab = 'negotiating';
    } elseif ($sentQuotations->count() > 0) {
        $defaultTab = 'sent';
    } else {
        $defaultTab = 'pending';
    }
@endphp

<div class="quotations-card-section" id="fqSection" style="margin-bottom: 32px;">
    <div style="background: #fff; border: 1px solid #e5e7eb; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.06);">

        <!-- Header -->
        <div
            style="padding: 16px 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <div
                    style="font-size: 0.95rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    Floating Quotations
                    <span
                        style="display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; padding: 0 7px; border-radius: 999px; background: #ef4444; color: #fff; font-size: 0.72rem; font-weight: 800;">
                        {{ $totalActive }}
                    </span>
                </div>
                <div style="font-size: 0.78rem; color: #94a3b8; margin-top: 1px;">
                    {{ $pendingQuotations->count() }} need{{ $pendingQuotations->count() === 1 ? 's' : '' }} sending
                    &nbsp;·&nbsp; {{ $sentQuotations->count() }} awaiting response
                    @if ($negotiatingQuotations->count() > 0)
                        &nbsp;·&nbsp; <span
                            style="color: #7e22ce; font-weight: 600;">{{ $negotiatingQuotations->count() }}
                            negotiating</span>
                    @endif
                </div>
            </div>
        </div>

        @if ($totalActive === 0)
            <div style="padding: 40px 20px; text-align: center; color: #94a3b8; font-size: 0.9rem;">
                No active quotations right now.
            </div>
        @else
            <!-- Tab bar -->
            <div
                style="display: flex; border-bottom: 1px solid #f1f5f9; padding: 0 20px; gap: 2px; background: #fafafa;">

                <!-- Pending tab -->
                <button type="button" id="fqTab-pending" onclick="switchFqTab('pending')"
                    style="padding: 10px 14px; font-size: 0.8rem; font-weight: 600; border: none; border-bottom: 2px solid transparent; background: transparent; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: color 0.15s, border-color 0.15s; {{ $pendingQuotations->count() === 0 ? 'opacity: 0.4;' : '' }}">
                    Pending
                    <span id="fqBadge-pending"
                        style="display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; font-size: 0.68rem; font-weight: 800; background: #f1f5f9; color: #64748b;">
                        {{ $pendingQuotations->count() }}
                    </span>
                </button>

                <!-- Sent / Waiting tab -->
                <button type="button" id="fqTab-sent" onclick="switchFqTab('sent')"
                    style="padding: 10px 14px; font-size: 0.8rem; font-weight: 600; border: none; border-bottom: 2px solid transparent; background: transparent; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: color 0.15s, border-color 0.15s; {{ $sentQuotations->count() === 0 ? 'opacity: 0.4;' : '' }}">
                    Waiting for Customer
                    <span id="fqBadge-sent"
                        style="display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; font-size: 0.68rem; font-weight: 800; background: #f1f5f9; color: #64748b;">
                        {{ $sentQuotations->count() }}
                    </span>
                </button>

                <!-- Negotiating tab -->
                <button type="button" id="fqTab-negotiating" onclick="switchFqTab('negotiating')"
                    style="padding: 10px 14px; font-size: 0.8rem; font-weight: 600; border: none; border-bottom: 2px solid transparent; background: transparent; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: color 0.15s, border-color 0.15s; {{ $negotiatingQuotations->count() === 0 ? 'opacity: 0.4;' : '' }}">
                    <span style="display: flex; align-items: center; gap: 5px;">
                        @if ($negotiatingQuotations->count() > 0)
                            <span
                                style="width: 7px; height: 7px; border-radius: 50%; background: #a855f7; display: inline-block; animation: qpulse 2s ease-in-out infinite;"></span>
                        @endif
                        Negotiating
                    </span>
                    <span id="fqBadge-negotiating"
                        style="display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; font-size: 0.68rem; font-weight: 800; background: {{ $negotiatingQuotations->count() > 0 ? '#f3e8ff' : '#f1f5f9' }}; color: {{ $negotiatingQuotations->count() > 0 ? '#7e22ce' : '#64748b' }};">
                        {{ $negotiatingQuotations->count() }}
                    </span>
                </button>

            </div>

            <!-- ─── Pending panel ─── -->
            <div id="fqPanel-pending" data-fq-panel style="display: none; padding: 16px 20px;">
                @if ($pendingQuotations->count() === 0)
                    <div style="padding: 32px 0; text-align: center; color: #94a3b8; font-size: 0.85rem;">No pending
                        quotations.</div>
                @else
                    <div
                        style="max-height: 380px; overflow-y: auto; padding-right: 4px; display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px;">
                        @foreach ($pendingQuotations as $quotation)
                            <div style="border: 1px solid #e5e7eb; border-radius: 10px; padding: 13px; background: #fff; cursor: pointer; transition: box-shadow 0.15s;"
                                onclick="viewQuotationDetails({{ $quotation->id }})"
                                onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'"
                                onmouseout="this.style.boxShadow='none'">
                                <div
                                    style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                    <div>
                                        <div
                                            style="font-size: 0.78rem; font-weight: 700; color: #0f172a; font-family: monospace;">
                                            {{ $quotation->quotation_number }}</div>
                                        <div style="font-size: 0.72rem; color: #94a3b8; margin-top: 1px;">
                                            {{ $quotation->created_at->diffForHumans() }}</div>
                                    </div>
                                    <div
                                        style="display: flex; flex-direction: column; align-items: flex-end; gap: 3px;">
                                        <span
                                            style="font-size: 10px; padding: 2px 8px; border-radius: 999px; color: #000;">PENDING</span>
                                        @if ($quotation->source_booking_id)
                                            <span
                                                style="font-size: 9px; padding: 1px 6px; border-radius: 999px; background: #dbeafe; color: #1d4ed8; font-weight: 700;">MOBILE</span>
                                        @endif
                                    </div>
                                </div>
                                <div style="font-size: 0.88rem; color: #0f172a; margin-bottom: 1px;">
                                    {{ $quotation->customer->full_name ?? ($quotation->customer->name ?? 'N/A') }}
                                </div>
                                <div style="font-size: 0.78rem; color: #000; margin-bottom: 10px;">
                                    {{ $quotation->customer->phone ?? '' }}</div>
                                <div
                                    style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid #f1f5f9;">
                                    <span
                                        style="font-size: 1rem; font-weight: 800; color: #0f172a;">₱{{ number_format($quotation->estimated_price, 2) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- ─── Sent / Waiting panel ─── -->
            <div id="fqPanel-sent" data-fq-panel style="display: none; padding: 16px 20px;">
                @if ($sentQuotations->count() === 0)
                    <div style="padding: 32px 0; text-align: center; color: #94a3b8; font-size: 0.85rem;">No quotations
                        awaiting customer response.</div>
                @else
                    <div
                        style="max-height: 380px; overflow-y: auto; padding-right: 4px; display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px;">
                        @foreach ($sentQuotations as $quotation)
                            @php
                                $timeRemaining = $quotation->getTimeRemaining();
                                $urgency = $timeRemaining['urgency'] ?? 'normal';
                                $accentColor = match ($urgency) {
                                    'urgent' => '#ef4444',
                                    'warning' => '#f59e0b',
                                    default => '#e5e7eb',
                                };
                                $badgeBg = match ($urgency) {
                                    'urgent' => '#fef2f2',
                                    'warning' => '#fffbeb',
                                    default => '#f8fafc',
                                };
                                $badgeColor = match ($urgency) {
                                    'urgent' => '#dc2626',
                                    'warning' => '#b45309',
                                    default => '#475569',
                                };
                                $badgeText = match ($urgency) {
                                    'urgent' => 'URGENT',
                                    'warning' => 'EXPIRING',
                                    default => 'AWAITING',
                                };
                            @endphp
                            <div style="border: 1px solid #e5e7eb; border-left: 4px solid {{ $accentColor }}; border-radius: 10px; padding: 13px; background: #fff; cursor: pointer; transition: box-shadow 0.15s;"
                                onclick="viewQuotationDetails({{ $quotation->id }})"
                                onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'"
                                onmouseout="this.style.boxShadow='none'">
                                <div
                                    style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                    <div>
                                        <div
                                            style="font-size: 0.78rem; font-weight: 700; color: #0f172a; font-family: monospace;">
                                            {{ $quotation->quotation_number }}</div>
                                        <div style="font-size: 0.72rem; color: #94a3b8; margin-top: 1px;">Sent
                                            {{ $quotation->sent_at?->diffForHumans() ?? '—' }}</div>
                                    </div>
                                    <span
                                        style="font-size: 0.68rem; padding: 2px 8px; background: {{ $badgeBg }}; color: {{ $badgeColor }}; border: 1px solid {{ $accentColor }}40; border-radius: 4px;">{{ $badgeText }}</span>
                                </div>
                                <div style="font-size: 0.88rem; color: #0f172a; margin-bottom: 1px;">
                                    {{ $quotation->customer->full_name ?? ($quotation->customer->name ?? 'N/A') }}
                                </div>
                                <div style="font-size: 0.78rem; color: #64748b; margin-bottom: 10px;">
                                    {{ $quotation->customer->phone ?? '' }}</div>
                                @if ($quotation->counter_offer_amount)
                                    <div
                                        style="padding: 6px 10px; border-radius: 7px; background: #fffbeb; border: 1px solid #fde68a; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                                        <span style="font-size: 0.75rem; color: #000; font-weight: 600;">Counter offer:
                                            ₱{{ number_format($quotation->counter_offer_amount, 2) }}</span>
                                    </div>
                                @endif
                                <div
                                    style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid #f1f5f9;">
                                    <span
                                        style="font-size: 1rem; font-weight: 800; color: #0f172a;">₱{{ number_format($quotation->estimated_price, 2) }}</span>
                                    <div style="font-size: 0.72rem; color: {{ $badgeColor }}; font-weight: 600;">
                                        {{ $timeRemaining['message'] ?? '—' }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- ─── Negotiating panel ─── -->
            <div id="fqPanel-negotiating" data-fq-panel style="display: none; padding: 16px 20px;">
                @if ($negotiatingQuotations->count() === 0)
                    <div style="padding: 32px 0; text-align: center; color: #94a3b8; font-size: 0.85rem;">No active
                        negotiations.</div>
                @else
                    <div
                        style="max-height: 380px; overflow-y: auto; padding-right: 4px; display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px;">
                        @foreach ($negotiatingQuotations as $quotation)
                            <div style="border: 1px solid #e9d5ff; border-left: 4px solid #a855f7; border-radius: 10px; padding: 13px; background: #fff; cursor: pointer; transition: box-shadow 0.15s;"
                                onclick="viewQuotationDetails({{ $quotation->id }})"
                                onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'"
                                onmouseout="this.style.boxShadow='none'">
                                <div
                                    style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                    <div>
                                        <div style="font-size: 0.78rem; color: #0f172a; font-family: monospace;">
                                            {{ $quotation->quotation_number }}</div>
                                        <div style="font-size: 0.72rem; color: #94a3b8; margin-top: 1px;">
                                            {{ $quotation->responded_at?->diffForHumans() ?? '—' }}</div>
                                    </div>
                                    <span
                                        style="font-size: 0.68rem; padding: 2px 8px; background: #f3e8ff; color: #7e22ce; border: 1px solid #d8b4fe; border-radius: 4px;">NEGOTIATING</span>
                                </div>
                                <div style="font-size: 0.88rem; font-weight: 600; color: #0f172a; margin-bottom: 1px;">
                                    {{ $quotation->customer->full_name ?? ($quotation->customer->name ?? 'N/A') }}
                                </div>
                                <div style="font-size: 0.78rem; color: #64748b; margin-bottom: 8px;">
                                    {{ $quotation->customer->phone ?? '' }}</div>
                                @if ($quotation->counter_offer_amount)
                                    <div
                                        style="padding: 6px 10px; border-radius: 7px; background: #f3e8ff; border: 1px solid #d8b4fe; margin-bottom: 8px;">
                                        <div style="font-size: 0.75rem; color: #7e22ce; font-weight: 600;">Counter
                                            offer: ₱{{ number_format($quotation->counter_offer_amount, 2) }}</div>
                                        @if ($quotation->response_note)
                                            <div style="font-size: 0.72rem; color: #a78bfa; margin-top: 2px;">
                                                "{{ Str::limit($quotation->response_note, 50) }}"</div>
                                        @endif
                                    </div>
                                @endif
                                <div
                                    style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid #f1f5f9;">
                                    <span
                                        style="font-size: 1rem; font-weight: 800; color: #0f172a;">₱{{ number_format($quotation->estimated_price, 2) }}</span>
                                    <button type="button"
                                        onclick="event.stopPropagation(); viewQuotationDetails({{ $quotation->id }})"
                                        style="padding: 5px 12px; border-radius: 7px; font-size: 0.75rem; font-weight: 700; background: #f3e8ff; color: #7e22ce; border: 1px solid #d8b4fe; cursor: pointer;">
                                        Review
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

    </div>
</div>

<style>
    @keyframes qpulse {

        0%,
        100% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.5;
            transform: scale(0.85);
        }
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.7;
        }
    }

    /* Custom thin scrollbar for quotation panels */
    #fqPanel-pending>div:first-child,
    #fqPanel-sent>div:first-child,
    #fqPanel-negotiating>div:first-child {
        scrollbar-width: thin;
        scrollbar-color: #e2e8f0 transparent;
    }

    #fqPanel-pending>div:first-child::-webkit-scrollbar,
    #fqPanel-sent>div:first-child::-webkit-scrollbar,
    #fqPanel-negotiating>div:first-child::-webkit-scrollbar {
        width: 5px;
    }

    #fqPanel-pending>div:first-child::-webkit-scrollbar-track,
    #fqPanel-sent>div:first-child::-webkit-scrollbar-track,
    #fqPanel-negotiating>div:first-child::-webkit-scrollbar-track {
        background: transparent;
    }

    #fqPanel-pending>div:first-child::-webkit-scrollbar-thumb,
    #fqPanel-sent>div:first-child::-webkit-scrollbar-thumb,
    #fqPanel-negotiating>div:first-child::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 999px;
    }
</style>

<script>
    (function() {
        var TAB_ACTIVE_COLOR = {
            pending: '#f59e0b',
            sent: '#2563eb',
            negotiating: '#a855f7',
        };

        function switchFqTab(tab) {
            ['pending', 'sent', 'negotiating'].forEach(function(t) {
                var btn = document.getElementById('fqTab-' + t);
                var panel = document.getElementById('fqPanel-' + t);
                if (!btn || !panel) return;

                if (t === tab) {
                    panel.style.display = 'block';
                    btn.style.color = TAB_ACTIVE_COLOR[t];
                    btn.style.borderBottomColor = TAB_ACTIVE_COLOR[t];
                    btn.style.fontWeight = '700';
                    // highlight badge
                    var badge = document.getElementById('fqBadge-' + t);
                    if (badge) {
                        badge.style.background = TAB_ACTIVE_COLOR[t] + '20';
                        badge.style.color = TAB_ACTIVE_COLOR[t];
                    }
                } else {
                    panel.style.display = 'none';
                    btn.style.color = '#64748b';
                    btn.style.borderBottomColor = 'transparent';
                    btn.style.fontWeight = '600';
                    var badge = document.getElementById('fqBadge-' + t);
                    if (badge) {
                        badge.style.background = '#f1f5f9';
                        badge.style.color = '#64748b';
                    }
                }
            });
        }

        // Expose globally so server-rendered onclick="" still works if needed
        window.switchFqTab = switchFqTab;

        document.addEventListener('DOMContentLoaded', function() {
            switchFqTab('{{ $defaultTab }}');
        });
    })();
</script>
