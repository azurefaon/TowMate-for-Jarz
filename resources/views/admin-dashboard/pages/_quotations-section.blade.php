<!-- Quotations Section -->
<div id="quotationsSection" style="display: none; margin-top: 40px;">
    <div class="section-header">
        <div>
            <h3>💰 Quotation Management</h3>
            <p>All quotations with status tracking, expiry monitoring, and color-coded urgency levels</p>
        </div>
    </div>

    <!-- Quotation Statistics -->
    <div
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div
            style="background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 8px;">Total Quotations</div>
            <div style="font-size: 2rem; font-weight: 700; color: #0f172a;">{{ $quotationStats['total'] }}</div>
        </div>
        <div
            style="background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 8px;">🟢 Active</div>
            <div style="font-size: 2rem; font-weight: 700; color: #16a34a;">{{ $quotationStats['active'] }}</div>
        </div>
        <div
            style="background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 8px;">🔴 Urgent (< 2 days)</div>
                    <div style="font-size: 2rem; font-weight: 700; color: #dc2626;">{{ $quotationStats['urgent'] }}
                    </div>
            </div>
            <div
                style="background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 8px;">⚫ Expired</div>
                <div style="font-size: 2rem; font-weight: 700; color: #64748b;">{{ $quotationStats['expired'] }}</div>
            </div>
        </div>

        <!-- Sub-tabs: Book Now / Scheduled -->
        <div style="display:flex; gap:8px; margin-bottom:16px;">
            <button class="qt-tab" data-type="book_now"
                style="padding:8px 20px; border-radius:6px; font-weight:700; font-size:0.85rem; background:#dcfce7; color:#166534; border:2px solid #86efac; cursor:pointer;">
                Book Now
            </button>
            <button class="qt-tab" data-type="schedule"
                style="padding:8px 20px; border-radius:6px; font-weight:700; font-size:0.85rem; background:#fff; color:#374151; border:2px solid #e5e7eb; cursor:pointer;">
                Scheduled
            </button>
        </div>

        <!-- Quotations Table -->
        <div
            style="background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <table style="width: 100%; border-collapse: collapse;">
                <thead style="background: #f8fafc; border-bottom: 2px solid #e5e7eb;">
                    <tr>
                        <th
                            style="padding: 12px 16px; text-align: left; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">
                            Quotation #</th>
                        <th
                            style="padding: 12px 16px; text-align: left; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">
                            Type</th>
                        <th
                            style="padding: 12px 16px; text-align: left; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">
                            Customer</th>
                        <th
                            style="padding: 12px 16px; text-align: left; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">
                            Vehicle</th>
                        <th
                            style="padding: 12px 16px; text-align: left; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">
                            Amount</th>
                        <th
                            style="padding: 12px 16px; text-align: left; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">
                            Status</th>
                        <th
                            style="padding: 12px 16px; text-align: left; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">
                            Sent</th>
                        <th
                            style="padding: 12px 16px; text-align: left; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">
                            Expires</th>
                        <th
                            style="padding: 12px 16px; text-align: left; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">
                            Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($allQuotations as $quotation)
                        @php $serviceType = $quotation->service_type ?? 'book_now'; @endphp
                        <tr style="border-bottom: 1px solid #f1f5f9;" data-service-type="{{ $serviceType }}">
                            <td style="padding: 16px;">
                                <div style="font-weight: 700; color: #0f172a; font-size: 1rem;">
                                    {{ $quotation->quotation_number }}</div>
                                <div style="font-size: 0.8rem; color: #64748b;">ID: {{ $quotation->id }}</div>
                            </td>
                            <td style="padding: 16px;">
                                @if ($serviceType === 'schedule')
                                    <span
                                        style="display:inline-block; padding:3px 10px; border-radius:999px; font-size:0.72rem; font-weight:700; background:#dbeafe; color:#1e40af; text-transform:uppercase; letter-spacing:0.04em;">Scheduled</span>
                                @else
                                    <span
                                        style="display:inline-block; padding:3px 10px; border-radius:999px; font-size:0.72rem; font-weight:700; background:#dcfce7; color:#166534; text-transform:uppercase; letter-spacing:0.04em;">Book
                                        Now</span>
                                @endif
                            </td>
                            <td style="padding: 16px;">
                                <div style="font-weight: 600; color: #0f172a;">{{ $quotation->customer->name ?? 'N/A' }}
                                </div>
                                <div style="font-size: 0.85rem; color: #64748b;">
                                    {{ $quotation->customer->phone ?? 'N/A' }}</div>
                            </td>
                            <td style="padding: 16px; color: #374151; font-size: 0.9rem;">
                                {{ $quotation->truckType->name ?? 'N/A' }}</td>
                            <td style="padding: 16px;">
                                <div style="font-weight: 700; font-size: 1.05rem; color: #0f172a;">
                                    ₱{{ number_format($quotation->estimated_price, 2) }}</div>
                                @if ($quotation->counter_offer_amount)
                                    <div style="font-size: 0.8rem; color: #f59e0b;">Counter:
                                        ₱{{ number_format($quotation->counter_offer_amount, 2) }}</div>
                                @endif
                            </td>
                            <td style="padding: 16px;">
                                @if ($quotation->status === 'pending')
                                    <span
                                        style="display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #fef3c7; color: #92400e; text-transform: uppercase; letter-spacing: 0.03em;">
                                        Pending Review
                                    </span>
                                @elseif($quotation->status === 'negotiating')
                                    <span
                                        style="display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #f3e8ff; color: #7e22ce; text-transform: uppercase; letter-spacing: 0.03em; animation: pulse 2s infinite;">
                                        Negotiating
                                    </span>
                                @elseif($quotation->urgency_level === 'urgent')
                                    <span
                                        style="display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #fee2e2; color: #991b1b; animation: pulse 2s infinite; text-transform: uppercase; letter-spacing: 0.03em;">
                                        URGENT
                                    </span>
                                @elseif($quotation->urgency_level === 'warning')
                                    <span
                                        style="display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #fef3c7; color: #92400e; text-transform: uppercase; letter-spacing: 0.03em;">
                                        Expiring Soon
                                    </span>
                                @elseif($quotation->urgency_level === 'expired' || $quotation->status === 'expired' || $quotation->status === 'disregarded')
                                    <span
                                        style="display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #f1f5f9; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em;">
                                        Expired
                                    </span>
                                @elseif($quotation->status === 'accepted')
                                    <span
                                        style="display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #dbeafe; color: #1e40af; text-transform: uppercase; letter-spacing: 0.03em;">
                                        Accepted
                                    </span>
                                @elseif($quotation->status === 'rejected')
                                    <span
                                        style="display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #fee2e2; color: #991b1b; text-transform: uppercase; letter-spacing: 0.03em;">
                                        Rejected
                                    </span>
                                @else
                                    <span
                                        style="display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #fef9c3; color: #713f12; text-transform: uppercase; letter-spacing: 0.03em;">
                                        Awaiting Confirmation
                                    </span>
                                @endif
                            </td>
                            <td style="padding: 16px;">
                                @if ($quotation->sent_at)
                                    <div style="color: #374151; font-size: 0.9rem;">
                                        {{ $quotation->sent_at->format('M d, Y') }}</div>
                                    <div style="font-size: 0.75rem; color: #94a3b8;">
                                        {{ $quotation->sent_at->format('g:i A') }}</div>
                                @else
                                    <div style="color: #94a3b8; font-size: 0.85rem;">Not sent yet</div>
                                @endif
                            </td>
                            <td style="padding: 16px;">
                                @if ($quotation->expires_at)
                                    <div
                                        style="font-weight: 600; font-size: 0.9rem; color: {{ $quotation->urgency_level === 'urgent' ? '#dc2626' : ($quotation->urgency_level === 'warning' ? '#f59e0b' : '#0f172a') }};">
                                        {{ $quotation->time_remaining_text }}
                                    </div>
                                    <div style="font-size: 0.8rem; color: #64748b;">
                                        {{ $quotation->expires_at->format('M d, g:i A') }}</div>
                                @else
                                    <div style="color: #94a3b8; font-size: 0.85rem;">No expiry set</div>
                                @endif
                            </td>
                            <td style="padding: 16px;">
                                @if ($quotation->status === 'pending')
                                    <button type="button" onclick="sendQuotationToCustomer({{ $quotation->id }})"
                                        style="padding: 6px 14px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; background: #10b981; color: white; border: none; cursor: pointer; transition: all 0.15s; margin-right: 4px;">
                                        📤 Send
                                    </button>
                                @elseif($quotation->counter_offer_amount)
                                    <button type="button" onclick="viewCustomerResponse({{ $quotation->id }})"
                                        style="padding: 6px 14px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; background: #f59e0b; color: white; border: none; cursor: pointer; transition: all 0.15s; margin-right: 4px;">
                                        💬 View Response
                                    </button>
                                @endif
                                <button type="button" onclick="viewQuotationDetails({{ $quotation->id }})"
                                    style="padding: 6px 14px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; background: #eff6ff; color: #1e40af; border: none; cursor: pointer; transition: all 0.15s;">
                                    👁️ View
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="padding: 60px 20px; text-align: center; color: #94a3b8;">
                                <div style="font-size: 1rem;">No quotations found.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
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

    <script>
        // Book Now / Scheduled sub-tab switching
        document.addEventListener('DOMContentLoaded', function() {
            const qtTabs = document.querySelectorAll('.qt-tab');
            const qtRows = document.querySelectorAll('tr[data-service-type]');

            function activateQtTab(type) {
                qtTabs.forEach(function(btn) {
                    if (btn.dataset.type === type) {
                        btn.style.background = type === 'schedule' ? '#dbeafe' : '#dcfce7';
                        btn.style.color = type === 'schedule' ? '#1e40af' : '#166534';
                        btn.style.borderColor = type === 'schedule' ? '#93c5fd' : '#86efac';
                    } else {
                        btn.style.background = '#fff';
                        btn.style.color = '#374151';
                        btn.style.borderColor = '#e5e7eb';
                    }
                });
                qtRows.forEach(function(row) {
                    row.style.display = row.dataset.serviceType === type ? '' : 'none';
                });
            }

            qtTabs.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    activateQtTab(this.dataset.type);
                });
            });

            // Default: show book_now
            activateQtTab('book_now');
        });

        // Quotations section tab handling
        document.addEventListener('DOMContentLoaded', function() {
            const quotationsTab = document.querySelector('[data-filter="quotations"]');
            const quotationsSection = document.getElementById('quotationsSection');
            const incomingList = document.getElementById('incomingList');
            const allTabs = document.querySelectorAll('.queue-filter-btn');

            if (quotationsTab && quotationsSection) {
                quotationsTab.addEventListener('click', function() {
                    // Hide incoming list
                    if (incomingList) incomingList.style.display = 'none';

                    // Show quotations section
                    quotationsSection.style.display = 'block';

                    // Update active tab
                    allTabs.forEach(tab => tab.classList.remove('is-active'));
                    quotationsTab.classList.add('is-active');
                });

                // Handle other tabs
                allTabs.forEach(tab => {
                    if (tab.dataset.filter !== 'quotations') {
                        tab.addEventListener('click', function() {
                            // Show incoming list
                            if (incomingList) incomingList.style.display = 'block';

                            // Hide quotations section
                            quotationsSection.style.display = 'none';
                        });
                    }
                });
            }
        });
    </script>
