<style>
    .incoming-card {
        position: relative;
    }

    .bdm-trigger-btn {
        border: 1px solid #000;
        background: #fff;
        color: #000;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 5px 10px;
        border-radius: 8px;
        cursor: pointer;
        line-height: 1;
    }

    .bdm-trigger-btn:hover {
        background: #facc15;
    }

    .bdm-trigger-btn--card {
        position: absolute;
        top: 12px;
        right: 12px;
    }

    #bookingDetailModalOverlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9500;
        background: rgba(15, 23, 42, 0.55);
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    #bookingDetailModalOverlay .modal-card {
        width: min(1600px, 96vw);
        max-width: 760px;
        max-height: 92vh;
        background: #fff;
        display: flex;
        flex-direction: column;
        border-radius: 16px;
        overflow: hidden;
        box-sizing: border-box;
    }

    .bdm-header {
        padding: 16px 22px 14px;
        border-bottom: 2px solid #000;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-shrink: 0;
        background: #fff;
        gap: 12px;
    }

    .bdm-header-titles {
        min-width: 0;
    }

    .bdm-header-titles h3 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 800;
        color: #000;
        letter-spacing: -0.01em;
        word-break: break-word;
    }

    .bdm-header-meta {
        margin: 4px 0 0;
        font-size: 0.78rem;
        font-weight: 600;
        color: #6b7280;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
    }

    .bdm-badge {
        display: inline-block;
        font-size: 0.66rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 3px 8px;
        border-radius: 999px;
        background: #f1f5f9;
        color: #334155;
        white-space: nowrap;
    }

    .bdm-badge--type {
        background: #111827;
        color: #facc15;
    }

    .bdm-close-btn {
        width: 30px;
        height: 30px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fff;
        color: #000;
        font-size: 1.2rem;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
        flex-shrink: 0;
    }

    .bdm-body {
        flex: 1;
        overflow-y: auto;
        min-height: 0;
        padding: 18px 24px;
    }

    .bdm-section {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 16px;
    }

    .bdm-section-hdr {
        padding: 9px 14px;
        background: #111827;
        font-size: 0.7rem;
        font-weight: 800;
        color: #facc15;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .bdm-section-body {
        padding: 14px;
        background: #fff;
        font-size: 0.85rem;
        color: #0f172a;
        word-break: break-word;
    }

    .bdm-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        padding: 5px 0;
    }

    .bdm-row-label {
        color: #6b7280;
        font-size: 0.78rem;
        flex-shrink: 0;
    }

    .bdm-row-value {
        text-align: right;
        font-weight: 600;
        min-width: 0;
        word-break: break-word;
    }

    .bdm-empty-state {
        color: #94a3b8;
        font-size: 0.82rem;
        font-style: italic;
    }

    .bdm-vehicle-card {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 10px 12px;
        margin-bottom: 8px;
        background: #f8fafc;
    }

    .bdm-vehicle-card:last-child {
        margin-bottom: 0;
    }

    .bdm-action-link {
        display: inline-block;
        margin-top: 10px;
        padding: 7px 14px;
        border: 2px solid #000;
        background: #facc15;
        color: #000;
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        cursor: pointer;
        border-radius: 6px;
    }

    .bdm-footer {
        padding: 14px 24px;
        border-top: 2px solid #000;
        display: flex;
        justify-content: flex-end;
        flex-shrink: 0;
        background: #fff;
    }

    .bdm-footer button {
        padding: 8px 16px;
        border: 2px solid #d1d5db;
        background: #fff;
        color: #374151;
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        border-radius: 6px;
    }

    @media (max-width: 560px) {
        #bookingDetailModalOverlay {
            padding: 0;
        }

        #bookingDetailModalOverlay .modal-card {
            width: 100vw;
            max-width: 100vw;
            max-height: 100vh;
            height: 100vh;
            border-radius: 0;
        }
    }
</style>

<div id="bookingDetailModalOverlay" aria-hidden="true" role="dialog" aria-modal="true"
    aria-labelledby="bdmTitle">
    <div class="modal-card">
        <div class="bdm-header">
            <div class="bdm-header-titles">
                <h3 id="bdmTitle">Booking Details</h3>
                <p class="bdm-header-meta">
                    <span id="bdmBookingCode">—</span>
                    <span class="bdm-badge bdm-badge--type" id="bdmServiceType">—</span>
                    <span class="bdm-badge" id="bdmStatus">—</span>
                </p>
            </div>
            <button type="button" class="bdm-close-btn" onclick="window.closeBookingDetailModal()"
                aria-label="Close booking details">×</button>
        </div>

        <div class="bdm-body" id="bdmBody">
            <div class="bdm-section">
                <div class="bdm-section-hdr">Customer</div>
                <div class="bdm-section-body" id="bdmCustomerSection"></div>
            </div>

            <div class="bdm-section">
                <div class="bdm-section-hdr">Trip</div>
                <div class="bdm-section-body" id="bdmTripSection"></div>
            </div>

            <div class="bdm-section">
                <div class="bdm-section-hdr" id="bdmVehiclesHeader">Vehicles</div>
                <div class="bdm-section-body" id="bdmVehiclesSection"></div>
            </div>

            <div class="bdm-section">
                <div class="bdm-section-hdr">Quotation</div>
                <div class="bdm-section-body" id="bdmQuotationSection"></div>
            </div>

            <div class="bdm-section">
                <div class="bdm-section-hdr">Assignment</div>
                <div class="bdm-section-body" id="bdmAssignmentSection"></div>
            </div>

            <div class="bdm-section">
                <div class="bdm-section-hdr">Invoice</div>
                <div class="bdm-section-body" id="bdmInvoiceSection"></div>
            </div>

            <div class="bdm-section">
                <div class="bdm-section-hdr">Receipt</div>
                <div class="bdm-section-body" id="bdmReceiptSection"></div>
            </div>
        </div>

        <div class="bdm-footer">
            <button type="button" onclick="window.closeBookingDetailModal()">Close</button>
        </div>
    </div>
</div>
