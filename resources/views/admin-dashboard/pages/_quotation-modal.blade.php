<style>
    /* Quotation modal — flat/plain overrides */
    #quotationModal * {
        border-radius: 0 !important;
        box-shadow: none !important;
    }

    #quotationModal [style*="font-weight"] {
        font-weight: 400 !important;
    }

    #quotationModal .modal-card {
        border: 1px solid #000 !important;
    }

    #quotationModal *:not(button):not(select):not(input):not(textarea):not(option) {
        color: #000000 !important;
    }
</style>

<div id="quotationModal"
    style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(15, 23, 42, 0.55); align-items: center; justify-content: center; padding: 20px;"
    aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card"
        style="width: min(1600px, 96vw); max-width: 600px; max-height: 92vh; overflow-y: auto; background: #fff; display: flex; flex-direction: column;">

        <div
            style="padding: 20px 24px 16px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: #fff; border-radius: 16px 16px 0 0; z-index: 10;">
            <div>
                <h3 style="margin: 0; font-size: 2.1rem; color: #0f172a;" id="quotationModalTitle">
                    Quotation Details</h3>
                <p style="margin: 4px 0 0; font-size: 1.1rem; color: #94a3b8;" id="quotationModalSubtitle">Review and
                    manage this quotation</p>
            </div>
            <button type="button" onclick="closeQuotationModal()"
                style="width: 32px; height: 32px; border-radius: 8px; border: 1px solid #e2e8f0; background: #f8fafc; color: #64748b; font-size: 1.1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1;">
                ×
            </button>
        </div>

        <!-- modal body -->
        <div style="padding: 20px 24px; flex: 1; overflow-y: auto; min-height: 0;">

            <!-- Mobile booking banner -->
            <div id="qmMobileBanner"
                style="display:none;background:#fffbeb;border:1px solid #fde68a;padding:10px 16px;
                       margin-bottom:14px;align-items:center;gap:10px;">
                <span style="font-size:0.7rem;font-weight:700;background:#f59e0b;color:#fff;
                             padding:2px 7px;text-transform:uppercase;letter-spacing:0.07em;">Mobile</span>
                <span style="font-size:0.85rem;color:#92400e;">Booking ref:
                    <span id="qmSourceBookingCode" style="font-family:monospace;font-weight:700;">—</span>
                </span>
            </div>

            <div style="margin-bottom: 20px;">

                <div
                    style="display: inline-flex; align-items: center; gap: 8px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 7px 14px; margin-bottom: 12px;">
                    <span
                        style="font-size: 0.72rem; color: #3b82f6; text-transform: uppercase; letter-spacing: 0.07em;">Quotation
                        #</span>
                    <span style="font-size: 0.95rem; color: #1d4ed8; font-family: monospace; letter-spacing: 0.03em;"
                        id="qmQuotationNumber">—</span>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 13px 18px;">
                    <div
                        style="font-size: 1.1rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px;">
                        Customer</div>
                    <div style="font-size: 1.1rem; color: #0f172a; margin-bottom: 3px;" id="qmCustomerName">—</div>
                    <div style="font-size: 1.1rem; color: #64748b;" id="qmCustomerPhone">—</div>
                    <div style="font-size: 1.1rem; color: #64748b;" id="qmCustomerEmail">—</div>
                </div>
            </div>

            <div
                style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 20px;">
                <div
                    style="font-size: 1.1rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px;">
                    Route</div>
                <div style="display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px;">
                    <div
                        style="flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; background: #22c55e; display: flex; align-items: center; justify-content: center; font-size: 0.72rem; color: #fff; margin-top: 1px;">
                        A</div>
                    <div>
                        <div style="font-size: 1.1rem; color: #94a3b8; margin-bottom: 2px;">Pickup</div>
                        <div style="font-size: 1.1rem; color: #0f172a;" id="qmPickupAddress">—</div>
                    </div>
                </div>
                <div style="margin-left: 12px; width: 1px; height: 10px; background: #e2e8f0; margin-bottom: 10px;">
                </div>
                <div style="display: flex; align-items: flex-start; gap: 10px;">
                    <div
                        style="flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; background: #ef4444; display: flex; align-items: center; justify-content: center; font-size: 0.72rem; color: #fff; margin-top: 1px;">
                        B</div>
                    <div>
                        <div style="font-size: 1.1rem; color: #94a3b8; margin-bottom: 2px;">Drop-off</div>
                        <div style="font-size: 1.1rem; color: #0f172a;" id="qmDropoffAddress">—</div>
                    </div>
                </div>
                <div
                    style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #e2e8f0;">
                    <div>
                        <div style="font-size: 1.1rem; color: #94a3b8; margin-bottom: 3px;">Distance</div>
                        <div style="font-size: 1.1rem; font-weight: 600; color: #0f172a;" id="qmDistance">—</div>
                    </div>
                    <div>
                        <div style="font-size: 1.1rem; color: #94a3b8; margin-bottom: 3px;">Truck Type</div>
                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <span style="font-size: 1.1rem; font-weight: 600; color: #0f172a;" id="qmTruckType">—</span>
                            <span id="qmTruckClassBadge"
                                  style="display:none;font-size:0.65rem;font-weight:700;padding:2px 7px;
                                         text-transform:uppercase;letter-spacing:0.06em;border:1px solid;"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customer Vehicle section -->
            <div id="qmCustomerVehicleSection"
                style="display:none;background:#f8fafc;border:1px solid #e2e8f0;padding:14px 18px;margin-bottom:20px;">
                <div style="font-size:0.72rem;color:#94a3b8;text-transform:uppercase;
                            letter-spacing:0.06em;margin-bottom:10px;">Customer Vehicle</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;font-size:0.88rem;">
                    <div><span style="color:#94a3b8;">Make / Model</span><br>
                         <span id="qmVehicleMakeModel" style="color:#0f172a;font-weight:600;">—</span></div>
                    <div><span style="color:#94a3b8;">Year</span><br>
                         <span id="qmVehicleYear" style="color:#0f172a;font-weight:600;">—</span></div>
                    <div><span style="color:#94a3b8;">Color</span><br>
                         <span id="qmVehicleColor" style="color:#0f172a;font-weight:600;">—</span></div>
                    <div><span style="color:#94a3b8;">Plate</span><br>
                         <span id="qmVehiclePlate" style="color:#0f172a;font-weight:700;font-family:monospace;">—</span></div>
                </div>
            </div>

            <!-- Pickup notes section -->
            <div id="qmNotesSection"
                style="display:none;background:#fffbeb;border:1px solid #fde68a;padding:12px 16px;margin-bottom:20px;">
                <div style="font-size:0.72rem;color:#92400e;text-transform:uppercase;
                            letter-spacing:0.06em;margin-bottom:6px;">Pickup Notes</div>
                <div id="qmNotesText" style="font-size:0.88rem;color:#78350f;"></div>
            </div>

            <div id="qmImageGallery"
                style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 16px; margin-bottom: 20px; display: none;">
                <div
                    style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px;">
                    Vehicle Photos</div>
                <div id="qmImageGrid" style="display: flex; gap: 8px; flex-wrap: wrap;"></div>
                <p id="qmNoImages" style="color: #94a3b8; font-size: 0.85rem; margin: 0; display: none;">No photos
                    uploaded.</p>
            </div>

            <div id="qmExtraVehiclesSection"
                style="display:none; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 20px;">
                <div style="padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                    <span
                        style="font-size: 1.1rem; color: #000000; text-transform: uppercase; letter-spacing: 0.05em;">Additional
                        Vehicles</span>
                    <span id="qmVehicleCount" style="margin-left:8px;font-size:0.72rem;color:#94a3b8;"></span>
                </div>
                <div id="qmExtraVehiclesList" style="padding: 14px 16px; display: grid; gap: 10px;"></div>
            </div>

            <div style="border: 1px solid #e2e8f0;  overflow: hidden; margin-bottom: 20px;">
                <div style="padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                    <span
                        style="font-size: 1.1rem; color: #000000; text-transform: uppercase; letter-spacing: 0.05em;">Price
                        Breakdown</span>
                </div>
                <div style="padding: 16px; display: grid; gap: 10px;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.88rem; color: #000000;">
                        <span>Base Rate (Unit)</span>
                        <span id="qmBasePrice">TBD</span>
                    </div>
                    <div id="qmDistanceFeeRow"
                        style="display: flex; justify-content: space-between; font-size: 1.1rem; color: #000000;">
                        <span id="qmDistanceFeeLabel">Distance Fee</span>
                        <span id="qmDistanceFee">₱0.00</span>
                    </div>
                    <div id="qmOtherFeesRow"
                        style="display: none; flex-direction: row; justify-content: space-between; font-size: 1.1rem; color: #000000;">
                        <span>Additional Fees</span>
                        <span id="qmOtherFees">₱0.00</span>
                    </div>
                    <div id="qmExtraVehiclesTotalRow"
                        style="display: none; flex-direction: row; justify-content: space-between; font-size: 1.1rem; color: #000000;">
                        <span id="qmExtraVehiclesLabel">Additional Vehicles</span>
                        <span id="qmExtraVehiclesTotal">₱0.00</span>
                    </div>
                    <div
                        style="border-top: 1px solid #e2e8f0; padding-top: 12px; display: flex; justify-content: space-between; align-items: baseline;">
                        <span style="font-size: 0.95rem; color: #0f172a;">Total</span>
                        <span style="font-size: 1.2rem; color: #0f172a;" id="qmTotalAmount">₱0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.82rem; color: #64748b; margin-top: 4px;">
                        <span>VAT (12%)</span>
                        <span id="qmVatAmount">₱0.00</span>
                    </div>
                </div>
            </div>

            <div id="qmUnitSection"
                style="border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 20px; display: none;">
                <div style="padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                    <span
                        style="font-size: 1.1rem; font-weight: 700; color: #000000; text-transform: uppercase; letter-spacing: 0.05em;">Assign
                        Unit</span>
                </div>
                <div style="padding: 16px;">
                    <label style="display: block; font-size: 1.1rem; color: #000000; margin-bottom: 6px;">Available
                        Unit <span style="color: #dc2626;">*</span></label>

                    <!-- Truck class filter pills -->
                    <div id="qmClassFilterRow"
                        style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap;">
                        <span style="font-size:0.78rem;color:#64748b;align-self:center;margin-right:4px;">
                            Filter by class:
                        </span>
                        <button type="button" onclick="filterUnitsByClass('all')" id="qmClassBtn-all"
                            style="padding:4px 12px;border:1px solid #d1d5db;background:#0f172a;color:#fff;
                                   font-size:0.75rem;font-weight:700;cursor:pointer;">All</button>
                        <button type="button" onclick="filterUnitsByClass('light')" id="qmClassBtn-light"
                            style="padding:4px 12px;border:1px solid #bfdbfe;background:#fff;color:#1d4ed8;
                                   font-size:0.75rem;font-weight:700;cursor:pointer;">Light</button>
                        <button type="button" onclick="filterUnitsByClass('medium')" id="qmClassBtn-medium"
                            style="padding:4px 12px;border:1px solid #bbf7d0;background:#fff;color:#15803d;
                                   font-size:0.75rem;font-weight:700;cursor:pointer;">Medium</button>
                        <button type="button" onclick="filterUnitsByClass('heavy')" id="qmClassBtn-heavy"
                            style="padding:4px 12px;border:1px solid #fed7aa;background:#fff;color:#c2410c;
                                   font-size:0.75rem;font-weight:700;cursor:pointer;">Heavy</button>
                    </div>

                    <!-- Unit count hint -->
                    <div id="qmUnitCountHint"
                        style="font-size:0.75rem;color:#94a3b8;margin-bottom:8px;"></div>

                    <select id="qmUnitSelect"
                        style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; font-size: 0.9rem; color: #0f172a; outline: none; box-sizing: border-box; background: #fff;">
                        <option value="">Select a unit</option>
                        @forelse($availableUnits as $unit)
                            <option value="{{ $unit['id'] }}"
                                    data-base-rate="{{ $unit['base_rate'] ?? 0 }}"
                                    data-truck-class="{{ strtolower($unit['truck_class'] ?? '') }}"
                                    data-truck-type="{{ $unit['truck_type'] ?? '' }}">
                                {{ $unit['label'] }} · {{ $unit['team_leader_name'] }}
                            </option>
                        @empty
                            <option value="" disabled>No online ready units available</option>
                        @endforelse
                    </select>
                    <small style="font-size: 0.78rem; color: #94a3b8; margin-top: 6px; display: block;">Selecting a
                        unit adds the unit base rate to the total. Unit must be assigned before sending.</small>
                </div>
            </div>

            <!-- Pricing section: dual-mode -->
            <div style="border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 20px;">
                <div
                    style="padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                    <span
                        style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Adjust
                        Price</span>
                </div>
                <div style="padding: 16px; display: grid; gap: 14px;">

                    <!-- PENDING: direct final-price input -->
                    <div id="qmDirectPriceBlock">
                        <label style="font-size:0.8rem;font-weight:600;color:#374151;display:block;margin-bottom:6px;">
                            Quoted Price (₱)
                            <span id="qmSuggestedPriceHint" style="font-weight:400;color:#94a3b8;"></span>
                        </label>
                        <input type="number" id="qmFinalPriceInput" step="0.01" min="0.01"
                            style="width:100%;padding:9px 12px;border:1px solid #d1d5db;
                                   font-size:1rem;color:#0f172a;box-sizing:border-box;"
                            onfocusin="this.style.borderColor='#6366f1'"
                            onfocusout="this.style.borderColor='#d1d5db'">
                    </div>

                    <!-- SENT / NEGOTIATING: delta adjustment -->
                    <div id="qmDeltaBlock" style="display:none;">
                        <div id="qmCurrentPriceRow"
                            style="display:flex;justify-content:space-between;padding:8px 12px;
                                   background:#eff6ff;border:1px solid #bfdbfe;margin-bottom:8px;">
                            <span style="font-size:0.82rem;color:#1d4ed8;">Current Sent Price</span>
                            <span id="qmCurrentPriceDisplay" style="font-size:0.88rem;font-weight:700;color:#1d4ed8;">₱0.00</span>
                        </div>
                        <label style="font-size:0.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;">
                            Price Adjustment (₱) — negative to reduce
                        </label>
                        <input type="number" id="qmOtherFeesInput" step="0.01" value="0.00"
                            style="width:100%;padding:9px 12px;border:1px solid #d1d5db;
                                   font-size:0.9rem;color:#0f172a;box-sizing:border-box;"
                            onfocusin="this.style.borderColor='#6366f1'"
                            onfocusout="this.style.borderColor='#d1d5db'">
                    </div>

                    <!-- Shared: new total preview -->
                    <div style="background:#f1f5f9;padding:12px 14px;display:flex;
                                justify-content:space-between;align-items:center;">
                        <span style="font-size:1.1rem;color:#475569;">New Total</span>
                        <span style="font-size:1.1rem;color:#0f172a;" id="qmCalculatedPrice">₱0.00</span>
                    </div>

                    <!-- Note field -->
                    <div>
                        <label
                            style="display: block; font-size: 0.8rem; font-family: sans-serif; color: #000000; margin-bottom: 6px;">Note
                            <span style="color: #94a3b8;">(optional)</span></label>
                        <textarea id="qmPriceNote" rows="2" placeholder="e.g. Rush fee, toll charges…"
                            style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; font-size: 0.88rem; color: #0f172a; resize: vertical; outline: none; box-sizing: border-box;"
                            onfocusin="this.style.borderColor='#6366f1'" onfocusout="this.style.borderColor='#d1d5db'"></textarea>
                    </div>
                </div>
            </div>

            <div id="qmCounterOfferSection"
                style="display: none; border: 1px solid #fde68a; overflow: hidden; margin-bottom: 20px;">
                <div style="padding: 12px 16px; background: #fffbeb; border-bottom: 1px solid #fde68a;">
                    <span
                        style="font-size: 0.75rem; font-weight: 700; color: #92400e; text-transform: uppercase; letter-spacing: 0.05em;">Customer
                        Counter Offer</span>
                </div>
                <div
                    style="padding: 14px 16px; display: grid; grid-template-columns: auto 1fr; gap: 8px 12px; font-size: 0.88rem; align-items: start;">
                    <span style="color: #78350f; font-weight: 600;">Amount:</span>
                    <span style="color: #0f172a; font-weight: 700;" id="qmCounterOfferAmount">—</span>
                    <span style="color: #78350f; font-weight: 600;">Message:</span>
                    <span style="color: #374151;" id="qmCounterOfferNote">—</span>
                </div>
            </div>

            <!-- Price Change History -->
            <div id="qmPriceHistorySection" style="display:none;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:20px;">
                <div style="padding:10px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;cursor:pointer;"
                     onclick="qmTogglePriceHistory()">
                    <span style="font-size:0.72rem;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;">Price Change History</span>
                    <span id="qmPriceHistoryChevron" style="font-size:0.9rem;color:#94a3b8;">▼</span>
                </div>
                <div id="qmPriceHistoryBody" style="padding:12px 16px;display:grid;gap:8px;"></div>
            </div>

        </div>

        <div
            style="padding: 16px 24px; border-top: 1px solid #f1f5f9; display: flex; gap: 8px; justify-content: flex-end; background: #fff; border-radius: 0 0 16px 16px;">
            <button type="button" onclick="closeQuotationModal()"
                style="padding: 9px 18px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff; color: #64748b; font-size: 0.875rem; font-weight: 600; cursor: pointer;">
                Close
            </button>
            <button type="button" id="qmCancelQuotationBtn" onclick="cancelQuotation()"
                style="padding: 9px 18px; border-radius: 8px; border: none; background: #fff; color: #dc2626; font-size: 0.875rem; font-weight: 600; cursor: pointer; border: 1px solid #fca5a5;">
                Cancel Quotation
            </button>
            <button type="button" id="qmUpdatePriceBtn" onclick="updateQuotationPrice()"
                style="padding: 9px 18px; border-radius: 8px; border: none; background: #334155; color: #fff; font-size: 0.875rem; font-weight: 600; cursor: pointer;">
                Update Price
            </button>
            <button type="button" id="qmSendBtn" onclick="sendQuotationFromModal()"
                style="padding: 9px 20px; border-radius: 8px; border: none; background: #2563eb; color: #fff; font-size: 0.875rem; font-weight: 700; cursor: pointer;">
                Send to Customer
            </button>
        </div>

    </div>
