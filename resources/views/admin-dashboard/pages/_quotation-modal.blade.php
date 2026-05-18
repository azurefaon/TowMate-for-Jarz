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

    /* Tab buttons */
    .qm-tab-btn {
        padding: 11px 20px;
        border: none;
        border-bottom: 2px solid transparent;
        background: transparent;
        font-size: 0.78rem;
        font-weight: 700;
        color: #94a3b8;
        cursor: pointer;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        transition: color 0.15s, border-color 0.15s, background 0.15s;
        flex-shrink: 0;
    }
    .qm-tab-btn[data-active="true"] {
        color: #0f172a !important;
        border-bottom-color: #0f172a;
        background: #fff;
    }
    .qm-tab-btn:hover:not([data-active="true"]) {
        color: #374151 !important;
        background: #f1f5f9;
    }
</style>

<div id="quotationModal"
    style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(15, 23, 42, 0.55); align-items: center; justify-content: center; padding: 20px;"
    aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card"
        style="width: min(1600px, 96vw); max-width: 620px; max-height: 92vh; background: #fff; display: flex; flex-direction: column;">

        <!-- HEADER -->
        <div style="padding: 18px 24px 14px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; background: #fff; z-index: 10;">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; color: #0f172a;" id="quotationModalTitle">Quotation Details</h3>
                <p style="margin: 3px 0 0; font-size: 0.78rem; color: #94a3b8;" id="quotationModalSubtitle">Review and manage this quotation</p>
            </div>
            <button type="button" onclick="closeQuotationModal()"
                style="width: 30px; height: 30px; border: 1px solid #e2e8f0; background: #f8fafc; color: #64748b; font-size: 1.1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1;">
                ×
            </button>
        </div>

        <!-- TAB NAVIGATION -->
        <div style="display: flex; border-bottom: 1px solid #e2e8f0; background: #f8fafc; flex-shrink: 0;">
            <button class="qm-tab-btn" id="qmTab-details" onclick="qmSetTab('details')" data-active="false">Details</button>
            <button class="qm-tab-btn" id="qmTab-quote"   onclick="qmSetTab('quote')"   data-active="true">Quote</button>
            <button class="qm-tab-btn" id="qmTab-history" onclick="qmSetTab('history')" data-active="false">
                History
                <span id="qmHistoryBadge"
                    style="display:none; background:#64748b; color:#fff; font-size:0.62rem; font-weight:700; padding:1px 6px; margin-left:4px; vertical-align:middle; border-radius:10px;">0</span>
            </button>
        </div>

        <!-- BODY: TAB PANES -->
        <div style="flex: 1; overflow-y: auto; min-height: 0;">

            <!-- ── PANE: Details ──────────────────────────────────────────── -->
            <div id="qmPane-details" style="display: none; padding: 18px 24px;">

                <!-- Mobile booking banner -->
                <div id="qmMobileBanner"
                    style="display:none; background:#fffbeb; border:1px solid #fde68a; padding:10px 16px; margin-bottom:14px; align-items:center; gap:10px;">
                    <span style="font-size:0.7rem; font-weight:700; background:#f59e0b; color:#fff; padding:2px 7px; text-transform:uppercase; letter-spacing:0.07em;">Mobile</span>
                    <span style="font-size:0.85rem; color:#92400e;">Booking ref:
                        <span id="qmSourceBookingCode" style="font-family:monospace; font-weight:700;">—</span>
                    </span>
                </div>

                <!-- Quotation number + Customer info -->
                <div style="margin-bottom: 18px;">
                    <div style="display: inline-flex; align-items: center; gap: 8px; background: #eff6ff; border: 1px solid #bfdbfe; padding: 6px 13px; margin-bottom: 10px;">
                        <span style="font-size: 0.7rem; color: #3b82f6; text-transform: uppercase; letter-spacing: 0.07em;">Quotation #</span>
                        <span style="font-size: 0.9rem; color: #1d4ed8; font-family: monospace; letter-spacing: 0.03em;" id="qmQuotationNumber">—</span>
                    </div>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px 16px;">
                        <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px;">Customer</div>
                        <div style="font-size: 0.9rem; color: #0f172a; margin-bottom: 2px; font-weight: 600;" id="qmCustomerName">—</div>
                        <div style="font-size: 0.82rem; color: #64748b;" id="qmCustomerPhone">—</div>
                        <div style="font-size: 0.82rem; color: #64748b;" id="qmCustomerEmail">—</div>
                    </div>
                </div>

                <!-- Route section -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 14px; margin-bottom: 18px;">
                    <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px;">Route</div>
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
                            <div style="display:flex; align-items:center; gap:5px; flex-wrap:wrap;">
                                <span style="font-size: 0.88rem; font-weight: 600; color: #0f172a;" id="qmTruckType">—</span>
                                <span id="qmTruckClassBadge"
                                      style="display:none; font-size:0.62rem; font-weight:700; padding:2px 6px; text-transform:uppercase; letter-spacing:0.06em; border:1px solid;"></span>
                            </div>
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
                <div id="qmNotesSection"
                    style="display:none; background:#fffbeb; border:1px solid #fde68a; padding:10px 14px; margin-bottom:18px;">
                    <div style="font-size:0.7rem; color:#92400e; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:4px;">Pickup Notes</div>
                    <div id="qmNotesText" style="font-size:0.85rem; color:#78350f;"></div>
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
            <div id="qmPane-quote" style="display: block; padding: 18px 24px;">

                <!-- Price Breakdown -->
                <div style="border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 18px;">
                    <div style="padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                        <span style="font-size: 0.72rem; color: #000000; text-transform: uppercase; letter-spacing: 0.05em;">Price Breakdown</span>
                    </div>
                    <div style="padding: 14px; display: grid; gap: 8px;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #000000;">
                            <span>Base Rate (Unit)</span>
                            <span id="qmBasePrice">TBD</span>
                        </div>
                        <div id="qmDistanceFeeRow"
                            style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #000000;">
                            <span id="qmDistanceFeeLabel">Distance Fee</span>
                            <span id="qmDistanceFee">₱0.00</span>
                        </div>
                        <div id="qmOtherFeesRow"
                            style="display: none; flex-direction: row; justify-content: space-between; font-size: 0.85rem; color: #000000;">
                            <span>Additional Fees</span>
                            <span id="qmOtherFees">₱0.00</span>
                        </div>
                        <div id="qmExtraVehiclesTotalRow"
                            style="display: none; flex-direction: row; justify-content: space-between; font-size: 0.85rem; color: #000000;">
                            <span id="qmExtraVehiclesLabel">Additional Vehicles</span>
                            <span id="qmExtraVehiclesTotal">₱0.00</span>
                        </div>
                        <div style="border-top: 1px solid #e2e8f0; padding-top: 8px; display: flex; justify-content: space-between; font-size: 0.82rem; color: #64748b;">
                            <span>Subtotal (before VAT)</span>
                            <span id="qmSubtotalAmount">₱0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.78rem; color: #64748b;">
                            <span>VAT (12%)</span>
                            <span id="qmVatAmount">₱0.00</span>
                        </div>
                        <div style="border-top: 1px solid #e2e8f0; padding-top: 10px; display: flex; justify-content: space-between; align-items: baseline;">
                            <span style="font-size: 0.9rem; font-weight: 600; color: #0f172a;">Total</span>
                            <span style="font-size: 1.15rem; font-weight: 700; color: #0f172a;" id="qmTotalAmount">₱0.00</span>
                        </div>
                    </div>
                </div>

                <!-- Assign Unit section -->
                <div id="qmUnitSection"
                    style="border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 18px; display: none;">
                    <div style="padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                        <span style="font-size: 0.72rem; font-weight: 700; color: #000000; text-transform: uppercase; letter-spacing: 0.05em;">Assign Unit</span>
                    </div>
                    <div style="padding: 14px;">
                        <label style="display: block; font-size: 0.82rem; color: #000000; margin-bottom: 5px;">Available Unit <span style="color: #dc2626;">*</span></label>

                        <div id="qmClassFilterRow" style="display:flex; gap:5px; margin-bottom:8px; flex-wrap:wrap;">
                            <span style="font-size:0.72rem; color:#64748b; align-self:center; margin-right:3px;">Filter:</span>
                            <button type="button" onclick="filterUnitsByClass('all')" id="qmClassBtn-all"
                                style="padding:3px 10px; border:1px solid #d1d5db; background:#0f172a; color:#fff; font-size:0.72rem; font-weight:700; cursor:pointer;">All</button>
                            <button type="button" onclick="filterUnitsByClass('light')" id="qmClassBtn-light"
                                style="padding:3px 10px; border:1px solid #bfdbfe; background:#fff; color:#1d4ed8; font-size:0.72rem; font-weight:700; cursor:pointer;">Light</button>
                            <button type="button" onclick="filterUnitsByClass('medium')" id="qmClassBtn-medium"
                                style="padding:3px 10px; border:1px solid #bbf7d0; background:#fff; color:#15803d; font-size:0.72rem; font-weight:700; cursor:pointer;">Medium</button>
                            <button type="button" onclick="filterUnitsByClass('heavy')" id="qmClassBtn-heavy"
                                style="padding:3px 10px; border:1px solid #fed7aa; background:#fff; color:#c2410c; font-size:0.72rem; font-weight:700; cursor:pointer;">Heavy</button>
                        </div>

                        <div id="qmUnitCountHint" style="font-size:0.72rem; color:#94a3b8; margin-bottom:6px;"></div>

                        <select id="qmUnitSelect"
                            style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; font-size: 0.88rem; color: #0f172a; outline: none; box-sizing: border-box; background: #fff;">
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
                        <small style="font-size: 0.72rem; color: #94a3b8; margin-top: 4px; display: block;">Unit must be assigned before sending.</small>
                    </div>
                </div>

                <!-- Adjust Price section -->
                <div style="border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 18px;">
                    <div style="padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                        <span style="font-size: 0.72rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Adjust Price</span>
                    </div>
                    <div style="padding: 14px; display: grid; gap: 12px;">

                        <!-- Draft/pending mode: direct final-price input -->
                        <div id="qmDirectPriceBlock">
                            <label style="font-size:0.78rem; font-weight:600; color:#374151; display:block; margin-bottom:5px;">
                                Quoted Price (₱)
                                <span id="qmSuggestedPriceHint" style="font-weight:400; color:#94a3b8;"></span>
                            </label>
                            <input type="number" id="qmFinalPriceInput" step="0.01" min="0.01"
                                style="width:100%; padding:8px 10px; border:1px solid #d1d5db; font-size:0.95rem; color:#0f172a; box-sizing:border-box;"
                                onfocusin="this.style.borderColor='#6366f1'"
                                onfocusout="this.style.borderColor='#d1d5db'">
                        </div>

                        <!-- Sent/negotiating mode: delta adjustment -->
                        <div id="qmDeltaBlock" style="display:none;">
                            <div id="qmCurrentPriceRow"
                                style="display:flex; justify-content:space-between; padding:7px 10px; background:#eff6ff; border:1px solid #bfdbfe; margin-bottom:7px;">
                                <span style="font-size:0.78rem; color:#1d4ed8;">Current Sent Price</span>
                                <span id="qmCurrentPriceDisplay" style="font-size:0.82rem; font-weight:700; color:#1d4ed8;">₱0.00</span>
                            </div>
                            <label style="font-size:0.78rem; font-weight:600; color:#374151; display:block; margin-bottom:4px;">
                                Price Adjustment (₱) — negative to reduce
                            </label>
                            <input type="number" id="qmOtherFeesInput" step="0.01" value="0.00"
                                style="width:100%; padding:8px 10px; border:1px solid #d1d5db; font-size:0.88rem; color:#0f172a; box-sizing:border-box;"
                                onfocusin="this.style.borderColor='#6366f1'"
                                onfocusout="this.style.borderColor='#d1d5db'">
                        </div>

                        <!-- New total preview -->
                        <div style="background:#f1f5f9; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size:0.85rem; color:#475569;">New Total</span>
                            <span style="font-size:0.95rem; font-weight:700; color:#0f172a;" id="qmCalculatedPrice">₱0.00</span>
                        </div>

                        <!-- Note / reason -->
                        <div>
                            <label style="display: block; font-size: 0.78rem; color: #000000; margin-bottom: 5px;">
                                Note / Reason
                                <span id="qmNoteRequiredBadge" style="display:none; color:#dc2626; font-weight:700;">*</span>
                                <span id="qmNoteOptionalBadge" style="color:#94a3b8;">(optional)</span>
                            </label>
                            <textarea id="qmPriceNote" rows="2" placeholder="e.g. Rush fee, toll charges…"
                                style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; font-size: 0.85rem; color: #0f172a; resize: vertical; outline: none; box-sizing: border-box;"
                                onfocusin="this.style.borderColor='#6366f1'"
                                onfocusout="this.style.borderColor='#d1d5db'"></textarea>
                            <div id="qmNoteError" style="display:none; color:#dc2626; font-size:0.72rem; margin-top:3px;">
                                Please enter a reason for the price change.
                            </div>
                        </div>

                        <!-- Draft saved indicator (Schedule bookings only) -->
                        <div id="qmDraftSavedIndicator"
                            style="display:none; background:#f0fdf4; border:1px solid #86efac; padding:9px 12px; align-items:center; gap:8px;">
                            <span style="color:#166534; font-size:0.82rem; font-weight:600;">✓ Draft saved — ready to send to customer</span>
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

            </div>

            <!-- ── PANE: History ───────────────────────────────────────────── -->
            <div id="qmPane-history" style="display: none; padding: 18px 24px;">

                <!-- Empty state -->
                <div id="qmHistoryEmpty"
                    style="text-align:center; padding: 36px 20px; color: #94a3b8;">
                    <div style="font-size: 1.8rem; margin-bottom: 8px;">📋</div>
                    <div style="font-size: 0.85rem;">No price changes recorded yet.</div>
                    <div style="font-size: 0.78rem; margin-top: 4px; color: #cbd5e1;">Changes will appear here when the price is updated.</div>
                </div>

                <!-- History entries -->
                <div id="qmPriceHistoryBody" style="display: grid; gap: 10px;"></div>

            </div>

        </div>

        <!-- FOOTER -->
        <div style="padding: 14px 24px; border-top: 1px solid #e2e8f0; display: flex; gap: 8px; justify-content: flex-end; background: #fff; flex-shrink: 0; flex-wrap: wrap;">
            <button type="button" onclick="closeQuotationModal()"
                style="padding: 8px 16px; border: 1px solid #e2e8f0; background: #fff; color: #64748b; font-size: 0.82rem; font-weight: 600; cursor: pointer;">
                Close
            </button>
            <button type="button" id="qmCancelQuotationBtn" onclick="cancelQuotation()"
                style="padding: 8px 16px; border: 1px solid #fca5a5; background: #fff; color: #dc2626; font-size: 0.82rem; font-weight: 600; cursor: pointer;">
                Cancel Quotation
            </button>
            <!-- Save as Draft (Schedule bookings only, shown when editable) -->
            <button type="button" id="qmSaveDraftBtn" onclick="qmSaveAsDraft()" style="display:none;
                padding: 8px 16px; border: 1px solid #d1d5db; background: #f8fafc; color: #374151; font-size: 0.82rem; font-weight: 600; cursor: pointer;">
                Save as Draft
            </button>
            <button type="button" id="qmUpdatePriceBtn" onclick="updateQuotationPrice()"
                style="padding: 8px 16px; border: none; background: #334155; color: #fff; font-size: 0.82rem; font-weight: 600; cursor: pointer;">
                Update Price
            </button>
            <button type="button" id="qmSendBtn" onclick="sendQuotationFromModal()"
                style="padding: 8px 18px; border: none; background: #2563eb; color: #fff; font-size: 0.82rem; font-weight: 700; cursor: pointer;">
                Send to Customer
            </button>
        </div>

    </div>
