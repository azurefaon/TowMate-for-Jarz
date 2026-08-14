<style>
    /* Quotation modal */
    #quotationModal * {
        box-shadow: none !important;
    }

    #quotationModal .modal-card {
        border: 1px solid #e5e7eb !important;
        border-radius: 16px !important;
        overflow: hidden !important;
    }

    /* Tab buttons */
    .qm-tab-btn {
        padding: 11px 20px;
        border: none;
        border-bottom: 3px solid transparent;
        background: #fff;
        font-size: 0.78rem;
        font-weight: 700;
        color: #6b7280;
        cursor: pointer;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        transition: color 0.12s, border-color 0.12s;
        flex-shrink: 0;
    }
    .qm-tab-btn[data-active="true"] {
        color: #000 !important;
        border-bottom-color: #facc15;
        background: #fff;
    }
    .qm-tab-btn:hover:not([data-active="true"]) {
        color: #111 !important;
        background: #f9fafb;
    }

    /* Section headers */
    .qm-section-hdr {
        padding: 9px 14px;
        background: #111827;
        font-size: 0.7rem;
        font-weight: 800;
        color: #facc15;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    /* Section containers */
    .qm-section {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 16px;
    }

    /* Current price highlight row */
    .qm-current-price-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 14px;
        background: #facc15;
    }

    /* New total row */
    .qm-new-total-row {
        background: #111827;
        border-radius: 8px;
        padding: 11px 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
</style>

<div id="quotationModal"
    style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(15, 23, 42, 0.55); align-items: center; justify-content: center; padding: 20px;"
    aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card"
        style="width: min(1600px, 96vw); max-width: 620px; max-height: 92vh; background: #fff; display: flex; flex-direction: column; border-radius: 16px; overflow: hidden;">

        <!-- HEADER -->
        <div style="padding: 16px 22px 14px; border-bottom: 2px solid #000; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; background: #fff; z-index: 10;">
            <div>
                <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #000; letter-spacing: -0.01em;" id="quotationModalTitle">Quotation Details</h3>
                <p style="margin: 3px 0 0; font-size: 0.78rem; font-weight: 600; color: #6b7280;" id="quotationModalSubtitle">Review and manage this quotation</p>
            </div>
            <button type="button" onclick="closeQuotationModal()"
                style="width: 30px; height: 30px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; color: #000; font-size: 1.2rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1;">
                ×
            </button>
        </div>

        <!-- TAB NAVIGATION -->
        <div style="display: flex; border-bottom: 2px solid #000; background: #fff; flex-shrink: 0;">
            <button class="qm-tab-btn" id="qmTab-details" onclick="qmSetTab('details')" data-active="true">Details</button>
            <button class="qm-tab-btn" id="qmTab-quote"   onclick="qmSetTab('quote')"   data-active="false">Quote</button>
        </div>

        <!-- BODY: TAB PANES -->
        <div style="flex: 1; overflow-y: auto; min-height: 0;">

            <!-- ── PANE: Details ──────────────────────────────────────────── -->
            <div id="qmPane-details" style="display: block; padding: 18px 24px;">

                <!-- Linked Scheduled Vehicles section -->
                <div id="qmLinkedVehicles" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; padding:10px 16px; margin-bottom:14px;">
                    <div style="font-size:0.7rem; font-weight:700; color:#15803d; text-transform:uppercase; letter-spacing:0.07em; margin-bottom:8px;">Linked Scheduled Vehicles</div>
                    <div id="qmLinkedVehiclesList"></div>
                </div>

                <!-- Quotation number + Customer info -->
                <div style="margin-bottom: 18px;">
                    <div style="display: inline-flex; align-items: center; gap: 8px; background: #000; border: 2px solid #000; padding: 6px 13px; margin-bottom: 10px;">
                        <span style="font-size: 0.7rem; color: #facc15; font-weight: 800; text-transform: uppercase; letter-spacing: 0.07em;">Quotation #</span>
                        <span style="font-size: 0.9rem; color: #fff; font-family: monospace; font-weight: 700; letter-spacing: 0.03em;" id="qmQuotationNumber">—</span>
                    </div>
                    <div style="background: #fff; border: 2px solid #000; padding: 12px 16px;">
                        <div style="font-size: 0.7rem; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px;">Customer</div>
                        <div style="font-size: 0.9rem; color: #000; margin-bottom: 2px; font-weight: 700;" id="qmCustomerName">—</div>
                        <div style="font-size: 0.82rem; color: #374151; font-weight: 600;" id="qmCustomerPhone">—</div>
                        <div style="font-size: 0.82rem; color: #374151;" id="qmCustomerEmail">—</div>
                    </div>
                </div>

                <!-- Route section -->
                <div style="background: #fff; border: 2px solid #000; padding: 14px; margin-bottom: 18px;">
                    <div style="font-size: 0.7rem; color: #000; font-weight: 800; text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 10px;">Route</div>
                    <div style="display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px;">
                        <div style="flex-shrink: 0; width: 22px; height: 22px; background: #22c55e; display: flex; align-items: center; justify-content: center; font-size: 0.68rem; color: #fff; margin-top: 1px;">A</div>
                        <div>
                            <div style="font-size: 0.72rem; color: #94a3b8; margin-bottom: 1px;">Pickup</div>
                            <div style="font-size: 0.88rem; color: #0f172a;" id="qmPickupAddress">—</div>
                        </div>
                    </div>
                    <div style="margin-left: 11px; width: 1px; height: 8px; background: #e2e8f0; margin-bottom: 8px;"></div>
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <div style="flex-shrink: 0; width: 22px; height: 22px; background: #ef4444; display: flex; align-items: center; justify-content: center; font-size: 0.68rem; color: #fff; margin-top: 1px;">B</div>
                        <div>
                            <div style="font-size: 0.72rem; color: #94a3b8; margin-bottom: 1px;">Drop-off</div>
                            <div style="font-size: 0.88rem; color: #0f172a;" id="qmDropoffAddress">—</div>
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                        <div>
                            <div style="font-size: 0.72rem; color: #94a3b8; margin-bottom: 2px;">Distance</div>
                            <div style="font-size: 0.88rem; font-weight: 600; color: #0f172a;" id="qmDistance">—</div>
                        </div>
                        <div>
                            <div style="font-size: 0.72rem; color: #94a3b8; margin-bottom: 2px;">Truck Type</div>
                            <div style="font-size: 0.88rem; font-weight: 600; color: #0f172a;" id="qmTruckType">—</div>
                        </div>
                    </div>
                </div>

                <!-- Customer Vehicle section -->
                <div id="qmCustomerVehicleSection"
                    style="display:none; background:#f8fafc; border:1px solid #e2e8f0; padding:12px 16px; margin-bottom:18px;">
                    <div style="font-size:0.7rem; color:#94a3b8; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:8px;">Customer Vehicle</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px 14px; font-size:0.82rem;">
                        <div><span style="color:#94a3b8;">Make / Model</span><br>
                             <span id="qmVehicleMakeModel" style="color:#0f172a; font-weight:600;">—</span></div>
                        <div><span style="color:#94a3b8;">Year</span><br>
                             <span id="qmVehicleYear" style="color:#0f172a; font-weight:600;">—</span></div>
                        <div><span style="color:#94a3b8;">Color</span><br>
                             <span id="qmVehicleColor" style="color:#0f172a; font-weight:600;">—</span></div>
                        <div><span style="color:#94a3b8;">Plate</span><br>
                             <span id="qmVehiclePlate" style="color:#0f172a; font-weight:700; font-family:monospace;">—</span></div>
                    </div>
                </div>

                <!-- Pickup notes section -->
                <div id="qmNotesSection" style="display:none; margin-bottom:18px;">
                    <div style="font-size:0.7rem; color:#94a3b8; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:4px;">Pickup Notes</div>
                    <div id="qmNotesText" style="font-size:0.85rem; color:#0f172a;"></div>
                </div>

                <!-- Vehicle photos -->
                <div id="qmImageGallery"
                    style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 14px; margin-bottom: 18px; display: none;">
                    <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px;">Vehicle Photos</div>
                    <div id="qmImageGrid" style="display: flex; gap: 8px; flex-wrap: wrap;"></div>
                    <p id="qmNoImages" style="color: #94a3b8; font-size: 0.82rem; margin: 0; display: none;">No photos uploaded.</p>
                </div>

                <!-- Extra vehicles section -->
                <div id="qmExtraVehiclesSection"
                    style="display:none; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 18px;">
                    <div style="padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                        <span style="font-size: 0.72rem; color: #000000; text-transform: uppercase; letter-spacing: 0.05em;">Additional Vehicles</span>
                        <span id="qmVehicleCount" style="margin-left:8px; font-size:0.7rem; color:#94a3b8;"></span>
                    </div>
                    <div id="qmExtraVehiclesList" style="padding: 12px 14px; display: grid; gap: 8px;"></div>
                </div>

            </div>

            <!-- ── PANE: Quote (default) ───────────────────────────────────── -->
            <div id="qmPane-quote" style="display: none; padding: 18px 24px;">

                <!-- Price Breakdown -->
                <div class="qm-section">
                    <div class="qm-section-hdr">Price Breakdown</div>
                    <div style="padding: 14px; display: grid; gap: 8px; background: #fff;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 600; color: #000;">
                            <span id="qmBaseRateLabel">Base Rate (Unit)</span>
                            <span id="qmBasePrice">TBD</span>
                        </div>
                        <div id="qmDistanceFeeRow"
                            style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #374151;">
                            <span id="qmDistanceFeeLabel">Distance Fee</span>
                            <span id="qmDistanceFee">₱0.00</span>
                        </div>
                        <div id="qmOtherFeesRow"
                            style="display: none; flex-direction: row; justify-content: space-between; font-size: 0.85rem; color: #374151;">
                            <span>Additional Fees</span>
                            <span id="qmOtherFees">₱0.00</span>
                        </div>
                        <div id="qmOtherFeesNote" style="display: none; padding-left: 4px; font-size: 0.78rem; color: #6b7280;">
                            ↳ <span id="qmOtherFeesNoteText"></span>
                        </div>
                        <div id="qmExtraVehiclesTotalRow"
                            style="display: none; flex-direction: row; justify-content: space-between; font-size: 0.85rem; color: #374151;">
                            <span id="qmExtraVehiclesLabel">Additional Vehicles</span>
                            <span id="qmExtraVehiclesTotal">₱0.00</span>
                        </div>
                        <div style="border-top: 2px solid #e5e7eb; padding-top: 8px; display: flex; justify-content: space-between; font-size: 0.82rem; color: #6b7280;">
                            <span>Subtotal (before VAT)</span>
                            <span id="qmSubtotalAmount">₱0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.78rem; color: #6b7280;">
                            <span>VAT (12%)</span>
                            <span id="qmVatAmount">₱0.00</span>
                        </div>
                        <div style="border-top: 2px solid #000; padding-top: 10px; display: flex; justify-content: space-between; align-items: baseline;">
                            <span style="font-size: 0.9rem; font-weight: 800; color: #000;">Total</span>
                            <span style="font-size: 1.2rem; font-weight: 800; color: #000;" id="qmTotalAmount">₱0.00</span>
                        </div>
                    </div>
                </div>

                <!-- Adjust Price section -->
                <div class="qm-section">
                    <div class="qm-section-hdr">Adjust Price</div>
                    <div style="padding: 14px; display: grid; gap: 12px; background: #fff;">

                        <!-- Current price (readonly display) -->
                        <div class="qm-current-price-row">
                            <span style="font-size:0.82rem; font-weight:800; color:#000; text-transform:uppercase; letter-spacing:0.05em;">Current Price</span>
                            <span id="qmCurrentPriceDisplay" style="font-size:1rem; font-weight:800; color:#000;">₱0.00</span>
                        </div>

                        <!-- Adjustment: +/- toggle + amount -->
                        <div>
                            <label style="font-size:0.78rem; font-weight:600; color:#374151; display:block; margin-bottom:6px;">
                                Adjustment
                            </label>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <button type="button" id="qmSignAdd" onclick="qmSetSign('+')"
                                    style="padding:7px 12px; border:1px solid #16a34a; border-radius:8px; background:#16a34a; color:#fff; font-size:0.82rem; font-weight:700; cursor:pointer; flex-shrink:0;">
                                    + Add
                                </button>
                                <button type="button" id="qmSignDeduct" onclick="qmSetSign('-')"
                                    style="padding:7px 12px; border:1px solid #d1d5db; border-radius:8px; background:#fff; color:#64748b; font-size:0.82rem; font-weight:700; cursor:pointer; flex-shrink:0;">
                                    − Deduct
                                </button>
                                <input type="number" id="qmAdjustAmount" step="0.01" min="0" placeholder="0.00"
                                    style="flex:1; padding:7px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem; color:#0f172a; box-sizing:border-box;"
                                    onkeydown="if(['e','E','+','-'].includes(event.key)) event.preventDefault()"
                                    oninput="recalcQuotationTotal()">
                            </div>
                        </div>

                        <!-- New total preview -->
                        <div class="qm-new-total-row">
                            <span style="font-size:0.82rem; font-weight:800; color:#facc15; text-transform:uppercase; letter-spacing:0.05em;">New Total</span>
                            <span style="font-size:1.05rem; font-weight:800; color:#fff;" id="qmCalculatedPrice">₱0.00</span>
                        </div>

                        <!-- Note / reason -->
                        <div>
                            <label style="display: block; font-size: 0.78rem; color: #000000; margin-bottom: 5px;">
                                Note / Reason
                                <span id="qmNoteRequiredHint" style="display:none; color:#dc2626; font-weight:700;">(required)</span>
                            </label>
                            <textarea id="qmPriceNote" rows="2" placeholder="e.g. Rush fee, toll charges…"
                                style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.85rem; color: #0f172a; resize: vertical; outline: none; box-sizing: border-box;"
                                onfocusin="this.style.borderColor='#facc15'"
                                onfocusout="this.style.borderColor='#d1d5db'"></textarea>
                            <div id="qmNoteError" style="display:none; color:#dc2626; font-size:0.72rem; margin-top:3px;">
                                Please enter a reason for the price change.
                            </div>
                        </div>

                        <!-- Draft saved indicator (Schedule bookings only) -->
                        <div id="qmDraftSavedIndicator"
                            style="display:none; background:#111827; border-radius:10px; padding:11px 14px; align-items:center; justify-content:space-between; gap:8px;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span style="font-size:0.82rem; font-weight:800; background:#facc15; color:#000; padding:2px 8px; text-transform:uppercase; letter-spacing:0.05em;">Recorded</span>
                                <span style="color:#e2e8f0; font-size:0.82rem; font-weight:600;">Ready to send to customer</span>
                            </div>
                            <span id="qmDraftRecordedPrice" style="color:#facc15; font-size:0.95rem; font-weight:800;"></span>
                        </div>

                    </div>
                </div>

                <!-- Dispatch Unit section (accepted quotations only) -->
                <div id="qmDispatchSection" style="display:none; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; margin-bottom: 18px;">
                    <div class="qm-section-hdr">Assign Unit to Dispatch</div>
                    <div style="padding: 14px;">
                        <div id="qmDispatchNoUnits" style="display:none; color:#dc2626; font-size:0.85rem; padding:4px 0 8px;">
                            No online ready units available at the moment.
                        </div>
                        <select id="qmDispatchUnitSelect"
                            style="width:100%; padding:8px 10px; border:1px solid #d1d5db; font-size:0.88rem; color:#0f172a; box-sizing:border-box; background:#fff;">
                            <option value="">Select a unit</option>
                            @forelse($availableUnits as $unit)
                                <option value="{{ $unit['id'] }}">
                                    {{ $unit['label'] }} · {{ $unit['team_leader_name'] }}
                                </option>
                            @empty
                                <option value="" disabled>No online ready units available</option>
                            @endforelse
                        </select>
                        <div id="qmDispatchUnitError" style="display:none; color:#dc2626; font-size:0.72rem; margin-top:4px;">
                            Please select a unit before dispatching.
                        </div>
                    </div>
                </div>

                <!-- Counter offer section -->
                <div id="qmCounterOfferSection"
                    style="display: none; border: 1px solid #fde68a; overflow: hidden; margin-bottom: 18px;">
                    <div style="padding: 10px 14px; background: #fffbeb; border-bottom: 1px solid #fde68a;">
                        <span style="font-size: 0.72rem; font-weight: 700; color: #92400e; text-transform: uppercase; letter-spacing: 0.05em;">Customer Counter Offer</span>
                    </div>
                    <div style="padding: 12px 14px; display: grid; grid-template-columns: auto 1fr; gap: 7px 10px; font-size: 0.85rem; align-items: start;">
                        <span style="color: #78350f; font-weight: 600;">Amount:</span>
                        <span style="color: #0f172a; font-weight: 700;" id="qmCounterOfferAmount">—</span>
                        <span style="color: #78350f; font-weight: 600;">Message:</span>
                        <span style="color: #374151;" id="qmCounterOfferNote">—</span>
                    </div>
                </div>

                <!-- Price History (collapsible) -->
                <div id="qmPriceHistorySection" style="display:none; margin-top:14px; border:1px solid #e2e8f0; overflow:hidden;">
                    <button type="button" onclick="qmTogglePriceHistory()" style="width:100%; padding:10px 14px; background:#f8fafc; border:none; text-align:left; cursor:pointer; font-size:0.78rem; font-weight:600; color:#374151; display:flex; justify-content:space-between; align-items:center;">
                        <span>Price History</span>
                        <span id="qmPriceHistoryCount" style="font-size:0.72rem; background:#e2e8f0; color:#64748b; padding:1px 7px; border-radius:999px;"></span>
                    </button>
                    <div id="qmPriceHistoryBody" style="display:none; padding:12px 14px;"></div>
                </div>

            </div>


        </div>

        <!-- FOOTER -->
        <div style="padding: 14px 24px; border-top: 2px solid #000; display: flex; gap: 8px; justify-content: flex-end; background: #fff; flex-shrink: 0; flex-wrap: wrap;">
            <button type="button" onclick="closeQuotationModal()"
                style="padding: 8px 16px; border: 2px solid #d1d5db; background: #fff; color: #374151; font-size: 0.82rem; font-weight: 700; cursor: pointer;">
                Close
            </button>
            <button type="button" id="qmCancelQuotationBtn" onclick="cancelQuotation()"
                style="padding: 8px 16px; border: 2px solid #dc2626; background: #fff; color: #dc2626; font-size: 0.82rem; font-weight: 700; cursor: pointer;">
                Reject Booking
            </button>
            <button type="button" id="qmDispatchNowBtn" onclick="qmConfirmDispatch()" style="display:none;
                padding: 8px 18px; border: 2px solid #000; background: #facc15; color: #000; font-size: 0.82rem; font-weight: 800; cursor: pointer; text-transform: uppercase; letter-spacing: 0.04em;">
                Confirm Dispatch
            </button>
            <button type="button" id="qmOpenDispatchBtn" onclick="qmShowDispatchPanel()" style="display:none;
                padding: 8px 18px; border: 2px solid #000; background: #0f172a; color: #fff; font-size: 0.82rem; font-weight: 800; cursor: pointer; text-transform: uppercase; letter-spacing: 0.04em;">
                Dispatch Now
            </button>
            <button type="button" id="qmRecordBtn" onclick="qmRecordQuotation()" style="display:none;
                padding: 8px 18px; border: 2px solid #000; background: #fff; color: #000; font-size: 0.82rem; font-weight: 800; cursor: pointer; text-transform: uppercase; letter-spacing: 0.04em;">
                Approve
            </button>
            <button type="button" id="qmUpdatePriceBtn" onclick="updateQuotationPrice()"
                style="padding: 8px 16px; border: 2px solid #000; background: #000; color: #fff; font-size: 0.82rem; font-weight: 700; cursor: pointer;">
                Update Price
            </button>
            <button type="button" id="qmSendBtn" onclick="sendQuotationFromModal()"
                style="padding: 8px 18px; border: 2px solid #000; background: #facc15; color: #000; font-size: 0.82rem; font-weight: 800; cursor: pointer; text-transform: uppercase; letter-spacing: 0.04em;">
                Send to Customer
            </button>
        </div>

    </div>
</div>

<script>
    let currentQuotationId = null;

    window.qmAdjustSign = '+';
    window.qmCurrentBase = 0;

    // Tracks per-modal-open state
    const qmState = {
        activeTab:            'details',
        bookingId:            null,   // source_booking_id for mobile bookings
        sourceBookingCode:    null,   // booking_code used to call the assign endpoint on dispatch
        serviceType:          null,   // 'book_now' | 'scheduled'
        isMobile:             false,
        draftSaved:           false,
        quotationStatus:      '',     // '' | 'pending' | 'draft' | 'sent' | 'negotiating' | ...
    };

    // ── Tab switching ──────────────────────────────────────────────────────────
    function qmSetTab(tab) {
        ['details', 'quote'].forEach(function(t) {
            document.getElementById('qmPane-' + t).style.display = t === tab ? 'block' : 'none';
            const btn = document.getElementById('qmTab-' + t);
            if (btn) btn.setAttribute('data-active', t === tab ? 'true' : 'false');
        });
        qmState.activeTab = tab;
    }

    // ── Vehicle photo gallery (shared by both modal entry points) ───────────────
    function qmRenderVehicleGallery(imagePaths) {
        const gallery = document.getElementById('qmImageGallery');
        const grid    = document.getElementById('qmImageGrid');
        const noImg   = document.getElementById('qmNoImages');
        grid.innerHTML = '';
        gallery.style.display = 'block';
        if (!imagePaths || imagePaths.length === 0) {
            noImg.style.display = 'block';
        } else {
            noImg.style.display = 'none';
            imagePaths.forEach(function(path) {
                const img = document.createElement('img');
                img.src = '/storage/' + path;
                img.title = 'Click to view full size';
                img.style.cssText = 'width:90px; height:70px; object-fit:cover; cursor:pointer; border:1px solid #e2e8f0; transition:opacity 0.15s;';
                img.onerror   = function() { this.style.display = 'none'; };
                img.onmouseover = function() { this.style.opacity = '0.8'; };
                img.onmouseout  = function() { this.style.opacity = '1'; };
                img.onclick   = function() { window.open(this.src, '_blank'); };
                grid.appendChild(img);
            });
        }
    }

    // ── Open modal for a brand-new Schedule booking (no quotation yet) ──────────
    function openNewScheduleQuote(card) {
        const d = card.dataset;

        // Reset global state
        currentQuotationId        = null;
        window.qmCurrentStatus    = 'pending';
        window.qmDistanceKm       = parseFloat(d.distance || 0);
        qmState.bookingId         = d.ref || d.id; // route uses booking_code, not numeric id
        qmState.serviceType       = 'scheduled';
        qmState.isMobile          = true;
        qmState.draftSaved        = false;

        // Modal header
        const title = document.getElementById('quotationModalTitle');
        const sub   = document.getElementById('quotationModalSubtitle');
        if (title) title.textContent = 'New Quotation — Schedule Booking';
        if (sub)   sub.textContent   = (d.customer || 'Customer') + ' · ' + parseFloat(d.distance || 0).toFixed(2) + ' km';

        // Populate Details tab
        const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val || '—'; };
        set('qmQuotationNumber', 'Draft — not yet assigned');
        set('qmCustomerName',    d.customer);
        set('qmCustomerPhone',   d.phone);
        set('qmCustomerEmail',   d.customerEmail);
        set('qmPickupAddress',   d.pickup);
        set('qmDropoffAddress',  d.dropoff);
        set('qmDistance',        parseFloat(d.distance || 0).toFixed(2) + ' km');
        set('qmTruckType',       d.truck);

        // Base rate label — shows truck type
        const baseRateLabelEl = document.getElementById('qmBaseRateLabel');
        if (baseRateLabelEl) baseRateLabelEl.textContent = 'Base Rate · ' + (d.truck || 'Unit') + ' (Scheduled)';

        // Hide vehicle-details/notes sections (no make/model/plate data on scheduled bookings)
        const vs = document.getElementById('qmCustomerVehicleSection');
        const ns = document.getElementById('qmNotesSection');
        if (vs) vs.style.display = 'none';
        if (ns) ns.style.display = 'none';

        // Vehicle photo gallery — images are captured at booking time regardless of service type
        let scheduleVehicleImages = [];
        try {
            scheduleVehicleImages = JSON.parse(d.vehicleImages || '[]');
        } catch (e) {
            scheduleVehicleImages = [];
        }
        qmRenderVehicleGallery(scheduleVehicleImages);

        // Clear quote form and re-enable inputs
        const adjustEl = document.getElementById('qmAdjustAmount');
        const noteInput = document.getElementById('qmPriceNote');
        if (adjustEl)  { adjustEl.value = ''; adjustEl.disabled = false; }
        if (noteInput) noteInput.value = '';
        document.getElementById('qmSignAdd')?.removeAttribute('disabled');
        document.getElementById('qmSignDeduct')?.removeAttribute('disabled');

        // Build price breakdown using the booking's stored values (authoritative)
        const finalTotal  = parseFloat(d.finalTotal || 0);
        const baseRate    = parseFloat(d.baseRate || 0);
        const distanceFee = parseFloat(d.distanceFee || 0);
        const distanceKm  = parseFloat(d.distance || 0);
        const kmIncrements = Math.floor(distanceKm / 4);

        // Store globals used by recalcQuotationTotal and unit-select handler
        window.qmBasePrice          = baseRate;
        window.qmDistanceFee        = distanceFee;
        window.qmExtraVehiclesTotal = 0;
        window.qmCurrentBase        = finalTotal;  // current price = what customer saw
        window.qmAdjustSign         = '+';
        qmSetSign('+');

        // Price Breakdown — base rate row
        const basePriceEl = document.getElementById('qmBasePrice');
        if (basePriceEl) basePriceEl.textContent = baseRate > 0 ? fmt(baseRate) : 'TBD';

        // Price Breakdown — distance fee row
        const distFeeEl   = document.getElementById('qmDistanceFee');
        const distLabelEl = document.getElementById('qmDistanceFeeLabel');
        if (distFeeEl)   distFeeEl.textContent   = distanceKm >= 4 ? fmt(distanceFee) : 'Free';
        if (distLabelEl) distLabelEl.textContent = distanceKm >= 4
            ? `Distance (${distanceKm.toFixed(2)} km × ₱300)`
            : `Distance (${distanceKm.toFixed(2)} km — free under 4 km)`;

        // Subtotal / VAT / Total use final_total as the source of truth
        const displaySub = Math.round(finalTotal / 1.12 * 100) / 100;
        const displayVat = Math.round((finalTotal - displaySub) * 100) / 100;
        document.getElementById('qmSubtotalAmount').textContent = fmt(displaySub);
        document.getElementById('qmVatAmount').textContent      = fmt(displayVat);
        document.getElementById('qmTotalAmount').textContent    = fmt(finalTotal);

        // Adjust Price section — current price & new total start at final_total
        const priceDisplay = document.getElementById('qmCurrentPriceDisplay');
        if (priceDisplay) priceDisplay.textContent = fmt(finalTotal);
        const calcEl = document.getElementById('qmCalculatedPrice');
        if (calcEl) calcEl.textContent = fmt(finalTotal);

        // Hide draft-saved indicator
        const indicator = document.getElementById('qmDraftSavedIndicator');
        if (indicator) indicator.style.display = 'none';

        // Show modal
        const modal = document.getElementById('quotationModal');
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');

        // Land on Details tab, update footer buttons
        qmSetTab('details');
        qmState.quotationStatus = 'pending';
        qmRenderFooterButtons();
    }

    // ── Record Quotation (draft-first flow) ────────────────────────────────────
    async function qmRecordQuotation() {
        if (!qmState.bookingId) {
            showModalMessage('No booking linked — cannot record quotation.', 'error');
            return;
        }

        const unitSelect = document.getElementById('qmUnitSelect');
        const adjustAmt  = parseFloat(document.getElementById('qmAdjustAmount')?.value || 0) || 0;
        const adjustSign = (window.qmAdjustSign === '+') ? 1 : -1;
        const price      = Math.max(0, (window.qmCurrentBase || 0) + adjustSign * adjustAmt);
        const note       = document.getElementById('qmPriceNote')?.value?.trim() || '';

        if (adjustAmt !== 0 && !note) {
            document.getElementById('qmNoteError').style.display = 'block';
            document.getElementById('qmPriceNote').focus();
            return;
        }
        document.getElementById('qmNoteError').style.display = 'none';

        if (price <= 0) {
            showModalMessage('Please enter a price before recording.', 'error');
            return;
        }

        const btn = document.getElementById('qmRecordBtn');
        btn.disabled    = true;
        btn.textContent = 'Approving…';

        const payload = {
            price:            price,
            additional_fee:   adjustSign * adjustAmt,
            assigned_unit_id: unitSelect?.value || null,
            dispatcher_note:  note,
            distance_km:      window.qmDistanceKm || null,
        };

        try {
            const resp = await fetch(`/admin-dashboard/booking/${qmState.bookingId}/save-draft`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload),
            });
            const data = await resp.json();

            if (data.success) {
                currentQuotationId = data.quotation_id;
                qmState.draftSaved      = true;
                qmState.quotationStatus = 'draft';

                // Show "Recorded" indicator with the price
                const indicator = document.getElementById('qmDraftSavedIndicator');
                if (indicator) indicator.style.display = 'flex';
                const recordedPriceEl = document.getElementById('qmDraftRecordedPrice');
                if (recordedPriceEl) recordedPriceEl.textContent = fmt(price);

                // Lock the adjust inputs — price is now recorded
                const adjustInput = document.getElementById('qmAdjustAmount');
                if (adjustInput) { adjustInput.value = ''; adjustInput.disabled = true; }
                document.getElementById('qmSignAdd')?.setAttribute('disabled', 'true');
                document.getElementById('qmSignDeduct')?.setAttribute('disabled', 'true');

                showModalMessage(data.message || 'Quotation recorded. You can now send it to the customer.', 'success');
                qmRenderFooterButtons();
                setTimeout(() => {
                    closeQuotationModal();
                    refreshFloatingQuotationsPanel();
                }, 1500);
            } else {
                showModalMessage(data.message || 'Failed to approve quotation.', 'error');
                btn.disabled    = false;
                btn.textContent = 'Approve';
            }
        } catch (err) {
            showModalMessage('Error approving quotation: ' + err.message, 'error');
            btn.disabled    = false;
            btn.textContent = 'Approve';
        }
    }

    // ── Load & render quotation details ──────────────────────────────────────
    function viewQuotationDetails(quotationId) {
        currentQuotationId = quotationId;

        // Reset state
        qmState.bookingId          = null;
        qmState.sourceBookingCode  = null;
        qmState.serviceType        = null;
        qmState.isMobile           = false;
        qmState.draftSaved         = false;

        const modal = document.getElementById('quotationModal');
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');

        // Start on Details tab
        qmSetTab('details');
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

                // Populate qmState
                qmState.bookingId          = q.source_booking_code || q.source_booking_id || null;
                qmState.sourceBookingCode  = q.source_booking_code || null;
                qmState.serviceType        = q.service_type || 'book_now';
                qmState.isMobile           = !!q.is_mobile_booking;
                window.qmDistanceKm = parseFloat(q.distance_km || 0);

                // ── Details tab content ──────────────────────────────────────

                // Customer info
                document.getElementById('qmCustomerName').textContent  = q.customer_name  || '—';
                document.getElementById('qmCustomerPhone').textContent = q.customer_phone || '—';
                document.getElementById('qmCustomerEmail').textContent = q.customer_email || '—';
                document.getElementById('qmQuotationNumber').textContent = q.quotation_number || '—';
                document.getElementById('qmPickupAddress').textContent  = q.pickup_address  || '—';
                document.getElementById('qmDropoffAddress').textContent = q.dropoff_address || '—';
                document.getElementById('qmDistance').textContent = q.distance_km ? `${q.distance_km} km` : '—';
                document.getElementById('qmTruckType').textContent = q.truck_type || '—';

                // Dynamic base rate label (Change 3)
                const modeLabel = q.service_type === 'schedule' ? 'Scheduled' : 'Book Now';
                const baseLabel = document.getElementById('qmBaseRateLabel');
                if (baseLabel) baseLabel.textContent = 'Base Rate · ' + (q.truck_type || 'Unit') + ' (' + modeLabel + ')';

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
                const evList    = document.getElementById('qmExtraVehiclesList');
                const evCount   = document.getElementById('qmVehicleCount');
                evList.innerHTML = '';
                if (q.extra_vehicles && q.extra_vehicles.length > 0) {
                    evSection.style.display = 'block';
                    evCount.textContent = `(${q.total_vehicles} vehicles total)`;
                    q.extra_vehicles.forEach(function(ev) {
                        const isScheduled = ev.service_type === 'schedule';
                        const priceText = isScheduled ? 'TBD (scheduled)' :
                            `₱${parseFloat(ev.estimated_price || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
                        const row = document.createElement('div');
                        row.style.cssText = 'padding:9px 11px; background:#f8fafc; border:1px solid #e2e8f0; font-size:0.82rem;';
                        const cls = ev.truck_class;
                        const cbs = cls === 'Heavy'  ? 'background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;' :
                                    cls === 'Medium' ? 'background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;' :
                                                       'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;';
                        row.innerHTML = `
                            <div style="font-weight:700; color:#0f172a; margin-bottom:3px;">
                                ${ev.truck_type_name || 'Tow Truck'}
                                ${cls ? `<span style="font-size:0.62rem;padding:2px 5px;${cbs}font-weight:700;text-transform:uppercase;margin-left:4px;">${cls}</span>` : ''}
                                ${isScheduled ? `<span style="background:#e0f2fe;color:#075985;font-size:0.68rem;padding:2px 5px;font-weight:700;margin-left:4px;">Scheduled</span>` : ''}
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
                qmRenderVehicleGallery(q.vehicle_image_paths || []);

                // ── Quote tab content ────────────────────────────────────────

                window.qmCustomerPrice  = parseFloat(q.subtotal || 0);
                window.qmEstimatedPrice = parseFloat(q.estimated_price || 0);
                window.qmCurrentStatus  = q.status;
                window.qmBasePrice      = parseFloat(q.base_price || 0);
                document.getElementById('qmPriceNote').value = '';

                const distanceKm  = parseFloat(q.distance_km || 0);
                const extraDist   = distanceKm;
                const distanceFee = distanceKm >= 4 ? Math.round(extraDist * 300 * 100) / 100 : 0;

                document.getElementById('qmBasePrice').textContent = window.qmBasePrice > 0 ? fmt(window.qmBasePrice) : 'TBD';
                document.getElementById('qmDistanceFee').textContent = distanceKm >= 4 ? fmt(distanceFee) : 'Free';
                document.getElementById('qmDistanceFeeLabel').textContent = distanceKm >= 4
                    ? `Distance (${extraDist.toFixed(2)} km × ₱300)`
                    : `Distance (${extraDist.toFixed(2)} km — free under 4 km)`;
                const otherFeesRow  = document.getElementById('qmOtherFeesRow');
                const otherFeesNote = document.getElementById('qmOtherFeesNote');
                const additionalFee = parseFloat(q.additional_fee || 0) || 0;
                if (additionalFee !== 0) {
                    document.getElementById('qmOtherFees').textContent =
                        (additionalFee < 0 ? '-' : '') + fmt(Math.abs(additionalFee));
                    otherFeesRow.style.display = 'flex';

                    const log = Array.isArray(q.price_change_log) ? q.price_change_log : [];
                    const latestReason = log.length > 0 ? (log[log.length - 1].reason || '') : '';
                    if (latestReason) {
                        document.getElementById('qmOtherFeesNoteText').textContent = latestReason;
                        otherFeesNote.style.display = 'block';
                    } else {
                        otherFeesNote.style.display = 'none';
                    }
                } else {
                    otherFeesRow.style.display = 'none';
                    otherFeesNote.style.display = 'none';
                }
                window.qmDistanceFee = distanceFee;

                // Extra vehicles total — use pre-VAT base (estimated_price is VAT-inclusive, divide by 1.12)
                const evTotal = (q.extra_vehicles || []).reduce(function(s, ev) {
                    if (ev.service_type === 'schedule') return s;
                    return s + Math.round(parseFloat(ev.estimated_price || 0) / 1.12 * 100) / 100;
                }, 0);
                window.qmExtraVehiclesTotal = evTotal;
                const evTotalRow = document.getElementById('qmExtraVehiclesTotalRow');
                if (evTotalRow) {
                    if (evTotal > 0) {
                        const evCnt = (q.extra_vehicles || []).filter(ev => ev.service_type !== 'schedule').length;
                        document.getElementById('qmExtraVehiclesLabel').textContent = 'Additional Vehicles (' + evCnt + ')';
                        document.getElementById('qmExtraVehiclesTotal').textContent = fmt(evTotal);
                        evTotalRow.style.display = 'flex';
                    } else {
                        evTotalRow.style.display = 'none';
                    }
                }

                // Price breakdown — back-calculate from the stored total to avoid rounding
                // drift caused by distance_km being stored at 2 dp instead of full precision.
                if (q.status === 'sent' || q.status === 'negotiating' || q.status === 'draft' || q.status === 'accepted') {
                    const savedTotal    = parseFloat(q.estimated_price || 0);
                    const savedVat      = Math.round(savedTotal / 1.12 * 0.12 * 100) / 100;
                    const savedSub      = Math.round((savedTotal - savedVat) * 100) / 100;
                    const backDistFee   = Math.round((savedSub - (window.qmBasePrice || 0) - evTotal) * 100) / 100;
                    document.getElementById('qmDistanceFee').textContent    = fmt(backDistFee);
                    document.getElementById('qmSubtotalAmount').textContent = fmt(savedSub);
                    document.getElementById('qmVatAmount').textContent      = fmt(savedVat);
                    document.getElementById('qmTotalAmount').textContent    = fmt(savedTotal);
                } else {
                    // pending / no quotation yet — prefer stored booking total (avoids rounding drift)
                    const bookingTotal = parseFloat(q.booking_final_total || 0);
                    if (bookingTotal > 0) {
                        const bVat  = Math.round(bookingTotal / 1.12 * 0.12 * 100) / 100;
                        const bSub  = Math.round((bookingTotal - bVat) * 100) / 100;
                        // back-calculate distance fee so all rows add up exactly
                        const backDistFee = Math.round((bSub - (window.qmBasePrice || 0) - evTotal) * 100) / 100;
                        document.getElementById('qmDistanceFee').textContent    = fmt(backDistFee);
                        document.getElementById('qmSubtotalAmount').textContent = fmt(bSub);
                        document.getElementById('qmVatAmount').textContent      = fmt(bVat);
                        document.getElementById('qmTotalAmount').textContent    = fmt(bookingTotal);
                    } else {
                        const compSubtotal = Math.round(((window.qmBasePrice || 0) + distanceFee + evTotal) * 100) / 100;
                        const tempVat = Math.round(compSubtotal * 0.12 * 100) / 100;
                        document.getElementById('qmSubtotalAmount').textContent = fmt(compSubtotal);
                        document.getElementById('qmVatAmount').textContent      = fmt(tempVat);
                        document.getElementById('qmTotalAmount').textContent    = fmt(compSubtotal + tempVat);
                    }
                }

                // Pricing setup: reset adjustment, set current base
                const adjustAmountEl = document.getElementById('qmAdjustAmount');
                if (adjustAmountEl) { adjustAmountEl.value = ''; adjustAmountEl.disabled = false; }
                document.getElementById('qmSignAdd')?.removeAttribute('disabled');
                document.getElementById('qmSignDeduct')?.removeAttribute('disabled');
                window.qmAdjustSign = '+';
                qmSetSign('+');

                if (q.status === 'draft' || q.status === 'sent' || q.status === 'negotiating' || q.status === 'accepted') {
                    // Always use the saved estimated_price as the base for further adjustments
                    window.qmCurrentBase = parseFloat(q.estimated_price || 0);
                    document.getElementById('qmCurrentPriceDisplay').textContent = fmt(window.qmCurrentBase);
                } else {
                    // pending — use stored booking total to avoid rounding drift from distance precision
                    const bookingTotal = parseFloat(q.booking_final_total || 0);
                    const subtotalInit = (window.qmBasePrice || 0) + (window.qmDistanceFee || 0) + (window.qmExtraVehiclesTotal || 0);
                    const computed     = Math.round(subtotalInit * 1.12 * 100) / 100;
                    const suggested    = bookingTotal > 0 ? bookingTotal : computed;
                    window.qmCurrentBase = suggested;
                    document.getElementById('qmCurrentPriceDisplay').textContent = fmt(suggested);
                }

                recalcQuotationTotal();

                // Counter offer
                const counterSection = document.getElementById('qmCounterOfferSection');
                if (q.counter_offer_amount) {
                    counterSection.style.display = 'block';
                    document.getElementById('qmCounterOfferAmount').textContent = fmt(q.counter_offer_amount);
                    document.getElementById('qmCounterOfferNote').textContent = q.response_note || 'No message';
                } else {
                    counterSection.style.display = 'none';
                }

                // ── Linked scheduled vehicles ────────────────────────────────
                const linkedSection = document.getElementById('qmLinkedVehicles');
                const linkedList    = document.getElementById('qmLinkedVehiclesList');
                if (linkedSection && linkedList) {
                    const siblings = q.group_siblings || [];
                    if (siblings.length > 0) {
                        linkedList.innerHTML = '';
                        siblings.forEach(function(s) {
                            const statusColors = {
                                scheduled:   {bg:'#f1f5f9', color:'#475569', label:'Scheduled'},
                                in_progress: {bg:'#fef9c3', color:'#a16207', label:'In Progress'},
                                completed:   {bg:'#dcfce7', color:'#15803d', label:'Completed'},
                                cancelled:   {bg:'#fee2e2', color:'#dc2626', label:'Cancelled'},
                            };
                            const sc = statusColors[s.status] || statusColors.scheduled;
                            const clsMap = {Heavy:'#c2410c', Medium:'#15803d', Light:'#1d4ed8'};
                            const clsColor = clsMap[s.truck_class] || '#475569';
                            const sched = fmtSchedDateTime(s.scheduled_date, s.scheduled_time);
                            const row = document.createElement('div');
                            row.style.cssText = 'display:flex; align-items:center; gap:10px; padding:7px 0; border-bottom:1px solid #d1fae5; font-size:0.82rem; flex-wrap:wrap;';
                            row.innerHTML = `
                                <span style="font-family:monospace; font-weight:700; color:#15803d;">${s.booking_code}</span>
                                <span style="color:#374151; font-weight:600;">${s.truck_type || '—'}</span>
                                ${s.truck_class ? `<span style="font-size:0.65rem; font-weight:700; color:${clsColor}; text-transform:uppercase;">${s.truck_class}</span>` : ''}
                                <span style="color:#64748b;">· ${sched}</span>
                                <span style="margin-left:auto; font-size:0.72rem; font-weight:700; padding:2px 8px; border-radius:999px; background:${sc.bg}; color:${sc.color};">${sc.label}</span>
                            `;
                            linkedList.appendChild(row);
                        });
                        linkedSection.style.display = 'block';
                    } else {
                        linkedSection.style.display = 'none';
                    }
                }

                // ── Price history ────────────────────────────────────────────
                const phSection = document.getElementById('qmPriceHistorySection');
                const phBody    = document.getElementById('qmPriceHistoryBody');
                const phCount   = document.getElementById('qmPriceHistoryCount');
                if (phSection && phBody) {
                    const log = q.price_change_log || [];
                    if (log.length > 0) {
                        if (phCount) phCount.textContent = log.length;
                        phBody.innerHTML = '';
                        log.forEach(function(entry) {
                            const row = document.createElement('div');
                            row.style.cssText = 'padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:0.8rem; color:#374151;';
                            const ts = entry.at ? new Date(entry.at).toLocaleString('en-PH', {month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit'}) : '—';
                            const oldP = `₱${parseFloat(entry.old||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
                            const newP = `₱${parseFloat(entry.new||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
                            row.innerHTML = `
                                <span style="color:#94a3b8; font-size:0.72rem;">${ts}</span>
                                &nbsp;
                                <span style="color:#dc2626; text-decoration:line-through;">${oldP}</span>
                                &nbsp;→&nbsp;
                                <strong style="color:#16a34a;">${newP}</strong>
                                ${entry.reason ? `&nbsp;·&nbsp;<em style="color:#64748b;">"${entry.reason}"</em>` : ''}
                                ${entry.by ? `&nbsp;·&nbsp;<span style="color:#94a3b8;">by ${entry.by}</span>` : ''}
                            `;
                            phBody.appendChild(row);
                        });
                        phSection.style.display = 'block';
                    } else {
                        phSection.style.display = 'none';
                    }
                }

                // ── Button visibility ────────────────────────────────────────
                qmState.quotationStatus = q.status;
                qmRenderFooterButtons();
            })
            .catch(err => {
                showModalMessage(`Error: ${err.message}`, 'error');
            });
    }

    function qmRenderFooterButtons() {
        const status    = qmState.quotationStatus;
        const recordBtn = document.getElementById('qmRecordBtn');
        const sendBtn   = document.getElementById('qmSendBtn');
        const updateBtn = document.getElementById('qmUpdatePriceBtn');
        const cancelBtn = document.getElementById('qmCancelQuotationBtn');

        const openDispatchBtn    = document.getElementById('qmOpenDispatchBtn');
        const confirmDispatchBtn = document.getElementById('qmDispatchNowBtn');

        // Reset all action buttons and hide dispatch section
        [recordBtn, sendBtn, updateBtn, cancelBtn, openDispatchBtn, confirmDispatchBtn].forEach(function(b) {
            if (b) { b.style.display = 'none'; b.disabled = false; b.style.opacity = '1'; }
        });
        const dispSection = document.getElementById('qmDispatchSection');
        if (dispSection) dispSection.style.display = 'none';

        // Accepted: customer agreed to the quote — show Dispatch Now to assign a unit
        if (status === 'accepted') {
            if (openDispatchBtn) openDispatchBtn.style.display = 'inline-block';
            return;
        }

        // Other terminal states — only Close button
        if (['rejected', 'expired', 'disregarded', 'cancelled'].includes(status)) {
            return;
        }

        // Cancel / Reject shown for any non-terminal state that has a real quotation
        if (currentQuotationId && cancelBtn) {
            cancelBtn.style.display = 'inline-block';
            const isScheduled = qmState.serviceType === 'scheduled' || qmState.serviceType === 'schedule';
            cancelBtn.textContent = isScheduled ? 'Reject Booking' : 'Cancel Quotation';
        }

        if (status === 'sent' || status === 'negotiating') {
            if (updateBtn) {
                updateBtn.style.display  = 'inline-block';
                updateBtn.textContent    = 'Revise & Resend';
                updateBtn.disabled       = true;
                updateBtn.style.opacity  = '0.4';
                updateBtn.style.cursor   = 'not-allowed';
            }
            return;
        }

        if (status === 'draft') {
            // Draft recorded — show Send + Edit Price (Edit Price starts disabled until adjustment entered)
            if (sendBtn) {
                sendBtn.style.display = 'inline-block';
                sendBtn.textContent = 'Send to Customer';
            }
            if (updateBtn) {
                updateBtn.style.display  = 'inline-block';
                updateBtn.textContent    = 'Edit Price';
                updateBtn.disabled       = true;
                updateBtn.style.opacity  = '0.4';
                updateBtn.style.cursor   = 'not-allowed';
            }
        } else {
            // No quotation yet ('' or 'pending') — only Record
            if (recordBtn) {
                recordBtn.style.display = 'inline-block';
            }
        }
    }

    // ── Price recalculation ──────────────────────────────────────────────────
    function recalcQuotationTotal() {
        const amt      = parseFloat(document.getElementById('qmAdjustAmount')?.value || 0) || 0;
        const sign     = (window.qmAdjustSign === '+') ? 1 : -1;
        const newTotal = Math.max(0, (window.qmCurrentBase || 0) + sign * amt);
        document.getElementById('qmCalculatedPrice').textContent = fmt(newTotal);

        // Price Breakdown (Base Rate / Subtotal / VAT / Total) stays fixed — only New Total updates.

        // Note/Reason is only required once an adjustment amount is entered
        const noteRequiredHint = document.getElementById('qmNoteRequiredHint');
        if (noteRequiredHint) noteRequiredHint.style.display = amt !== 0 ? 'inline' : 'none';

        // Enable Update/Edit Price button only when there is an actual adjustment
        const updateBtn = document.getElementById('qmUpdatePriceBtn');
        if (updateBtn && updateBtn.style.display !== 'none') {
            const hasChange = amt > 0;
            updateBtn.disabled = !hasChange;
            updateBtn.style.opacity  = hasChange ? '1' : '0.4';
            updateBtn.style.cursor   = hasChange ? 'pointer' : 'not-allowed';
        }
    }

    // ── Unit class filter ────────────────────────────────────────────────────
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
            btn.style.background  = s.bg;
            btn.style.color       = s.color;
            btn.style.borderColor = s.border;
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

        const firstVisible = Array.from(select.options).find(o => o.value && !o.hidden);
        select.value = firstVisible ? firstVisible.value : '';
        recalcQuotationTotal();

        if (hint) {
            hint.textContent = visibleCount > 0
                ? visibleCount + ' unit' + (visibleCount === 1 ? '' : 's') + ' available'
                : 'No units available for this class — try a different class.';
            hint.style.color = visibleCount > 0 ? '#94a3b8' : '#ef4444';
        }
    };

    function qmTogglePriceHistory() {
        const body = document.getElementById('qmPriceHistoryBody');
        if (body) body.style.display = body.style.display === 'none' ? 'block' : 'none';
    }

    // ── Utility ──────────────────────────────────────────────────────────────
    function fmt(val) {
        return `₱${parseFloat(val).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }

    function fmtSchedDateTime(date, time) {
        if (!date) return '—';
        const [y, m, d] = date.split('-').map(Number);
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        let datePart = `${months[m-1]} ${d}, ${y}`;
        if (!time) return datePart;
        const [h, min] = time.split(':').map(Number);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const hr12 = h % 12 || 12;
        return `${datePart} · ${hr12}:${String(min).padStart(2,'0')} ${ampm}`;
    }

    function qmSetSign(sign) {
        window.qmAdjustSign = sign;
        const addBtn    = document.getElementById('qmSignAdd');
        const deductBtn = document.getElementById('qmSignDeduct');
        if (sign === '+') {
            if (addBtn)    { addBtn.style.background = '#16a34a'; addBtn.style.color = '#fff'; addBtn.style.borderColor = '#16a34a'; }
            if (deductBtn) { deductBtn.style.background = '#fff'; deductBtn.style.color = '#64748b'; deductBtn.style.borderColor = '#d1d5db'; }
        } else {
            if (addBtn)    { addBtn.style.background = '#fff'; addBtn.style.color = '#64748b'; addBtn.style.borderColor = '#d1d5db'; }
            if (deductBtn) { deductBtn.style.background = '#dc2626'; deductBtn.style.color = '#fff'; deductBtn.style.borderColor = '#dc2626'; }
        }
        recalcQuotationTotal();
    }

    // ── Dispatch Now flow (accepted quotations — assign a unit and start the job) ──
    function qmShowDispatchPanel() {
        const section    = document.getElementById('qmDispatchSection');
        const openBtn    = document.getElementById('qmOpenDispatchBtn');
        const confirmBtn = document.getElementById('qmDispatchNowBtn');
        const unitSelect = document.getElementById('qmDispatchUnitSelect');
        const noUnitsMsg = document.getElementById('qmDispatchNoUnits');

        if (!section) return;
        section.style.display = 'block';
        if (openBtn) openBtn.style.display = 'none';
        if (confirmBtn) confirmBtn.style.display = 'inline-block';

        const hasUnits = unitSelect && Array.from(unitSelect.options).some(o => o.value && !o.disabled);
        if (noUnitsMsg) noUnitsMsg.style.display = hasUnits ? 'none' : 'block';
        if (confirmBtn) {
            confirmBtn.disabled       = !hasUnits;
            confirmBtn.style.opacity  = hasUnits ? '1' : '0.4';
            confirmBtn.style.cursor   = hasUnits ? 'pointer' : 'not-allowed';
        }
        if (unitSelect) {
            unitSelect.onchange = function() {
                const hasVal = !!this.value;
                if (confirmBtn) {
                    confirmBtn.disabled       = !hasVal;
                    confirmBtn.style.opacity  = hasVal ? '1' : '0.4';
                    confirmBtn.style.cursor   = hasVal ? 'pointer' : 'not-allowed';
                }
            };
        }
        section.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function qmConfirmDispatch() {
        const bookingCode = qmState.sourceBookingCode;
        const unitSelect  = document.getElementById('qmDispatchUnitSelect');
        const confirmBtn  = document.getElementById('qmDispatchNowBtn');
        const errEl       = document.getElementById('qmDispatchUnitError');

        if (!bookingCode) {
            showModalMessage('No booking linked to this quotation.', 'error');
            return;
        }
        if (!unitSelect || !unitSelect.value) {
            if (errEl) errEl.style.display = 'block';
            return;
        }
        if (errEl) errEl.style.display = 'none';
        if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.textContent = 'Dispatching…'; }

        fetch('/admin-dashboard/booking/' + bookingCode + '/assign', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ action: 'accept', assigned_unit_id: unitSelect.value })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showModalMessage('Dispatched! The team leader can now accept the task.', 'success');
                setTimeout(function() {
                    closeQuotationModal();
                    qmRemoveIncomingCard(bookingCode);
                    refreshFloatingQuotationsPanel();
                }, 1500);
            } else {
                showModalMessage(data.message || 'Dispatch failed.', 'error');
                if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = 'Confirm Dispatch'; }
            }
        })
        .catch(function() {
            showModalMessage('Server error. Please try again.', 'error');
            if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = 'Confirm Dispatch'; }
        });
    }

    function closeQuotationModal() {
        const modal = document.getElementById('quotationModal');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        qmState.quotationStatus   = '';
        qmState.draftSaved        = false;
        qmState.sourceBookingCode = null;
        currentQuotationId = null;
        const indicator = document.getElementById('qmDraftSavedIndicator');
        if (indicator) indicator.style.display = 'none';
    }

    // Best-effort removal of a main-queue card by booking code, for actions
    // (dispatch, reject) that move a booking out of the incoming queue. A
    // no-op if the booking isn't shown there (e.g. it only lived in the
    // Floating Quotations panel), so it's always safe to call.
    function qmRemoveIncomingCard(bookingCode) {
        if (!bookingCode) return;
        const card = document.querySelector('.incoming-card[data-ref="' + bookingCode + '"]');
        if (card) card.remove();
    }

    // ── Update / Revise price ────────────────────────────────────────────────
    function updateQuotationPrice() {
        if (!currentQuotationId) return;

        const note = document.getElementById('qmPriceNote').value.trim();
        const noteError = document.getElementById('qmNoteError');

        const isSentState   = window.qmCurrentStatus === 'sent' || window.qmCurrentStatus === 'negotiating';
        const adjustAmt     = parseFloat(document.getElementById('qmAdjustAmount')?.value || 0) || 0;
        const adjustSign    = (window.qmAdjustSign === '+') ? 1 : -1;
        const newPrice      = Math.max(0, (window.qmCurrentBase || 0) + adjustSign * adjustAmt);
        const otherFees     = adjustSign * adjustAmt;

        // Note is only required when an adjustment is actually being made
        if (adjustAmt !== 0 && !note) {
            if (noteError) noteError.style.display = 'block';
            document.getElementById('qmPriceNote').focus();
            return;
        }
        if (noteError) noteError.style.display = 'none';

        if (newPrice <= 0) {
            showModalMessage('New price must be greater than zero.', 'error');
            return;
        }

        const btn = document.getElementById('qmUpdatePriceBtn');
        btn.disabled    = true;
        btn.textContent = 'Updating...';

        fetch(`/admin-dashboard/quotations/${currentQuotationId}/update-price`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    new_price:      newPrice,
                    additional_fee: otherFees,
                    note:           note
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showModalMessage(data.message || 'Price updated', 'success');
                    setTimeout(() => {
                        closeQuotationModal();
                        refreshFloatingQuotationsPanel();
                    }, 1500);
                } else {
                    showModalMessage(data.message || 'Failed to update', 'error');
                    btn.disabled    = false;
                    btn.textContent = isSentState ? 'Revise & Resend' : 'Save Changes';
                }
            })
            .catch(() => {
                showModalMessage('Error updating price', 'error');
                btn.disabled    = false;
                btn.textContent = isSentState ? 'Revise & Resend' : 'Save Changes';
            });
    }

    // ── Send to customer ─────────────────────────────────────────────────────
    function sendQuotationFromModal() {
        if (!currentQuotationId) return;
        sendQuotationToCustomer(currentQuotationId);
    }

    function sendQuotationToCustomer(quotationId) {
        const id = quotationId || currentQuotationId;
        if (!id) return;

        const btn          = document.getElementById('qmSendBtn');
        const originalText = btn ? btn.textContent : '';
        if (btn) {
            btn.disabled    = true;
            btn.textContent = 'Sending...';
        }

        fetch(`/admin-dashboard/quotations/${id}/send`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (document.getElementById('quotationModal').style.display === 'flex') {
                        showModalMessage(data.message || 'Quotation sent to customer', 'success');
                        setTimeout(() => {
                            closeQuotationModal();
                            refreshFloatingQuotationsPanel();
                        }, 2000);
                    } else {
                        alert('✅ ' + (data.message || 'Quotation sent successfully!'));
                        refreshFloatingQuotationsPanel();
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

    // ── Reject / Cancel booking or quotation ─────────────────────────────────
    function cancelQuotation() {
        if (!currentQuotationId) return;

        const isScheduled = qmState.serviceType === 'scheduled' || qmState.serviceType === 'schedule';
        const btnLabel     = isScheduled ? 'Reject Booking' : 'Cancel Quotation';
        const bookingCode  = qmState.sourceBookingCode;
        const btn          = document.getElementById('qmCancelQuotationBtn');
        btn.disabled    = true;
        btn.textContent = isScheduled ? 'Rejecting...' : 'Cancelling...';

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
                    showModalMessage(data.message || (isScheduled ? 'Booking rejected' : 'Quotation cancelled'), 'success');
                    setTimeout(() => {
                        closeQuotationModal();
                        if (isScheduled) qmRemoveIncomingCard(bookingCode);
                        refreshFloatingQuotationsPanel();
                    }, 1500);
                } else {
                    showModalMessage(data.message || 'Failed', 'error');
                    btn.disabled    = false;
                    btn.textContent = btnLabel;
                }
            })
            .catch(() => {
                showModalMessage('Server error. Please try again.', 'error');
                btn.disabled    = false;
                btn.textContent = btnLabel;
            });
    }

    // ── Toast messages ───────────────────────────────────────────────────────
    function showModalMessage(message, type) {
        const existing = document.getElementById('qmMessage');
        if (existing) existing.remove();

        const colors = {
            success: {bg: '#f0fdf4', color: '#166534', border: '#86efac', icon: '✓'},
            error:   {bg: '#fef2f2', color: '#991b1b', border: '#fca5a5', icon: '✕'},
            info:    {bg: '#f0f9ff', color: '#0369a1', border: '#7dd3fc', icon: 'ℹ'},
        };
        const c = colors[type] || colors.info;

        const div = document.createElement('div');
        div.id = 'qmMessage';
        div.style.cssText =
            `padding:9px 12px; margin-bottom:12px; font-size:0.82rem; font-weight:600; background:${c.bg}; color:${c.color}; border:1px solid ${c.border}; display:flex; align-items:center; gap:7px;`;
        div.innerHTML = `<span>${c.icon}</span><span>${message}</span>`;

        // Insert at top of the currently visible pane
        const pane = document.getElementById('qmPane-' + qmState.activeTab);
        if (pane) pane.insertBefore(div, pane.firstChild);

        if (type !== 'error') {
            setTimeout(() => { if (div.parentNode) div.remove(); }, 5000);
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeQuotationModal();
    });
</script>
