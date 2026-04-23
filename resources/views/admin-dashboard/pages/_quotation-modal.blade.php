<!-- Quotation View/Edit Modal -->
<div id="quotationModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 20px;" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card" style="width: min(900px, 100%); max-height: 90vh; overflow-y: auto; background: #fff; border-radius: 20px; padding: 28px; box-shadow: 0 24px 60px rgba(0,0,0,0.2);">
        
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
            <div>
                <h3 style="margin: 0 0 4px; font-size: 1.3rem; font-weight: 700; color: #0f172a;" id="quotationModalTitle">Quotation Details</h3>
                <p style="margin: 0; font-size: 0.9rem; color: #64748b;" id="quotationModalSubtitle">Review and edit quotation before sending</p>
            </div>
            <button type="button" onclick="closeQuotationModal()" style="width: 36px; height: 36px; border-radius: 8px; border: 1px solid #e5e7eb; background: #fff; color: #64748b; font-size: 1.2rem; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                ×
            </button>
        </div>

        <!-- Customer Info -->
        <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 1px solid #93c5fd; border-radius: 14px; padding: 16px; margin-bottom: 20px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                <div>
                    <div style="font-size: 0.75rem; color: #1e40af; font-weight: 600; margin-bottom: 2px;">Customer</div>
                    <div style="font-size: 0.95rem; font-weight: 600; color: #0f172a;" id="qmCustomerName">—</div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: #1e40af; font-weight: 600; margin-bottom: 2px;">Phone</div>
                    <div style="font-size: 0.95rem; font-weight: 600; color: #0f172a;" id="qmCustomerPhone">—</div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: #1e40af; font-weight: 600; margin-bottom: 2px;">Quotation #</div>
                    <div style="font-size: 0.95rem; font-weight: 600; color: #0f172a;" id="qmQuotationNumber">—</div>
                </div>
            </div>
        </div>

        <!-- Service Details -->
        <div style="background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 12px; font-size: 0.95rem; font-weight: 700; color: #0f172a;">Service Details</h4>
            
            <div style="display: grid; gap: 10px;">
                <div style="display: flex; gap: 10px;">
                    <div style="flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%; background: #dcfce7; color: #166534; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem;">A</div>
                    <div style="flex: 1;">
                        <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 2px;">Pickup</div>
                        <div style="font-size: 0.9rem; font-weight: 600; color: #0f172a;" id="qmPickupAddress">—</div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <div style="flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%; background: #fee2e2; color: #991b1b; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem;">B</div>
                    <div style="flex: 1;">
                        <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 2px;">Drop-off</div>
                        <div style="font-size: 0.9rem; font-weight: 600; color: #0f172a;" id="qmDropoffAddress">—</div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-top: 8px;">
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 2px;">Distance</div>
                        <div style="font-size: 0.9rem; font-weight: 600; color: #0f172a;" id="qmDistance">—</div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 2px;">Truck Type</div>
                        <div style="font-size: 0.9rem; font-weight: 600; color: #0f172a;" id="qmTruckType">—</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Price Breakdown -->
        <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 14px; padding: 16px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 12px; font-size: 0.95rem; font-weight: 700; color: #0c4a6e;">💵 Price Breakdown</h4>
            
            <div style="display: grid; gap: 8px;">
                <div style="display: flex; justify-content: space-between; font-size: 0.9rem; color: #334155;">
                    <span>Base Price</span>
                    <span style="font-weight: 600;" id="qmBasePrice">₱0.00</span>
                </div>
                <div id="qmDistanceFeeRow" style="display: flex; justify-content: space-between; font-size: 0.9rem; color: #334155;">
                    <span id="qmDistanceFeeLabel">Distance Fee</span>
                    <span style="font-weight: 600;" id="qmDistanceFee">₱0.00</span>
                </div>
                <div id="qmExcessFeeRow" style="display: none; padding-left: 16px; border-left: 2px solid #fbbf24; margin-left: 8px;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #78350f; margin-bottom: 4px;">
                        <span>First 4 km × <span id="qmPerKmRate">₱0.00</span>/km</span>
                        <span style="font-weight: 600;" id="qmFirst4KmFee">₱0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #78350f;">
                        <span><span id="qmExcessKm">0.00</span> km × ₱200/km</span>
                        <span style="font-weight: 600;" id="qmExcessKmFee">₱0.00</span>
                    </div>
                </div>
                <div style="border-top: 1px dashed #bae6fd; margin-top: 4px; padding-top: 8px; display: flex; justify-content: space-between; font-size: 0.95rem; color: #0c4a6e; font-weight: 600;">
                    <span>Customer's Expected Price</span>
                    <span id="qmCustomerPrice">₱0.00</span>
                </div>
                <div id="qmOtherFeesRow" style="display: none; flex-direction: row; justify-content: space-between; font-size: 0.9rem; color: #334155;">
                    <span>Other Fees</span>
                    <span style="font-weight: 600;" id="qmOtherFees">₱0.00</span>
                </div>
                <div style="border-top: 2px solid #0c4a6e; margin-top: 8px; padding-top: 12px; display: flex; justify-content: space-between; font-size: 1.1rem; font-weight: 700; color: #0c4a6e;">
                    <span>TOTAL AMOUNT</span>
                    <span id="qmTotalAmount">₱0.00</span>
                </div>
            </div>
        </div>

        <!-- Edit Price Form -->
        <div style="background: #fffbeb; border: 2px solid #fbbf24; border-radius: 14px; padding: 16px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 12px; font-size: 0.95rem; font-weight: 700; color: #92400e;">💰 Edit Quotation Price</h4>
            
            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #78350f; margin-bottom: 6px;">Other Fees (₱)</label>
                <input type="number" id="qmOtherFeesInput" step="0.01" min="0" value="0.00" onfocus="if(this.value == '0' || this.value == '0.00') this.value = '';" onblur="if(this.value == '') this.value = '0.00';" style="width: 100%; padding: 10px 12px; border: 1px solid #fbbf24; border-radius: 10px; font-size: 0.9rem; background: #fff;">
                <small style="font-size: 0.75rem; color: #78350f; margin-top: 4px; display: block;">Extra charges (toll, parking, etc.)</small>
            </div>

            <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 10px; padding: 12px; margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.9rem; font-weight: 600; color: #78350f;">New Total Price:</span>
                    <span style="font-size: 1.2rem; font-weight: 700; color: #92400e;" id="qmCalculatedPrice">₱0.00</span>
                </div>
                <small style="font-size: 0.75rem; color: #78350f; margin-top: 4px; display: block;">Customer's Price + Other Fees</small>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #78350f; margin-bottom: 6px;">Note (Optional)</label>
                <textarea id="qmPriceNote" rows="2" placeholder="Reason for price adjustment..." style="width: 100%; padding: 10px 12px; border: 1px solid #fbbf24; border-radius: 10px; font-size: 0.9rem; background: #fff; resize: vertical;"></textarea>
            </div>
        </div>

        <!-- Counter Offer Info (if exists) -->
        <div id="qmCounterOfferSection" style="display: none; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 14px; padding: 14px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 1.2rem;">💬</span>
                <span style="font-size: 0.9rem; font-weight: 700; color: #92400e;">Customer Counter Offer</span>
            </div>
            <div style="display: grid; grid-template-columns: auto 1fr; gap: 8px; font-size: 0.85rem; color: #78350f;">
                <strong>Amount:</strong>
                <span id="qmCounterOfferAmount">—</span>
                <strong>Note:</strong>
                <span id="qmCounterOfferNote">—</span>
            </div>
        </div>

        <!-- Actions -->
        <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 16px; border-top: 1px solid #e5e7eb;">
            <button type="button" onclick="closeQuotationModal()" style="padding: 10px 20px; border-radius: 10px; border: 1px solid #e5e7eb; background: #fff; color: #475569; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                Cancel
            </button>
            <button type="button" id="qmCancelQuotationBtn" onclick="cancelQuotation()" style="padding: 10px 20px; border-radius: 10px; border: none; background: #dc2626; color: #fff; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
                ❌ Cancel Quotation
            </button>
            <button type="button" id="qmUpdatePriceBtn" onclick="updateQuotationPrice()" style="padding: 10px 20px; border-radius: 10px; border: none; background: #f59e0b; color: #fff; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#d97706'" onmouseout="this.style.background='#f59e0b'">
                💾 Update & Send
            </button>
            <button type="button" id="qmSendBtn" onclick="sendQuotationFromModal()" style="padding: 10px 20px; border-radius: 10px; border: none; background: #10b981; color: #fff; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#059669'" onmouseout="this.style.background='#10b981'">
                📤 Send Quotation
            </button>
        </div>

    </div>
