<div id="quotationModal"
    style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(3px); align-items: center; justify-content: center; padding: 20px;"
    aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card"
        style="width: min(860px, 100%); max-height: 92vh; overflow-y: auto; background: #fff; border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,0.18); display: flex; flex-direction: column;">

        <!-- Modal -->
        <div
            style="padding: 20px 24px 16px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: #fff; border-radius: 16px 16px 0 0; z-index: 10;">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #0f172a;" id="quotationModalTitle">
                    Quotation Details</h3>
                <p style="margin: 4px 0 0; font-size: 0.8rem; color: #94a3b8;" id="quotationModalSubtitle">Review and
                    manage this quotation</p>
            </div>
            <button type="button" onclick="closeQuotationModal()"
                style="width: 32px; height: 32px; border-radius: 8px; border: 1px solid #e2e8f0; background: #f8fafc; color: #64748b; font-size: 1.1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1;">
                ×
            </button>
        </div>

        <!-- modal body -->
        <div style="padding: 20px 24px; flex: 1; overflow-y: auto;">

            <!-- quotation badge and customer info-->
            <div style="margin-bottom: 20px;">

                <div
                    style="display: inline-flex; align-items: center; gap: 8px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 7px 14px; margin-bottom: 12px;">
                    <span
                        style="font-size: 0.72rem; font-weight: 700; color: #3b82f6; text-transform: uppercase; letter-spacing: 0.07em;">Quotation
                        #</span>
                    <span
                        style="font-size: 0.95rem; font-weight: 800; color: #1d4ed8; font-family: monospace; letter-spacing: 0.03em;"
                        id="qmQuotationNumber">—</span>
                </div>
                <!-- Customer card with phone stacked below name -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 13px 18px;">
                    <div
                        style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px;">
                        Customer</div>
                    <div style="font-size: 0.95rem; font-weight: 700; color: #0f172a; margin-bottom: 3px;"
                        id="qmCustomerName">—</div>
                    <div style="font-size: 0.82rem; color: #64748b; font-weight: 500;" id="qmCustomerPhone">—</div>
                    <div style="font-size: 0.82rem; color: #64748b; font-weight: 500;" id="qmCustomerEmail">—</div>
                </div>
            </div>

            <!-- Route -->
            <div
                style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 20px;">
                <div
                    style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px;">
                    Route</div>
                <div style="display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px;">
                    <div
                        style="flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; background: #22c55e; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.72rem; color: #fff; margin-top: 1px;">
                        A</div>
                    <div>
                        <div style="font-size: 0.72rem; color: #94a3b8; margin-bottom: 2px;">Pickup</div>
                        <div style="font-size: 0.88rem; font-weight: 600; color: #0f172a;" id="qmPickupAddress">—</div>
                    </div>
                </div>
                <div style="margin-left: 12px; width: 1px; height: 10px; background: #e2e8f0; margin-bottom: 10px;">
                </div>
                <div style="display: flex; align-items: flex-start; gap: 10px;">
                    <div
                        style="flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; background: #ef4444; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.72rem; color: #fff; margin-top: 1px;">
                        B</div>
                    <div>
                        <div style="font-size: 0.72rem; color: #94a3b8; margin-bottom: 2px;">Drop-off</div>
                        <div style="font-size: 0.88rem; font-weight: 600; color: #0f172a;" id="qmDropoffAddress">—</div>
                    </div>
                </div>
                <div
                    style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #e2e8f0;">
                    <div>
                        <div style="font-size: 0.72rem; color: #94a3b8; margin-bottom: 3px;">Distance</div>
                        <div style="font-size: 0.88rem; font-weight: 600; color: #0f172a;" id="qmDistance">—</div>
                    </div>
                    <div>
                        <div style="font-size: 0.72rem; color: #94a3b8; margin-bottom: 3px;">Truck Type</div>
                        <div style="font-size: 0.88rem; font-weight: 600; color: #0f172a;" id="qmTruckType">—</div>
                    </div>
                </div>
            </div>

            <!-- Vehicle Photos -->
            <div id="qmImageGallery"
                style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 20px; display: none;">
                <div
                    style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px;">
                    Vehicle Photos</div>
                <div id="qmImageGrid" style="display: flex; gap: 8px; flex-wrap: wrap;"></div>
                <p id="qmNoImages" style="color: #94a3b8; font-size: 0.85rem; margin: 0; display: none;">No photos
                    uploaded.</p>
            </div>

            <!-- Price Breakdown -->
            <div style="border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-bottom: 20px;">
                <div style="padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                    <span
                        style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Price
                        Breakdown</span>
                </div>
                <div style="padding: 16px; display: grid; gap: 10px;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.88rem; color: #475569;">
                        <span>Base Rate (Unit)</span>
                        <span style="font-weight: 600;" id="qmBasePrice">TBD</span>
                    </div>
                    <div id="qmDistanceFeeRow"
                        style="display: flex; justify-content: space-between; font-size: 0.88rem; color: #475569;">
                        <span id="qmDistanceFeeLabel">Per-4km Charge</span>
                        <span style="font-weight: 600;" id="qmDistanceFee">₱0.00</span>
                    </div>
                    <div id="qmOtherFeesRow"
                        style="display: none; flex-direction: row; justify-content: space-between; font-size: 0.88rem; color: #475569;">
                        <span>Additional Fees</span>
                        <span style="font-weight: 600;" id="qmOtherFees">₱0.00</span>
                    </div>
                    <div
                        style="border-top: 1px solid #e2e8f0; padding-top: 12px; display: flex; justify-content: space-between; align-items: baseline;">
                        <span style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">Total</span>
                        <span style="font-size: 1.2rem; font-weight: 800; color: #0f172a;"
                            id="qmTotalAmount">₱0.00</span>
                    </div>
                </div>
            </div>

            <!-- Unit Assignment (shown for pending quotations) -->
            <div id="qmUnitSection"
                style="border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-bottom: 20px; display: none;">
                <div style="padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                    <span
                        style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Assign
                        Unit</span>
                </div>
                <div style="padding: 16px;">
                    <label
                        style="display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin-bottom: 6px;">Available
                        Unit <span style="color: #dc2626;">*</span></label>
                    <select id="qmUnitSelect"
                        style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.9rem; color: #0f172a; outline: none; box-sizing: border-box; background: #fff;">
                        <option value="">Select a unit</option>
                        @forelse($availableUnits as $unit)
                            <option value="{{ $unit['id'] }}" data-base-rate="{{ $unit['base_rate'] ?? 0 }}">
                                {{ $unit['label'] }} ({{ ucfirst($unit['truck_class'] ?? 'N/A') }}) ·
                                {{ $unit['team_leader_name'] }}
                            </option>
                        @empty
                            <option value="" disabled>No online ready units available</option>
                        @endforelse
                    </select>
                    <small style="font-size: 0.78rem; color: #94a3b8; margin-top: 6px; display: block;">Selecting a unit
                        adds the unit base rate to the total. Unit must be assigned before sending.</small>
                </div>
            </div>

            <!-- Edit Price -->
            <div style="border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-bottom: 20px;">
                <div
                    style="padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                    <span
                        style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Adjust
                        Price</span>
                    <span style="font-size: 0.72rem; color: #94a3b8;">Optional — enter 0 to keep base price</span>
                </div>
                <div style="padding: 16px; display: grid; gap: 14px;">
                    <div>
                        <label
                            style="display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin-bottom: 6px;">Additional
                            Fees (₱)</label>
                        <input type="number" id="qmOtherFeesInput" step="0.01" min="0" value="0.00"
                            onfocus="if(this.value == '0' || this.value == '0.00') this.value = '';"
                            onblur="if(this.value == '') this.value = '0.00';"
                            style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.9rem; color: #0f172a; outline: none; box-sizing: border-box;"
                            onfocusin="this.style.borderColor='#6366f1'"
                            onfocusout="this.style.borderColor='#d1d5db'">
                    </div>
                    <div
                        style="background: #f1f5f9; border-radius: 8px; padding: 12px 14px; display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 0.85rem; color: #475569; font-weight: 500;">New Total</span>
                        <span style="font-size: 1.15rem; font-weight: 800; color: #0f172a;"
                            id="qmCalculatedPrice">₱0.00</span>
                    </div>
                    <div>
                        <label
                            style="display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin-bottom: 6px;">Note
                            <span style="font-weight: 400; color: #94a3b8;">(optional)</span></label>
                        <textarea id="qmPriceNote" rows="2" placeholder="example Rush fee, toll charges etc."
                            style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.88rem; color: #0f172a; resize: vertical; outline: none; box-sizing: border-box;"
                            onfocusin="this.style.borderColor='#6366f1'" onfocusout="this.style.borderColor='#d1d5db'"></textarea>
                    </div>
                </div>
            </div>

            <!-- Counter Offer (shown when customer negotiated) -->
            <div id="qmCounterOfferSection"
                style="display: none; border: 1px solid #fde68a; border-radius: 10px; overflow: hidden; margin-bottom: 20px;">
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

        </div>

        <!-- Modal Footer / Actions -->
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
                        img.onerror = function() {
                            this.style.display = 'none';
                        };
                        img.onmouseover = function() {
                            this.style.opacity = '0.8';
                        };
                        img.onmouseout = function() {
                            this.style.opacity = '1';
                        };
                        img.onclick = function() {
                            window.open(this.src, '_blank');
                        };
                        grid.appendChild(img);
                    });
                }

                // Price data
                window.qmCustomerPrice = parseFloat(q.subtotal || 0);
                document.getElementById('qmOtherFeesInput').value = '0.00';
                document.getElementById('qmPriceNote').value = '';

                const distanceKm = parseFloat(q.distance_km || 0);
                const kmIncrements = Math.floor(distanceKm / 4);
                const distanceFee = kmIncrements * 200;

                document.getElementById('qmBasePrice').textContent = 'TBD';
                document.getElementById('qmDistanceFee').textContent = fmt(distanceFee);
                document.getElementById('qmDistanceFeeLabel').textContent =
                    `Per-4km (${kmIncrements} × ₱200)`;

                document.getElementById('qmTotalAmount').textContent = fmt(distanceFee);
                document.getElementById('qmCalculatedPrice').textContent = fmt(distanceFee);
                document.getElementById('qmOtherFeesRow').style.display = 'none';
                window.qmDistanceFee = distanceFee;

                // Show unit section for pending quotations
                const unitSection = document.getElementById('qmUnitSection');
                const unitSelect = document.getElementById('qmUnitSelect');
                if (q.status === 'pending') {
                    if (unitSection) unitSection.style.display = 'block';
                    if (unitSelect) {
                        unitSelect.onchange = function() {
                            recalcQuotationTotal();
                        };
                        // Auto-select the first available unit
                        const firstAvail = Array.from(unitSelect.options).find(o => o.value && !o.disabled);
                        unitSelect.value = firstAvail ? firstAvail.value : '';
                    }
                } else {
                    if (unitSection) unitSection.style.display = 'none';
                }

                function recalcQuotationTotal() {
                    const selectedOpt = unitSelect ? unitSelect.options[unitSelect.selectedIndex] : null;
                    const baseRate = selectedOpt && unitSelect.value ?
                        parseFloat(selectedOpt.getAttribute('data-base-rate') || 0) :
                        0;
                    document.getElementById('qmBasePrice').textContent = baseRate > 0 ? fmt(baseRate) : 'TBD';
                    const fees = parseFloat(document.getElementById('qmOtherFeesInput').value || 0);
                    const newTotal = baseRate + (window.qmDistanceFee || 0) + fees;
                    document.getElementById('qmTotalAmount').textContent = fmt(newTotal);
                    document.getElementById('qmCalculatedPrice').textContent = fmt(newTotal);
                    window.qmCustomerPrice = baseRate + (window.qmDistanceFee || 0);
                }

                // Run initial calc with auto-selected unit
                if (q.status === 'pending') recalcQuotationTotal();

                // Real-time total recalc
                const otherFeesInput = document.getElementById('qmOtherFeesInput');
                otherFeesInput.oninput = function() {
                    const fees = parseFloat(this.value || 0);
                    const otherRow = document.getElementById('qmOtherFeesRow');
                    if (fees !== 0) {
                        otherRow.style.display = 'flex';
                        document.getElementById('qmOtherFees').textContent = fees >= 0 ? fmt(fees) :
                            `- ${fmt(Math.abs(fees))}`;
                    } else {
                        otherRow.style.display = 'none';
                    }
                    recalcQuotationTotal();
                };

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
                const sendBtn = document.getElementById('qmSendBtn');
                const updateBtn = document.getElementById('qmUpdatePriceBtn');
                if (q.status === 'pending') {
                    sendBtn.style.display = 'inline-block';
                    updateBtn.textContent = 'Update & Send';
                } else {
                    sendBtn.style.display = 'none';
                    updateBtn.textContent = 'Update Price';
                }
            })
            .catch(err => {
                showModalMessage(`Error: ${err.message}`, 'error');
            });
    }

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

        const unitSelect = document.getElementById('qmUnitSelect');
        const unitSection = document.getElementById('qmUnitSection');
        const unitRequired = unitSection && unitSection.style.display !== 'none';
        if (unitRequired && unitSelect && !unitSelect.value) {
            showModalMessage('Please assign a unit before updating the quotation.', 'error');
            unitSelect.focus();
            return;
        }

        const otherFees = parseFloat(document.getElementById('qmOtherFeesInput').value || 0);
        const selectedOpt = unitSelect ? unitSelect.options[unitSelect.selectedIndex] : null;
        const unitBaseRate = selectedOpt && unitSelect && unitSelect.value ?
            parseFloat(selectedOpt.getAttribute('data-base-rate') || 0) : 0;
        const newPrice = unitBaseRate + (window.qmDistanceFee || 0) + otherFees;
        const note = document.getElementById('qmPriceNote').value;
        const assignedUnitId = unitSelect ? (unitSelect.value || null) : null;

        if (newPrice < 0) {
            showModalMessage('Price cannot be negative', 'error');
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
                    btn.textContent = 'Update Price';
                }
            })
            .catch(() => {
                showModalMessage('Error updating price', 'error');
                btn.disabled = false;
                btn.textContent = 'Update Price';
            });
    }

    function sendQuotationFromModal() {
        if (!currentQuotationId) return;
        sendQuotationToCustomer(currentQuotationId);
    }

    function sendQuotationToCustomer(quotationId) {
        const id = quotationId || currentQuotationId;
        if (!id) return;

        const unitSelect = document.getElementById('qmUnitSelect');
        const unitSection = document.getElementById('qmUnitSection');
        const unitRequired = unitSection && unitSection.style.display !== 'none';
        if (unitRequired && unitSelect && !unitSelect.value) {
            showModalMessage('Please assign a unit before sending the quotation.', 'error');
            unitSelect.focus();
            return;
        }

        const btn = document.getElementById('qmSendBtn');
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
                body: JSON.stringify({
                    assigned_unit_id: assignedUnitId
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // If modal is open, show message there; otherwise show alert
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
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = originalText;
                    }
                    if (document.getElementById('quotationModal').style.display === 'flex') {
                        showModalMessage(data.message || 'Failed to send', 'error');
                    } else {
                        alert('❌ ' + (data.message || 'Failed to send quotation'));
                    }
                }
            })
            .catch(err => {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = originalText;
                }
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
            success: {
                bg: '#f0fdf4',
                color: '#166534',
                border: '#86efac',
                icon: '✓'
            },
            error: {
                bg: '#fef2f2',
                color: '#991b1b',
                border: '#fca5a5',
                icon: '✕'
            },
            info: {
                bg: '#f0f9ff',
                color: '#0369a1',
                border: '#7dd3fc',
                icon: 'ℹ'
            },
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
            setTimeout(() => {
                if (div.parentNode) div.remove();
            }, 5000);
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeQuotationModal();
    });

    document.getElementById('quotationModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeQuotationModal();
    });
</script>
