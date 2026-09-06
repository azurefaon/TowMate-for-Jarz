/**
 * Booking drawer ("View & Quote") — shared by the Book Now AND Scheduled
 * queues.
 *
 * Scope: replaces the old #actionModal flow for #bookNowPanel cards, and the
 * old #quotationModal flow for the Scheduled queue, with one slide-in
 * drawer. Status-changing actions (send / cancel / assign / reject /
 * accept-decline counter / dispatch / reschedule) deliberately reload the
 * page instead of patching the DOM optimistically — avoids client state
 * drifting from server truth — and the drawer auto-reopens for the same
 * booking afterward (see reloadAfterSuccess()/reopenDrawerAfterReload()) so
 * it doesn't appear to close from the dispatcher's point of view. Save
 * Quote/Save changes (submitSaveDraft()) never change the booking's
 * status/queue bucket, so those instead refresh state and the drawer in
 * place with no reload at all (see applySaveDraftSuccess()) — the drawer
 * never even appears to close.
 *
 * Scheduled-specific rule (see effectiveStatus()): a Scheduled booking never
 * reserves a Unit/TL in advance. Once its quotation is accepted
 * (status === "scheduled_confirmed"), the server computes a scheduling
 * bucket (confirmed / upcoming / ready / overdue — Booking::getSchedulingBucketAttribute())
 * that this file reads off data-scheduling-bucket rather than recomputing.
 * "Available units" and "Proceed to Dispatch"/"Dispatch Now" only appear at
 * ready/overdue; confirmed/upcoming show an informational note only.
 * #incomingList/#actionModal are untouched and keep using their existing flow.
 */
