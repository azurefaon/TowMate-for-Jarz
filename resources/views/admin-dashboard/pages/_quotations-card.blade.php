@php
    $pendingQuotations = $allQuotations->where('status', 'pending');
    $sentQuotations = $allQuotations->where('status', 'sent');
    $negotiatingQuotations = $allQuotations->where('status', 'negotiating');
    $totalActive = $pendingQuotations->count() + $sentQuotations->count() + $negotiatingQuotations->count();
@endphp

<div class="quotations-card-section" style="margin-bottom: 32px;">
    <div style="background: #fff; border: 1px solid #e5e7eb; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.06);">

        <!-- Header -->
        <div
            style="padding: 16px 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 10px;">
                {{-- <div
                        style="width: 36px; height: 36px; border-radius: 10px; background: #eff6ff; display: flex; align-items: center; justify-content: center; font-size: 1rem;">
                    </div> --}}
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
                        {{ $pendingQuotations->count() }} need{{ $pendingQuotations->count() === 1 ? 's' : '' }}
                        sending
                        &nbsp;·&nbsp; {{ $sentQuotations->count() }} awaiting response
                        @if ($negotiatingQuotations->count() > 0)
                            &nbsp;·&nbsp; <span
                                style="color: #7e22ce; font-weight: 600;">{{ $negotiatingQuotations->count() }}
                                negotiating</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div style="padding: 16px 20px; display: grid; gap: 20px;">

            @if ($pendingQuotations->count() > 0)
                <div>
                    {{-- <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 10px;">
                        <div style="width: 8px; height: 8px; border-radius: 50%; background: #f59e0b;"></div>
                        <span
                            style="font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.06em;">Ready
                            to Send</span>
                        <span style="font-size: 0.72rem; color: #94a3b8;">({{ $pendingQuotations->count() }})</span>
                    </div> --}}
                    <div
                        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px;">
                        @foreach ($pendingQuotations->take(4) as $quotation)
                            <div style="border: 1px solid #e5e7eb;  border-radius: 10px; padding: 13px; background: #fff; cursor: pointer; transition: box-shadow 0.15s;"
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
                                    <span
                                        style="font-size: 0.68rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #fef9c3; color: #854d0e; border: 1px solid #fde68a;">PENDING</span>
                                </div>
                                <div style="font-size: 0.88rem; font-weight: 600; color: #0f172a; margin-bottom: 1px;">
                                    {{ $quotation->customer->full_name ?? ($quotation->customer->name ?? 'N/A') }}
                                </div>
                                <div style="font-size: 0.78rem; color: #64748b; margin-bottom: 10px;">
                                    {{ $quotation->customer->phone ?? '' }}</div>
                                <div
                                    style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid #f1f5f9;">
                                    <span
                                        style="font-size: 1rem; font-weight: 800; color: #0f172a;">₱{{ number_format($quotation->estimated_price, 2) }}</span>
                                    <button type="button"
                                        onclick="event.stopPropagation(); sendQuotationToCustomer({{ $quotation->id }})"
                                        style="padding: 5px 14px; border-radius: 7px; font-size: 0.78rem; font-weight: 700; background: #2563eb; color: #fff; border: none; cursor: pointer;"
                                        onmouseover="this.style.background='#1d4ed8'"
                                        onmouseout="this.style.background='#2563eb'">
                                        Send →
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($sentQuotations->count() > 0)
                <div>
                    <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 10px;">
                        <div
                            style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; animation: qpulse 2s ease-in-out infinite;">
                        </div>
                        <span
                            style="font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.06em;">Waiting
                            for Customer</span>
                        <span style="font-size: 0.72rem; color: #94a3b8;">({{ $sentQuotations->count() }})</span>
                    </div>
                    <div
                        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px;">
                        @foreach ($sentQuotations->take(4) as $quotation)
                            @php
                                $timeRemaining = $quotation->getTimeRemaining();
                                $urgency = $timeRemaining['urgency'] ?? 'normal';
                                $accentColor = match ($urgency) {
                                    'urgent' => '#ffffff',
                                    'warning' => '#f59e0b',
                                    default => '#ffffff',
                                };
                                $badgeBg = match ($urgency) {
                                    'urgent' => '#fef2f2',
                                    'warning' => '#fffbeb',
                                    default => '#f0fdf4',
                                };
                                $badgeColor = match ($urgency) {
                                    'urgent' => '#dc2626',
                                    'warning' => '#b45309',
                                    default => '#15803d',
                                };
                                $badgeText = match ($urgency) {
                                    'urgent' => 'URGENT',
                                    'warning' => 'EXPIRING',
                                    default => 'AWAITING CONFIRMATION',
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
                                        style="font-size: 0.68rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: {{ $badgeBg }}; color: {{ $badgeColor }}; border: 1px solid {{ $accentColor }}20;">{{ $badgeText }}</span>
                                </div>
                                <div style="font-size: 0.88rem; font-weight: 600; color: #0f172a; margin-bottom: 1px;">
                                    {{ $quotation->customer->full_name ?? ($quotation->customer->name ?? 'N/A') }}
                                </div>
                                <div style="font-size: 0.78rem; color: #64748b; margin-bottom: 10px;">
                                    {{ $quotation->customer->phone ?? '' }}</div>

                                @if ($quotation->counter_offer_amount)
                                    <div
                                        style="padding: 6px 10px; border-radius: 7px; background: #fffbeb; border: 1px solid #fde68a; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                                        <span style="font-size: 0.72rem;">💬</span>
                                        <span style="font-size: 0.75rem; color: #92400e; font-weight: 600;">Counter
                                            offer: ₱{{ number_format($quotation->counter_offer_amount, 2) }}</span>
                                    </div>
                                @endif

                                <div
                                    style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid #f1f5f9;">
                                    <span
                                        style="font-size: 1rem; font-weight: 800; color: #0f172a;">₱{{ number_format($quotation->estimated_price, 2) }}</span>
                                    <div style="text-align: right;">
                                        <div style="font-size: 0.72rem; color: {{ $accentColor }}; font-weight: 600;">
                                            {{ $timeRemaining['message'] ?? '—' }}</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($negotiatingQuotations->count() > 0)
                <div>
                    <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 10px;">
                        <div
                            style="width: 8px; height: 8px; border-radius: 50%; background: #a855f7; animation: qpulse 2s ease-in-out infinite;">
                        </div>
                        <span
                            style="font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.06em;">Customer
                            Negotiating</span>
                        <span
                            style="font-size: 0.72rem; color: #94a3b8;">({{ $negotiatingQuotations->count() }})</span>
                    </div>
                    <div
                        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px;">
                        @foreach ($negotiatingQuotations->take(4) as $quotation)
                            <div style="border: 1px solid #e9d5ff; border-left: 4px solid #a855f7; border-radius: 10px; padding: 13px; background: #fff; cursor: pointer; transition: box-shadow 0.15s;"
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
                                            {{ $quotation->responded_at?->diffForHumans() ?? '—' }}</div>
                                    </div>
                                    <span
                                        style="font-size: 0.68rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #f3e8ff; color: #7e22ce; border: 1px solid #d8b4fe;">NEGOTIATING</span>
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
                </div>
            @endif

            @if ($totalActive === 0)
                <div style="padding: 40px 20px; text-align: center; color: #94a3b8; font-size: 0.9rem;">
                    No active quotations right now.
                </div>
            @endif

        </div>

        @if ($totalActive > 8)
            <div style="padding: 12px 20px; border-top: 1px solid #f1f5f9; text-align: center;">
                <a href="#quotationsSection"
                    onclick="document.querySelector('[data-filter=quotations]').click(); return false;"
                    style="font-size: 0.82rem; font-weight: 600; color: #2563eb; text-decoration: none;">
                    + {{ $totalActive - 8 }} more quotations — View All
                </a>
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
</style>