</div>

<script>
    let currentQuotationId = null;

    // Tracks per-modal-open state
    const qmState = {
        activeTab:    'quote',
        bookingId:    null,   // source_booking_id for mobile bookings
        serviceType:  null,   // 'book_now' | 'scheduled'
        isMobile:     false,
        draftSaved:   false,
        historyCount: 0,
    };

    // ── Tab switching ──────────────────────────────────────────────────────────
    function qmSetTab(tab) {
        ['details', 'quote', 'history'].forEach(function(t) {
            document.getElementById('qmPane-' + t).style.display = t === tab ? 'block' : 'none';
            const btn = document.getElementById('qmTab-' + t);
            if (btn) btn.setAttribute('data-active', t === tab ? 'true' : 'false');
        });
        qmState.activeTab = tab;
    }

    // ── Open modal for a brand-new Schedule booking (no quotation yet) ──────────
    function openNewScheduleQuote(card) {
        const d = card.dataset;

        // Reset global state
        currentQuotationId        = null;
        window.qmCurrentStatus    = 'pending';
        window.qmDistanceKm       = parseFloat(d.distance || 0);
        qmState.bookingId         = d.bookingId;
        qmState.serviceType       = 'scheduled';
        qmState.isMobile          = true;
        qmState.draftSaved        = false;
        qmState.historyCount      = 0;

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
        set('qmCustomerEmail',   '');
        set('qmPickupAddress',   d.pickup);
        set('qmDropoffAddress',  d.dropoff);
        set('qmDistance',        parseFloat(d.distance || 0).toFixed(2) + ' km');
        set('qmTruckType',       d.truck);

        // Show mobile banner with booking ref
        const banner  = document.getElementById('qmMobileBanner');
        const srcCode = document.getElementById('qmSourceBookingCode');
        if (banner)  banner.style.display = 'flex';
        if (srcCode) srcCode.textContent  = d.ref || '—';

        // Hide vehicle section (no data yet), notes section
        const vs = document.getElementById('qmCustomerVehicleSection');
        const ns = document.getElementById('qmNotesSection');
        if (vs) vs.style.display = 'none';
        if (ns) ns.style.display = 'none';

        // Clear quote form
        const priceInput = document.getElementById('qmFinalPriceInput');
        const noteInput  = document.getElementById('qmPriceNote');
        if (priceInput) priceInput.value = '';
        if (noteInput)  noteInput.value  = '';

        // Hide draft-saved indicator + history badge
        const indicator = document.getElementById('qmDraftSavedIndicator');
        if (indicator) indicator.style.display = 'none';
        const badge = document.getElementById('qmHistoryBadge');
        if (badge) badge.style.display = 'none';

        // Clear history pane
        const historyBody = document.getElementById('qmPriceHistoryBody');
        if (historyBody) historyBody.innerHTML = '';
        const historyEmpty = document.getElementById('qmHistoryEmpty');
        if (historyEmpty) historyEmpty.style.display = 'block';

        // Show modal
        const modal = document.getElementById('quotationModal');
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');

        // Land on Quote tab, update footer buttons
        qmSetTab('quote');
        qmUpdateFooterButtons({ status: 'pending', service_type: 'scheduled' });
    }

    // ── Save as Draft (Schedule mobile bookings) ────────────────────────────────
    async function qmSaveAsDraft() {
        if (!qmState.bookingId) {
            showModalMessage('No booking linked — cannot save draft.', 'error');
            return;
        }

        const unitSelect = document.getElementById('qmUnitSelect');
        const price      = parseFloat(document.getElementById('qmFinalPriceInput')?.value || 0);
        const note       = document.getElementById('qmPriceNote')?.value?.trim() || '';

        if (price <= 0) {
            showModalMessage('Please enter a valid price before saving.', 'error');
            return;
        }

        const btn = document.getElementById('qmSaveDraftBtn');
        btn.disabled    = true;
        btn.textContent = 'Saving…';

        const payload = {
            price:            price,
            additional_fee:   0,
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
                // Update quotation ID for subsequent Send call
                currentQuotationId = data.quotation_id;
                qmState.draftSaved = true;

                // Show "Draft saved ✓" indicator
                const indicator = document.getElementById('qmDraftSavedIndicator');
                if (indicator) indicator.style.display = 'flex';

                // Enable Send button
                const sendBtn = document.getElementById('qmSendBtn');
                if (sendBtn) {
                    sendBtn.disabled = false;
                    sendBtn.style.opacity = '1';
                }

                showModalMessage(data.message || 'Draft saved.', 'success');
                btn.textContent = 'Update Draft';
            } else {
                showModalMessage(data.message || 'Failed to save draft.', 'error');
                btn.textContent = 'Save as Draft';
            }
        } catch (err) {
            showModalMessage('Error saving draft: ' + err.message, 'error');
            btn.textContent = 'Save as Draft';
        } finally {
            btn.disabled = false;
        }
    }

    // ── Load & render quotation details ──────────────────────────────────────
    function viewQuotationDetails(quotationId) {
        currentQuotationId = quotationId;

        // Reset state
        qmState.bookingId   = null;
        qmState.serviceType = null;
        qmState.isMobile    = false;
        qmState.draftSaved  = false;
        qmState.historyCount = 0;

        const modal = document.getElementById('quotationModal');
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');

        // Start on Quote tab
        qmSetTab('quote');
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
                qmState.bookingId   = q.source_booking_id || null;
                qmState.serviceType = q.service_type || 'book_now';
                qmState.isMobile    = !!q.is_mobile_booking;
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
                const gallery = document.getElementById('qmImageGallery');
                const grid    = document.getElementById('qmImageGrid');
                const noImg   = document.getElementById('qmNoImages');
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
                        img.style.cssText = 'width:90px; height:70px; object-fit:cover; cursor:pointer; border:1px solid #e2e8f0; transition:opacity 0.15s;';
                        img.onerror   = function() { this.style.display = 'none'; };
                        img.onmouseover = function() { this.style.opacity = '0.8'; };
                        img.onmouseout  = function() { this.style.opacity = '1'; };
                        img.onclick   = function() { window.open(this.src, '_blank'); };
                        grid.appendChild(img);
                    });
                }

                // ── Quote tab content ────────────────────────────────────────

                window.qmCustomerPrice  = parseFloat(q.subtotal || 0);
                window.qmEstimatedPrice = parseFloat(q.estimated_price || 0);
                window.qmCurrentStatus  = q.status;
                window.qmBasePrice      = parseFloat(q.base_price || 0);
                document.getElementById('qmPriceNote').value = '';

                const distanceKm  = parseFloat(q.distance_km || 0);
                const extraDist   = Math.max(0, distanceKm - 1);
                const distanceFee = Math.round(extraDist * 300 * 100) / 100;

                document.getElementById('qmBasePrice').textContent = window.qmBasePrice > 0 ? fmt(window.qmBasePrice) : 'TBD';
                document.getElementById('qmDistanceFee').textContent = fmt(distanceFee);
                document.getElementById('qmDistanceFeeLabel').textContent = `Distance (${extraDist.toFixed(2)} km extra × ₱300)`;
                document.getElementById('qmOtherFeesRow').style.display = 'none';
                window.qmDistanceFee = distanceFee;

                // Extra vehicles total (non-scheduled only)
                const evTotal = (q.extra_vehicles || []).reduce(function(s, ev) {
                    return ev.service_type !== 'schedule' ? s + parseFloat(ev.estimated_price || 0) : s;
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

                // Price breakdown display
                if (q.status === 'sent' || q.status === 'negotiating') {
                    const sentTotal    = parseFloat(q.estimated_price || 0);
                    const sentSubtotal = Math.round(sentTotal / 1.12 * 100) / 100;
                    const sentVat      = Math.round((sentTotal - sentSubtotal) * 100) / 100;
                    document.getElementById('qmSubtotalAmount').textContent = fmt(sentSubtotal);
                    document.getElementById('qmVatAmount').textContent      = fmt(sentVat);
                    document.getElementById('qmTotalAmount').textContent    = fmt(sentTotal);
                } else {
                    const tempSubtotal = (window.qmBasePrice || 0) + distanceFee + evTotal;
                    const tempVat      = Math.round(tempSubtotal * 0.12 * 100) / 100;
                    document.getElementById('qmSubtotalAmount').textContent = fmt(tempSubtotal);
                    document.getElementById('qmVatAmount').textContent      = fmt(tempVat);
                    document.getElementById('qmTotalAmount').textContent    = fmt(tempSubtotal + tempVat);
                }

                // Unit section
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
                                const subtotalCalc  = newBase + (window.qmDistanceFee || 0) + (window.qmExtraVehiclesTotal || 0);
                                const vatCalc       = Math.round(subtotalCalc * 0.12 * 100) / 100;
                                const newSuggested  = Math.round(subtotalCalc * 1.12 * 100) / 100;
                                document.getElementById('qmBasePrice').textContent          = fmt(newBase);
                                document.getElementById('qmFinalPriceInput').value          = newSuggested.toFixed(2);
                                document.getElementById('qmSuggestedPriceHint').textContent = '(suggested: ' + fmt(newSuggested) + ')';
                                document.getElementById('qmSubtotalAmount').textContent     = fmt(subtotalCalc);
                                document.getElementById('qmVatAmount').textContent          = fmt(vatCalc);
                                document.getElementById('qmTotalAmount').textContent        = fmt(newSuggested);
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
                const directBlock    = document.getElementById('qmDirectPriceBlock');
                const deltaBlock     = document.getElementById('qmDeltaBlock');
                const finalInput     = document.getElementById('qmFinalPriceInput');
                const suggestedHint  = document.getElementById('qmSuggestedPriceHint');
                const otherFeesInput = document.getElementById('qmOtherFeesInput');

                if (q.status === 'draft' || q.status === 'pending') {
                    directBlock.style.display = 'block';
                    deltaBlock.style.display  = 'none';
                    const subtotalInit = (window.qmBasePrice || 0) + (window.qmDistanceFee || 0) + (window.qmExtraVehiclesTotal || 0);
                    const suggested    = Math.round(subtotalInit * 1.12 * 100) / 100;
                    if (finalInput) finalInput.value = suggested.toFixed(2);
                    if (suggestedHint) suggestedHint.textContent = '(suggested: ' + fmt(suggested) + ')';
                    if (finalInput) finalInput.oninput = recalcQuotationTotal;
                } else {
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

                // ── History tab content ──────────────────────────────────────
                const histBody    = document.getElementById('qmPriceHistoryBody');
                const histEmpty   = document.getElementById('qmHistoryEmpty');
                const histBadge   = document.getElementById('qmHistoryBadge');
                const priceLog    = q.price_change_log || [];
                histBody.innerHTML = '';

                if (priceLog.length > 0) {
                    qmState.historyCount = priceLog.length;
                    histEmpty.style.display = 'none';
                    histBadge.textContent   = priceLog.length;
                    histBadge.style.display = 'inline';

                    priceLog.forEach(function(entry) {
                        const row = document.createElement('div');
                        row.style.cssText = 'padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; font-size:0.82rem;';
                        const dt = entry.at
                            ? new Date(entry.at).toLocaleString('en-PH', {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'})
                            : '—';
                        row.innerHTML = `
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <span style="font-size:0.72rem; color:#64748b;">${dt}</span>
                                <span style="color:#0f172a; font-weight:700;">${fmt(entry.old || 0)} → ${fmt(entry.new || 0)}</span>
                            </div>
                            ${entry.reason ? `<div style="color:#78350f; font-size:0.78rem; margin-bottom:2px;">"${entry.reason}"</div>` : ''}
                            <div style="color:#94a3b8; font-size:0.72rem;">by ${entry.by || 'Dispatcher'}</div>`;
                        histBody.appendChild(row);
                    });
                } else {
                    histEmpty.style.display = 'block';
                    histBadge.style.display = 'none';
                }

                // ── Button visibility ────────────────────────────────────────
                qmUpdateFooterButtons(q);
            })
            .catch(err => {
                showModalMessage(`Error: ${err.message}`, 'error');
            });
    }

    function qmUpdateFooterButtons(q) {
        const sendBtn      = document.getElementById('qmSendBtn');
        const updateBtn    = document.getElementById('qmUpdatePriceBtn');
        const cancelBtn    = document.getElementById('qmCancelQuotationBtn');
        const saveDraftBtn = document.getElementById('qmSaveDraftBtn');
        const noteRequired = document.getElementById('qmNoteRequiredBadge');
        const noteOptional = document.getElementById('qmNoteOptionalBadge');

        const isScheduled = (q.service_type === 'scheduled' || q.service_type === 'schedule');
        const status = q.status;

        // Reset all
        [sendBtn, updateBtn, cancelBtn, saveDraftBtn].forEach(function(b) {
            if (b) { b.style.display = 'none'; b.disabled = false; }
        });

        if (status === 'accepted' || status === 'rejected' || status === 'expired' || status === 'disregarded') {
            // Terminal state — close only
            return;
        }

        if (status === 'sent' || status === 'negotiating') {
            // Already sent — allow revision and cancel
            if (cancelBtn) cancelBtn.style.display = 'inline-block';
            if (updateBtn) {
                updateBtn.style.display = 'inline-block';
                updateBtn.textContent = 'Revise & Resend';
            }
            // Note is REQUIRED when updating a sent quotation
            if (noteRequired) noteRequired.style.display = 'inline';
            if (noteOptional) noteOptional.style.display = 'none';
            return;
        }

        // draft / pending — editable state
        if (cancelBtn) cancelBtn.style.display = 'inline-block';
        // Note is optional when saving draft or initial send
        if (noteRequired) noteRequired.style.display = 'none';
        if (noteOptional) noteOptional.style.display = 'inline';

        if (isScheduled && qmState.isMobile) {
            // Schedule mobile: Save as Draft → then Send
            if (saveDraftBtn) saveDraftBtn.style.display = 'inline-block';
            if (sendBtn) {
                sendBtn.style.display = 'inline-block';
                sendBtn.textContent = 'Send to Customer';
                // Disable Send until draft is explicitly saved
                sendBtn.disabled = !qmState.draftSaved;
                sendBtn.style.opacity = qmState.draftSaved ? '1' : '0.45';
            }
            // Hide Update Price (use Save as Draft instead)
            if (updateBtn) updateBtn.style.display = 'none';
        } else {
            // Book Now or non-mobile Schedule: direct send
            if (sendBtn) {
                sendBtn.style.display = 'inline-block';
                sendBtn.textContent = 'Send to Customer';
                sendBtn.disabled = false;
                sendBtn.style.opacity = '1';
            }
            if (updateBtn) {
                updateBtn.style.display = 'inline-block';
                updateBtn.textContent = status === 'pending' ? 'Update & Send' : 'Save Changes';
            }
        }
    }

    // ── Price recalculation ──────────────────────────────────────────────────
    function recalcQuotationTotal() {
        const vatEl      = document.getElementById('qmVatAmount');
        const subtotalEl = document.getElementById('qmSubtotalAmount');
        if (window.qmCurrentStatus === 'draft' || window.qmCurrentStatus === 'pending') {
            const typed    = parseFloat(document.getElementById('qmFinalPriceInput')?.value || 0);
            const vatAmt   = Math.round(typed * 0.12 / 1.12 * 100) / 100;
            const subtotal = Math.round((typed - vatAmt) * 100) / 100;
            document.getElementById('qmCalculatedPrice').textContent = fmt(typed);
            if (document.getElementById('qmTotalAmount'))
                document.getElementById('qmTotalAmount').textContent = fmt(typed);
            if (vatEl) vatEl.textContent = fmt(vatAmt);
            if (subtotalEl) subtotalEl.textContent = fmt(subtotal);
        } else {
            const fees     = parseFloat(document.getElementById('qmOtherFeesInput')?.value || 0);
            const newTotal = Math.max(0, (window.qmEstimatedPrice || 0) + fees);
            const vatAmt   = Math.round(newTotal * 0.12 / 1.12 * 100) / 100;
            document.getElementById('qmCalculatedPrice').textContent = fmt(newTotal);
            if (vatEl) vatEl.textContent = fmt(vatAmt);
            if (subtotalEl) subtotalEl.textContent = fmt(Math.round((newTotal - vatAmt) * 100) / 100);
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

    // ── Utility ──────────────────────────────────────────────────────────────
    function fmt(val) {
        return `₱${parseFloat(val).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }

    function closeQuotationModal() {
        const modal = document.getElementById('quotationModal');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        currentQuotationId = null;
        // Reset draft saved indicator for next open
        const indicator = document.getElementById('qmDraftSavedIndicator');
        if (indicator) indicator.style.display = 'none';
    }

    // ── Update / Revise price ────────────────────────────────────────────────
    function updateQuotationPrice() {
        if (!currentQuotationId) return;

        const unitSelect   = document.getElementById('qmUnitSelect');
        const unitSection  = document.getElementById('qmUnitSection');
        const unitRequired = unitSection && unitSection.style.display !== 'none';
        if (unitRequired && unitSelect && !unitSelect.value) {
            showModalMessage('Please assign a unit before updating the quotation.', 'error');
            unitSelect.focus();
            return;
        }

        const note = document.getElementById('qmPriceNote').value.trim();
        const noteError = document.getElementById('qmNoteError');

        // Require note when quotation is already sent/negotiating
        const isSentState = window.qmCurrentStatus === 'sent' || window.qmCurrentStatus === 'negotiating';
        if (isSentState && !note) {
            if (noteError) noteError.style.display = 'block';
            document.getElementById('qmPriceNote').focus();
            return;
        }
        if (noteError) noteError.style.display = 'none';

        let newPrice, otherFees = 0;
        if (window.qmCurrentStatus === 'pending' || window.qmCurrentStatus === 'draft') {
            newPrice = parseFloat(document.getElementById('qmFinalPriceInput')?.value || 0);
        } else {
            otherFees = parseFloat(document.getElementById('qmOtherFeesInput')?.value || 0);
            newPrice  = Math.max(0, (window.qmEstimatedPrice || 0) + otherFees);
        }

        const assignedUnitId = unitSelect ? (unitSelect.value || null) : null;

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
                    new_price:        newPrice,
                    additional_fee:   otherFees,
                    assigned_unit_id: assignedUnitId,
                    note:             note
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
            btn.disabled    = true;
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
                body: JSON.stringify({assigned_unit_id: assignedUnitId})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (document.getElementById('quotationModal').style.display === 'flex') {
                        showModalMessage(data.message || 'Quotation sent to customer', 'success');
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

    // ── Cancel quotation ─────────────────────────────────────────────────────
    function cancelQuotation() {
        if (!currentQuotationId) return;

        const btn = document.getElementById('qmCancelQuotationBtn');
        btn.disabled    = true;
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
                    btn.disabled    = false;
                    btn.textContent = 'Cancel Quotation';
                }
            })
            .catch(() => {
                showModalMessage('Error cancelling quotation', 'error');
                btn.disabled    = false;
                btn.textContent = 'Cancel Quotation';
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