(function () {
    "use strict";

    // ------------------------------------------------------------------
    // Icons (inline SVG, no external icon library — matches CSP-free plain
    // hand-written JS already used elsewhere on this page).
    // ------------------------------------------------------------------
    var ICON_PATHS = {
        search: '<circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>',
        check: '<polyline points="20 6 9 17 4 12"></polyline>',
        fileText:
            '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="10" y1="9" x2="8" y2="9"></line>',
        save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline>',
        send: '<line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>',
        trash: '<polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line>',
        plus: '<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>',
        pencil: '<path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path>',
        xCircle:
            '<circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line>',
        clock: '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
        user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
        mapPin: '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle>',
        chevronDown: '<polyline points="6 9 12 15 18 9"></polyline>',
        image: '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline>',
        starOutline:
            '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>',
    };
    function icon(name, size) {
        size = size || 16;
        return (
            '<svg width="' +
            size +
            '" height="' +
            size +
            '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:none;vertical-align:-3px;">' +
            (ICON_PATHS[name] || "") +
            "</svg>"
        );
    }
    function starIconFilled(size) {
        size = size || 16;
        return (
            '<svg width="' +
            size +
            '" height="' +
            size +
            '" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="flex:none;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>'
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    function peso(n) {
        n = Number(n) || 0;
        return (
            "\u20b1" +
            n.toLocaleString("en-PH", {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            })
        );
    }
    function esc(s) {
        s = s === null || s === undefined ? "" : String(s);
        return s
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }
    function timeAgoLabel(iso) {
        if (!iso) return "-";
        var then = new Date(iso).getTime();
        if (isNaN(then)) return "-";
        var sec = Math.max(0, Math.floor((Date.now() - then) / 1000));
        if (sec < 60) return sec + "s ago";
        if (sec < 3600) return Math.floor(sec / 60) + "m ago";
        if (sec < 86400) return Math.floor(sec / 3600) + "h ago";
        return Math.floor(sec / 86400) + "d ago";
    }
    function fillRoute(tpl, id) {
        return tpl.replace(":booking", id).replace(":quotation", id);
    }
    function csrfHeaders() {
        return {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-TOKEN": window.RB_CSRF || "",
        };
    }
    function apiCall(url, method, payload) {
        return fetch(url, {
            method: method,
            headers: csrfHeaders(),
            body: JSON.stringify(payload || {}),
        }).then(function (res) {
            return res
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (data) {
                    return { ok: res.ok, status: res.status, data: data };
                });
        });
    }

    var AVAILABLE_UNITS = [];
    try {
        var unitsScriptEl = document.getElementById("rbAvailableUnitsJson");
        AVAILABLE_UNITS = unitsScriptEl
            ? JSON.parse(unitsScriptEl.textContent || "[]")
            : [];
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
        try {
            photos = JSON.parse(d.photos || "[]");
        } catch (e) {
            photos = [];
        }
        var priceChangeLog = [];
        try {
            priceChangeLog = JSON.parse(d.priceChangeLog || "[]");
        } catch (e) {
            priceChangeLog = [];
        }

        return {
            bookingCode: d.bookingCode || d.id,
            status: d.status,
            createdAt: d.createdAt,
            customerName: d.customerName || "Guest",
            customerPhone: d.customerPhone || "",
            customerEmail: d.customerEmail || "",
            pickup: d.pickup || "",
            pickupNotes: d.pickupNotes || "",
            dropoff: d.dropoff || "",
            distanceKm: d.distanceKm || "",
            customerNote: d.customerNote || "",
            currentPrice: parseFloat(d.currentPrice || "0") || 0,
            // immutable snapshot of the customer's original estimate, for the reference
            // box only — unlike currentPrice, this is never overwritten by the
            // quotation-details fetch
            originalTotal: parseFloat(d.currentPrice || "0") || 0,
            baseRate: parseFloat(d.baseRate || "0") || 0,
            perKmRate: parseFloat(d.perKmRate || "0") || 0,
            // Real, server-persisted additional fee — never reverse-engineered
            // from currentPrice (see pricingSectionHtml()'s "Adjustments" row).
            additionalFee: parseFloat(d.currentAdditional || "0") || 0,
            vatExclusiveTotal: parseFloat(d.vatExclusiveTotal || "0") || 0,
            vatAmount: parseFloat(d.vatAmount || "0") || 0,
            truckType: d.truckType || "",
            truckTypeId: parseInt(d.truckTypeId || "0", 10) || 0,
            vehicleCategory: d.vehicleCategory || "",
            photos: photos,
            dispatchZone: d.dispatchZone || "General Dispatch Zone",
            recommendedUnitId: d.recommendedUnit || "",
            quotationId: d.quotationId || "",
            quotationStatus: d.quotationStatus || "",
            // Filled in from the quotation-details fetch (mergeQuotationDetailsIntoState())
            // — the moment the quotation row was first created, for the synthetic
            // "Draft saved" history entry. Stays pinned to the original creation
            // time even after later edits (Eloquent never touches created_at on
            // ->update()), so reopening an existing Draft shows the same timestamp.
            quotationCreatedAt: "",
            sentAt: "",
            quotationVersion: 0,
            rescheduleEvents: [],
            // client-only, never sent to the server directly — folded into one
            // update-price/save-draft/assign call at commit time (see §0.A of plan)
            adjustments: [],
            // Pre-populated from the booking's own soft-reserved unit (if any) — this
            // is what makes "Proceed to Dispatch" auto-use a previously selected unit
            // without the dispatcher needing to re-pick it.
            selectedUnitId: d.selectedUnit || null,
            // Snapshot of the server-saved value, kept unchanged while selectedUnitId
            // is mutated by clicks — lets us detect "unit selection changed" the same
            // way `adjustments` detects a staged price change (see footerHtml()).
            originalSelectedUnitId: d.selectedUnit || null,
            adjFormOpen: false,
            historyOpen: false,
            // Scheduled-only — null/"" for Book Now. schedulingBucket is
            // computed server-side (Booking::getSchedulingBucketAttribute())
            // and only meaningful once status === "scheduled_confirmed".
            isScheduled: d.status === "scheduled" || d.status === "scheduled_confirmed",
            schedulingBucket: d.schedulingBucket || "",
            scheduledFor: d.scheduledFor || "",
            // Synchronous snapshot from the card's own data — avoids a blank-history
            // flash while the quotation-details fetch below is still in flight (or
            // if it fails); that fetch still overwrites this with the freshest copy
            // once it resolves.
            priceChangeLog: priceChangeLog,
        };
    }

    /**
     * Resolves the effective "card status" the footer/UI should treat this
     * booking as. For a confirmed Scheduled booking this is one of
     * confirmed/upcoming/ready/overdue (schedulingBucket, computed
     * server-side) instead of the single "confirmed" Book Now uses.
     */
    function effectiveStatus(s) {
        if (s.quotationStatus === "draft") return "draft";
        if (s.quotationStatus === "sent") return "sent";
        if (s.quotationStatus === "negotiating") return "negotiating";
        if (s.quotationStatus === "price_review_requested") return "price_review_requested";
        if (s.quotationStatus === "expired") return "expired";
        if (s.status === "scheduled_confirmed")
            return s.schedulingBucket || "confirmed";
        if (s.status === "confirmed") return "confirmed";
        return "new";
    }

    /** True when there's a staged price adjustment or an unsaved unit-selection change. */
    function hasStagedDraftChanges(s) {
        return (
            s.adjustments.length > 0 ||
            String(s.selectedUnitId || "") !==
                String(s.originalSelectedUnitId || "")
        );
    }
    function netAdjustment(s) {
        return s.adjustments.reduce(function (sum, a) {
            return sum + a.amount;
        }, 0);
    }
    /**
     * VAT-exclusive amount before any dispatcher-entered distance fee or staged
     * adjustments. Once a quotation exists, currentPrice already reflects the full
     * committed price (refreshed from getQuotationDetails), so deriving from it
     * (rather than the booking's own pre-quote vat_exclusive_total) keeps drafts
     * showing their real saved price instead of a stale customer-facing estimate.
     */
    function baseSubtotal(s) {
        // For a fresh booking (no quotation yet), use the real truck-class base
        // rate directly — this is what the server will actually use (assignBooking()
        // reads the selected unit's truckType.base_rate), unlike the booking's own
        // preview total which the customer app may have computed with a different,
        // rougher formula.
        if (effectiveStatus(s) === "new") return s.baseRate;
        return s.currentPrice / 1.12;
    }
    /**
     * First 4 km included in base fee, then the truck type's own per_km_rate
     * (SuperAdmin-editable) — must match BookingService::distanceFeeFor()
     * exactly (see confirmThenSend()). Only relevant for a fresh booking with
     * no quotation yet; an existing quotation's price already has whatever
     * distance fee was used when it was created.
     */
    function distanceFeeFor(s) {
        if (effectiveStatus(s) !== "new") return 0;
        var km = parseFloat(s.distanceKm) || 0;
        return km > 0 ? Math.max(0, km - 4) * (s.perKmRate || 0) : 0;
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
    var drawerEl = document.getElementById("rbDrawer");
    var drawerOverlayEl = document.getElementById("rbDrawerOverlay");

    // Response shape: { success: true, quotation: {...} } — see
    // DispatchController::getQuotationDetails(). Shared by drawer-open and by
    // applySaveDraftSuccess() (in-place refresh after Save Quote/Save changes).
    function fetchQuotationDetails(quotationId) {
        var url = fillRoute(window.RB_ROUTES.quoteDetails, quotationId);
        return fetch(url, { headers: { Accept: "application/json" } })
            .then(function (res) {
                return res.json();
            })
            .then(function (payload) {
                return payload && payload.quotation;
            });
    }

    // Note there is no "vehicle_category" field on a quotation (only make/model/
    // year/color/plate) — Category stays sourced from the booking's own
    // vehicleType relation set at drawer-open time; only Plate (and
    // price/history/counter-offer) get refreshed here.
    function mergeQuotationDetailsIntoState(data) {
        if (!data) return;
        if (typeof data.estimated_price !== "undefined") {
            state.currentPrice =
                parseFloat(data.estimated_price) || state.currentPrice;
        }
        if (typeof data.additional_fee !== "undefined" && data.additional_fee !== null) {
            state.additionalFee = parseFloat(data.additional_fee) || 0;
        }
        if (data.price_change_log && Array.isArray(data.price_change_log)) {
            state.priceChangeLog = data.price_change_log;
        }
        if (data.created_at) {
            state.quotationCreatedAt = data.created_at;
        }
        if (data.sent_at) {
            state.sentAt = data.sent_at;
        }
        if (typeof data.version !== "undefined" && data.version !== null) {
            state.quotationVersion = parseInt(data.version, 10) || 0;
        }
        if (data.reschedule_events && Array.isArray(data.reschedule_events)) {
            state.rescheduleEvents = data.reschedule_events;
        }
        if (data.vehicle_plate_number)
            state.vehiclePlate = data.vehicle_plate_number;
        if (
            typeof data.distance_km !== "undefined" &&
            data.distance_km !== null &&
            Number(data.distance_km) > 0
        ) {
            state.distanceKm = data.distance_km;
        }
        if (
            typeof data.counter_offer_amount !== "undefined" &&
            data.counter_offer_amount !== null
        ) {
            state.counterOfferAmount =
                parseFloat(data.counter_offer_amount) || null;
        }
        // Only meaningful while quotationStatus === 'price_review_requested' —
        // the customer's own submitted reason for the review.
        state.reviewReason = data.response_note || null;
    }

    window.openBookingDrawer = function (cardEl) {
        if (!cardEl) return;
        state = buildStateFromCard(cardEl);

        drawerOverlayEl.classList.add("is-open");
        drawerEl.classList.add("is-open");
        renderDrawer();

        // If there's a live quotation, fetch its real details (price change log,
        // exact current price, expiry) rather than trusting only the card's
        // page-load snapshot.
        if (state.quotationId) {
            var openedState = state;
            fetchQuotationDetails(state.quotationId)
                .then(function (data) {
                    if (state !== openedState) return; // drawer moved on to a different booking meanwhile
                    mergeQuotationDetailsIntoState(data);
                    renderDrawer();
                })
                .catch(function () {
                    /* keep showing the page-load snapshot on failure */
                });
        }
    };

    async function closeBookingDrawer() {
        if (state && hasStagedDraftChanges(state)) {
            var message = state.adjustments.length
                ? "You have unsaved price adjustments that haven't been sent yet. Close without saving?"
                : "You have an unsaved unit selection change. Close without saving?";
            var ok = await rbConfirm(message, { okLabel: "Close without saving" });
            if (!ok) return;
        }
        drawerEl.classList.remove("is-open");
        drawerOverlayEl.classList.remove("is-open");
        setTimeout(function () {
            drawerEl.innerHTML = "";
        }, 220);
        state = null;
    }
    drawerOverlayEl.addEventListener("click", closeBookingDrawer);
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            closeLightbox();
            closeUnitsModalIfOpen();
            if (drawerEl.classList.contains("is-open")) closeBookingDrawer();
        }
    });

    // ------------------------------------------------------------------
    // Section renderers
    // ------------------------------------------------------------------
    function requestSectionHtml(s) {
        var eff = effectiveStatus(s);
        var rows = [
            ["Mode", s.isScheduled ? "Scheduled" : "Book Now"],
            ["Submitted", esc(timeAgoLabel(s.createdAt))],
            ["Zone", esc(s.dispatchZone)],
            ["Truck class requested", esc(s.truckType)],
        ];
        var cells = rows
            .map(function (r, i) {
                var divider =
                    i === 2 ? '<div class="rb-grid-divider"></div>' : "";
                return (
                    divider +
                    "<div><dt>" +
                    r[0] +
                    "</dt><dd>" +
                    r[1] +
                    "</dd></div>"
                );
            })
            .join("");
        return (
            '<div class="rb-section"><h4>Request</h4><div class="rb-grid">' +
            cells +
            "</div></div>"
        );
    }

    function vehicleSectionHtml(s) {
        if (!s.photos.length) return "";
        return (
            '<div class="rb-section"><h4>Vehicle</h4>' +
            photoStackHtml(s) +
            "</div>"
        );
    }

    function photoStackHtml(s) {
        var n = s.photos.length;
        if (!n) return "";
        var back = "";
        if (n >= 3)
            back +=
                '<div class="rb-photo-stack-back rb-photo-stack-back-2"></div>';
        if (n >= 2)
            back +=
                '<div class="rb-photo-stack-back rb-photo-stack-back-1"></div>';
        var badge =
            n > 1
                ? '<div class="rb-photo-stack-badge">' +
                  icon("image", 12) +
                  " " +
                  n +
                  "</div>"
                : "";
        return (
            '<div class="rb-photo-stack-wrap">' +
            back +
            '<div class="rb-photo-box" id="rbPhotoStackTrigger">' +
            '<img src="' +
            esc(s.photos[0]) +
            '" alt="Vehicle photo">' +
            badge +
            "</div></div>"
        );
    }

    /** "Starts in 42 min" / "Overdue by 1 hr" — client-side countdown text off the server's scheduledFor. */
    function scheduleCountdownLabel(s) {
        if (!s.scheduledFor) return "";
        var target = new Date(s.scheduledFor).getTime();
        if (isNaN(target)) return "";
        var diffMs = target - Date.now();
        var mins = Math.round(Math.abs(diffMs) / 60000);
        var label = mins < 60
            ? mins + " min"
            : mins < 1440
              ? Math.round(mins / 60) + " hr"
              : Math.round(mins / 1440) + " d";
        return diffMs >= 0 ? "Starts in " + label : "Overdue by " + label;
    }

    function serviceScheduleSectionHtml(s) {
        if (!s.isScheduled || !s.scheduledFor) return "";
        var d = new Date(s.scheduledFor);
        var dateLabel = isNaN(d.getTime())
            ? "Schedule pending"
            : d.toLocaleString("en-PH", {
                  month: "short",
                  day: "numeric",
                  year: "numeric",
                  hour: "numeric",
                  minute: "2-digit",
              });
        var sub = scheduleCountdownLabel(s);
        return (
            '<div class="rb-section"><h4>Service Schedule</h4><div class="rb-grid">' +
            "<div><dt>Scheduled for</dt><dd>" +
            esc(dateLabel) +
            "</dd></div>" +
            (sub
                ? "<div><dt>Status</dt><dd>" + esc(sub) + "</dd></div>"
                : "") +
            "</div></div>"
        );
    }

    function routeSectionHtml(s) {
        var noteHtml = s.pickupNotes
            ? ' <span style="color:#8A93A3;">- ' +
              esc(s.pickupNotes) +
              "</span>"
            : "";
        // Distance is computed entirely on the customer's side (from the pickup/
        // dropoff locations they set) — always read-only here, dispatchers never
        // enter or edit it.
        return (
            '<div class="rb-section"><h4>Route</h4><div class="rb-route">' +
            '<div class="rb-route-row"><span class="rb-route-dot rb-pick"></span><span class="rb-route-addr">' +
            esc(s.pickup) +
            noteHtml +
            "</span></div>" +
            '<div class="rb-route-row"><span class="rb-route-dot rb-drop"></span><span class="rb-route-addr">' +
            esc(s.dropoff) +
            "</span></div>" +
            '<div class="rb-route-meta"><span>Distance</span><span class="rb-mono">' +
            (s.distanceKm ? esc(s.distanceKm) + " km" : "—") +
            "</span></div>" +
            "</div></div>"
        );
    }

    function customerNoteSectionHtml(s) {
        if (!s.customerNote) return "";
        return (
            '<div class="rb-section"><h4>Customer note</h4><div class="rb-note-box">' +
            esc(s.customerNote) +
            "</div></div>"
        );
    }

    function pricingSectionHtml(s) {
        var eff = effectiveStatus(s);
        var editable = eff === "new" || eff === "draft";

        var fixedTotal = (baseSubtotal(s) + distanceFeeFor(s)) * 1.12;
        var accepted =
            eff === "confirmed" ||
            eff === "upcoming" ||
            eff === "ready" ||
            eff === "overdue";
        var fixedLabel = editable
            ? "Total"
            : eff === "negotiating"
              ? "Your last offer"
              : accepted
                ? "Agreed total"
                : "Quoted total";

        // Base rate and distance stay valid, known figures no matter the quote's
        // status, so the breakdown never has to collapse to a single opaque
        // total.
        var breakdownDistFeeKm = 0; // chargeable km after the first-4-km allowance — label only, math unchanged below
        var breakdownDistFee = (function () {
            var km = parseFloat(s.distanceKm) || 0;
            breakdownDistFeeKm = Math.max(0, km - 4);
            return km > 0 ? breakdownDistFeeKm * (s.perKmRate || 0) : 0;
        })();
        // Real, server-persisted additional fee — never reverse-engineered from
        // currentPrice. A Price Review "Adjust price" override changes the total
        // directly without touching additional_fee, so back-calculating a delta
        // from currentPrice - (base + distance) surfaces a confusing phantom
        // figure that was never actually saved anywhere (e.g. "keep current
        // price" showing a nonexistent adjustment just from rounding drift).
        var additionalFee = eff === "new" ? 0 : s.additionalFee || 0;
        var breakdownSubtotal = s.baseRate + breakdownDistFee + additionalFee;
        // VAT and the final total always come straight from the real committed
        // price (never recomputed from the reference rows above) so this can
        // never drift from what's actually saved.
        var breakdownTotal =
            eff === "new" ? breakdownSubtotal * 1.12 : s.currentPrice;
        var breakdownVat =
            eff === "new"
                ? breakdownSubtotal * 0.12
                : breakdownTotal - breakdownTotal / 1.12;

        var breakdown =
            '<div class="rb-breakdown">' +
            '<div class="rb-b-row"><span>Base rate (' +
            esc(s.truckType || "Truck class") +
            ')</span><span class="rb-mono">' +
            peso(s.baseRate) +
            "</span></div>" +
            '<div class="rb-b-row"><span>Distance fee' +
            (s.distanceKm ? " (" + breakdownDistFeeKm.toFixed(2) + " km after first 4 km)" : "") +
            '</span><span class="rb-mono">' +
            peso(breakdownDistFee) +
            "</span></div>" +
            (additionalFee !== 0
                ? '<div class="rb-b-row rb-b-adj"><span>Additional fee</span><span class="rb-mono ' +
                  (additionalFee > 0 ? "rb-is-add" : "rb-is-deduct") +
                  '">' +
                  (additionalFee > 0 ? "+" : "") +
                  peso(additionalFee) +
                  "</span></div>"
                : "") +
            '<div class="rb-b-row"><span>VAT (12%)</span><span class="rb-mono">' +
            peso(breakdownVat) +
            "</span></div>" +
            '<div class="rb-b-row rb-b-final"><span>' +
            fixedLabel +
            ' (incl. VAT)</span><span class="rb-mono">' +
            peso(breakdownTotal) +
            "</span></div>" +
            "</div>";

        var adjustedHtml = "";
        var netAdj = netAdjustment(s);
        if (netAdj !== 0) {
            var adj = adjustedTotal(s);
            adjustedHtml =
                '<div class="rb-breakdown">' +
                '<div class="rb-b-row"><span>Base total</span><span class="rb-mono">' +
                peso(fixedTotal) +
                "</span></div>" +
                '<div class="rb-b-row rb-b-adj"><span>Adjustments</span><span class="rb-mono ' +
                (netAdj > 0 ? "rb-is-add" : "rb-is-deduct") +
                '">' +
                (netAdj > 0 ? "+" : "") +
                peso(netAdj) +
                "</span></div>" +
                '<div class="rb-b-row rb-b-final"><span>' +
                (editable ? "Total after adjustments" : "Actual agreed total") +
                ' (incl. VAT)</span><span class="rb-mono">' +
                peso(adj.total) +
                "</span></div>" +
                "</div>";
        }

        var historyHtml = "";
        var hasHistory =
            s.priceChangeLog.length > 0 || s.adjustments.length > 0;
        if (hasHistory) {
            // price_change_log mixes real monetary adjustments with lifecycle/
            // informational events (a quote being sent, a review requested, a
            // price kept unchanged) \u2014 only entries with an actual non-zero
            // delta render as a signed peso amount; every known lifecycle
            // `type` gets its own plain, non-monetary label instead of a
            // misleading "+\u20b10.00". Rounded to 2dp before the zero-check so
            // float noise never masquerades as a real change.
            var rows = s.priceChangeLog
                .map(function (h) {
                    var delta =
                        Math.round(
                            ((parseFloat(h.new) || 0) -
                                (parseFloat(h.old) || 0)) *
                                100,
                        ) / 100;
                    var lifecycleTypes = {
                        price_review_requested: 1,
                        price_review_kept: 1,
                        quotation_sent: 1,
                    };
                    var isMonetary = !lifecycleTypes[h.type] && delta !== 0;

                    var signHtml = "";
                    var labelHtml;

                    if (isMonetary) {
                        var isAdd = delta >= 0;
                        signHtml =
                            '<span class="rb-adj-sign ' +
                            (isAdd ? "rb-is-add" : "rb-is-deduct") +
                            '">' +
                            (isAdd ? "+" : "\u2212") +
                            peso(Math.abs(delta)) +
                            "</span>";
                        labelHtml = esc(h.reason || "Price adjusted");
                    } else if (h.type === "price_review_requested") {
                        labelHtml =
                            '<div style="font-weight:700;color:#111111;">Customer requested price review</div>' +
                            (h.reason
                                ? "<div>" + esc(h.reason) + "</div>"
                                : "");
                    } else if (h.type === "price_review_kept") {
                        labelHtml =
                            "Price retained at " +
                            peso(parseFloat(h.new) || 0);
                    } else if (h.type === "quotation_sent") {
                        labelHtml =
                            h.version && h.version > 1
                                ? "Revised quotation sent"
                                : "Initial quotation sent";
                    } else {
                        labelHtml = esc(h.reason || "Quotation updated");
                    }

                    return (
                        '<div class="rb-adj-row">' +
                        signHtml +
                        '<span class="rb-adj-reason">' +
                        labelHtml +
                        '</span><span class="rb-adj-time">' +
                        esc(timeAgoLabel(h.at) || "") +
                        "</span></div>"
                    );
                })
                .join("");
            rows += s.adjustments
                .map(function (a) {
                    return (
                        '<div class="rb-adj-row"><span class="rb-adj-sign ' +
                        (a.amount > 0 ? "rb-is-add" : "rb-is-deduct") +
                        '">' +
                        (a.amount > 0 ? "+" : "\u2212") +
                        peso(Math.abs(a.amount)) +
                        '</span><span class="rb-adj-reason">' +
                        esc(a.reason) +
                        ' <em style="color:#8A93A3;">(not sent yet)</em></span><span class="rb-adj-time">just now</span></div>'
                    );
                })
                .join("");
            historyHtml =
                '<button type="button" class="rb-btn rb-btn-secondary rb-history-toggle-btn" id="rbHistoryToggleBtn">' +
                icon("fileText") +
                " Price history</button>" +
                '<div class="rb-history" id="rbHistoryList"' +
                (s.historyOpen ? "" : ' style="display:none;"') +
                ">" +
                rows +
                "</div>";
        }

        var adjFormHtml = "";
        if (editable) {
            adjFormHtml =
                '<div class="rb-sub-label">Add price adjustment</div>' +
                '<div class="rb-adj-form" id="rbAdjForm"' +
                (s.adjFormOpen ? "" : ' style="display:none;"') +
                ">" +
                '<div class="rb-adj-form-row">' +
                '<select id="rbAdjType"><option value="add">Add</option><option value="deduct">Deduct</option></select>' +
                '<div class="rb-currency-input-wrap"><span class="rb-currency-ic">\u20b1</span><input type="text" class="rb-mono" id="rbAdjAmount" placeholder="Enter amount, e.g. 200.50" inputmode="decimal"></div>' +
                "</div>" +
                '<div class="rb-adj-reason-label"><span>Reason <span style="color:#D8402C;">*</span></span></div>' +
                '<textarea id="rbAdjReason" placeholder="Enter reason"></textarea>' +
                '<div class="rb-adj-error" id="rbAdjError" style="display:none;"></div>' +
                '<div class="rb-adj-form-actions">' +
                '<button type="button" class="rb-btn rb-btn-secondary" id="rbAdjCancelBtn">Cancel</button>' +
                '<button type="button" class="rb-btn rb-btn-primary" id="rbAdjAddBtn">' +
                icon("plus") +
                " Add adjustment</button>" +
                "</div></div>" +
                '<button type="button" class="rb-btn rb-btn-secondary rb-adj-toggle-btn" id="rbAdjToggleBtn"' +
                (s.adjFormOpen ? ' style="display:none;"' : "") +
                ">" +
                icon("plus") +
                " Add price adjustment</button>" +
                '<div class="rb-adj-hint">You can add multiple adjustments if needed.</div>';
        }

        var negotiatingHtml = "";
        if (eff === "negotiating" && s.counterOfferAmount) {
            negotiatingHtml =
                '<div class="rb-cq-ref" style="border-color:#D8402C;"><div class="rb-cq-label" style="color:#D8402C;">Customer countered</div>' +
                '<div class="rb-cq-row rb-cq-total"><span>New offer</span><span class="rb-mono">' +
                peso(s.counterOfferAmount) +
                "</span></div></div>";
        }

        var reviewReasonHtml = "";
        if (eff === "price_review_requested" && s.reviewReason) {
            reviewReasonHtml =
                '<div class="rb-cq-ref" style="border-color:#D97706;"><div class="rb-cq-label" style="color:#D97706;">Customer requested a price review</div>' +
                '<div style="font-size:12.5px;color:#3F3F46;padding-top:4px;">' +
                esc(s.reviewReason) +
                "</div></div>";
        }

        var reviewAdjustFormHtml = "";
        if (eff === "price_review_requested") {
            reviewAdjustFormHtml =
                '<div class="rb-adj-form" id="rbReviewAdjustForm"' +
                (s.adjFormOpen ? "" : ' style="display:none;"') +
                ">" +
                '<div class="rb-sub-label">Adjust price</div>' +
                '<div class="rb-adj-form-row">' +
                '<select id="rbReviewAdjustType"><option value="add">Add</option><option value="deduct">Deduct</option></select>' +
                '<div class="rb-currency-input-wrap"><span class="rb-currency-ic">₱</span><input type="text" class="rb-mono" id="rbReviewAdjustAmount" placeholder="Enter amount, e.g. 200.50" inputmode="decimal"></div>' +
                "</div>" +
                '<div class="rb-adj-reason-label"><span>Note to customer <span style="color:#D8402C;">*</span></span></div>' +
                '<textarea id="rbReviewAdjustReason" placeholder="Explain the new price"></textarea>' +
                '<div class="rb-adj-error" id="rbReviewAdjustError" style="display:none;"></div>' +
                '<div class="rb-adj-form-actions">' +
                '<button type="button" class="rb-btn rb-btn-secondary" id="rbReviewAdjustCancelBtn">Cancel</button>' +
                "</div></div>";
        }

        return (
            '<div class="rb-section"><h4>' + (s.isScheduled ? "Pricing" : "Pricing &amp; unit") + '</h4>' +
            breakdown +
            adjustedHtml +
            negotiatingHtml +
            reviewReasonHtml +
            historyHtml +
            adjFormHtml +
            reviewAdjustFormHtml +
            "</div>"
        );
    }

    function unitCardHtml(u, isRecommended, isSelected) {
        var star = isRecommended
            ? '<span class="rb-unit-star rb-is-rec">' +
              starIconFilled(16) +
              "</span>"
            : '<span class="rb-unit-star">' +
              icon("starOutline", 16) +
              "</span>";
        var crew =
            u.crew_names && u.crew_names.length ? u.crew_names.join(", ") : "-";
        return (
            '<div class="rb-unit-card' +
            (isSelected ? " rb-is-selected" : "") +
            '" data-unit-id="' +
            u.id +
            '">' +
            '<div class="rb-unit-card-top">' +
            '<div class="rb-unit-avatar">' +
            icon("mapPin", 20) +
            "</div>" +
            '<div class="rb-unit-card-info">' +
            '<div class="rb-unit-card-name">' +
            esc(u.label) +
            "</div>" +
            '<div class="rb-unit-card-row">' +
            icon("user", 13) +
            " " +
            esc(u.team_leader_name) +
            "</div>" +
            '<div class="rb-unit-card-row">' +
            esc(u.status_summary || "") +
            "</div>" +
            "</div>" +
            '<div class="rb-unit-card-side">' +
            star +
            '<button type="button" class="rb-btn rb-btn-secondary" data-assign-unit="' +
            u.id +
            '">' +
            (isSelected ? "✓ Selected" : "Select") +
            "</button>" +
            "</div></div>" +
            '<button type="button" class="rb-unit-expand-toggle" data-expand-unit="' +
            u.id +
            '">' +
            icon("chevronDown", 13) +
            " Driver &amp; crew</button>" +
            '<div class="rb-unit-expand-body" id="rbUnitExpand-' +
            u.id +
            '">' +
            '<div class="rb-unit-expand-row"><span>Driver</span><b>' +
            esc(u.driver_name || "-") +
            "</b></div>" +
            '<div class="rb-unit-expand-row"><span>Crew</span><b>' +
            esc(crew) +
            "</b></div>" +
            "</div></div>"
        );
    }

    function unitsSectionHtml(s) {
        // Scheduled bookings never reserve a unit in advance — "Available
        // units" only appears once the booking has actually reached its
        // ready/overdue dispatch window. Book Now is unaffected (isScheduled
        // is false, so this always falls through to the section below).
        if (s.isScheduled) {
            var eff = effectiveStatus(s);
            if (eff !== "ready" && eff !== "overdue") return "";
        }
        return (
            '<div class="rb-section"><h4>Available units</h4>' +
            '<div class="rb-search-wrap"><span class="rb-search-ic">' +
            icon("search", 15) +
            '</span><input type="text" id="rbUnitSearch" placeholder="Search unit or team leader"></div>' +
            '<div class="rb-unit-option-list" id="rbUnitList" style="display:flex;flex-direction:column;gap:10px;margin-top:10px;"></div>' +
            "</div>"
        );
    }

    function historyTimelineSectionHtml(s) {
        var items = [
            {
                labelHtml: esc("Booking submitted"),
                noteHtml: "",
                time: timeAgoLabel(s.createdAt),
                at: s.createdAt,
                accept: false,
            },
        ];
        // Synthetic — no dedicated "draft saved" event is persisted anywhere
        // (saveQuotationDraft() only logs a price_change_log entry when
        // there's an actual price delta to record, which a first save has
        // none of). quotation.created_at already pins the true first-save
        // moment and never moves on later edits, so it's reused here instead
        // of adding a new persisted event. Skipped if a future price_change_log
        // entry type ever represents this same moment, to avoid a duplicate line.
        var hasDraftSavedLogEntry = s.priceChangeLog.some(function (h) {
            return h.type === "draft_saved";
        });
        if (s.quotationId && s.quotationCreatedAt && !hasDraftSavedLogEntry) {
            items.push({
                labelHtml: esc("Draft saved"),
                noteHtml: "",
                time: timeAgoLabel(s.quotationCreatedAt),
                at: s.quotationCreatedAt,
                accept: false,
            });
        }
        function sentVersionLabel(version) {
            return parseInt(version, 10) <= 1
                ? "Initial quotation sent"
                : "Revised quotation sent";
        }
        var sentVersionsLogged = {};
        s.priceChangeLog.forEach(function (h) {
            if (h.type === "quotation_sent") {
                sentVersionsLogged[h.version] = true;
                items.push({
                    labelHtml: esc(sentVersionLabel(h.version)),
                    noteHtml: "",
                    time: timeAgoLabel(h.at),
                    at: h.at,
                    accept: false,
                });
                return;
            }
            var delta = (parseFloat(h.new) || 0) - (parseFloat(h.old) || 0);
            var priceHtml;
            if (h.type === "price_review_requested") {
                priceHtml = "Customer requested a price review";
            } else if (h.type === "price_review_kept") {
                priceHtml =
                    "Price review completed \u2014 amount unchanged (" +
                    peso(h.new) +
                    ")";
            } else {
                priceHtml =
                    delta === 0
                        ? "Price updated (now " + peso(h.new) + ")"
                        : '<span class="' +
                          (delta > 0 ? "rb-is-add" : "rb-is-deduct") +
                          '">' +
                          (delta > 0 ? "+" : "\u2212") +
                          peso(Math.abs(delta)) +
                          "</span> (now " +
                          peso(h.new) +
                          ")";
            }
            items.push({
                labelHtml: priceHtml,
                noteHtml: h.reason ? esc(h.reason) : "",
                time: timeAgoLabel(h.at) || "",
                at: h.at,
                accept: false,
            });
        });
        if (
            s.quotationId &&
            s.sentAt &&
            s.quotationVersion &&
            !sentVersionsLogged[s.quotationVersion]
        ) {
            items.push({
                labelHtml: esc(sentVersionLabel(s.quotationVersion)),
                noteHtml: "",
                time: timeAgoLabel(s.sentAt),
                at: s.sentAt,
                accept: false,
            });
        }
        (s.rescheduleEvents || []).forEach(function (ev) {
            items.push({
                labelHtml: esc("Booking rescheduled"),
                noteHtml:
                    ev.old_scheduled_for && ev.new_scheduled_for
                        ? esc(ev.old_scheduled_for + " → " + ev.new_scheduled_for)
                        : "",
                time: timeAgoLabel(ev.at),
                at: ev.at,
                accept: false,
            });
        });
        items.sort(function (a, b) {
            var ta = new Date(a.at).getTime();
            var tb = new Date(b.at).getTime();
            return (isNaN(ta) ? 0 : ta) - (isNaN(tb) ? 0 : tb);
        });
        var rows = items
            .map(function (it) {
                var ic = it.accept ? icon("check", 14) : icon("fileText", 14);
                var noteRow = it.noteHtml
                    ? '<div class="rb-t-note">' + it.noteHtml + "</div>"
                    : "";
                return (
                    '<div class="rb-t-item' +
                    (it.accept ? " rb-is-accept" : "") +
                    '"><span class="rb-t-icon">' +
                    ic +
                    '</span><div><div class="rb-t-text">' +
                    it.labelHtml +
                    "</div>" +
                    noteRow +
                    '<div class="rb-t-time">' +
                    esc(it.time) +
                    "</div></div></div>"
                );
            })
            .join("");
        return (
            '<div class="rb-section"><h4>Quote history</h4><div class="rb-timeline">' +
            rows +
            "</div></div>"
        );
    }

    // ------------------------------------------------------------------
    // Footer (status-specific actions)
    // ------------------------------------------------------------------
    function footerHtml(s) {
        var eff = effectiveStatus(s);
        var main = "";
        var draftNote = "";
        if (eff === "new") {
            main =
                '<button type="button" class="rb-btn rb-btn-primary" id="rbSaveDraftBtn">' +
                icon("save") +
                " Save Quote</button>";
        } else if (eff === "draft") {
            var hasStagedChanges = hasStagedDraftChanges(s);
            draftNote =
                '<div style="font-size:11px;color:#8A93A3;margin-bottom:8px;"></div>';
            main =
                '<button type="button" class="rb-btn rb-btn-secondary" id="rbEditPriceBtn">' +
                icon("pencil") +
                " Edit price</button>" +
                '<button type="button" class="rb-btn rb-btn-secondary" id="rbSaveDraftBtn"' +
                (hasStagedChanges ? "" : " disabled") +
                ">" +
                icon("save") +
                " Save changes</button>" +
                '<button type="button" class="rb-btn rb-btn-primary" id="rbSendBtn">' +
                icon("send") +
                " Send to Customer</button>";
        } else if (eff === "sent") {
            main =
                '<button type="button" class="rb-btn rb-btn-secondary" id="rbCancelQuoteBtn">' +
                icon("xCircle") +
                " Cancel quote</button>";
        } else if (eff === "negotiating") {
            main =
                '<button type="button" class="rb-btn rb-btn-secondary" id="rbDeclineCounterBtn">Decline, keep ' +
                peso(s.currentPrice) +
                "</button>" +
                '<button type="button" class="rb-btn rb-btn-primary" id="rbAcceptCounterBtn">Resend at ' +
                peso(s.counterOfferAmount || s.currentPrice) +
                "</button>";
        } else if (eff === "price_review_requested") {
            main = s.adjFormOpen
                ? '<button type="button" class="rb-btn rb-btn-primary" id="rbReviewAdjustSendBtn">' +
                  icon("send") +
                  " Send to Customer</button>"
                : '<button type="button" class="rb-btn rb-btn-secondary" id="rbKeepPriceBtn">Keep current price ' +
                  peso(s.currentPrice) +
                  "</button>" +
                  '<button type="button" class="rb-btn rb-btn-primary" id="rbAdjustPriceBtn">' +
                  icon("pencil") +
                  " Adjust price</button>";
        } else if (eff === "confirmed" || eff === "upcoming") {
            main = s.isScheduled
                ? '<div class="rb-sched-foot-note" style="font-size:12.5px;color:#5B6472;line-height:1.5;">No unit selection needed yet — available units are shown 1 hour before the scheduled service time.</div>'
                : '<button type="button" class="rb-btn rb-btn-primary" id="rbDispatchBtn"' +
                  (s.selectedUnitId ? "" : " disabled") +
                  ">Proceed to Dispatch</button>";
        } else if (eff === "ready") {
            main =
                '<button type="button" class="rb-btn rb-btn-primary" id="rbDispatchBtn"' +
                (s.selectedUnitId ? "" : " disabled") +
                ">Proceed to Dispatch</button>";
        } else if (eff === "overdue") {
            main =
                '<button type="button" class="rb-btn rb-btn-primary" id="rbDispatchBtn"' +
                (s.selectedUnitId ? "" : " disabled") +
                ">Dispatch Now</button>" +
                '<button type="button" class="rb-btn rb-btn-secondary" id="rbRescheduleBtn">Reschedule</button>';
        } else if (eff === "expired") {
            main = ""; // no Extend / Resend — expired quotes are done, only Reject remains
        }

        var expiredChip =
            eff === "expired"
                ? '<div class="rb-expired-chip">' +
                  icon("clock", 14) +
                  "<span>Quote expired</span></div>"
                : "";

        // Scheduled: no cancel option in confirmed/upcoming/ready (the
        // customer app is the cancel path there) — only Overdue gets a
        // dispatcher-initiated "Cancel Booking", reusing the same
        // action:'reject' wire contract as Book Now's reject link.
        var showRejectLink = s.isScheduled
            ? eff === "overdue"
            : eff !== "confirmed";
        var rejectLabel = s.isScheduled ? "Cancel Booking" : "Reject this booking";
        var rejectHtml = showRejectLink
            ? '<div class="rb-drawer-foot-reject"><button type="button" class="rb-link-btn" id="rbRejectBtn" style="color:#D8402C;">' +
              icon("trash", 14) +
              " " + rejectLabel + "</button></div>"
            : "";

        return (
            expiredChip +
            draftNote +
            '<div class="rb-drawer-foot-main">' +
            main +
            "</div>" +
            rejectHtml
        );
    }

    // ------------------------------------------------------------------
    // Full drawer render
    // ------------------------------------------------------------------
    function renderDrawer() {
        var s = state;
        var initials = (s.customerName || "?")
            .split(" ")
            .filter(Boolean)
            .slice(0, 2)
            .map(function (p) {
                return p[0];
            })
            .join("")
            .toUpperCase();

        // innerHTML replacement below rebuilds .rb-drawer-body as a brand-new node,
        // which resets scroll to the top — restore wherever the dispatcher was
        // scrolled to (e.g. re-rendering after "Add price adjustment") so re-renders
        // don't keep bouncing them back up.
        var prevBody = drawerEl.querySelector(".rb-drawer-body");
        var prevScrollTop = prevBody ? prevBody.scrollTop : 0;

        drawerEl.innerHTML =
            '<div class="rb-drawer-head">' +
            '<div class="rb-who"><div class="rb-avatar">' +
            esc(initials) +
            "</div><div><h3>" +
            esc(s.customerName) +
            '</h3><div class="rb-sub"><span>' +
            esc(s.customerPhone) +
            "</span>" +
            (s.customerEmail
                ? "<span>" + esc(s.customerEmail) + "</span>"
                : "") +
            "</div></div></div>" +
            '<button type="button" class="rb-drawer-close" id="rbDrawerCloseBtn">\u2715</button>' +
            "</div>" +
            '<div class="rb-drawer-body">' +
            requestSectionHtml(s) +
            serviceScheduleSectionHtml(s) +
            vehicleSectionHtml(s) +
            routeSectionHtml(s) +
            customerNoteSectionHtml(s) +
            pricingSectionHtml(s) +
            unitsSectionHtml(s) +
            historyTimelineSectionHtml(s) +
            "</div>" +
            '<div class="rb-drawer-foot">' +
            footerHtml(s) +
            "</div>";

        wireDrawerEvents();
        renderUnitList("");

        var newBody = drawerEl.querySelector(".rb-drawer-body");
        if (newBody) newBody.scrollTop = prevScrollTop;
    }

    // ------------------------------------------------------------------
    // Event wiring (re-run after every renderDrawer())
    // ------------------------------------------------------------------
    function wireDrawerEvents() {
        var s = state;

        byId("rbDrawerCloseBtn", function (el) {
            el.onclick = closeBookingDrawer;
        });

        byId("rbPhotoStackTrigger", function (el) {
            el.onclick = function () {
                openLightbox(s.photos, 0);
            };
        });

        // ---- price adjustment form ----
        // Re-render on toggle (instead of imperatively flipping both state and
        // DOM) so s.adjFormOpen stays the single source of truth for what's shown.
        byId("rbAdjToggleBtn", function (el) {
            el.onclick = function () {
                s.adjFormOpen = true;
                renderDrawer();
            };
        });
        byId("rbAdjCancelBtn", function (el) {
            el.onclick = function () {
                s.adjFormOpen = false;
                renderDrawer();
            };
        });
        byId("rbAdjAmount", function (el) {
            el.addEventListener("input", function () {
                var v = el.value.replace(/[^\d.]/g, "");
                var firstDot = v.indexOf(".");
                if (firstDot !== -1)
                    v =
                        v.slice(0, firstDot + 1) +
                        v.slice(firstDot + 1).replace(/\./g, "");
                var parts = v.split(".");
                parts[0] = parts[0]
                    .replace(/^0+(?=\d)/, "")
                    .replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                el.value = parts.join(".");
            });
        });
        byId("rbAdjAddBtn", function (el) {
            el.onclick = function () {
                var typeSel = document.getElementById("rbAdjType");
                var amtInput = document.getElementById("rbAdjAmount");
                var reasonInput = document.getElementById("rbAdjReason");
                var amt =
                    parseFloat(
                        (amtInput.value || "0").replace(/[^\d.]/g, ""),
                    ) || 0;
                var reason = (reasonInput.value || "").trim();
                if (amt <= 0) {
                    showAdjError(
                        "Enter an amount greater than zero.",
                        amtInput,
                    );
                    return;
                }
                if (!reason) {
                    showAdjError(
                        "A reason is required for price adjustments.",
                        reasonInput,
                    );
                    return;
                }
                var signed = typeSel.value === "deduct" ? -amt : amt;
                s.adjustments.push({ amount: signed, reason: reason });
                s.adjFormOpen = true;
                renderDrawer();
            };
        });

        // ---- price history toggle ----
        byId("rbHistoryToggleBtn", function (el) {
            el.onclick = function () {
                s.historyOpen = !s.historyOpen;
                byId("rbHistoryList", function (l) {
                    l.style.display = s.historyOpen ? "" : "none";
                });
            };
        });

        // ---- footer actions ----
        byId("rbSaveDraftBtn", function (el) {
            el.onclick = function () {
                submitSaveDraft();
            };
        });
        byId("rbEditPriceBtn", function (el) {
            el.onclick = function () {
                s.adjFormOpen = true;
                renderDrawer();
                var amt = document.getElementById("rbAdjAmount");
                if (amt) {
                    amt.scrollIntoView({ behavior: "smooth", block: "center" });
                    amt.focus();
                }
            };
        });
        byId("rbSendBtn", function (el) {
            el.onclick = function () {
                confirmThenSend();
            };
        });
        byId("rbCancelQuoteBtn", function (el) {
            el.onclick = function () {
                submitCancelQuote();
            };
        });
        byId("rbDeclineCounterBtn", function (el) {
            el.onclick = function () {
                submitDecideOnCounter(false);
            };
        });
        byId("rbAcceptCounterBtn", function (el) {
            el.onclick = function () {
                submitDecideOnCounter(true);
            };
        });

        // ---- price review response (Keep current price / Adjust price) ----
        byId("rbKeepPriceBtn", function (el) {
            el.onclick = function () {
                submitKeepPrice();
            };
        });
        byId("rbAdjustPriceBtn", function (el) {
            el.onclick = function () {
                s.adjFormOpen = true;
                renderDrawer();
            };
        });
        byId("rbReviewAdjustCancelBtn", function (el) {
            el.onclick = function () {
                s.adjFormOpen = false;
                renderDrawer();
            };
        });
        byId("rbReviewAdjustAmount", function (el) {
            el.addEventListener("input", function () {
                var v = el.value.replace(/[^\d.]/g, "");
                var firstDot = v.indexOf(".");
                if (firstDot !== -1)
                    v =
                        v.slice(0, firstDot + 1) +
                        v.slice(firstDot + 1).replace(/\./g, "");
                var parts = v.split(".");
                parts[0] = parts[0]
                    .replace(/^0+(?=\d)/, "")
                    .replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                el.value = parts.join(".");
            });
        });
        byId("rbReviewAdjustSendBtn", function (el) {
            el.onclick = function () {
                var typeSel = document.getElementById("rbReviewAdjustType");
                var amtInput = document.getElementById("rbReviewAdjustAmount");
                var reasonInput = document.getElementById(
                    "rbReviewAdjustReason",
                );
                var amt =
                    parseFloat(
                        (amtInput.value || "0").replace(/[^\d.]/g, ""),
                    ) || 0;
                var reason = (reasonInput.value || "").trim();
                if (amt <= 0) {
                    showAdjError(
                        "Enter an amount greater than zero.",
                        amtInput,
                        "rbReviewAdjustError",
                    );
                    return;
                }
                if (!reason) {
                    showAdjError(
                        "A note explaining the new price is required.",
                        reasonInput,
                        "rbReviewAdjustError",
                    );
                    return;
                }
                // The backend still takes the new absolute total — the dispatcher
                // only enters an add/deduct delta, computed against the current
                // price here (same convention as the New/Draft adjustment form).
                var signed = typeSel.value === "deduct" ? -amt : amt;
                var newPrice = Number(s.currentPrice || 0) + signed;
                if (newPrice <= 0) {
                    showAdjError(
                        "The resulting price must be greater than zero.",
                        amtInput,
                        "rbReviewAdjustError",
                    );
                    return;
                }
                submitAdjustPriceAfterReview(newPrice, reason);
            };
        });

        byId("rbDispatchBtn", function (el) {
            el.onclick = function () {
                submitDispatch();
            };
        });
        byId("rbRescheduleBtn", function (el) {
            el.onclick = function () {
                submitReschedule();
            };
        });
        byId("rbRejectBtn", function (el) {
            el.onclick = function () {
                promptRejectReason();
            };
        });
    }

    function byId(id, fn) {
        var el = document.getElementById(id);
        if (el) fn(el);
    }

    var adjErrorTimer = null;
    function showAdjError(msg, focusEl, targetId) {
        var errEl = document.getElementById(targetId || "rbAdjError");
        if (!errEl) return;
        if (adjErrorTimer) clearTimeout(adjErrorTimer);
        errEl.textContent = msg;
        errEl.style.display = "";
        if (focusEl) focusEl.focus();
        adjErrorTimer = setTimeout(function () {
            errEl.style.display = "none";
        }, 3000);
    }

    // ------------------------------------------------------------------
    // Unit list (search + render + select)
    // ------------------------------------------------------------------
    function unitsForClass(truckTypeId) {
        var bookingCode = state ? state.bookingCode : null;
        return AVAILABLE_UNITS.filter(function (u) {
            if (Number(u.truck_type_id) !== Number(truckTypeId)) return false;
            // Soft-reserved by a different booking's pending quote — hide it here,
            // but never hide it from the booking that actually holds the reservation.
            if (
                u.reserved_by_booking_code &&
                String(u.reserved_by_booking_code) !== String(bookingCode)
            )
                return false;
            return true;
        });
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
        var listEl = document.getElementById("rbUnitList");
        if (!listEl) return;
        var pool = unitsForClass(s.truckTypeId);
        var q = (query || "").trim().toLowerCase();
        var filtered = pool.filter(function (u) {
            return (
                (u.label || "").toLowerCase().indexOf(q) > -1 ||
                (u.team_leader_name || "").toLowerCase().indexOf(q) > -1
            );
        });

        if (!filtered.length) {
            listEl.innerHTML =
                '<div class="rb-unit-empty-note">' +
                icon("mapPin", 24) +
                "<span>No " +
                esc(s.truckType || "matching") +
                " units ready right now \u2014 pick manually once one comes online.</span></div>";
            return;
        }

        var serverRecommended =
            s.recommendedUnitId &&
            filtered.some(function (u) {
                return String(u.id) === String(s.recommendedUnitId);
            })
                ? s.recommendedUnitId
                : null;
        var recommendedId = !q
            ? serverRecommended || (filtered[0] ? filtered[0].id : null)
            : null; // prefer the real zone-aware pick; fall back to coverage-sorted first
        var sorted = sortedWithRecommendedFirst(filtered, recommendedId);
        listEl.innerHTML = sorted
            .map(function (u) {
                return unitCardHtml(
                    u,
                    String(u.id) === String(recommendedId),
                    String(u.id) === String(s.selectedUnitId),
                );
            })
            .join("");

        listEl.querySelectorAll("[data-assign-unit]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var clickedId = btn.dataset.assignUnit;
                var alreadySelected =
                    String(s.selectedUnitId) === String(clickedId);
                s.selectedUnitId = alreadySelected ? null : clickedId;
                // renderUnitList() below only redraws the units list, not the
                // footer — flip Save changes directly so a selection change
                // (including deselecting) is never silently lost on close.
                var saveBtn = document.getElementById("rbSaveDraftBtn");
                if (saveBtn && effectiveStatus(s) === "draft") {
                    saveBtn.disabled = !hasStagedDraftChanges(s);
                }
                // Present only for eff statuses confirmed/ready/overdue (see
                // footerHtml()) — null elsewhere, so no extra status check needed.
                var dispatchBtn = document.getElementById("rbDispatchBtn");
                if (dispatchBtn) {
                    dispatchBtn.disabled = !s.selectedUnitId;
                }
                renderUnitList(
                    document.getElementById("rbUnitSearch")
                        ? document.getElementById("rbUnitSearch").value
                        : "",
                );
            });
        });
        listEl.querySelectorAll("[data-expand-unit]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var body = document.getElementById(
                    "rbUnitExpand-" + btn.dataset.expandUnit,
                );
                if (body) body.classList.toggle("rb-is-open");
            });
        });

        byId("rbUnitSearch", function (searchEl) {
            searchEl.oninput = function () {
                renderUnitList(searchEl.value);
            };
        });
    }
    // ------------------------------------------------------------------
    // Styled confirm/alert/prompt modal — replaces native window.confirm()/
    // alert()/prompt() so every dialog matches the app's own look instead of
    // the browser's. Reuses the "View all N units" modal shell (that feature
    // was deferred this round, so the mount point was otherwise unused).
    // ------------------------------------------------------------------
    var rbModalBackdrop = document.getElementById("rbUnitsModalBackdrop");
    var rbModalBox = document.getElementById("rbUnitsModalBox");
    var rbModalResolver = null;
    var rbModalCancelValue;
    function closeRbModalWith(result) {
        if (rbModalBackdrop) rbModalBackdrop.classList.remove("is-open");
        if (rbModalBox) rbModalBox.innerHTML = "";
        var resolve = rbModalResolver;
        rbModalResolver = null;
        if (resolve) resolve(result);
    }
    function closeUnitsModalIfOpen() {
        closeRbModalWith(rbModalCancelValue);
    }
    if (rbModalBackdrop) {
        rbModalBackdrop.addEventListener("click", function (e) {
            if (e.target === rbModalBackdrop)
                closeRbModalWith(rbModalCancelValue);
        });
    }

    function rbConfirm(message, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            if (!rbModalBackdrop || !rbModalBox) {
                resolve(window.confirm(message));
                return;
            }
            rbModalCancelValue = false;
            rbModalResolver = resolve;
            rbModalBox.innerHTML =
                '<div class="rb-view-all-modal-head"><div style="font-weight:700;font-size:15px;">' +
                esc(opts.title || "Confirm") +
                "</div></div>" +
                '<div class="rb-view-all-modal-body"><p style="margin:0;font-size:13px;color:#5B6472;line-height:1.5;">' +
                esc(message) +
                "</p></div>" +
                '<div class="rb-view-all-modal-foot"><button type="button" class="rb-btn rb-btn-secondary" id="rbModalCancel">' +
                esc(opts.cancelLabel || "Cancel") +
                '</button><button type="button" class="rb-btn rb-btn-primary" id="rbModalOk">' +
                esc(opts.okLabel || "Confirm") +
                "</button></div>";
            rbModalBackdrop.classList.add("is-open");
            document.getElementById("rbModalCancel").onclick = function () {
                closeRbModalWith(false);
            };
            document.getElementById("rbModalOk").onclick = function () {
                closeRbModalWith(true);
            };
        });
    }
    function rbAlert(message, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            if (!rbModalBackdrop || !rbModalBox) {
                window.alert(message);
                resolve();
                return;
            }
            rbModalCancelValue = undefined;
            rbModalResolver = resolve;
            rbModalBox.innerHTML =
                '<div class="rb-view-all-modal-head"><div style="font-weight:700;font-size:15px;">' +
                esc(opts.title || "Notice") +
                "</div></div>" +
                '<div class="rb-view-all-modal-body"><p style="margin:0;font-size:13px;color:#5B6472;line-height:1.5;">' +
                esc(message) +
                "</p></div>" +
                '<div class="rb-view-all-modal-foot"><button type="button" class="rb-btn rb-btn-primary" id="rbModalOk">OK</button></div>';
            rbModalBackdrop.classList.add("is-open");
            document.getElementById("rbModalOk").onclick = function () {
                closeRbModalWith(undefined);
            };
        });
    }
    function rbPrompt(message, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            if (!rbModalBackdrop || !rbModalBox) {
                resolve(window.prompt(message, opts.defaultValue || ""));
                return;
            }
            rbModalCancelValue = null;
            rbModalResolver = resolve;
            rbModalBox.innerHTML =
                '<div class="rb-view-all-modal-head"><div style="font-weight:700;font-size:15px;">' +
                esc(opts.title || "Input needed") +
                "</div></div>" +
                '<div class="rb-view-all-modal-body">' +
                '<p style="margin:0 0 8px;font-size:13px;color:#5B6472;line-height:1.5;">' +
                esc(message) +
                "</p>" +
                '<textarea id="rbModalInput" rows="3" style="width:100%;border:1px solid #CFD4DC;border-radius:8px;padding:9px 10px;font-size:13px;font-family:inherit;resize:vertical;box-sizing:border-box;" placeholder="' +
                esc(opts.placeholder || "") +
                '">' +
                esc(opts.defaultValue || "") +
                "</textarea>" +
                "</div>" +
                '<div class="rb-view-all-modal-foot"><button type="button" class="rb-btn rb-btn-secondary" id="rbModalCancel">Cancel</button><button type="button" class="rb-btn rb-btn-primary" id="rbModalOk">' +
                esc(opts.okLabel || "OK") +
                "</button></div>";
            rbModalBackdrop.classList.add("is-open");
            var input = document.getElementById("rbModalInput");
            input.focus();
            document.getElementById("rbModalCancel").onclick = function () {
                closeRbModalWith(null);
            };
            document.getElementById("rbModalOk").onclick = function () {
                closeRbModalWith(input.value);
            };
        });
    }

    // Exposed globally so other scripts on this page (e.g. the Scheduled
    // queue's _quotation-modal.blade.php) can use the same styled dialogs
    // instead of native confirm()/alert()/prompt().
    window.rbConfirm = rbConfirm;
    window.rbAlert = rbAlert;
    window.rbPrompt = rbPrompt;

    // ------------------------------------------------------------------
    // Lightbox
    // ------------------------------------------------------------------
    var lightboxBackdrop = document.getElementById("rbLightboxBackdrop");
    var lightboxState = { photos: [], index: 0 };
    function renderLightbox() {
        var url = lightboxState.photos[lightboxState.index];
        document.getElementById("rbLightboxImage").innerHTML = url
            ? '<img src="' + esc(url) + '" alt="Vehicle photo">'
            : "";
        document.getElementById("rbLightboxCaption").textContent =
            "Photo " +
            (lightboxState.index + 1) +
            " of " +
            lightboxState.photos.length;
    }
    function openLightbox(photos, index) {
        lightboxState.photos = photos || [];
        lightboxState.index = index || 0;
        if (!lightboxState.photos.length) return;
        renderLightbox();
        lightboxBackdrop.classList.add("is-open");
    }
    function closeLightbox() {
        lightboxBackdrop.classList.remove("is-open");
    }
    byId("rbLightboxClose", function (el) {
        el.onclick = closeLightbox;
    });
    byId("rbLightboxPrev", function (el) {
        el.onclick = function () {
            lightboxState.index =
                (lightboxState.index - 1 + lightboxState.photos.length) %
                lightboxState.photos.length;
            renderLightbox();
        };
    });
    byId("rbLightboxNext", function (el) {
        el.onclick = function () {
            lightboxState.index =
                (lightboxState.index + 1) % lightboxState.photos.length;
            renderLightbox();
        };
    });
    if (lightboxBackdrop)
        lightboxBackdrop.addEventListener("click", function (e) {
            if (e.target === lightboxBackdrop) closeLightbox();
        });

    // ------------------------------------------------------------------
    // Commit actions — each folds all staged adjustments into ONE network
    // call, per the plan's §0.A correction (update-price emails the
    // customer + versions the quotation on every call).
    // ------------------------------------------------------------------
    function setBusy(disabled) {
        drawerEl.querySelectorAll(".rb-btn").forEach(function (b) {
            b.disabled = disabled;
        });
        // Save changes must stay disabled whenever there's nothing staged to
        // save — the blanket toggle above would otherwise wrongly re-enable it
        // after a busy state ends without a full re-render (e.g. a failed
        // action on a booking with no pending adjustments).
        if (!disabled && state && !hasStagedDraftChanges(state)) {
            var saveBtn = document.getElementById("rbSaveDraftBtn");
            if (saveBtn) saveBtn.disabled = true;
        }
        // Proceed to Dispatch must stay disabled with no unit picked, for the
        // same reason — the blanket toggle above would otherwise wrongly
        // re-enable it once some other busy cycle on this drawer finishes.
        if (!disabled && state && !state.selectedUnitId) {
            var dispatchBtn = document.getElementById("rbDispatchBtn");
            if (dispatchBtn) dispatchBtn.disabled = true;
        }
    }
    function showDrawerNetworkError(message) {
        rbAlert(message || "Something went wrong. Please try again.", {
            title: "Something went wrong",
        });
    }
    function reloadAfterSuccess() {
        // Full reload keeps client state from drifting off server truth (see file
        // header) — but that also blanks out `state`/closes the drawer and resets
        // the queue tab as a side effect. Stash which booking was open (see
        // reopenDrawerAfterReload() below) AND which queue tab was active (see
        // dispatch.js's init, which otherwise always defaults back to Book Now)
        // so both restore once the new page loads.
        if (state && state.bookingCode) {
            try {
                sessionStorage.setItem(
                    "rbReopenBookingCode",
                    state.bookingCode,
                );
                var activeTabBtn = document.querySelector(
                    ".queue-filter-btn.is-active",
                );
                var activeFilter =
                    activeTabBtn && activeTabBtn.dataset.filter;
                if (activeFilter) {
                    sessionStorage.setItem(
                        "rbReopenQueueFilter",
                        activeFilter,
                    );
                }
            } catch (e) {
                /* ignore */
            }
        }
        window.location.reload();
    }

    /** Shared payload for saveQuotationDraft() — price is VAT-inclusive there (assigned to estimated_price verbatim, no server-side re-derivation). */
    function draftSavePayload(s) {
        var adj = adjustedTotal(s);
        return {
            price: Number(adj.total.toFixed(2)),
            additional_fee: Number(netAdjustment(s).toFixed(2)),
            selected_unit_id: s.selectedUnitId || null,
            dispatcher_note:
                s.adjustments
                    .map(function (a) {
                        return a.reason;
                    })
                    .join("; ") || null,
            distance_km: s.distanceKm || null,
        };
    }

    // Save Quote/Save changes no longer force a full reload, but AVAILABLE_UNITS
    // (the #rbAvailableUnitsJson blob, shared read-only across every card's
    // drawer) is still only ever loaded once at page load — without this, a
    // unit just soft-reserved here would keep showing as free in a different
    // booking's drawer opened later in the same tab, until some other action
    // happens to trigger a real reload. Mirrors what the server just did.
    function updateAvailableUnitsReservation(bookingCode, newUnitId) {
        var newId = newUnitId ? Number(newUnitId) : null;
        AVAILABLE_UNITS.forEach(function (u) {
            if (
                String(u.reserved_by_booking_code) === String(bookingCode) &&
                Number(u.id) !== newId
            ) {
                u.reserved_by_booking_code = null;
            }
            if (newId && Number(u.id) === newId) {
                u.reserved_by_booking_code = bookingCode;
            }
        });
    }

    function findCardByBookingCode(bookingCode) {
        // Book Now uses .incoming-card; the Scheduled queue is a table of
        // .jobs-row <tr> elements — both carry data-booking-code, so this
        // covers whichever queue the booking actually lives in.
        var cards = document.querySelectorAll(
            ".incoming-card[data-booking-code], .jobs-row[data-booking-code]",
        );
        for (var i = 0; i < cards.length; i++) {
            if (cards[i].dataset.bookingCode === bookingCode) return cards[i];
        }
        return null;
    }

    // Keeps the underlying queue card's own dataset in sync after an in-place
    // save (see applySaveDraftSuccess()) — without this, closing the drawer and
    // reopening the SAME card (no intervening full reload) would rebuild `state`
    // from stale data via buildStateFromCard() and the save would appear to have
    // been lost. Doesn't touch the card's visible face/badges — those only
    // refresh on the next real reload (Send/Reject/Dispatch/Cancel still do one).
    function updateCardDatasetFromState(s) {
        var card = findCardByBookingCode(s.bookingCode);
        if (!card) return;
        card.dataset.quotationId = s.quotationId || "";
        card.dataset.quotationStatus = s.quotationStatus || "";
        card.dataset.currentPrice = s.currentPrice || 0;
        card.dataset.priceChangeLog = JSON.stringify(s.priceChangeLog || []);
        card.dataset.selectedUnit = s.selectedUnitId || "";
    }

    // Save Quote / Save changes only ever persist price/unit-selection details —
    // they never move the booking to a different queue bucket or status — so,
    // per explicit request, they refresh the already-open drawer in place
    // instead of reloadAfterSuccess()'s full page reload (which the dispatcher
    // saw as the drawer visibly closing and reopening).
    function applySaveDraftSuccess(data, committedPrice) {
        var s = state;
        s.currentPrice = committedPrice; // exactly what the server just stored verbatim as estimated_price
        s.adjustments = [];
        s.originalSelectedUnitId = s.selectedUnitId;
        if (data && data.quotation_id) s.quotationId = data.quotation_id;
        if (data && data.quotation_status)
            s.quotationStatus = data.quotation_status;
        updateAvailableUnitsReservation(s.bookingCode, s.selectedUnitId);
        renderDrawer();
        updateCardDatasetFromState(s);

        if (s.quotationId) {
            fetchQuotationDetails(s.quotationId)
                .then(function (fresh) {
                    if (state !== s) return; // drawer moved on to a different booking meanwhile
                    mergeQuotationDetailsIntoState(fresh);
                    renderDrawer();
                    updateCardDatasetFromState(s);
                })
                .catch(function () {
                    /* optimistic values already applied above */
                });
        }
    }

    function submitSaveDraft() {
        var s = state;
        // Distance is computed entirely on the customer's side (from the pickup/
        // dropoff locations they set) — dispatchers never enter it. If it's somehow
        // missing on a fresh booking, block the save with an explanation instead of
        // offering a field to fix, since there's nothing for the dispatcher to fix.
        if (effectiveStatus(s) === "new" && !(parseFloat(s.distanceKm) > 0)) {
            rbAlert(
                "Distance isn't available for this booking yet. It's calculated automatically from the customer's pickup and drop-off locations — dispatchers can't enter it manually. Try again once it syncs, or contact support if this persists.",
                { title: "Distance not available" },
            );
            return;
        }
        var payload = draftSavePayload(s);
        setBusy(true);
        apiCall(
            fillRoute(window.RB_ROUTES.saveDraft, s.bookingCode),
            "POST",
            payload,
        )
            .then(function (res) {
                setBusy(false);
                if (!res.ok) {
                    showDrawerNetworkError(res.data && res.data.message);
                    return;
                }
                applySaveDraftSuccess(res.data, payload.price);
            })
            .catch(function () {
                setBusy(false);
                showDrawerNetworkError();
            });
    }

    // Shared by both confirmThenSend() branches below — sending never moves the
    // booking out of the Book Now queue (only the quotation's own status changes,
    // to 'sent'; see QuotationService::sendQuotation()), so this refreshes state
    // and the drawer in place instead of reloadAfterSuccess(), same as
    // applySaveDraftSuccess() for Save Quote/Save changes.
    function applySendSuccess(committedPrice) {
        var s = state;
        s.currentPrice = committedPrice;
        s.adjustments = [];
        s.originalSelectedUnitId = s.selectedUnitId;
        s.quotationStatus = "sent";
        updateAvailableUnitsReservation(s.bookingCode, s.selectedUnitId);
        renderDrawer();
        updateCardDatasetFromState(s);

        if (s.quotationId) {
            fetchQuotationDetails(s.quotationId)
                .then(function (fresh) {
                    if (state !== s) return;
                    mergeQuotationDetailsIntoState(fresh);
                    renderDrawer();
                    updateCardDatasetFromState(s);
                })
                .catch(function () {
                    /* optimistic values already applied above */
                });
        }
    }

    async function confirmThenSend() {
        var s = state;

        var adj = adjustedTotal(s);
        var displayTotal = s.adjustments.length ? adj.total : s.currentPrice;
        var ok = await rbConfirm(
            "Send this quote to " +
                s.customerName +
                " for " +
                peso(displayTotal) +
                " (incl. VAT)? This cannot be undone.",
            { title: "Send quote", okLabel: "Send to customer" },
        );
        if (!ok) return;

        if (s.adjustments.length) {
            // Draft with unsaved staged adjustments — persist them to the draft first
            // (saveQuotationDraft takes price VAT-inclusive verbatim), then send it.
            var payload = draftSavePayload(s);
            setBusy(true);
            apiCall(
                fillRoute(window.RB_ROUTES.saveDraft, s.bookingCode),
                "POST",
                payload,
            )
                .then(function (res) {
                    if (!res.ok) {
                        setBusy(false);
                        showDrawerNetworkError(res.data && res.data.message);
                        return;
                    }
                    if (res.data && res.data.quotation_id)
                        s.quotationId = res.data.quotation_id; // brand-new draft on a 'new' booking
                    return apiCall(
                        fillRoute(window.RB_ROUTES.quoteSend, s.quotationId),
                        "POST",
                        {},
                    );
                })
                .then(function (res) {
                    setBusy(false);
                    if (!res) return; // save-draft already failed and reported above
                    if (!res.ok) {
                        showDrawerNetworkError(res.data && res.data.message);
                        return;
                    }
                    applySendSuccess(payload.price);
                })
                .catch(function () {
                    setBusy(false);
                    showDrawerNetworkError();
                });
        } else {
            // Draft already exists on the server with no further staged changes — just send it.
            setBusy(true);
            apiCall(
                fillRoute(window.RB_ROUTES.quoteSend, s.quotationId),
                "POST",
                {},
            )
                .then(function (res) {
                    setBusy(false);
                    if (!res.ok) {
                        showDrawerNetworkError(res.data && res.data.message);
                        return;
                    }
                    applySendSuccess(s.currentPrice);
                })
                .catch(function () {
                    setBusy(false);
                    showDrawerNetworkError();
                });
        }
    }

    async function submitCancelQuote() {
        var ok = await rbConfirm(
            "Cancel this quote? The customer will no longer be able to accept it.",
            {
                title: "Cancel quote",
                okLabel: "Cancel quote",
                cancelLabel: "Keep it",
            },
        );
        if (!ok) return;
        setBusy(true);
        apiCall(
            fillRoute(window.RB_ROUTES.quoteCancel, state.quotationId),
            "POST",
            {},
        )
            .then(function (res) {
                setBusy(false);
                if (!res.ok) {
                    showDrawerNetworkError(res.data && res.data.message);
                    return;
                }
                reloadAfterSuccess();
            })
            .catch(function () {
                setBusy(false);
                showDrawerNetworkError();
            });
    }

    /**
     * Dispatcher's two responses to a customer's "Request Price Review" —
     * Book Now only. Distinct from submitDecideOnCounter() above (that one
     * responds to the old, dead-for-Book-Now negotiation path and stays
     * untouched since Scheduled's own tabbed modal still relies on its
     * backend endpoint).
     */
    async function submitKeepPrice() {
        var ok = await rbConfirm(
            "Keep the current price (" +
                peso(state.currentPrice) +
                ") and notify the customer? A new 1-hour response window will start.",
            { title: "Keep current price", okLabel: "Keep price" },
        );
        if (!ok) return;
        setBusy(true);
        apiCall(
            fillRoute(window.RB_ROUTES.quoteKeepPrice, state.quotationId),
            "POST",
            {},
        )
            .then(function (res) {
                setBusy(false);
                if (!res.ok) {
                    showDrawerNetworkError(res.data && res.data.message);
                    return;
                }
                reloadAfterSuccess();
            })
            .catch(function () {
                setBusy(false);
                showDrawerNetworkError();
            });
    }

    function submitAdjustPriceAfterReview(newPrice, note) {
        setBusy(true);
        apiCall(
            fillRoute(window.RB_ROUTES.quoteAdjustPrice, state.quotationId),
            "POST",
            { new_price: Number(newPrice.toFixed(2)), note: note },
        )
            .then(function (res) {
                setBusy(false);
                if (!res.ok) {
                    showDrawerNetworkError(res.data && res.data.message);
                    return;
                }
                reloadAfterSuccess();
            })
            .catch(function () {
                setBusy(false);
                showDrawerNetworkError();
            });
    }

    /**
     * Accept/Decline counter both resend the quote at a given price — the
     * customer always has final say (this backend has no way for a
     * dispatcher to instantly confirm on their behalf). Accept resends at
     * their proposed price; Decline resends at the original price. Both
     * transition the quotation back to "sent", awaiting the customer's
     * real acceptance in their own app.
     */
    async function submitDecideOnCounter(acceptCounter) {
        var s = state;
        var newPrice = acceptCounter
            ? s.counterOfferAmount || s.currentPrice
            : s.currentPrice;
        var verb = acceptCounter
            ? "resend the quote at their proposed price"
            : "resend your original price";
        var ok = await rbConfirm(
            "This will " +
                verb +
                " (" +
                peso(newPrice) +
                ") and the customer will need to accept it again. Continue?",
            { title: "Resend quote", okLabel: "Resend quote" },
        );
        if (!ok) return;
        setBusy(true);
        apiCall(
            fillRoute(window.RB_ROUTES.quoteUpdatePrice, s.quotationId),
            "PATCH",
            {
                new_price: Number(newPrice.toFixed(2)),
                additional_fee: 0,
            },
        )
            .then(function (res) {
                setBusy(false);
                if (!res.ok) {
                    showDrawerNetworkError(res.data && res.data.message);
                    return;
                }
                reloadAfterSuccess();
            })
            .catch(function () {
                setBusy(false);
                showDrawerNetworkError();
            });
    }

    function submitDispatch() {
        var s = state;
        if (!s.selectedUnitId) {
            rbAlert("Pick a unit from Available units first.", {
                title: "No unit selected",
            });
            return;
        }
        setBusy(true);
        apiCall(fillRoute(window.RB_ROUTES.assign, s.bookingCode), "POST", {
            action: "accept",
            assigned_unit_id: s.selectedUnitId,
        })
            .then(function (res) {
                setBusy(false);
                if (!res.ok) {
                    showDrawerNetworkError(res.data && res.data.message);
                    return;
                }
                var target =
                    window.RB_ROUTES.jobsIndex +
                    "?booking=" +
                    encodeURIComponent(s.bookingCode);
                window.location.href = target;
            })
            .catch(function () {
                setBusy(false);
                showDrawerNetworkError();
            });
    }

    /** Custom validated dialog for the Scheduled dispatcher "Cancel Booking" action — reuses the same rbModalBackdrop/rbModalBox shell as promptReschedule(). Unlike rbPrompt(), the reason here is always required, with inline validation. */
    function promptCancelReason() {
        return new Promise(function (resolve) {
            if (!rbModalBackdrop || !rbModalBox) {
                resolve(window.prompt("Reason for cancellation"));
                return;
            }
            rbModalCancelValue = null;
            rbModalResolver = resolve;
            rbModalBox.innerHTML =
                '<div class="rb-view-all-modal-head"><div style="font-weight:700;font-size:15px;">Cancel this booking</div></div>' +
                '<div class="rb-view-all-modal-body">' +
                '<label style="font-size:11px;color:#5B6472;display:block;margin-bottom:4px;">Reason for cancellation <span style="color:#D8402C;">*</span></label>' +
                '<textarea id="rbCancelReason" rows="3" style="width:100%;border:1px solid #CFD4DC;border-radius:8px;padding:9px 10px;font-size:13px;font-family:inherit;resize:vertical;box-sizing:border-box;" placeholder="e.g. Customer requested cancellation"></textarea>' +
                '<div id="rbCancelReasonError" style="display:none;color:#D8402C;font-size:12px;margin-top:6px;"></div>' +
                "</div>" +
                '<div class="rb-view-all-modal-foot"><button type="button" class="rb-btn rb-btn-secondary" id="rbModalCancel">Cancel</button><button type="button" class="rb-btn rb-btn-primary" id="rbModalOk">Cancel booking</button></div>';
            rbModalBackdrop.classList.add("is-open");
            var input = document.getElementById("rbCancelReason");
            input.focus();
            document.getElementById("rbModalCancel").onclick = function () {
                closeRbModalWith(null);
            };
            document.getElementById("rbModalOk").onclick = function () {
                var reason = (input.value || "").trim();
                var errEl = document.getElementById("rbCancelReasonError");
                if (!reason) {
                    errEl.textContent = "A reason is required to cancel this booking.";
                    errEl.style.display = "";
                    return;
                }
                closeRbModalWith(reason);
            };
        });
    }

    async function promptRejectReason() {
        // A Scheduled booking already at Overdue reuses this exact reject
        // wire contract for its "Cancel Booking" action (see footerHtml()) —
        // the server tells the two apart by the locked booking's own status
        // (DispatchController::assignBooking()'s reject branch), so only the
        // dialog copy needs to differ here. Cancelling a Scheduled booking
        // requires a reason (validated both here and server-side); rejecting
        // a never-quoted Book Now request stays optional via rbPrompt().
        var isSchedCancel = state && state.isScheduled && effectiveStatus(state) === "overdue";
        var reason = isSchedCancel
            ? await promptCancelReason()
            : await rbPrompt(
                  "Reason for rejecting this booking (shown to the customer):",
                  {
                      title: "Reject this booking",
                      okLabel: "Reject booking",
                      placeholder: "e.g. Outside service area",
                  },
              );
        if (reason === null) return; // cancelled
        setBusy(true);
        apiCall(fillRoute(window.RB_ROUTES.assign, state.bookingCode), "POST", {
            action: "reject",
            rejection_reason: reason || (isSchedCancel ? "Cancelled by dispatcher." : "Rejected by dispatcher."),
        })
            .then(function (res) {
                setBusy(false);
                if (!res.ok) {
                    showDrawerNetworkError(res.data && res.data.message);
                    return;
                }
                reloadAfterSuccess();
            })
            .catch(function () {
                setBusy(false);
                showDrawerNetworkError();
            });
    }

    /** Custom multi-field dialog (date + time + reason) reusing the same rbModalBackdrop/rbModalBox shell as rbPrompt(). */
    function promptReschedule(opts) {
        opts = opts || {};
        var reasonRequired = !!opts.reasonRequired;
        return new Promise(function (resolve) {
            if (!rbModalBackdrop || !rbModalBox) {
                resolve(null);
                return;
            }
            rbModalCancelValue = null;
            rbModalResolver = resolve;
            rbModalBox.innerHTML =
                '<div class="rb-view-all-modal-head"><div style="font-weight:700;font-size:15px;">Reschedule booking</div></div>' +
                '<div class="rb-view-all-modal-body">' +
                '<div style="font-size:12.5px;color:#5B6472;margin-bottom:10px;">Current schedule: <strong>' +
                esc(opts.currentLabel || "-") +
                "</strong></div>" +
                '<div style="display:flex;gap:8px;margin-bottom:10px;">' +
                '<div style="flex:1;"><label style="font-size:11px;color:#5B6472;display:block;margin-bottom:4px;">New date</label><input type="date" id="rbReschedDate" style="width:100%;border:1px solid #CFD4DC;border-radius:8px;padding:8px 10px;font-size:13px;box-sizing:border-box;"></div>' +
                '<div style="flex:1;"><label style="font-size:11px;color:#5B6472;display:block;margin-bottom:4px;">New time</label><input type="time" id="rbReschedTime" style="width:100%;border:1px solid #CFD4DC;border-radius:8px;padding:8px 10px;font-size:13px;box-sizing:border-box;"></div>' +
                "</div>" +
                '<label style="font-size:11px;color:#5B6472;display:block;margin-bottom:4px;">Reason' +
                (reasonRequired
                    ? ' <span style="color:#D8402C;">*</span>'
                    : " (optional)") +
                "</label>" +
                '<textarea id="rbReschedReason" rows="2" style="width:100%;border:1px solid #CFD4DC;border-radius:8px;padding:9px 10px;font-size:13px;font-family:inherit;resize:vertical;box-sizing:border-box;" placeholder="Why is this being rescheduled?"></textarea>' +
                '<div id="rbReschedError" style="display:none;color:#D8402C;font-size:12px;margin-top:6px;"></div>' +
                '<div style="font-size:11.5px;color:#8A93A3;margin-top:10px;">Availability will be checked again once this booking reaches its new Ready window.</div>' +
                "</div>" +
                '<div class="rb-view-all-modal-foot"><button type="button" class="rb-btn rb-btn-secondary" id="rbModalCancel">Cancel</button><button type="button" class="rb-btn rb-btn-primary" id="rbModalOk">Save new schedule</button></div>';
            rbModalBackdrop.classList.add("is-open");
            document.getElementById("rbModalCancel").onclick = function () {
                closeRbModalWith(null);
            };
            document.getElementById("rbModalOk").onclick = function () {
                var date = document.getElementById("rbReschedDate").value;
                var time = document.getElementById("rbReschedTime").value;
                var reason = (
                    document.getElementById("rbReschedReason").value || ""
                ).trim();
                var errEl = document.getElementById("rbReschedError");
                if (!date || !time) {
                    errEl.textContent = "Pick a new date and time.";
                    errEl.style.display = "";
                    return;
                }
                if (reasonRequired && !reason) {
                    errEl.textContent = "A reason is required when rescheduling an overdue booking.";
                    errEl.style.display = "";
                    return;
                }
                closeRbModalWith({ date: date, time: time, reason: reason });
            };
        });
    }

    function submitReschedule() {
        var s = state;
        var currentLabel = "-";
        if (s.scheduledFor) {
            var d = new Date(s.scheduledFor);
            if (!isNaN(d.getTime())) {
                currentLabel = d.toLocaleString("en-PH", {
                    month: "short",
                    day: "numeric",
                    year: "numeric",
                    hour: "numeric",
                    minute: "2-digit",
                });
            }
        }
        promptReschedule({
            currentLabel: currentLabel,
            reasonRequired: effectiveStatus(s) === "overdue",
        }).then(function (result) {
            if (!result) return;
            setBusy(true);
            apiCall(fillRoute(window.RB_ROUTES.reschedule, s.bookingCode), "POST", {
                new_scheduled_date: result.date,
                new_scheduled_time: result.time,
                reason: result.reason || null,
            })
                .then(function (res) {
                    setBusy(false);
                    if (!res.ok) {
                        showDrawerNetworkError(res.data && res.data.message);
                        return;
                    }
                    reloadAfterSuccess();
                })
                .catch(function () {
                    setBusy(false);
                    showDrawerNetworkError();
                });
        });
    }

    // Note: #rbBnFilter's actual status-filtering is handled by
    // dispatch.js's initializeBookNowFilter(), which filters the real
    // .jobs-row table rows (this file used to filter a .rb-qcard card grid
    // that no longer exists in the current table-based Book Now layout).

    function updateRbWaitPills() {
        document.querySelectorAll("[data-rb-created]").forEach(function (pill) {
            var target = pill.querySelector("[data-rb-wait]");
            if (!target) return;
            var created = new Date(pill.dataset.rbCreated).getTime();
            if (isNaN(created)) return;
            var sec = Math.max(0, Math.floor((Date.now() - created) / 1000));
            var label =
                sec < 60
                    ? sec + "s"
                    : sec < 3600
                      ? Math.floor(sec / 60) + "m"
                      : Math.floor(sec / 3600) + "h";
            target.textContent = label + " ago";
        });
    }
    window.setInterval(updateRbWaitPills, 5000);

    // Auto-reopen the drawer for whichever booking a mutating action (save
    // quote/changes, send, dispatch, reject, etc.) just reloaded the page for
    // — see reloadAfterSuccess(). One-shot: cleared immediately so a later,
    // unrelated manual refresh doesn't also pop the drawer back open.
    (function reopenDrawerAfterReload() {
        var bookingCode;
        try {
            bookingCode = sessionStorage.getItem("rbReopenBookingCode");
            sessionStorage.removeItem("rbReopenBookingCode");
        } catch (e) {
            return;
        }
        if (!bookingCode) return;
        var card = findCardByBookingCode(bookingCode);
        if (card) window.openBookingDrawer(card);
    })();
})();