</div>

<script>
let currentQuotationId = null;

function viewQuotationDetails(quotationId) {
    currentQuotationId = quotationId;
    
    // Show loading state
    const modal = document.getElementById('quotationModal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    
    showModalMessage('Loading quotation details...', 'info');
    
    // Fetch quotation details
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
            console.log('Response status:', r.status);
            console.log('Response headers:', r.headers);
            if (!r.ok) {
                return r.text().then(text => {
                    console.error('Error response:', text);
                    throw new Error(`HTTP ${r.status}: ${text.substring(0, 100)}`);
                });
            }
            return r.json();
        })
        .then(data => {
            console.log('Quotation data:', data);
            // Remove loading message
            const loadingMsg = document.getElementById('qmMessage');
            if (loadingMsg) loadingMsg.remove();
            
            if (!data.success) {
                showModalMessage(data.message || 'Failed to load quotation', 'error');
                // DON'T auto-close on error - let user read the message
                return;
            }
            
            const q = data.quotation;
            
            // Check if all elements exist before setting values
            const elements = {
                qmCustomerName: document.getElementById('qmCustomerName'),
                qmCustomerPhone: document.getElementById('qmCustomerPhone'),
                qmQuotationNumber: document.getElementById('qmQuotationNumber'),
                qmPickupAddress: document.getElementById('qmPickupAddress'),
                qmDropoffAddress: document.getElementById('qmDropoffAddress'),
                qmDistance: document.getElementById('qmDistance'),
                qmTruckType: document.getElementById('qmTruckType'),
                qmPriceNote: document.getElementById('qmPriceNote'),
                qmBasePrice: document.getElementById('qmBasePrice'),
                qmDistanceFee: document.getElementById('qmDistanceFee'),
                qmCustomerPrice: document.getElementById('qmCustomerPrice'),
                qmDistanceFeeLabel: document.getElementById('qmDistanceFeeLabel'),
                qmExcessFeeRow: document.getElementById('qmExcessFeeRow'),
                qmCounterOfferSection: document.getElementById('qmCounterOfferSection'),
                qmSendBtn: document.getElementById('qmSendBtn'),
                qmUpdatePriceBtn: document.getElementById('qmUpdatePriceBtn')
            };
            
            // Check for missing elements
            const missingElements = Object.entries(elements)
                .filter(([key, el]) => !el)
                .map(([key]) => key);
            
            if (missingElements.length > 0) {
                console.error('Missing modal elements:', missingElements);
                showModalMessage(`Modal elements not found: ${missingElements.join(', ')}`, 'error');
                return;
            }
            
            // Populate modal - now safe to set values
            elements.qmCustomerName.textContent = q.customer_name || '—';
            elements.qmCustomerPhone.textContent = q.customer_phone || '—';
            elements.qmQuotationNumber.textContent = q.quotation_number || '—';
            elements.qmPickupAddress.textContent = q.pickup_address || '—';
            elements.qmDropoffAddress.textContent = q.dropoff_address || '—';
            elements.qmDistance.textContent = q.distance_km ? `${q.distance_km} km` : '—';
            elements.qmTruckType.textContent = q.truck_type || '—';
            
            // Store customer's expected price (subtotal) for calculation
            window.qmCustomerPrice = parseFloat(q.subtotal || 0);
            
            // Set initial value for other fees input - always start at 0
            document.getElementById('qmOtherFeesInput').value = '0.00';
            document.getElementById('qmPriceNote').value = '';
            
            // Price breakdown with 4km rule
            const distanceKm = parseFloat(q.distance_km || 0);
            const perKmRate = parseFloat(q.per_km_rate || 0);
            const distanceFee = parseFloat(q.distance_fee || 0);
            
            elements.qmBasePrice.textContent = `₱${parseFloat(q.base_price || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            elements.qmDistanceFee.textContent = `₱${distanceFee.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            
            // Customer's Expected Price (same as total - what customer sees)
            const customerPrice = parseFloat(q.subtotal || 0);
            document.getElementById('qmCustomerPrice').textContent = `₱${customerPrice.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            
            // Other fees row is hidden - additional_fee is for dispatcher reference only
            const otherFeesRow = document.getElementById('qmOtherFeesRow');
            otherFeesRow.style.display = 'none';
            
            // Total amount (same as customer price)
            const totalAmount = customerPrice;
            document.getElementById('qmTotalAmount').textContent = `₱${totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            
            // Set calculated price display
            document.getElementById('qmCalculatedPrice').textContent = `₱${totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            
            // Add event listener for real-time calculation
            const otherFeesInput = document.getElementById('qmOtherFeesInput');
            
            function updateCalculatedPrice() {
                const customerPrice = window.qmCustomerPrice || 0;
                const fees = parseFloat(otherFeesInput.value || 0);
                const newTotal = customerPrice + fees;
                
                document.getElementById('qmCalculatedPrice').textContent = `₱${newTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                
                // Update the breakdown display
                const otherFeesRow = document.getElementById('qmOtherFeesRow');
                if (fees > 0) {
                    otherFeesRow.style.display = 'flex';
                    document.getElementById('qmOtherFees').textContent = `₱${fees.toFixed(2)}`;
                } else if (fees < 0) {
                    otherFeesRow.style.display = 'flex';
                    document.getElementById('qmOtherFees').textContent = `- ₱${Math.abs(fees).toFixed(2)}`;
                } else {
                    otherFeesRow.style.display = 'none';
                }
                
                document.getElementById('qmTotalAmount').textContent = `₱${newTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            }
            
            otherFeesInput.addEventListener('input', updateCalculatedPrice);
            
            // Show breakdown if distance > 4km
            if (q.has_excess) {
                const first4KmFee = 4 * perKmRate;
                const excessKm = parseFloat(q.excess_km || 0);
                const excessFee = excessKm * 200;
                
                elements.qmDistanceFeeLabel.textContent = `Distance Fee (${distanceKm.toFixed(2)} km)`;
                elements.qmExcessFeeRow.style.display = 'block';
                document.getElementById('qmPerKmRate').textContent = `₱${perKmRate.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                document.getElementById('qmFirst4KmFee').textContent = `₱${first4KmFee.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                document.getElementById('qmExcessKm').textContent = excessKm.toFixed(2);
                document.getElementById('qmExcessKmFee').textContent = `₱${excessFee.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            } else {
                elements.qmDistanceFeeLabel.textContent = `Distance (${distanceKm.toFixed(2)} km × ₱${perKmRate.toFixed(2)}/km)`;
                elements.qmExcessFeeRow.style.display = 'none';
            }
            
            // Counter offer section
            if (q.counter_offer_amount) {
                elements.qmCounterOfferSection.style.display = 'block';
                document.getElementById('qmCounterOfferAmount').textContent = `₱${parseFloat(q.counter_offer_amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                document.getElementById('qmCounterOfferNote').textContent = q.response_note || 'No note provided';
            } else {
                elements.qmCounterOfferSection.style.display = 'none';
            }
            
            // Show/hide buttons based on status
            if (q.status === 'pending') {
                elements.qmSendBtn.style.display = 'inline-block';
                elements.qmUpdatePriceBtn.textContent = '💾 Update & Send';
            } else {
                elements.qmSendBtn.style.display = 'none';
                elements.qmUpdatePriceBtn.textContent = '💾 Update Price';
            }
            
            // Show modal
            const modal = document.getElementById('quotationModal');
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
        })
        .catch(err => {
            console.error('Fetch error:', err);
            console.error('Error details:', err.message);
            showModalMessage(`Error loading quotation: ${err.message}`, 'error');
            // DON'T auto-close on error - let user read the message and close manually
        });
}

function closeQuotationModal() {
    const modal = document.getElementById('quotationModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    currentQuotationId = null;
}

function updateQuotationPrice() {
    if (!currentQuotationId) return;
    
    const otherFees = parseFloat(document.getElementById('qmOtherFeesInput').value || 0);
    const customerPrice = window.qmCustomerPrice || 0;
    const newPrice = customerPrice + otherFees;
    const note = document.getElementById('qmPriceNote').value;
    
    if (newPrice < 0) {
        showModalMessage('Price cannot be negative', 'error');
        return;
    }
    
    const btn = document.getElementById('qmUpdatePriceBtn');
    const originalText = btn.textContent;
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
            note: note
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showModalMessage(data.message || 'Price updated successfully', 'success');
            setTimeout(() => {
                closeQuotationModal();
                location.reload();
            }, 1500);
        } else {
            showModalMessage(data.message || 'Failed to update price', 'error');
            btn.disabled = false;
            btn.textContent = originalText;
        }
    })
    .catch(err => {
        console.error(err);
        showModalMessage('Error updating price', 'error');
        btn.disabled = false;
        btn.textContent = originalText;
    });
}

function sendQuotationFromModal() {
    if (!currentQuotationId) return;
    sendQuotationToCustomer(currentQuotationId);
}

function sendQuotationToCustomer(quotationId) {
    if (!currentQuotationId) return;
    
    const btn = document.getElementById('qmSendBtn');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Sending...';
    
    fetch(`/admin-dashboard/quotations/${quotationId}/send`, {
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
            showModalMessage(data.message || 'Quotation sent successfully to customer via email', 'success');
            setTimeout(() => {
                closeQuotationModal();
                location.reload();
            }, 2000);
        } else {
            showModalMessage(data.message || 'Failed to send quotation', 'error');
            btn.disabled = false;
            btn.textContent = originalText;
        }
    })
    .catch(err => {
        console.error(err);
        showModalMessage('Error sending quotation', 'error');
        btn.disabled = false;
        btn.textContent = originalText;
    });
}

function cancelQuotation() {
    if (!currentQuotationId) return;
    
    const btn = document.getElementById('qmCancelQuotationBtn');
    const originalText = btn.textContent;
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
            showModalMessage(data.message || 'Quotation cancelled successfully', 'success');
            setTimeout(() => {
                closeQuotationModal();
                location.reload();
            }, 1500);
        } else {
            showModalMessage(data.message || 'Failed to cancel quotation', 'error');
            btn.disabled = false;
            btn.textContent = originalText;
        }
    })
    .catch(err => {
        console.error(err);
        showModalMessage('Error cancelling quotation', 'error');
        btn.disabled = false;
        btn.textContent = originalText;
    });
}

function showModalMessage(message, type = 'info') {
    // Remove existing message if any
    const existingMsg = document.getElementById('qmMessage');
    if (existingMsg) existingMsg.remove();
    
    // Create message element
    const msgDiv = document.createElement('div');
    msgDiv.id = 'qmMessage';
    msgDiv.style.cssText = `
        padding: 12px 16px;
        border-radius: 10px;
        margin-bottom: 16px;
        font-size: 0.9rem;
        font-weight: 600;
        text-align: center;
        animation: slideDown 0.3s ease;
    `;
    
    if (type === 'success') {
        msgDiv.style.background = '#dcfce7';
        msgDiv.style.color = '#166534';
        msgDiv.style.border = '1px solid #86efac';
        msgDiv.innerHTML = `✅ ${message}`;
    } else if (type === 'error') {
        msgDiv.style.background = '#fee2e2';
        msgDiv.style.color = '#991b1b';
        msgDiv.style.border = '1px solid #fca5a5';
        msgDiv.innerHTML = `❌ ${message}`;
    } else {
        msgDiv.style.background = '#dbeafe';
        msgDiv.style.color = '#1e40af';
        msgDiv.style.border = '1px solid #93c5fd';
        msgDiv.innerHTML = `ℹ️ ${message}`;
    }
    
    // Insert at top of modal content
    const modalCard = document.querySelector('#quotationModal .modal-card');
    modalCard.insertBefore(msgDiv, modalCard.firstChild);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (msgDiv.parentNode) {
            msgDiv.style.animation = 'slideUp 0.3s ease';
            setTimeout(() => msgDiv.remove(), 300);
        }
    }, 5000);
}

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeQuotationModal();
    }
});

// Close modal on backdrop click
document.getElementById('quotationModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeQuotationModal();
    }
});

// Add animation styles
const style = document.createElement('style');
style.textContent = `
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    @keyframes slideUp {
        from {
            opacity: 1;
            transform: translateY(0);
        }
        to {
            opacity: 0;
            transform: translateY(-10px);
        }
    }
`;
document.head.appendChild(style);
</script>
