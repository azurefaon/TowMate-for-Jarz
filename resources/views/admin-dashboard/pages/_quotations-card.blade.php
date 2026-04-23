<div class="quotations-card-section" style="margin-bottom: 32px;">
    @if ($allQuotations->whereIn('status', ['pending', 'sent'])->count() > 0)
        <div
            style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 2px solid #fbbf24; border-radius: 16px; padding: 20px; box-shadow: 0 4px 12px rgba(251, 191, 36, 0.15);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div>
                    <h3
                        style="margin: 0 0 4px; font-size: 1.1rem; font-weight: 700; color: #92400e; display: flex; align-items: center; gap: 8px;">
                        FLOATING QUOTATIONS
                        <span
                            style="display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 28px; padding: 0 8px; border-radius: 999px; background: #dc2626; color: #fff; font-size: 0.85rem; font-weight: 700; box-shadow: 0 2px 8px rgba(220, 38, 38, 0.3);">
                            {{ $allQuotations->whereIn('status', ['pending', 'sent'])->count() }}
                        </span>
                    </h3>
                    <p style="margin: 0; font-size: 0.85rem; color: #78350f;">Review and send quotations to customers</p>
                </div>
                <a href="#quotationsSection"
                    onclick="document.querySelector('[data-filter=quotations]').click(); return false;"
                    style="padding: 8px 16px; border-radius: 8px; background: #fff; color: #92400e; font-size: 0.85rem; font-weight: 600; text-decoration: none; border: 1px solid #fbbf24; transition: all 0.15s;">
                    View All →
                </a>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">
                @foreach ($allQuotations->whereIn('status', ['pending', 'sent'])->take(6) as $quotation)
                    @php
                        $timeRemaining = $quotation->getTimeRemaining();
                        $urgency = $timeRemaining['urgency'] ?? 'normal';

                        $cardBorder = match ($urgency) {
                            'urgent'
                                => 'border: 2px solid #dc2626; background: linear-gradient(135deg, #fee2e2, #fef2f2);',
                            'warning'
                                => 'border: 2px solid #f59e0b; background: linear-gradient(135deg, #fef3c7, #fffbeb);',
                            default => 'border: 1px solid #e5e7eb; background: #fff;',
                        };

                        $urgencyBadge = match ($urgency) {
                            'urgent'
                                => '<span style="display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; background: #fee2e2; color: #991b1b; animation: pulse 2s infinite;">🔴 URGENT</span>',
                            'warning'
                                => '<span style="display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; background: #fef3c7; color: #92400e;">🟡 EXPIRING</span>',
                            default
                                => '<span style="display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; background: #dcfce7; color: #166534;">🟢 ACTIVE</span>',
                        };
                    @endphp

                    <div style="{{ $cardBorder }} border-radius: 12px; padding: 14px; transition: all 0.2s; cursor: pointer;"
                        onclick="viewQuotationDetails({{ $quotation->id }})"
                        onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(0,0,0,0.1)';"
                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">

                        <div
                            style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem; color: #0f172a; margin-bottom: 2px;">
                                    {{ $quotation->quotation_number }}
                                </div>
                                <div style="font-size: 0.75rem; color: #64748b;">
                                    {{ $quotation->created_at->diffForHumans() }}
                                </div>
                            </div>
                            {!! $urgencyBadge !!}
                        </div>

                        <div style="margin-bottom: 10px;">
                            <div style="font-weight: 600; font-size: 0.9rem; color: #0f172a; margin-bottom: 2px;">
                                {{ $quotation->customer->full_name ?? ($quotation->customer->name ?? 'N/A') }}
                            </div>
                            <div style="font-size: 0.8rem; color: #64748b;">
                                {{ $quotation->customer->phone ?? 'N/A' }}
                            </div>
                        </div>

                        <div
                            style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid #e5e7eb;">
                            <div>
                                <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 2px;">Amount</div>
                                <div style="font-weight: 700; font-size: 1rem; color: #0f172a;">
                                    ₱{{ number_format($quotation->estimated_price, 2) }}
                                </div>
                            </div>
                            <div style="text-align: right;">
                                @if ($quotation->status === 'pending')
                                    <button type="button"
                                        onclick="event.stopPropagation(); sendQuotationToCustomer({{ $quotation->id }})"
                                        style="padding: 6px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; background: #10b981; color: white; border: none; cursor: pointer; transition: all 0.15s;"
                                        onmouseover="this.style.background='#059669'"
                                        onmouseout="this.style.background='#10b981'">
                                        📤 Send
                                    </button>
                                @else
                                    <div
                                        style="font-size: 0.7rem; color: {{ $urgency === 'urgent' ? '#dc2626' : ($urgency === 'warning' ? '#f59e0b' : '#64748b') }}; font-weight: 600;">
                                        {{ $timeRemaining['message'] ?? 'N/A' }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if ($quotation->counter_offer_amount)
                            <div
                                style="margin-top: 8px; padding: 6px 10px; border-radius: 6px; background: #fef3c7; border: 1px solid #fbbf24;">
                                <div style="font-size: 0.7rem; color: #92400e; font-weight: 600;">💬 Counter Offer:
                                    ₱{{ number_format($quotation->counter_offer_amount, 2) }}</div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($allQuotations->whereIn('status', ['pending', 'sent'])->count() > 6)
                <div style="text-align: center; margin-top: 16px;">
                    <a href="#quotationsSection"
                        onclick="document.querySelector('[data-filter=quotations]').click(); return false;"
                        style="display: inline-block; padding: 10px 20px; border-radius: 8px; background: #fff; color: #92400e; font-size: 0.9rem; font-weight: 600; text-decoration: none; border: 1px solid #fbbf24; transition: all 0.15s;">
                        View All {{ $allQuotations->whereIn('status', ['pending', 'sent'])->count() }} Quotations →
                    </a>
                </div>
            @endif
        </div>
    @else
        <div
            style="background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; text-align: center;">
            <div style="font-size: 2rem; margin-bottom: 8px;">✅</div>
            <div style="font-size: 0.95rem; font-weight: 600; color: #64748b;">No pending quotations</div>
            <div style="font-size: 0.85rem; color: #94a3b8; margin-top: 4px;">All quotations have been processed</div>
        </div>
    @endif
</div>

<style>
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