</div>

<script>
    let currentQuotationId = null;

    function viewQuotationDetails(quotationId) {
        currentQuotationId = quotationId;

        const modal = document.getElementById('quotationModal');
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');

        showModalMessage('Loading...', 'info');

        fetch(`/admin-dashboard/quotations/${quotationId}/details`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                credentials: 'same-origin'
            })
            .then(r => {
                if (!r.ok) {
                    return r.text().then(text => {
                        throw new Error(`HTTP ${r.status}: ${text.substring(0, 100)}`);
                    });
                }
                return r.json();
            })
            .then(data => {
                const loadingMsg = document.getElementById('qmMessage');
                if (loadingMsg) loadingMsg.remove();

                if (!data.success) {
                    showModalMessage(data.message || 'Failed to load quotation', 'error');
                    return;
                }

                const q = data.quotation;

                // Customer info
                document.getElementById('qmCustomerName').textContent = q.customer_name || '—';
                document.getElementById('qmCustomerPhone').textContent = q.customer_phone || '—';
                document.getElementById('qmCustomerEmail').textContent = q.customer_email || '—';
                document.getElementById('qmQuotationNumber').textContent = q.quotation_number || '—';
                document.getElementById('qmPickupAddress').textContent = q.pickup_address || '—';
                document.getElementById('qmDropoffAddress').textContent = q.dropoff_address || '—';
                document.getElementById('qmDistance').textContent = q.distance_km ? `${q.distance_km} km` : '—';
                document.getElementById('qmTruckType').textContent = q.truck_type || '—';

                // Mobile banner
                const mobileBanner = document.getElementById('qmMobileBanner');
                if (mobileBanner) {
                    mobileBanner.style.display = q.is_mobile_booking ? 'flex' : 'none';
                    if (q.is_mobile_booking)
                        document.getElementById('qmSourceBookingCode').textContent = q.source_booking_code || '—';
                }

                // Truck class badge
                const classBadge = document.getElementById('qmTruckClassBadge');
                if (classBadge) {
                    const classMap = {
                        Heavy:  {bg:'#fff7ed', color:'#c2410c', border:'#fed7aa'},
                        Medium: {bg:'#f0fdf4', color:'#15803d', border:'#bbf7d0'},
                        Light:  {bg:'#eff6ff', color:'#1d4ed8', border:'#bfdbfe'},
                    };
                    const cs = classMap[q.truck_class] || classMap.Light;
                    if (q.truck_class) {
                        Object.assign(classBadge.style, {
                            display: 'inline-block',
                            background: cs.bg,
                            color: cs.color,
                            borderColor: cs.border
                        });
                        classBadge.textContent = q.truck_class + ' Duty';
                    } else {
                        classBadge.style.display = 'none';
                    }
                }

                // Customer vehicle section
                const vehSection = document.getElementById('qmCustomerVehicleSection');
                const hasVeh = q.vehicle_make || q.vehicle_model || q.vehicle_plate_number;
                if (vehSection) {
                    vehSection.style.display = hasVeh ? 'block' : 'none';
                    if (hasVeh) {
                        document.getElementById('qmVehicleMakeModel').textContent =
                            [q.vehicle_make, q.vehicle_model].filter(Boolean).join(' ') || '—';
                        document.getElementById('qmVehicleYear').textContent  = q.vehicle_year  || '—';
                        document.getElementById('qmVehicleColor').textContent = q.vehicle_color || '—';
                        document.getElementById('qmVehiclePlate').textContent = q.vehicle_plate_number || '—';
                    }
                }

                // Pickup notes
                const notesSection = document.getElementById('qmNotesSection');
                if (notesSection) {
                    notesSection.style.display = q.notes ? 'block' : 'none';
                    if (q.notes) document.getElementById('qmNotesText').textContent = q.notes;
                }

                // Extra vehicles
                const evSection = document.getElementById('qmExtraVehiclesSection');
                const evList = document.getElementById('qmExtraVehiclesList');
                const evCount = document.getElementById('qmVehicleCount');
                evList.innerHTML = '';
                if (q.extra_vehicles && q.extra_vehicles.length > 0) {
                    evSection.style.display = 'block';
                    evCount.textContent = `(${q.total_vehicles} vehicles total)`;
                    q.extra_vehicles.forEach(function(ev) {
                        const isScheduled = ev.service_type === 'schedule';
                        const priceText = isScheduled ? 'TBD (scheduled)' :
                            `₱${parseFloat(ev.estimated_price || 0).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2})}`;
                        const row = document.createElement('div');
                        row.style.cssText =
                            'padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;';
                        const classBadgeStyle = ev.truck_class === 'Heavy'
                            ? 'background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;'
                            : ev.truck_class === 'Medium'
                                ? 'background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;'
                                : 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;';
                        row.innerHTML = `
                            <div style="font-weight:700;color:#0f172a;margin-bottom:4px;">
                                ${ev.truck_type_name || 'Tow Truck'}
                                ${ev.truck_class ? `<span style="font-size:0.65rem;padding:2px 6px;${classBadgeStyle}font-weight:700;text-transform:uppercase;margin-left:4px;">${ev.truck_class}</span>` : ''}
                                ${isScheduled ? `<span style="background:#e0f2fe;color:#075985;font-size:0.7rem;padding:2px 6px;font-weight:700;margin-left:4px;">Scheduled</span>` : ''}
                            </div>
                            <div style="color:#64748b;">
                                ${ev.vehicle_name ? 'Vehicle: <strong>' + ev.vehicle_name + '</strong> &nbsp;·&nbsp; ' : ''}
                                Est. Price: <strong style="color:#0f172a;">${priceText}</strong>
                                ${ev.scheduled_date ? ' · ' + ev.scheduled_date : ''}
                            </div>`;
                        evList.appendChild(row);
                    });
                } else {
                    evSection.style.display = 'none';
                }

                // Vehicle image gallery
                const gallery = document.getElementById('qmImageGallery');
                const grid = document.getElementById('qmImageGrid');
                const noImg = document.getElementById('qmNoImages');
                grid.innerHTML = '';
                gallery.style.display = 'block';
                const imagePaths = q.vehicle_image_paths || [];
                if (imagePaths.length === 0) {
                    noImg.style.display = 'block';
                } else {
                    noImg.style.display = 'none';
                    imagePaths.forEach(function(path) {
                        const img = document.createElement('img');
                        img.src = '/storage/' + path;
                        img.title = 'Click to view full size';
                        img.style.cssText =
                            'width:100px;height:78px;object-fit:cover;border-radius:8px;cursor:pointer;border:1px solid #e2e8f0;transition:opacity 0.15s;';
                        img.onerror = function() { this.style.display = 'none'; };
                        img.onmouseover = function() { this.style.opacity = '0.8'; };
                        img.onmouseout  = function() { this.style.opacity = '1'; };
                        img.onclick = function() { window.open(this.src, '_blank'); };
                        grid.appendChild(img);
                    });
                }

                // Price data
                window.qmCustomerPrice    = parseFloat(q.subtotal || 0);
                window.qmEstimatedPrice   = parseFloat(q.estimated_price || 0);
                window.qmCurrentStatus    = q.status;
                window.qmBasePrice        = parseFloat(q.base_price || 0);
                document.getElementById('qmPriceNote').value = '';

                const distanceKm    = parseFloat(q.distance_km || 0);
                const extraDist     = Math.max(0, distanceKm - 1);
                const distanceFee   = Math.round(extraDist * 300 * 100) / 100;

                document.getElementById('qmBasePrice').textContent =
                    window.qmBasePrice > 0 ? fmt(window.qmBasePrice) : 'TBD';
                document.getElementById('qmDistanceFee').textContent = fmt(distanceFee);
                document.getElementById('qmDistanceFeeLabel').textContent =
                    `Distance (${extraDist.toFixed(2)} km extra × ₱300)`;

                document.getElementById('qmOtherFeesRow').style.display = 'none';
                window.qmDistanceFee = distanceFee;

                // Price change history
                const histSection = document.getElementById('qmPriceHistorySection');
                const histBody    = document.getElementById('qmPriceHistoryBody');
                const priceLog    = q.price_change_log || [];
                if (histSection && histBody) {
                    histBody.innerHTML = '';
                    if (priceLog.length > 0) {
                        histSection.style.display = 'block';
                        priceLog.forEach(function(entry) {
                            const row = document.createElement('div');
                            row.style.cssText = 'padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:0.82rem;';
                            const dt = entry.at ? new Date(entry.at).toLocaleString('en-PH', {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
                            row.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;">
                                <span style="color:#64748b;">${dt}</span>
                                <span style="color:#0f172a;font-weight:700;">${fmt(entry.old || 0)} → ${fmt(entry.new || 0)}</span>
                            </div>
                            ${entry.reason ? `<div style="color:#78350f;font-size:0.78rem;">${entry.reason}</div>` : ''}
                            <div style="color:#94a3b8;font-size:0.75rem;">by ${entry.by || 'Dispatcher'}</div>`;
                            histBody.appendChild(row);
                        });
                    } else {
                        histSection.style.display = 'none';
                    }
                }

                // Extra vehicles total (non-scheduled only)
                const evTotal = (q.extra_vehicles || []).reduce(function(s, ev) {
                    return ev.service_type !== 'schedule' ? s + parseFloat(ev.estimated_price || 0) : s;
                }, 0);
                window.qmExtraVehiclesTotal = evTotal;
                const evTotalRow = document.getElementById('qmExtraVehiclesTotalRow');
                if (evTotalRow) {
                    if (evTotal > 0) {
                        const evCnt = (q.extra_vehicles || []).filter(function(ev) {
                            return ev.service_type !== 'schedule';
                        }).length;
                        document.getElementById('qmExtraVehiclesLabel').textContent = 'Additional Vehicles (' + evCnt + ')';
                        document.getElementById('qmExtraVehiclesTotal').textContent = fmt(evTotal);
                        evTotalRow.style.display = 'flex';
                    } else {
                        evTotalRow.style.display = 'none';
                    }
                }
                document.getElementById('qmTotalAmount').textContent = fmt(distanceFee + evTotal);

                // Show unit section for draft/pending quotations
                const unitSection = document.getElementById('qmUnitSection');
                const unitSelect  = document.getElementById('qmUnitSelect');
                if (q.status === 'draft' || q.status === 'pending') {
                    if (unitSection) unitSection.style.display = 'block';
                    if (unitSelect) {
                        unitSelect.onchange = function() {
                            const selOpt  = this.options[this.selectedIndex];
                            const newBase = this.value ? parseFloat(selOpt.getAttribute('data-base-rate') || 0) : 0;
                            if (newBase > 0) {
                                window.qmBasePrice = newBase;
                                const newSuggested = newBase + (window.qmDistanceFee || 0) + (window.qmExtraVehiclesTotal || 0);
                                document.getElementById('qmBasePrice').textContent     = fmt(newBase);
                                document.getElementById('qmFinalPriceInput').value     = newSuggested.toFixed(2);
                                document.getElementById('qmSuggestedPriceHint').textContent = '(suggested: ' + fmt(newSuggested) + ')';
                            }
                            recalcQuotationTotal();
                        };
                        const firstAvail = Array.from(unitSelect.options).find(o => o.value && !o.disabled && !o.hidden);
                        unitSelect.value = firstAvail ? firstAvail.value : '';
                        if (firstAvail) unitSelect.dispatchEvent(new Event('change'));
                    }
                } else {
                    if (unitSection) unitSection.style.display = 'none';
                }

                // Dual-mode pricing setup
                const directBlock   = document.getElementById('qmDirectPriceBlock');
                const deltaBlock    = document.getElementById('qmDeltaBlock');
                const finalInput    = document.getElementById('qmFinalPriceInput');
                const suggestedHint = document.getElementById('qmSuggestedPriceHint');
                const otherFeesInput = document.getElementById('qmOtherFeesInput');

                if (q.status === 'draft' || q.status === 'pending') {
                    directBlock.style.display = 'block';
                    deltaBlock.style.display  = 'none';
                    const suggested = (window.qmBasePrice || 0) + (window.qmDistanceFee || 0) + (window.qmExtraVehiclesTotal || 0);
                    if (finalInput) finalInput.value = suggested.toFixed(2);
                    if (suggestedHint) suggestedHint.textContent = '(suggested: ' + fmt(suggested) + ')';
                    if (finalInput) finalInput.oninput = recalcQuotationTotal;
                } else if (q.status !== 'draft') {
                    directBlock.style.display = 'none';
                    deltaBlock.style.display  = 'block';
                    if (otherFeesInput) otherFeesInput.value = '0.00';
                    document.getElementById('qmCurrentPriceDisplay').textContent = fmt(window.qmEstimatedPrice || 0);
                    if (otherFeesInput) otherFeesInput.oninput = function() {
                        const fees    = parseFloat(this.value || 0);
                        const otherRow = document.getElementById('qmOtherFeesRow');
                        if (fees !== 0) {
                            otherRow.style.display = 'flex';
                            document.getElementById('qmOtherFees').textContent = fees >= 0 ? fmt(fees) : `- ${fmt(Math.abs(fees))}`;
                        } else {
                            otherRow.style.display = 'none';
                        }
                        recalcQuotationTotal();
                    };
                }

                // Initial calc
                recalcQuotationTotal();

                // Auto-filter units by customer's requested truck class
                if ((q.status === 'draft' || q.status === 'pending') && q.truck_class) {
                    filterUnitsByClass(q.truck_class.toLowerCase());
                } else {
                    filterUnitsByClass('all');
                }

                // Counter offer
                const counterSection = document.getElementById('qmCounterOfferSection');
                if (q.counter_offer_amount) {
                    counterSection.style.display = 'block';
                    document.getElementById('qmCounterOfferAmount').textContent = fmt(q.counter_offer_amount);
                    document.getElementById('qmCounterOfferNote').textContent = q.response_note || 'No message';
                } else {
                    counterSection.style.display = 'none';
                }

                // Button visibility
                const sendBtn   = document.getElementById('qmSendBtn');
                const updateBtn = document.getElementById('qmUpdatePriceBtn');
                const cancelBtn = document.getElementById('qmCancelQuotationBtn');
                if (q.status === 'draft') {
                    sendBtn.style.display = 'inline-block';
                    sendBtn.textContent = 'Send to Customer';
                    updateBtn.textContent = 'Save Changes';
                    if (cancelBtn) cancelBtn.style.display = 'inline-block';
                } else if (q.status === 'pending') {
                    sendBtn.style.display = 'inline-block';
                    sendBtn.textContent = 'Send to Customer';
                    updateBtn.textContent = 'Update & Send';
                    if (cancelBtn) cancelBtn.style.display = 'inline-block';
                } else {
                    sendBtn.style.display = 'none';
                    updateBtn.textContent = 'Revise & Resend';
                    if (cancelBtn) cancelBtn.style.display = q.status === 'sent' ? 'inline-block' : 'none';
                }
            })
            .catch(err => {
                showModalMessage(`Error: ${err.message}`, 'error');
            });
    }

    function recalcQuotationTotal() {
        const vatEl = document.getElementById('qmVatAmount');
        if (window.qmCurrentStatus === 'draft' || window.qmCurrentStatus === 'pending') {
            const typed = parseFloat(document.getElementById('qmFinalPriceInput')?.value || 0);
            document.getElementById('qmCalculatedPrice').textContent = fmt(typed);
            if (document.getElementById('qmTotalAmount'))
                document.getElementById('qmTotalAmount').textContent = fmt(typed);
            // VAT is additive — if typed is already VAT-inclusive, extract it; otherwise show 0
            if (vatEl) vatEl.textContent = fmt(typed * 0.12 / 1.12);
        } else {
            const fees     = parseFloat(document.getElementById('qmOtherFeesInput')?.value || 0);
            const newTotal = Math.max(0, (window.qmEstimatedPrice || 0) + fees);
            document.getElementById('qmCalculatedPrice').textContent = fmt(newTotal);
            if (vatEl) vatEl.textContent = fmt(newTotal * 0.12 / 1.12);
        }
    }

    function qmTogglePriceHistory() {
        const body = document.getElementById('qmPriceHistoryBody');
        const chevron = document.getElementById('qmPriceHistoryChevron');
        if (!body) return;
        const hidden = body.style.display === 'none';
        body.style.display = hidden ? 'grid' : 'none';
        if (chevron) chevron.textContent = hidden ? '▲' : '▼';
    }

    window.filterUnitsByClass = function(cls) {
        const select  = document.getElementById('qmUnitSelect');
        const hint    = document.getElementById('qmUnitCountHint');
        const allBtns = ['all', 'light', 'medium', 'heavy'];

        allBtns.forEach(function(c) {
            const btn = document.getElementById('qmClassBtn-' + c);
            if (!btn) return;
            const activeStyles = {
                all:    {bg:'#0f172a', color:'#fff',    border:'#d1d5db'},
                light:  {bg:'#1d4ed8', color:'#fff',    border:'#bfdbfe'},
                medium: {bg:'#15803d', color:'#fff',    border:'#bbf7d0'},
                heavy:  {bg:'#c2410c', color:'#fff',    border:'#fed7aa'},
            };
            const inactiveStyles = {
                all:    {bg:'#fff', color:'#374151', border:'#d1d5db'},
                light:  {bg:'#fff', color:'#1d4ed8', border:'#bfdbfe'},
                medium: {bg:'#fff', color:'#15803d', border:'#bbf7d0'},
                heavy:  {bg:'#fff', color:'#c2410c', border:'#fed7aa'},
            };
            const s = (c === cls) ? activeStyles[c] : inactiveStyles[c];
            btn.style.background   = s.bg;
            btn.style.color        = s.color;
            btn.style.borderColor  = s.border;
        });

        if (!select) return;

        let visibleCount = 0;
        Array.from(select.options).forEach(function(opt) {
            if (!opt.value) return;
            const optClass = (opt.getAttribute('data-truck-class') || '').toLowerCase();
            const matches  = cls === 'all' || optClass === cls;
            opt.hidden   = !matches;
            opt.disabled = !matches;
            if (matches) visibleCount++;
        });

        const firstVisible = Array.from(select.options).find(
            function(o) { return o.value && !o.hidden; }
        );
        select.value = firstVisible ? firstVisible.value : '';
        recalcQuotationTotal();

        if (hint) {
            hint.textContent = visibleCount > 0
                ? visibleCount + ' unit' + (visibleCount === 1 ? '' : 's') + ' available'
                : 'No units available for this class — try a different class.';
            hint.style.color = visibleCount > 0 ? '#94a3b8' : '#ef4444';
        }
    };

    function fmt(val) {
        return `₱${parseFloat(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }

    function closeQuotationModal() {
        const modal = document.getElementById('quotationModal');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        currentQuotationId = null;
    }

    function updateQuotationPrice() {
        if (!currentQuotationId) return;

        const unitSelect    = document.getElementById('qmUnitSelect');
        const unitSection   = document.getElementById('qmUnitSection');
        const unitRequired  = unitSection && unitSection.style.display !== 'none';
        if (unitRequired && unitSelect && !unitSelect.value) {
            showModalMessage('Please assign a unit before updating the quotation.', 'error');
            unitSelect.focus();
            return;
        }

        let newPrice, otherFees = 0;
        if (window.qmCurrentStatus === 'pending') {
            newPrice = parseFloat(document.getElementById('qmFinalPriceInput')?.value || 0);
        } else {
            otherFees = parseFloat(document.getElementById('qmOtherFeesInput')?.value || 0);
            newPrice  = Math.max(0, (window.qmEstimatedPrice || 0) + otherFees);
        }

        const note           = document.getElementById('qmPriceNote').value;
        const assignedUnitId = unitSelect ? (unitSelect.value || null) : null;

        if (newPrice <= 0) {
            showModalMessage('New price must be greater than zero.', 'error');
            return;
        }

        const btn = document.getElementById('qmUpdatePriceBtn');
        btn.disabled = true;
        btn.textContent = 'Updating...';

        fetch(`/admin-dashboard/quotations/${currentQuotationId}/update-price`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    new_price: newPrice,
                    additional_fee: otherFees,
                    assigned_unit_id: assignedUnitId,
                    note: note
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showModalMessage(data.message || 'Price updated', 'success');
                    setTimeout(() => {
                        closeQuotationModal();
                        location.reload();
                    }, 1500);
                } else {
                    showModalMessage(data.message || 'Failed to update', 'error');
                    btn.disabled = false;
                    btn.textContent = window.qmCurrentStatus === 'pending' ? 'Update & Send' : 'Revise & Resend';
                }
            })
            .catch(() => {
                showModalMessage('Error updating price', 'error');
                btn.disabled = false;
                btn.textContent = window.qmCurrentStatus === 'pending' ? 'Update & Send' : 'Revise & Resend';
            });
    }

    function sendQuotationFromModal() {
        if (!currentQuotationId) return;
        sendQuotationToCustomer(currentQuotationId);
    }

    function sendQuotationToCustomer(quotationId) {
        const id = quotationId || currentQuotationId;
        if (!id) return;

        const unitSelect   = document.getElementById('qmUnitSelect');
        const unitSection  = document.getElementById('qmUnitSection');
        const unitRequired = unitSection && unitSection.style.display !== 'none';
        if (unitRequired && unitSelect && !unitSelect.value) {
            showModalMessage('Please assign a unit before sending the quotation.', 'error');
            unitSelect.focus();
            return;
        }

        const btn          = document.getElementById('qmSendBtn');
        const originalText = btn ? btn.textContent : '';
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Sending...';
        }

        const assignedUnitId = unitSelect ? (unitSelect.value || null) : null;

        fetch(`/admin-dashboard/quotations/${id}/send`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ assigned_unit_id: assignedUnitId })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (document.getElementById('quotationModal').style.display === 'flex') {
                        showModalMessage(data.message || 'Quotation sent to customer via email', 'success');
                        setTimeout(() => {
                            closeQuotationModal();
                            location.reload();
                        }, 2000);
                    } else {
                        alert('✅ ' + (data.message || 'Quotation sent successfully!'));
                        location.reload();
                    }
                } else {
                    if (btn) { btn.disabled = false; btn.textContent = originalText; }
                    if (document.getElementById('quotationModal').style.display === 'flex') {
                        showModalMessage(data.message || 'Failed to send', 'error');
                    } else {
                        alert('❌ ' + (data.message || 'Failed to send quotation'));
                    }
                }
            })
            .catch(err => {
                if (btn) { btn.disabled = false; btn.textContent = originalText; }
                alert('❌ Error sending quotation: ' + err.message);
            });
    }

    function cancelQuotation() {
        if (!currentQuotationId) return;

        const btn = document.getElementById('qmCancelQuotationBtn');
        btn.disabled = true;
        btn.textContent = 'Cancelling...';

        fetch(`/admin-dashboard/quotations/${currentQuotationId}/cancel`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showModalMessage(data.message || 'Quotation cancelled', 'success');
                    setTimeout(() => {
                        closeQuotationModal();
                        location.reload();
                    }, 1500);
                } else {
                    showModalMessage(data.message || 'Failed to cancel', 'error');
                    btn.disabled = false;
                    btn.textContent = 'Cancel Quotation';
                }
            })
            .catch(() => {
                showModalMessage('Error cancelling quotation', 'error');
                btn.disabled = false;
                btn.textContent = 'Cancel Quotation';
            });
    }

    function showModalMessage(message, type) {
        const existing = document.getElementById('qmMessage');
        if (existing) existing.remove();

        const colors = {
            success: { bg: '#f0fdf4', color: '#166534', border: '#86efac', icon: '✓' },
            error:   { bg: '#fef2f2', color: '#991b1b', border: '#fca5a5', icon: '✕' },
            info:    { bg: '#f0f9ff', color: '#0369a1', border: '#7dd3fc', icon: 'ℹ' },
        };
        const c = colors[type] || colors.info;

        const div = document.createElement('div');
        div.id = 'qmMessage';
        div.style.cssText =
            `padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:0.875rem; font-weight:600; background:${c.bg}; color:${c.color}; border:1px solid ${c.border}; display:flex; align-items:center; gap:8px;`;
        div.innerHTML = `<span>${c.icon}</span><span>${message}</span>`;

        const body = document.querySelector('#quotationModal .modal-card > div:nth-child(2)');
        if (body) body.insertBefore(div, body.firstChild);

        if (type !== 'error') {
            setTimeout(() => { if (div.parentNode) div.remove(); }, 5000);
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeQuotationModal();
    });
</script>
