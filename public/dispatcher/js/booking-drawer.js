/**
 * Booking drawer ("View & Quote") — Book Now queue only.
 *
 * Scope: replaces the old #actionModal flow for #bookNowPanel cards with a
 * single slide-in drawer. Deliberately reloads the page after any mutating
 * action (save draft / send / cancel / assign / reject / accept-decline
 * counter) instead of patching the DOM optimistically — avoids client state
 * drifting from server truth. #incomingList/#actionModal and the Scheduled
 * queue are untouched and keep using their existing flows.
 *
 * See C:\Users\caval\.claude\plans\lets-brainstorm-and-clean-snappy-crystal.md
 * for the full implementation plan and endpoint map this file follows.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // Icons (inline SVG, no external icon library — matches CSP-free plain
    // hand-written JS already used elsewhere on this page).
    // ------------------------------------------------------------------
    var ICON_PATHS = {
        search: '<circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>',
        check: '<polyline points="20 6 9 17 4 12"></polyline>',
        fileText: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="10" y1="9" x2="8" y2="9"></line>',
        save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline>',
        send: '<line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>',
        trash: '<polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line>',
        plus: '<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>',
        pencil: '<path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path>',
        xCircle: '<circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line>',
        clock: '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
        user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
        mapPin: '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle>',
        chevronDown: '<polyline points="6 9 12 15 18 9"></polyline>',
        image: '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline>',
        starOutline: '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>'
    };
    function icon(name, size) {
        size = size || 16;
        return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:none;vertical-align:-3px;">' + (ICON_PATHS[name] || '') + '</svg>';
    }
    function starIconFilled(size) {
        size = size || 16;
        return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="flex:none;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>';
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    function peso(n) {
        n = Number(n) || 0;
        return '\u20b1' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function esc(s) {
        s = (s === null || s === undefined) ? '' : String(s);
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function timeAgoLabel(iso) {
        if (!iso) return '-';
        var then = new Date(iso).getTime();
        if (isNaN(then)) return '-';
        var sec = Math.max(0, Math.floor((Date.now() - then) / 1000));
        if (sec < 60) return sec + 's ago';
        if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
        if (sec < 86400) return Math.floor(sec / 3600) + 'h ago';
        return Math.floor(sec / 86400) + 'd ago';
    }
    function fillRoute(tpl, id) {
        return tpl.replace(':booking', id).replace(':quotation', id);
    }
    function csrfHeaders() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': window.RB_CSRF || ''
        };
    }
    function apiCall(url, method, payload) {
        return fetch(url, {
            method: method,
            headers: csrfHeaders(),
            body: JSON.stringify(payload || {})
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                return { ok: res.ok, status: res.status, data: data };
            });
        });
    }

    var AVAILABLE_UNITS = [];
    try {
        var unitsScriptEl = document.getElementById('rbAvailableUnitsJson');
        AVAILABLE_UNITS = unitsScriptEl ? JSON.parse(unitsScriptEl.textContent || '[]') : [];
    } catch (e) {
        AVAILABLE_UNITS = [];
    }

    // ------------------------------------------------------------------
    // State for the currently open drawer
    // ------------------------------------------------------------------
    var state = null; // built fresh each time the drawer opens

    function buildStateFromCard(cardEl) {
        var d = cardEl.dataset;
        var photos = [];
        try { photos = JSON.parse(d.photos || '[]'); } catch (e) { photos = []; }

        return {
            bookingCode: d.bookingCode || d.id,
            status: d.status,
            createdAt: d.createdAt,
            customerName: d.customerName || 'Guest',
            customerPhone: d.customerPhone || '',
            customerEmail: d.customerEmail || '',
            pickup: d.pickup || '',
            pickupNotes: d.pickupNotes || '',
            dropoff: d.dropoff || '',
            distanceKm: d.distanceKm || '',
            customerNote: d.customerNote || '',
            currentPrice: parseFloat(d.currentPrice || '0') || 0,
            // immutable snapshot of the customer's original estimate, for the reference
            // box only — unlike currentPrice, this is never overwritten by the
            // quotation-details fetch
            originalTotal: parseFloat(d.currentPrice || '0') || 0,
            baseRate: parseFloat(d.baseRate || '0') || 0,
            vatExclusiveTotal: parseFloat(d.vatExclusiveTotal || '0') || 0,
            vatAmount: parseFloat(d.vatAmount || '0') || 0,
            truckType: d.truckType || '',
            truckTypeId: parseInt(d.truckTypeId || '0', 10) || 0,
            vehicleCategory: d.vehicleCategory || '',
            photos: photos,
            dispatchZone: d.dispatchZone || 'General Dispatch Zone',
            recommendedUnitId: d.recommendedUnit || '',
            quotationId: d.quotationId || '',
            quotationStatus: d.quotationStatus || '',
            // client-only, never sent to the server directly — folded into one
            // update-price/save-draft/assign call at commit time (see §0.A of plan)
            adjustments: [],
            selectedUnitId: null,
            adjFormOpen: false,
            historyOpen: false,
            priceChangeLog: []
        };
    }

    /** Resolves the effective "card status" the footer/UI should treat this booking as. */
    function effectiveStatus(s) {
        if (s.quotationStatus === 'draft') return 'draft';
        if (s.quotationStatus === 'sent') return 'sent';
        if (s.quotationStatus === 'negotiating') return 'negotiating';
        if (s.quotationStatus === 'expired') return 'expired';
        if (s.status === 'confirmed' || s.status === 'scheduled_confirmed') return 'confirmed';
        return 'new';
    }

    function netAdjustment(s) {
        return s.adjustments.reduce(function (sum, a) { return sum + a.amount; }, 0);
    }
    /**
     * VAT-exclusive amount before any dispatcher-entered distance fee or staged
     * adjustments. Once a quotation exists, currentPrice already reflects the full
     * committed price (refreshed from getQuotationDetails), so deriving from it
     * (rather than the booking's own pre-quote vat_exclusive_total) keeps drafts
     * showing their real saved price instead of a stale customer-facing estimate.
     */
    function baseSubtotal(s) {
        return s.currentPrice / 1.12;
    }
    /**
     * MMDA formula: first km included in base fee, ₱300/km after that — must match
     * BookingService::distanceFeeFor() exactly (see confirmThenSend()). Only relevant
     * for a fresh booking with no quotation yet; an existing quotation's price already
     * has whatever distance fee was used when it was created.
     */
    function distanceFeeFor(s) {
        if (effectiveStatus(s) !== 'new') return 0;
        var km = parseFloat(s.distanceKm) || 0;
        return km > 0 ? Math.max(0, km - 1) * 300 : 0;
    }
    /** Total after distance fee + staged adjustments, VAT-inclusive, using the real 12% split. */
    function adjustedTotal(s) {
        var subtotal = baseSubtotal(s) + distanceFeeFor(s) + netAdjustment(s);
        var vat = subtotal * 0.12;
        return { subtotal: subtotal, vat: vat, total: subtotal + vat };
    }

    // ------------------------------------------------------------------
    // Drawer shell
    // ------------------------------------------------------------------
    var drawerEl = document.getElementById('rbDrawer');
    var drawerOverlayEl = document.getElementById('rbDrawerOverlay');

    window.openBookingDrawer = function (cardEl) {
        if (!cardEl) return;
        state = buildStateFromCard(cardEl);

        drawerOverlayEl.classList.add('is-open');
        drawerEl.classList.add('is-open');
        renderDrawer();

        // If there's a live quotation, fetch its real details (price change log,
        // exact current price, expiry) rather than trusting only the card's
        // page-load snapshot.
        if (state.quotationId) {
            var url = fillRoute(window.RB_ROUTES.quoteDetails, state.quotationId);
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(function (payload) {
                    // Response shape: { success: true, quotation: {...} } — see
                    // DispatchController::getQuotationDetails(). Note there is no
                    // "vehicle_category" field on a quotation (only make/model/year/
                    // color/plate) — Category stays sourced from the booking's own
                    // vehicleType relation set at drawer-open time; only Plate (and
                    // price/history/counter-offer) get refreshed here.
                    var data = payload && payload.quotation;
                    if (!data) return;
                    if (typeof data.estimated_price !== 'undefined') {
                        state.currentPrice = parseFloat(data.estimated_price) || state.currentPrice;
                    }
                    if (data.price_change_log && Array.isArray(data.price_change_log)) {
                        state.priceChangeLog = data.price_change_log;
                    }
                    if (data.vehicle_plate_number) state.vehiclePlate = data.vehicle_plate_number;
                    if (typeof data.distance_km !== 'undefined' && data.distance_km !== null && Number(data.distance_km) > 0) {
                        state.distanceKm = data.distance_km;
                    }
                    if (typeof data.counter_offer_amount !== 'undefined' && data.counter_offer_amount !== null) {
                        state.counterOfferAmount = parseFloat(data.counter_offer_amount) || null;
                    }
                    renderDrawer();
                })
                .catch(function () { /* keep showing the page-load snapshot on failure */ });
        }
    };

    function closeBookingDrawer() {
        if (state && state.adjustments.length) {
            var ok = window.confirm('You have unsaved price adjustments that haven\'t been sent yet. Close without saving?');
            if (!ok) return;
        }
        drawerEl.classList.remove('is-open');
        drawerOverlayEl.classList.remove('is-open');
        setTimeout(function () { drawerEl.innerHTML = ''; }, 220);
        state = null;
    }
    drawerOverlayEl.addEventListener('click', closeBookingDrawer);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeLightbox();
            closeUnitsModalIfOpen();
            if (drawerEl.classList.contains('is-open')) closeBookingDrawer();
        }
    });

    // ------------------------------------------------------------------
    // Section renderers
    // ------------------------------------------------------------------
    function requestSectionHtml(s) {
        var eff = effectiveStatus(s);
        var rows = [
            ['Mode', 'Book Now'],
            ['Submitted', esc(timeAgoLabel(s.createdAt))],
            ['Zone', esc(s.dispatchZone)],
            ['Truck class requested', esc(s.truckType)]
        ];
        var cells = rows.map(function (r, i) {
            var divider = (i === 2) ? '<div class="rb-grid-divider"></div>' : '';
            return divider + '<div><dt>' + r[0] + '</dt><dd>' + r[1] + '</dd></div>';
        }).join('');
        return '<div class="rb-section"><h4>Request</h4><div class="rb-grid">' + cells + '</div></div>';
    }

    function vehicleSectionHtml(s) {
        var rows = [
            ['Category', s.vehicleCategory ? esc(s.vehicleCategory) : '<span style="color:#8A93A3;">Not specified</span>'],
            ['Plate', s.vehiclePlate ? '<span class="rb-mono">' + esc(s.vehiclePlate) + '</span>' : '<span style="color:#8A93A3;">Not yet on file</span>']
        ];
        var cells = rows.map(function (r) { return '<div><dt>' + r[0] + '</dt><dd>' + r[1] + '</dd></div>'; }).join('');
        return '<div class="rb-section"><h4>Vehicle</h4><div class="rb-grid">' + cells + '</div>' + photoStackHtml(s) + '</div>';
    }

    function photoStackHtml(s) {
        var n = s.photos.length;
        if (!n) return '';
        var back = '';
        if (n >= 3) back += '<div class="rb-photo-stack-back rb-photo-stack-back-2"></div>';
        if (n >= 2) back += '<div class="rb-photo-stack-back rb-photo-stack-back-1"></div>';
        var badge = n > 1 ? '<div class="rb-photo-stack-badge">' + icon('image', 12) + ' ' + n + '</div>' : '';
        return '<div class="rb-photo-stack-wrap">' + back +
            '<div class="rb-photo-box" id="rbPhotoStackTrigger">' +
            '<img src="' + esc(s.photos[0]) + '" alt="Vehicle photo">' + badge +
            '</div></div>';
    }

    function routeSectionHtml(s) {
        var noteHtml = s.pickupNotes ? ' <span style="color:#8A93A3;">- ' + esc(s.pickupNotes) + '</span>' : '';
        var needsDistance = effectiveStatus(s) === 'new';
        var distanceHtml = needsDistance
            ? '<input type="text" inputmode="decimal" id="rbDistanceKm" class="rb-mono" style="width:80px;text-align:right;border:1px solid #E2E5EA;border-radius:6px;padding:3px 6px;" value="' + esc(s.distanceKm || '') + '" placeholder="0.0"> km'
            : (s.distanceKm ? esc(s.distanceKm) + ' km' : '-');
        return '<div class="rb-section"><h4>Route</h4><div class="rb-route">' +
            '<div class="rb-route-row"><span class="rb-route-dot rb-pick"></span><span class="rb-route-addr">' + esc(s.pickup) + noteHtml + '</span></div>' +
            '<div class="rb-route-row"><span class="rb-route-dot rb-drop"></span><span class="rb-route-addr">' + esc(s.dropoff) + '</span></div>' +
            '<div class="rb-route-meta"><span>Distance' + (needsDistance ? ' <span style="color:#D8402C;">*</span>' : '') + '</span><span class="rb-mono">' + distanceHtml + '</span></div>' +
            (needsDistance ? '<div class="rb-adj-error" id="rbDistanceError" hidden style="color:#D8402C;font-size:0.75rem;margin-top:4px;"></div>' : '') +
            '</div></div>';
    }

    function customerNoteSectionHtml(s) {
        if (!s.customerNote) return '';
        return '<div class="rb-section"><h4>Customer note</h4><div class="rb-note-box">' + esc(s.customerNote) + '</div></div>';
    }

    function pricingSectionHtml(s) {
        var eff = effectiveStatus(s);
        var editable = (eff === 'new' || eff === 'draft');
        var custRef = '<div class="rb-cq-ref">' +
            '<div class="rb-cq-label">Customer Estimate</div>' +
            '<div class="rb-cq-row"><span>Subtotal</span><span class="rb-mono">' + peso(s.vatExclusiveTotal) + '</span></div>' +
            '<div class="rb-cq-row"><span>VAT (12%)</span><span class="rb-mono">' + peso(s.vatAmount) + '</span></div>' +
            '<div class="rb-cq-row rb-cq-total"><span>Total</span><span class="rb-mono">' + peso(s.originalTotal) + '</span></div>' +
            '</div>';

        var fixedTotal = (baseSubtotal(s) + distanceFeeFor(s)) * 1.12;
        var fixedLabel = editable ? 'Total' : (eff === 'negotiating' ? 'Your last offer' : 'Agreed total');
        var breakdown = '<div class="rb-breakdown"><div class="rb-b-row rb-b-final rb-b-solo"><span>' + fixedLabel + ' (incl. VAT)</span><span class="rb-mono">' + peso(fixedTotal) + '</span></div></div>';

        var adjustedHtml = '';
        var netAdj = netAdjustment(s);
        if (netAdj !== 0) {
            var adj = adjustedTotal(s);
            adjustedHtml = '<div class="rb-breakdown">' +
                '<div class="rb-b-row"><span>Base total</span><span class="rb-mono">' + peso(fixedTotal) + '</span></div>' +
                '<div class="rb-b-row rb-b-adj"><span>Adjustments</span><span class="rb-mono ' + (netAdj > 0 ? 'rb-is-add' : 'rb-is-deduct') + '">' + (netAdj > 0 ? '+' : '') + peso(netAdj) + '</span></div>' +
                '<div class="rb-b-row rb-b-final"><span>' + (editable ? 'Total after adjustments' : 'Actual agreed total') + ' (incl. VAT)</span><span class="rb-mono">' + peso(adj.total) + '</span></div>' +
                '</div>';
        }

        var historyHtml = '';
        var hasHistory = s.priceChangeLog.length > 0 || s.adjustments.length > 0;
        if (hasHistory) {
            var rows = s.priceChangeLog.map(function (h) {
                var delta = (parseFloat(h.new) || 0) - (parseFloat(h.old) || 0);
                var isAdd = delta >= 0;
                return '<div class="rb-adj-row"><span class="rb-adj-sign ' + (isAdd ? 'rb-is-add' : 'rb-is-deduct') + '">' + (isAdd ? '+' : '\u2212') + peso(Math.abs(delta)) + '</span><span class="rb-adj-reason">' + esc(h.reason || 'Price updated') + '</span><span class="rb-adj-time">' + esc(timeAgoLabel(h.at) || '') + '</span></div>';
            }).join('');
            rows += s.adjustments.map(function (a) {
                return '<div class="rb-adj-row"><span class="rb-adj-sign ' + (a.amount > 0 ? 'rb-is-add' : 'rb-is-deduct') + '">' + (a.amount > 0 ? '+' : '\u2212') + peso(Math.abs(a.amount)) + '</span><span class="rb-adj-reason">' + esc(a.reason) + ' <em style="color:#8A93A3;">(not sent yet)</em></span><span class="rb-adj-time">just now</span></div>';
            }).join('');
            historyHtml = '<button type="button" class="rb-btn rb-btn-secondary rb-history-toggle-btn" id="rbHistoryToggleBtn">' + icon('fileText') + ' Price history</button>' +
                '<div class="rb-history" id="rbHistoryList"' + (s.historyOpen ? '' : ' hidden') + '>' + rows + '</div>';
        }

        var adjFormHtml = '';
        if (editable) {
            adjFormHtml = '<div class="rb-sub-label">Add price adjustment</div>' +
                '<div class="rb-adj-form" id="rbAdjForm"' + (s.adjFormOpen ? '' : ' hidden') + '>' +
                '<div class="rb-adj-form-row">' +
                '<select id="rbAdjType"><option value="add">Add</option><option value="deduct">Deduct</option></select>' +
                '<div class="rb-currency-input-wrap"><span class="rb-currency-ic">\u20b1</span><input type="text" class="rb-mono" id="rbAdjAmount" placeholder="Enter amount, e.g. 200.50" inputmode="decimal"></div>' +
                '</div>' +
                '<div class="rb-adj-reason-label"><span>Reason <span style="color:#D8402C;">*</span></span></div>' +
                '<textarea id="rbAdjReason" placeholder="Enter reason"></textarea>' +
                '<div class="rb-adj-error" id="rbAdjError" hidden></div>' +
                '<div class="rb-adj-form-actions">' +
                '<button type="button" class="rb-btn rb-btn-secondary" id="rbAdjCancelBtn">Cancel</button>' +
                '<button type="button" class="rb-btn rb-btn-primary" id="rbAdjAddBtn">' + icon('plus') + ' Add adjustment</button>' +
                '</div></div>' +
                '<button type="button" class="rb-btn rb-btn-secondary rb-adj-toggle-btn" id="rbAdjToggleBtn"' + (s.adjFormOpen ? ' hidden' : '') + '>' + icon('plus') + ' Add price adjustment</button>' +
                '<div class="rb-adj-hint">You can add multiple adjustments if needed.</div>';
        }

        var negotiatingHtml = '';
        if (eff === 'negotiating' && s.counterOfferAmount) {
            negotiatingHtml = '<div class="rb-cq-ref" style="border-color:#D8402C;"><div class="rb-cq-label" style="color:#D8402C;">Customer countered</div>' +
                '<div class="rb-cq-row rb-cq-total"><span>New offer</span><span class="rb-mono">' + peso(s.counterOfferAmount) + '</span></div></div>';
        }

        return '<div class="rb-section"><h4>Pricing &amp; unit</h4>' + custRef + breakdown + adjustedHtml + negotiatingHtml + historyHtml + adjFormHtml + '</div>';
    }

    function unitCardHtml(u, isRecommended, isSelected) {
        var star = isRecommended ? '<span class="rb-unit-star rb-is-rec">' + starIconFilled(16) + '</span>' : '<span class="rb-unit-star">' + icon('starOutline', 16) + '</span>';
        var crew = (u.crew_names && u.crew_names.length) ? u.crew_names.join(', ') : '-';
        return '<div class="rb-unit-card' + (isSelected ? ' rb-is-selected' : '') + '" data-unit-id="' + u.id + '">' +
            '<div class="rb-unit-card-top">' +
            '<div class="rb-unit-avatar">' + icon('mapPin', 20) + '</div>' +
            '<div class="rb-unit-card-info">' +
            '<div class="rb-unit-card-name">' + esc(u.label) + '</div>' +
            '<div class="rb-unit-card-row">' + icon('user', 13) + ' ' + esc(u.team_leader_name) + '</div>' +
            '<div class="rb-unit-card-row">' + esc(u.status_summary || '') + '</div>' +
            '</div>' +
            '<div class="rb-unit-card-side">' + star +
            '<button type="button" class="rb-btn rb-btn-secondary" data-assign-unit="' + u.id + '">' + (isSelected ? 'Selected' : 'Assign') + '</button>' +
            '</div></div>' +
            '<button type="button" class="rb-unit-expand-toggle" data-expand-unit="' + u.id + '">' + icon('chevronDown', 13) + ' Driver &amp; crew</button>' +
            '<div class="rb-unit-expand-body" id="rbUnitExpand-' + u.id + '">' +
            '<div class="rb-unit-expand-row"><span>Driver</span><b>' + esc(u.driver_name || '-') + '</b></div>' +
            '<div class="rb-unit-expand-row"><span>Crew</span><b>' + esc(crew) + '</b></div>' +
            '</div></div>';
    }

    function unitsSectionHtml(s) {
        return '<div class="rb-section"><h4>Available units</h4>' +
            '<div class="rb-search-wrap"><span class="rb-search-ic">' + icon('search', 15) + '</span><input type="text" id="rbUnitSearch" placeholder="Search unit or team leader"></div>' +
            '<div class="rb-unit-option-list" id="rbUnitList" style="display:flex;flex-direction:column;gap:10px;margin-top:10px;"></div>' +
            '</div>';
    }

    function historyTimelineSectionHtml(s) {
        var items = [{ label: 'Booking submitted', time: timeAgoLabel(s.createdAt), accept: false }];
        s.priceChangeLog.forEach(function (h) {
            items.push({ label: (h.reason ? h.reason : 'Price updated') + ' \u2014 ' + peso(h.new), time: timeAgoLabel(h.at) || '', accept: false });
        });
        var rows = items.map(function (it) {
            var ic = it.accept ? icon('check', 14) : icon('fileText', 14);
            return '<div class="rb-t-item' + (it.accept ? ' rb-is-accept' : '') + '"><span class="rb-t-icon">' + ic + '</span><div><div class="rb-t-text">' + esc(it.label) + '</div><div class="rb-t-time">' + esc(it.time) + '</div></div></div>';
        }).join('');
        return '<div class="rb-section"><h4>Quote history</h4><div class="rb-timeline">' + rows + '</div></div>';
    }

    // ------------------------------------------------------------------
    // Footer (status-specific actions)
    // ------------------------------------------------------------------
    function footerHtml(s) {
        var eff = effectiveStatus(s);
        var main = '';
        if (eff === 'new') {
            main = '<button type="button" class="rb-btn rb-btn-secondary" id="rbSaveDraftBtn">' + icon('save') + ' Save as draft</button>' +
                '<button type="button" class="rb-btn rb-btn-primary" id="rbSendBtn">' + icon('send') + ' Send to customer</button>';
        } else if (eff === 'draft') {
            main = '<button type="button" class="rb-btn rb-btn-secondary" id="rbEditPriceBtn">' + icon('pencil') + ' Edit price</button>' +
                '<button type="button" class="rb-btn rb-btn-primary" id="rbSendBtn">' + icon('send') + ' Send to customer</button>';
        } else if (eff === 'sent') {
            main = '<button type="button" class="rb-btn rb-btn-secondary" id="rbCancelQuoteBtn">' + icon('xCircle') + ' Cancel quote</button>';
        } else if (eff === 'negotiating') {
            main = '<button type="button" class="rb-btn rb-btn-secondary" id="rbDeclineCounterBtn">Decline, keep ' + peso(s.currentPrice) + '</button>' +
                '<button type="button" class="rb-btn rb-btn-primary" id="rbAcceptCounterBtn">Resend at ' + peso(s.counterOfferAmount || s.currentPrice) + '</button>';
        } else if (eff === 'confirmed') {
            main = '<button type="button" class="rb-btn rb-btn-primary" id="rbDispatchBtn">Proceed to Dispatch</button>';
        } else if (eff === 'expired') {
            main = ''; // no Extend / Resend — expired quotes are done, only Reject remains
        }

        var expiredChip = eff === 'expired'
            ? '<div class="rb-expired-chip">' + icon('clock', 14) + '<span>Quote expired</span></div>'
            : '';

        var rejectHtml = (eff !== 'confirmed')
            ? '<div class="rb-drawer-foot-reject"><button type="button" class="rb-link-btn" id="rbRejectBtn" style="color:#D8402C;">' + icon('trash', 14) + ' Reject this booking</button></div>'
            : '';

        return expiredChip + '<div class="rb-drawer-foot-main">' + main + '</div>' + rejectHtml;
    }

    // ------------------------------------------------------------------
    // Full drawer render
    // ------------------------------------------------------------------
    function renderDrawer() {
        var s = state;
        var initials = (s.customerName || '?').split(' ').filter(Boolean).slice(0, 2).map(function (p) { return p[0]; }).join('').toUpperCase();

        drawerEl.innerHTML =
            '<div class="rb-drawer-head">' +
            '<div class="rb-who"><div class="rb-avatar">' + esc(initials) + '</div><div><h3>' + esc(s.customerName) + '</h3><div class="rb-sub"><span>' + esc(s.customerPhone) + '</span>' + (s.customerEmail ? '<span>' + esc(s.customerEmail) + '</span>' : '') + '</div></div></div>' +
            '<button type="button" class="rb-drawer-close" id="rbDrawerCloseBtn">\u2715</button>' +
            '</div>' +
            '<div class="rb-drawer-body">' +
            requestSectionHtml(s) +
            vehicleSectionHtml(s) +
            routeSectionHtml(s) +
            customerNoteSectionHtml(s) +
            pricingSectionHtml(s) +
            unitsSectionHtml(s) +
            historyTimelineSectionHtml(s) +
            '</div>' +
            '<div class="rb-drawer-foot">' + footerHtml(s) + '</div>';

        wireDrawerEvents();
        renderUnitList('');
    }

    // ------------------------------------------------------------------
    // Event wiring (re-run after every renderDrawer())
    // ------------------------------------------------------------------
    function wireDrawerEvents() {
        var s = state;

        byId('rbDrawerCloseBtn', function (el) { el.onclick = closeBookingDrawer; });

        byId('rbPhotoStackTrigger', function (el) { el.onclick = function () { openLightbox(s.photos, 0); }; });

        byId('rbDistanceKm', function (el) {
            el.addEventListener('change', function () {
                var v = parseFloat(el.value) || 0;
                s.distanceKm = v > 0 ? v : '';
                renderDrawer();
            });
        });

        // ---- price adjustment form ----
        byId('rbAdjToggleBtn', function (el) {
            el.onclick = function () {
                s.adjFormOpen = true;
                byId('rbAdjForm', function (f) { f.hidden = false; });
                el.hidden = true;
            };
        });
        byId('rbAdjCancelBtn', function (el) {
            el.onclick = function () {
                s.adjFormOpen = false;
                byId('rbAdjForm', function (f) { f.hidden = true; });
                byId('rbAdjToggleBtn', function (t) { t.hidden = false; });
            };
        });
        byId('rbAdjAmount', function (el) {
            el.addEventListener('input', function () {
                var v = el.value.replace(/[^\d.]/g, '');
                var firstDot = v.indexOf('.');
                if (firstDot !== -1) v = v.slice(0, firstDot + 1) + v.slice(firstDot + 1).replace(/\./g, '');
                var parts = v.split('.');
                parts[0] = parts[0].replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                el.value = parts.join('.');
            });
        });
        byId('rbAdjAddBtn', function (el) {
            el.onclick = function () {
                var typeSel = document.getElementById('rbAdjType');
                var amtInput = document.getElementById('rbAdjAmount');
                var reasonInput = document.getElementById('rbAdjReason');
                var amt = parseFloat((amtInput.value || '0').replace(/[^\d.]/g, '')) || 0;
                var reason = (reasonInput.value || '').trim();
                if (amt <= 0) { showAdjError('Enter an amount greater than zero.', amtInput); return; }
                if (!reason) { showAdjError('A reason is required for price adjustments.', reasonInput); return; }
                var signed = typeSel.value === 'deduct' ? -amt : amt;
                s.adjustments.push({ amount: signed, reason: reason });
                s.adjFormOpen = true;
                renderDrawer();
            };
        });

        // ---- price history toggle ----
        byId('rbHistoryToggleBtn', function (el) {
            el.onclick = function () {
                s.historyOpen = !s.historyOpen;
                byId('rbHistoryList', function (l) { l.hidden = !s.historyOpen; });
            };
        });

        // ---- footer actions ----
        byId('rbSaveDraftBtn', function (el) { el.onclick = function () { submitSaveDraft(); }; });
        byId('rbEditPriceBtn', function (el) {
            el.onclick = function () {
                s.adjFormOpen = true;
                renderDrawer();
                var amt = document.getElementById('rbAdjAmount');
                if (amt) { amt.scrollIntoView({ behavior: 'smooth', block: 'center' }); amt.focus(); }
            };
        });
        byId('rbSendBtn', function (el) { el.onclick = function () { confirmThenSend(); }; });
        byId('rbCancelQuoteBtn', function (el) { el.onclick = function () { submitCancelQuote(); }; });
        byId('rbDeclineCounterBtn', function (el) { el.onclick = function () { submitDecideOnCounter(false); }; });
        byId('rbAcceptCounterBtn', function (el) { el.onclick = function () { submitDecideOnCounter(true); }; });
        byId('rbDispatchBtn', function (el) { el.onclick = function () { submitDispatch(); }; });
        byId('rbRejectBtn', function (el) { el.onclick = function () { promptRejectReason(); }; });
    }

    function byId(id, fn) { var el = document.getElementById(id); if (el) fn(el); }

    var adjErrorTimer = null;
    function showAdjError(msg, focusEl, targetId) {
        var errEl = document.getElementById(targetId || 'rbAdjError');
        if (!errEl) return;
        if (adjErrorTimer) clearTimeout(adjErrorTimer);
        errEl.textContent = msg;
        errEl.hidden = false;
        if (focusEl) focusEl.focus();
        adjErrorTimer = setTimeout(function () { errEl.hidden = true; }, 3000);
    }

    // ------------------------------------------------------------------
    // Unit list (search + render + select)
    // ------------------------------------------------------------------
    function unitsForClass(truckTypeId) {
        return AVAILABLE_UNITS.filter(function (u) { return Number(u.truck_type_id) === Number(truckTypeId); });
    }
    function sortedWithRecommendedFirst(list, recommendedId) {
        var arr = list.slice();
        arr.sort(function (a, z) {
            if (String(a.id) === String(recommendedId)) return -1;
            if (String(z.id) === String(recommendedId)) return 1;
            return 0;
        });
        return arr;
    }

    function renderUnitList(query) {
        var s = state;
        var listEl = document.getElementById('rbUnitList');
        if (!listEl) return;
        var pool = unitsForClass(s.truckTypeId);
        var q = (query || '').trim().toLowerCase();
        var filtered = pool.filter(function (u) {
            return (u.label || '').toLowerCase().indexOf(q) > -1 || (u.team_leader_name || '').toLowerCase().indexOf(q) > -1;
        });

        if (!filtered.length) {
            listEl.innerHTML = '<div class="rb-unit-empty-note">' + icon('mapPin', 24) +
                '<span>No ' + esc(s.truckType || 'matching') + ' units ready right now \u2014 pick manually once one comes online.</span></div>';
            return;
        }

        var serverRecommended = s.recommendedUnitId && filtered.some(function (u) { return String(u.id) === String(s.recommendedUnitId); })
            ? s.recommendedUnitId : null;
        var recommendedId = !q ? (serverRecommended || (filtered[0] ? filtered[0].id : null)) : null; // prefer the real zone-aware pick; fall back to coverage-sorted first
        var sorted = sortedWithRecommendedFirst(filtered, recommendedId);
        listEl.innerHTML = sorted.map(function (u) {
            return unitCardHtml(u, String(u.id) === String(recommendedId), String(u.id) === String(s.selectedUnitId));
        }).join('');

        listEl.querySelectorAll('[data-assign-unit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                s.selectedUnitId = btn.dataset.assignUnit;
                renderUnitList(document.getElementById('rbUnitSearch') ? document.getElementById('rbUnitSearch').value : '');
            });
        });
        listEl.querySelectorAll('[data-expand-unit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var body = document.getElementById('rbUnitExpand-' + btn.dataset.expandUnit);
                if (body) body.classList.toggle('rb-is-open');
            });
        });

        byId('rbUnitSearch', function (searchEl) {
            searchEl.oninput = function () { renderUnitList(searchEl.value); };
        });
    }
    function closeUnitsModalIfOpen() { /* "View all N units" modal deferred this round — no-op */ }

    // ------------------------------------------------------------------
    // Lightbox
    // ------------------------------------------------------------------
    var lightboxBackdrop = document.getElementById('rbLightboxBackdrop');
    var lightboxState = { photos: [], index: 0 };
    function renderLightbox() {
        var url = lightboxState.photos[lightboxState.index];
        document.getElementById('rbLightboxImage').innerHTML = url ? '<img src="' + esc(url) + '" alt="Vehicle photo">' : '';
        document.getElementById('rbLightboxCaption').textContent = 'Photo ' + (lightboxState.index + 1) + ' of ' + lightboxState.photos.length;
    }
    function openLightbox(photos, index) {
        lightboxState.photos = photos || [];
        lightboxState.index = index || 0;
        if (!lightboxState.photos.length) return;
        renderLightbox();
        lightboxBackdrop.classList.add('is-open');
    }
    function closeLightbox() { lightboxBackdrop.classList.remove('is-open'); }
    byId('rbLightboxClose', function (el) { el.onclick = closeLightbox; });
    byId('rbLightboxPrev', function (el) {
        el.onclick = function () { lightboxState.index = (lightboxState.index - 1 + lightboxState.photos.length) % lightboxState.photos.length; renderLightbox(); };
    });
    byId('rbLightboxNext', function (el) {
        el.onclick = function () { lightboxState.index = (lightboxState.index + 1) % lightboxState.photos.length; renderLightbox(); };
    });
    if (lightboxBackdrop) lightboxBackdrop.addEventListener('click', function (e) { if (e.target === lightboxBackdrop) closeLightbox(); });

    // ------------------------------------------------------------------
    // Commit actions — each folds all staged adjustments into ONE network
    // call, per the plan's §0.A correction (update-price emails the
    // customer + versions the quotation on every call).
    // ------------------------------------------------------------------
    function setBusy(disabled) {
        drawerEl.querySelectorAll('.rb-btn').forEach(function (b) { b.disabled = disabled; });
    }
    function showDrawerNetworkError(message) {
        window.alert(message || 'Something went wrong. Please try again.');
    }
    function reloadAfterSuccess() {
        window.location.reload();
    }

    /** Shared payload for saveQuotationDraft() — price is VAT-inclusive there (assigned to estimated_price verbatim, no server-side re-derivation). */
    function draftSavePayload(s) {
        var adj = adjustedTotal(s);
        return {
            price: Number(adj.total.toFixed(2)),
            additional_fee: Number(netAdjustment(s).toFixed(2)),
            assigned_unit_id: s.selectedUnitId || null,
            dispatcher_note: s.adjustments.map(function (a) { return a.reason; }).join('; ') || null,
            distance_km: s.distanceKm || null
        };
    }

    function submitSaveDraft() {
        var s = state;
        setBusy(true);
        apiCall(fillRoute(window.RB_ROUTES.saveDraft, s.bookingCode), 'POST', draftSavePayload(s))
            .then(function (res) {
                setBusy(false);
                if (!res.ok) { showDrawerNetworkError(res.data && res.data.message); return; }
                reloadAfterSuccess();
            }).catch(function () { setBusy(false); showDrawerNetworkError(); });
    }

    function confirmThenSend() {
        var s = state;
        var eff = effectiveStatus(s);

        if (eff === 'new' && !(parseFloat(s.distanceKm) > 0)) {
            showAdjError('Enter the trip distance before sending a quote.', document.getElementById('rbDistanceKm'), 'rbDistanceError');
            return;
        }

        var adj = adjustedTotal(s);
        var displayTotal = (eff === 'new' || s.adjustments.length) ? adj.total : s.currentPrice;
        var ok = window.confirm('Send this quote to ' + s.customerName + ' for ' + peso(displayTotal) + ' (incl. VAT)? This cannot be undone.');
        if (!ok) return;

        if (eff === 'new') {
            // Fresh booking — create the quotation and send it in one shot via the accept
            // action. assignBooking() computes the base total itself server-side from the
            // selected unit's own truck-type base_rate plus distance_fee, then × 1.12 for
            // VAT — it does NOT expect a pre-computed total from the frontend (mirrors how
            // the existing #actionModal flow leaves `price` blank in the normal case,
            // dispatch.js:1841-1844). `price` here is only an additional-fee override, so it
            // must stay unsent/null; only `additional_fee` carries our net staged adjustment.
            setBusy(true);
            apiCall(
                fillRoute(window.RB_ROUTES.assign, s.bookingCode),
                'POST',
                {
                    action: 'accept',
                    assigned_unit_id: s.selectedUnitId || null,
                    additional_fee: Number(netAdjustment(s).toFixed(2)),
                    distance_km: s.distanceKm,
                    // distance_fee is required by assignBooking()'s validation for any
                    // booking not already confirmed/scheduled_confirmed, and is checked
                    // server-side against the MMDA per-km rate formula (₱300/km after the
                    // first km) — must match that exact formula or the request 422s.
                    distance_fee: Number((Math.max(0, (parseFloat(s.distanceKm) || 0) - 1) * 300).toFixed(2)),
                    dispatcher_note: s.adjustments.map(function (a) { return a.reason; }).join('; ') || null
                }
            ).then(function (res) {
                setBusy(false);
                if (!res.ok) { showDrawerNetworkError(res.data && res.data.message); return; }
                reloadAfterSuccess();
            }).catch(function () { setBusy(false); showDrawerNetworkError(); });
        } else if (eff === 'draft' && s.adjustments.length) {
            // Draft with unsaved staged adjustments — persist them to the draft first
            // (saveQuotationDraft takes price VAT-inclusive verbatim), then send it.
            setBusy(true);
            apiCall(fillRoute(window.RB_ROUTES.saveDraft, s.bookingCode), 'POST', draftSavePayload(s))
                .then(function (res) {
                    if (!res.ok) { setBusy(false); showDrawerNetworkError(res.data && res.data.message); return; }
                    return apiCall(fillRoute(window.RB_ROUTES.quoteSend, s.quotationId), 'POST', {});
                })
                .then(function (res) {
                    setBusy(false);
                    if (!res) return; // save-draft already failed and reported above
                    if (!res.ok) { showDrawerNetworkError(res.data && res.data.message); return; }
                    reloadAfterSuccess();
                }).catch(function () { setBusy(false); showDrawerNetworkError(); });
        } else {
            // Draft already exists on the server with no further staged changes — just send it.
            setBusy(true);
            apiCall(fillRoute(window.RB_ROUTES.quoteSend, s.quotationId), 'POST', {})
                .then(function (res) {
                    setBusy(false);
                    if (!res.ok) { showDrawerNetworkError(res.data && res.data.message); return; }
                    reloadAfterSuccess();
                }).catch(function () { setBusy(false); showDrawerNetworkError(); });
        }
    }

    function submitCancelQuote() {
        if (!window.confirm('Cancel this quote? The customer will no longer be able to accept it.')) return;
        setBusy(true);
        apiCall(fillRoute(window.RB_ROUTES.quoteCancel, state.quotationId), 'POST', {})
            .then(function (res) {
                setBusy(false);
                if (!res.ok) { showDrawerNetworkError(res.data && res.data.message); return; }
                reloadAfterSuccess();
            }).catch(function () { setBusy(false); showDrawerNetworkError(); });
    }

    /**
     * Accept/Decline counter both resend the quote at a given price — the
     * customer always has final say (this backend has no way for a
     * dispatcher to instantly confirm on their behalf). Accept resends at
     * their proposed price; Decline resends at the original price. Both
     * transition the quotation back to "sent", awaiting the customer's
     * real acceptance in their own app.
     */
    function submitDecideOnCounter(acceptCounter) {
        var s = state;
        var newPrice = acceptCounter ? (s.counterOfferAmount || s.currentPrice) : s.currentPrice;
        var verb = acceptCounter ? 'resend the quote at their proposed price' : 'resend your original price';
        if (!window.confirm('This will ' + verb + ' (' + peso(newPrice) + ') and the customer will need to accept it again. Continue?')) return;
        setBusy(true);
        apiCall(fillRoute(window.RB_ROUTES.quoteUpdatePrice, s.quotationId), 'PATCH', {
            new_price: Number(newPrice.toFixed(2)),
            additional_fee: 0
        }).then(function (res) {
            setBusy(false);
            if (!res.ok) { showDrawerNetworkError(res.data && res.data.message); return; }
            reloadAfterSuccess();
        }).catch(function () { setBusy(false); showDrawerNetworkError(); });
    }

    function submitDispatch() {
        var s = state;
        if (!s.selectedUnitId) {
            window.alert('Pick a unit from Available units first.');
            return;
        }
        setBusy(true);
        apiCall(fillRoute(window.RB_ROUTES.assign, s.bookingCode), 'POST', {
            action: 'accept',
            assigned_unit_id: s.selectedUnitId
        }).then(function (res) {
            setBusy(false);
            if (!res.ok) { showDrawerNetworkError(res.data && res.data.message); return; }
            reloadAfterSuccess();
        }).catch(function () { setBusy(false); showDrawerNetworkError(); });
    }

    function promptRejectReason() {
        var reason = window.prompt('Reason for rejecting this booking (shown to the customer):', '');
        if (reason === null) return; // cancelled
        setBusy(true);
        apiCall(fillRoute(window.RB_ROUTES.assign, state.bookingCode), 'POST', {
            action: 'reject',
            rejection_reason: reason || 'Rejected by dispatcher.'
        }).then(function (res) {
            setBusy(false);
            if (!res.ok) { showDrawerNetworkError(res.data && res.data.message); return; }
            reloadAfterSuccess();
        }).catch(function () { setBusy(false); showDrawerNetworkError(); });
    }
})();
