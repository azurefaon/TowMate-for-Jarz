(function () {
    "use strict";

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
    var QUOTATION_STATUS_LABELS = {
        pending: "Pending",
        draft: "Draft",
        sent: "Sent",
        negotiating: "Negotiating",
        price_review_requested: "Price Review Requested",
        accepted: "Accepted",
        rejected: "Rejected",
        expired: "Expired",
        disregarded: "Disregarded",
    };
    function quotationStatusLabel(status) {
        if (!status) return "";
        if (QUOTATION_STATUS_LABELS[status])
            return QUOTATION_STATUS_LABELS[status];
        return String(status)
            .split("_")
            .filter(Boolean)
            .map(function (w) {
                return w.charAt(0).toUpperCase() + w.slice(1);
            })
            .join(" ");
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
        if (state && state.isMockPreview) {
            return Promise.resolve({
                ok: false,
                status: 0,
                data: { message: "Mock preview — actions are disabled." },
            });
        }
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
    var isDrawerBusy = false;

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
            isMockPreview: d.mockPreview === "1",
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
            discount: 0,
            vatExclusiveTotal: parseFloat(d.vatExclusiveTotal || "0") || 0,
            vatAmount: parseFloat(d.vatAmount || "0") || 0,
            vatRate: parseFloat(d.vatRate || "0.12") || 0.12,
            truckType: d.truckType || "",
            truckTypeId: parseInt(d.truckTypeId || "0", 10) || 0,
            vehicleCategory: d.vehicleCategory || "",
            photos: photos,
            dispatchZone: d.dispatchZone || "General Dispatch Zone",
            recommendedUnitId: d.recommendedUnit || "",
            quotationId: d.quotationId || "",
            quotationNumber: d.quotationNumber || "",
            quotationStatus: d.quotationStatus || "",
            quotationCreatedAt: "",
            sentAt: "",
            quotationVersion: 0,
            rescheduleEvents: [],
            adjustments: [],
            priceAdjustments: [],
            selectedUnitId: d.selectedUnit || null,
            originalSelectedUnitId: d.selectedUnit || null,
            adjFormOpen: false,
            historyOpen: false,
            isScheduled:
                d.status === "scheduled" || d.status === "scheduled_confirmed",
            schedulingBucket: d.schedulingBucket || "",
            scheduledFor: d.scheduledFor || "",

            priceChangeLog: priceChangeLog,
            groupVehicles: [],
        };
    }

    function effectiveStatus(s) {
        if (s.quotationStatus === "draft") return "draft";
        if (s.quotationStatus === "sent") return "sent";
        if (s.quotationStatus === "negotiating") return "negotiating";
        if (s.quotationStatus === "price_review_requested")
            return "price_review_requested";
        if (s.quotationStatus === "expired") return "expired";
        if (s.status === "scheduled_confirmed")
            return s.schedulingBucket || "confirmed";
        if (s.status === "confirmed") return "confirmed";
        return "new";
    }

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
    function vatPercentLabel(rate) {
        var pct = Math.round((rate || 0.12) * 10000) / 100;
        return (pct % 1 === 0 ? pct.toFixed(0) : pct.toFixed(2)) + "%";
    }
    function baseSubtotal(s) {
        if (effectiveStatus(s) === "new") return s.baseRate;
        if (s.vatExclusiveTotal > 0) return s.vatExclusiveTotal;
        return (
            (s.currentPrice - (s.additionalFee || 0)) /
            (1 + (s.vatRate || 0.12))
        );
    }
    function distanceFeeFor(s) {
        if (effectiveStatus(s) !== "new") return 0;
        var km = parseFloat(s.distanceKm) || 0;
        return km > 0 ? Math.max(0, km - 4) * (s.perKmRate || 0) : 0;
    }
    function adjustedTotal(s) {
        if (s.groupVehicles && s.groupVehicles.length > 1) {
            var groupBaseTotal = s.groupVehicles.reduce(function (
                sum,
                vehicle,
            ) {
                return sum + (parseFloat(vehicle.final_total) || 0);
            }, 0);
            var groupVat = s.groupVehicles.reduce(function (sum, vehicle) {
                return sum + (parseFloat(vehicle.vat_amount) || 0);
            }, 0);
            var groupSubtotal = s.groupVehicles.reduce(function (sum, vehicle) {
                var vehicleVat = parseFloat(vehicle.vat_amount) || 0;
                var vehicleRate =
                    parseFloat(vehicle.vat_rate) || s.vatRate || 0.12;
                return sum + (vehicleRate > 0 ? vehicleVat / vehicleRate : 0);
            }, 0);
            var groupTotal = (s.currentPrice || 0) + netAdjustment(s);
            var groupAdjustment = groupTotal - groupBaseTotal;
            return {
                subtotal: groupSubtotal,
                vat: groupVat,
                baseTotal: groupBaseTotal,
                adjustment: groupAdjustment,
                total: groupTotal,
            };
        }
        var subtotal = baseSubtotal(s) + distanceFeeFor(s);
        var vat =
            s.vatAmount > 0 && effectiveStatus(s) !== "new"
                ? s.vatAmount
                : subtotal * (s.vatRate || 0.12);
        var baseTotal = subtotal + vat;
        var adjustment = (s.additionalFee || 0) + netAdjustment(s);
        return {
            subtotal: subtotal,
            vat: vat,
            baseTotal: baseTotal,
            adjustment: adjustment,
            total: baseTotal + adjustment,
        };
    }

    var drawerEl = document.getElementById("rbDrawer");
    var drawerOverlayEl = document.getElementById("rbDrawerOverlay");

    function fetchQuotationDetails(quotationId) {
        if (state && state.isMockPreview) {
            return Promise.resolve(null);
        }
        var url = fillRoute(window.RB_ROUTES.quoteDetails, quotationId);
        return fetch(url, { headers: { Accept: "application/json" } })
            .then(function (res) {
                return res.json();
            })
            .then(function (payload) {
                return payload && payload.quotation;
            });
    }

    function mergeQuotationDetailsIntoState(data) {
        if (!data) return;
        if (Array.isArray(data.group_vehicles)) {
            state.groupVehicles = data.group_vehicles.map(function (vehicle) {
                return Object.assign({}, vehicle, {
                    selected_unit_id:
                        vehicle.selected_unit_id ||
                        vehicle.assigned_unit_id ||
                        null,
                    assignment_busy: false,
                });
            });
        }
        if (typeof data.estimated_price !== "undefined") {
            state.currentPrice =
                parseFloat(data.estimated_price) || state.currentPrice;
        }
        if (
            typeof data.additional_fee !== "undefined" &&
            data.additional_fee !== null
        ) {
            state.additionalFee = parseFloat(data.additional_fee) || 0;
        }
        if (typeof data.discount !== "undefined" && data.discount !== null) {
            state.discount = parseFloat(data.discount) || 0;
        }
        if (typeof data.subtotal !== "undefined" && data.subtotal !== null) {
            state.vatExclusiveTotal =
                parseFloat(data.subtotal) || state.vatExclusiveTotal;
        }
        if (
            typeof data.vat_amount !== "undefined" &&
            data.vat_amount !== null
        ) {
            state.vatAmount = parseFloat(data.vat_amount) || state.vatAmount;
        }
        if (typeof data.vat_rate !== "undefined" && data.vat_rate !== null) {
            state.vatRate = parseFloat(data.vat_rate) || state.vatRate;
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
        if (data.price_adjustments && Array.isArray(data.price_adjustments)) {
            state.priceAdjustments = data.price_adjustments;
        }
        if (data.status) {
            state.quotationStatus = data.status;
        }
        if (data.quotation_number) {
            state.quotationNumber = data.quotation_number;
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
        state.reviewReason = data.response_note || null;
    }

    window.openBookingDrawer = function (cardEl) {
        if (!cardEl) return;
        state = buildStateFromCard(cardEl);

        drawerOverlayEl.classList.add("is-open");
        drawerEl.classList.add("is-open");
        renderDrawer();

        if (state.quotationId) {
            var openedState = state;
            fetchQuotationDetails(state.quotationId)
                .then(function (data) {
                    if (state !== openedState) return; // drawer moved on to a different booking meanwhile
                    mergeQuotationDetailsIntoState(data);
                    renderDrawer();
                })
                .catch(function () {});
        }
    };

    async function closeBookingDrawer() {
        if (state && hasStagedDraftChanges(state)) {
            var message = state.adjustments.length
                ? "You have unsaved price adjustments that haven't been sent yet. Close without saving?"
                : "You have an unsaved unit selection change. Close without saving?";
            var ok = await rbConfirm(message, {
                okLabel: "Close without saving",
            });
            if (!ok) return;
        }
        drawerEl.classList.remove("is-open");
        drawerOverlayEl.classList.remove("is-open");
        setTimeout(function () {
            drawerEl.innerHTML = "";
        }, 220);
        state = null;
    }
    drawerOverlayEl.addEventListener("click", function () {
        if (isDrawerBusy) return;
        closeBookingDrawer();
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            closeLightbox();
            closeUnitsModalIfOpen();
            if (drawerEl.classList.contains("is-open")) closeBookingDrawer();
        }
    });

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
        return (
            '<div class="rb-section"><h4>Vehicle</h4>' +
            photoStackHtml(s) +
            "</div>"
        );
    }

    function photoFallbackHtml(text, visible) {
        return (
            '<span class="rb-photo-fallback-text"' +
            (visible ? "" : ' style="display:none;"') +
            ">" +
            esc(text) +
            "</span>"
        );
    }

    function photoStackHtml(s) {
        var n = s.photos.length;
        if (!n) {
            return (
                '<div class="rb-photo-stack-wrap">' +
                '<div class="rb-photo-box rb-photo-empty">' +
                photoFallbackHtml("No vehicle photo provided", true) +
                "</div></div>"
            );
        }
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
        var firstUrl = s.photos[0];
        var imgHtml = firstUrl
            ? '<img src="' +
              esc(firstUrl) +
              '" alt="Vehicle photo" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'block\';">' +
              photoFallbackHtml("Unable to load photo", false)
            : photoFallbackHtml("No vehicle photo provided", true);
        return (
            '<div class="rb-photo-stack-wrap">' +
            back +
            '<div class="rb-photo-box" id="rbPhotoStackTrigger">' +
            imgHtml +
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
        var label =
            mins < 60
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
            (sub ? "<div><dt>Status</dt><dd>" + esc(sub) + "</dd></div>" : "") +
            "</div></div>"
        );
    }

    function routeSectionHtml(s) {
        var noteHtml = s.pickupNotes
            ? ' <span style="color:#8A93A3;">- ' +
              esc(s.pickupNotes) +
              "</span>"
            : "";
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
        var grouped = s.groupVehicles && s.groupVehicles.length > 1;
        var eff = effectiveStatus(s);
        var editable = eff === "new" || eff === "draft";
        var breakdown;
        var fixedTotal;
        var accepted;
        var adj = adjustedTotal(s);

        if (grouped) {
            var groupRows = s.groupVehicles
                .map(function (vehicle, index) {
                    return (
                        '<div class="rb-breakdown" style="margin-bottom:12px;">' +
                        '<div class="rb-sub-label">Vehicle ' +
                        (index + 1) +
                        " — " +
                        esc(
                            vehicle.vehicle_name ||
                                vehicle.truck_type_name ||
                                "Vehicle",
                        ) +
                        "</div>" +
                        '<div class="rb-b-row"><span>Truck type</span><span>' +
                        esc(vehicle.truck_type_name || "—") +
                        "</span></div>" +
                        '<div class="rb-b-row"><span>Base rate</span><span class="rb-mono">' +
                        peso(vehicle.base_rate) +
                        "</span></div>" +
                        '<div class="rb-b-row"><span>Distance fee</span><span class="rb-mono">' +
                        peso(vehicle.distance_fee) +
                        "</span></div>" +
                        '<div class="rb-b-row"><span>VAT (' +
                        vatPercentLabel(vehicle.vat_rate || s.vatRate) +
                        ')</span><span class="rb-mono">' +
                        peso(vehicle.vat_amount) +
                        "</span></div>" +
                        '<div class="rb-b-row rb-b-final"><span>Vehicle service total</span><span class="rb-mono">' +
                        peso(vehicle.final_total) +
                        "</span></div>" +
                        '<div class="rb-b-row"><span>Assigned unit</span><span>' +
                        esc(
                            vehicle.assigned_unit_id ||
                                vehicle.selected_unit_id ||
                                "Not assigned",
                        ) +
                        "</span></div>" +
                        "</div>"
                    );
                })
                .join("");
            var groupTotal = s.groupVehicles.reduce(function (sum, vehicle) {
                return sum + (parseFloat(vehicle.final_total) || 0);
            }, 0);
            fixedTotal = groupTotal;
            accepted =
                eff === "confirmed" ||
                eff === "upcoming" ||
                eff === "ready" ||
                eff === "overdue";
            breakdown = groupRows;
        } else {
            fixedTotal = adj.baseTotal;
            accepted =
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

            var breakdownDistFeeKm = 0;
            var breakdownDistFee = (function () {
                var km = parseFloat(s.distanceKm) || 0;
                breakdownDistFeeKm = Math.max(0, km - 4);
                return km > 0 ? breakdownDistFeeKm * (s.perKmRate || 0) : 0;
            })();
            var additionalFee = editable ? 0 : s.additionalFee || 0;
            var breakdownTotal = editable ? fixedTotal : s.currentPrice;
            var breakdownVat = editable
                ? adj.vat
                : s.vatAmount > 0
                  ? s.vatAmount
                  : breakdownTotal - breakdownTotal / (1 + (s.vatRate || 0.12));

            breakdown =
                '<div class="rb-breakdown">' +
                '<div class="rb-b-row"><span>Base rate (' +
                esc(s.truckType || "Truck class") +
                ')</span><span class="rb-mono">' +
                peso(s.baseRate) +
                "</span></div>" +
                '<div class="rb-b-row"><span>Distance fee' +
                (s.distanceKm
                    ? " (" +
                      breakdownDistFeeKm.toFixed(2) +
                      " km after first 4 km)"
                    : "") +
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
                '<div class="rb-b-row"><span>VAT (' +
                vatPercentLabel(s.vatRate) +
                ')</span><span class="rb-mono">' +
                peso(breakdownVat) +
                "</span></div>" +
                '<div class="rb-b-row rb-b-final"><span>' +
                fixedLabel +
                ' (incl. VAT)</span><span class="rb-mono">' +
                peso(breakdownTotal) +
                "</span></div>" +
                "</div>";
        }

        var adjustedHtml = "";
        if (grouped) {
            adjustedHtml =
                '<div class="rb-breakdown">' +
                '<div class="rb-b-row"><span>Base total (incl. VAT)</span><span class="rb-mono">' +
                peso(fixedTotal) +
                "</span></div>" +
                (adj.adjustment !== 0
                    ? '<div class="rb-b-row rb-b-adj"><span>Quotation adjustment</span><span class="rb-mono ' +
                      (adj.adjustment > 0 ? "rb-is-add" : "rb-is-deduct") +
                      '">' +
                      (adj.adjustment > 0 ? "+" : "") +
                      peso(adj.adjustment) +
                      "</span></div>"
                    : "") +
                '<div class="rb-b-row rb-b-final"><span>Final quoted total</span><span class="rb-mono">' +
                peso(adj.total) +
                "</span></div></div>";
        }
        var displayAdjustment = editable ? adj.adjustment : netAdjustment(s);
        if (!grouped && displayAdjustment !== 0) {
            var adjustedFinalLabel = editable
                ? "Final total"
                : "Actual agreed total (incl. VAT)";
            var adjustedFinalAmount = editable
                ? adj.total
                : fixedTotal + displayAdjustment;
            adjustedHtml =
                '<div class="rb-breakdown">' +
                '<div class="rb-b-row"><span>Base total (incl. VAT)</span><span class="rb-mono">' +
                peso(fixedTotal) +
                "</span></div>" +
                '<div class="rb-b-row rb-b-adj"><span>Adjustments</span><span class="rb-mono ' +
                (displayAdjustment > 0 ? "rb-is-add" : "rb-is-deduct") +
                '">' +
                (displayAdjustment > 0 ? "+" : "") +
                peso(displayAdjustment) +
                "</span></div>" +
                '<div class="rb-b-row rb-b-final"><span>' +
                adjustedFinalLabel +
                '</span><span class="rb-mono">' +
                peso(adjustedFinalAmount) +
                "</span></div>" +
                "</div>";
        }

        var lockedQuotationStatuses = {
            accepted: 1,
            rejected: 1,
            expired: 1,
            disregarded: 1,
            cancelled: 1,
        };
        var isQuotationLocked = !!lockedQuotationStatuses[s.quotationStatus];
        var adjustmentCount = s.priceAdjustments.length + s.adjustments.length;

        var rows =
            '<div class="rb-adj-row"><span class="rb-adj-reason">Initial calculated price \u2014 ' +
            peso(adj.baseTotal) +
            '</span><span class="rb-adj-time">' +
            esc(timeAgoLabel(s.quotationCreatedAt || s.createdAt) || "") +
            "</span></div>";

        rows += s.priceAdjustments
            .map(function (a) {
                var isAdd = a.type === "add";
                var isActive = a.status === "active";
                var addedRow =
                    '<div class="rb-adj-row"><span class="rb-adj-sign ' +
                    (isAdd ? "rb-is-add" : "rb-is-deduct") +
                    '">' +
                    (isAdd ? "+" : "\u2212") +
                    peso(a.amount) +
                    '</span><span class="rb-adj-reason">' +
                    esc(
                        a.reason ||
                            (isAdd
                                ? "Adjustment added"
                                : "Adjustment deducted"),
                    ) +
                    (isActive && !isQuotationLocked
                        ? ' <button type="button" class="rb-adj-undo-btn" data-undo-adjustment="' +
                          a.id +
                          '">Undo</button>'
                        : !isActive
                          ? ' <span class="rb-adj-reverted-badge">Reverted</span>'
                          : "") +
                    '</span><span class="rb-adj-time">' +
                    esc(timeAgoLabel(a.created_at) || "") +
                    "</span></div>";

                var revertedRow = !isActive
                    ? '<div class="rb-adj-row"><span class="rb-adj-reason">Adjustment reverted \u2014 ' +
                      (isAdd ? "+" : "\u2212") +
                      peso(a.amount) +
                      (a.reason ? " (" + esc(a.reason) + ")" : "") +
                      '</span><span class="rb-adj-time">' +
                      esc(timeAgoLabel(a.reverted_at) || "") +
                      "</span></div>"
                    : "";

                return addedRow + revertedRow;
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

        var historyLabel =
            "Price history" +
            (adjustmentCount > 0
                ? " (" +
                  adjustmentCount +
                  " adjustment" +
                  (adjustmentCount === 1 ? "" : "s") +
                  ")"
                : "");
        var historyHtml =
            '<button type="button" class="rb-btn rb-btn-secondary rb-history-toggle-btn" id="rbHistoryToggleBtn">' +
            '<span class="rb-history-btn-label">' +
            icon("fileText") +
            " " +
            historyLabel +
            "</span>" +
            '<span class="rb-chevron' +
            (s.historyOpen ? " rb-is-open" : "") +
            '" id="rbHistoryChevron">' +
            icon("chevronDown") +
            "</span></button>" +
            '<div class="rb-history" id="rbHistoryList"' +
            (s.historyOpen ? "" : ' style="display:none;"') +
            ">" +
            (rows ||
                '<div class="rb-adj-empty">No price adjustments yet.</div>') +
            "</div>";

        var adjFormHtml = "";
        if (editable) {
            adjFormHtml =
                '<div class="rb-sub-label">New adjustment</div>' +
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
                " Add price adjustment</button>";
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
            '<div class="rb-section"><h4>Pricing</h4>' +
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
        var groupAccepted =
            s.quotationStatus === "accepted" &&
            (s.status === "confirmed" || s.status === "scheduled_confirmed");
        if (!groupAccepted) {
            return '<div class="rb-section"><h4>Assignment</h4><div class="rb-note-box">Unit assignment available after customer acceptance.</div></div>';
        }
        if (s.groupVehicles && s.groupVehicles.length > 1) {
            if (
                s.isScheduled &&
                effectiveStatus(s) !== "ready" &&
                effectiveStatus(s) !== "overdue"
            ) {
                return "";
            }
            return (
                '<div class="rb-section"><h4>Assign vehicles</h4><div class="rb-group-vehicle-list">' +
                s.groupVehicles
                    .map(function (vehicle, index) {
                        return (
                            '<div class="rb-group-vehicle" data-group-vehicle="' +
                            vehicle.booking_id +
                            '">' +
                            '<div class="rb-group-vehicle-head">' +
                            '<div class="rb-group-vehicle-title">Vehicle ' +
                            (index + 1) +
                            " — " +
                            esc(vehicle.vehicle_name || "Vehicle") +
                            "</div>" +
                            '<div class="rb-group-vehicle-meta">' +
                            esc(
                                vehicle.truck_type_name ||
                                    "Required truck type",
                            ) +
                            " · " +
                            peso(vehicle.final_total) +
                            " · " +
                            esc(vehicle.status || "") +
                            "</div>" +
                            "</div>" +
                            '<div class="rb-search-wrap"><span class="rb-search-ic">' +
                            icon("search", 15) +
                            '</span><input type="text" data-group-search="' +
                            vehicle.booking_id +
                            '" placeholder="Search compatible unit or team leader"></div>' +
                            '<div class="rb-unit-option-list" data-group-unit-list="' +
                            vehicle.booking_id +
                            '" style="display:flex;flex-direction:column;gap:10px;"></div>' +
                            '<button type="button" class="rb-btn rb-btn-primary rb-group-vehicle-assign" data-group-dispatch="' +
                            vehicle.booking_id +
                            '"' +
                            (vehicle.selected_unit_id ? "" : " disabled") +
                            ">" +
                            (vehicle.assigned_unit_id
                                ? "Update assignment"
                                : "Assign vehicle") +
                            "</button></div>"
                        );
                    })
                    .join("") +
                "</div></div>"
            );
        }
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

        if (s.quotationId && s.quotationCreatedAt) {
            items.push({
                labelHtml: esc("Initial quotation generated"),
                noteHtml: "",
                time: timeAgoLabel(s.quotationCreatedAt),
                at: s.quotationCreatedAt,
                accept: false,
            });
        }
        function sentVersionLabel(version) {
            return parseInt(version, 10) <= 1
                ? "Quotation sent"
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
            if (h.type === "price_review_requested") {
                items.push({
                    labelHtml: "Customer requested price review",
                    noteHtml: h.reason ? esc(h.reason) : "",
                    time: timeAgoLabel(h.at) || "",
                    at: h.at,
                    accept: false,
                });
            } else if (h.type === "price_review_kept") {
                items.push({
                    labelHtml:
                        "Price review completed \u2014 amount unchanged (" +
                        peso(h.new) +
                        ")",
                    noteHtml: h.reason ? esc(h.reason) : "",
                    time: timeAgoLabel(h.at) || "",
                    at: h.at,
                    accept: false,
                });
            }
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
                        ? esc(
                              ev.old_scheduled_for +
                                  " → " +
                                  ev.new_scheduled_for,
                          )
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
        if (s.quotationStatus === "accepted") {
            rows +=
                '<div class="rb-t-item rb-is-accept"><span class="rb-t-icon">' +
                icon("check", 14) +
                '</span><div><div class="rb-t-text">Customer accepted</div>' +
                '<div class="rb-t-time">—</div></div></div>';
            rows +=
                '<div class="rb-t-item rb-is-accept"><span class="rb-t-icon">' +
                icon("check", 14) +
                '</span><div><div class="rb-t-text">Dispatcher confirmed</div>' +
                '<div class="rb-t-time">—</div></div></div>';
        }
        return (
            '<div class="rb-section"><h4>Quote history</h4><div class="rb-timeline">' +
            rows +
            "</div></div>"
        );
    }

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
                " Cancel quotation</button>";
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
        if (
            s.groupVehicles &&
            s.groupVehicles.length > 1 &&
            ["confirmed", "upcoming", "ready", "overdue"].indexOf(eff) !== -1
        ) {
            main = "";
        }

        var expiredChip =
            eff === "expired"
                ? '<div class="rb-expired-chip">' +
                  icon("clock", 14) +
                  "<span>Quote expired</span></div>"
                : "";

        var showRejectLink = s.isScheduled
            ? eff === "overdue"
            : eff !== "confirmed";
        var rejectLabel = s.isScheduled
            ? "Cancel Booking"
            : "Reject this booking";
        var rejectHtml = showRejectLink
            ? '<div class="rb-drawer-foot-reject"><button type="button" class="rb-link-btn" id="rbRejectBtn" style="color:#D8402C;">' +
              icon("trash", 14) +
              " " +
              rejectLabel +
              "</button></div>"
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

    function quotationIdentityHtml(s) {
        var numberText = s.quotationNumber
            ? esc(s.quotationNumber)
            : "No quotation yet";
        var bookingText = s.bookingCode ? esc(s.bookingCode) : "—";
        var statusText = s.quotationNumber
            ? quotationStatusLabel(s.quotationStatus)
            : "";
        return (
            '<div class="rb-quote-label">Quotation</div>' +
            '<div class="rb-quote-id rb-mono">' +
            numberText +
            "</div>" +
            '<div class="rb-quote-row">' +
            '<span class="rb-quote-booking rb-mono">Booking: ' +
            bookingText +
            "</span>" +
            (statusText
                ? '<span class="rb-status-badge">' + esc(statusText) + "</span>"
                : "") +
            "</div>"
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

        var prevBody = drawerEl.querySelector(".rb-drawer-body");
        var prevScrollTop = prevBody ? prevBody.scrollTop : 0;

        drawerEl.innerHTML =
            '<div class="rb-drawer-head">' +
            '<div class="rb-who"><div class="rb-avatar">' +
            esc(initials) +
            "</div><div>" +
            quotationIdentityHtml(s) +
            "<h3>" +
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
        if (s.groupVehicles && s.groupVehicles.length > 1) {
            renderGroupUnitLists();
        }

        var newBody = drawerEl.querySelector(".rb-drawer-body");
        if (newBody) newBody.scrollTop = prevScrollTop;

        if (s.isMockPreview) {
            setBusy(true);
        }
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
                byId("rbHistoryChevron", function (c) {
                    c.classList.toggle("rb-is-open", s.historyOpen);
                });
            };
        });

        drawerEl
            .querySelectorAll("[data-undo-adjustment]")
            .forEach(function (btn) {
                btn.onclick = function () {
                    submitUndoAdjustment(btn.dataset.undoAdjustment);
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
        document.querySelectorAll("[data-group-search]").forEach(function (el) {
            el.oninput = function () {
                var vehicle = (s.groupVehicles || []).find(function (item) {
                    return (
                        String(item.booking_id) ===
                        String(el.dataset.groupSearch)
                    );
                });
                if (vehicle) renderGroupUnitList(vehicle, el.value);
            };
        });
        document
            .querySelectorAll("[data-group-dispatch]")
            .forEach(function (el) {
                el.onclick = function () {
                    var vehicle = (s.groupVehicles || []).find(function (item) {
                        return (
                            String(item.booking_id) ===
                            String(el.dataset.groupDispatch)
                        );
                    });
                    if (vehicle) submitGroupDispatch(vehicle);
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

    function unitsForGroupVehicle(vehicle) {
        var used = (state.groupVehicles || [])
            .filter(function (other) {
                return (
                    String(other.booking_id) !== String(vehicle.booking_id) &&
                    other.selected_unit_id
                );
            })
            .map(function (other) {
                return String(other.selected_unit_id);
            });
        return AVAILABLE_UNITS.filter(function (unit) {
            if (Number(unit.truck_type_id) !== Number(vehicle.truck_type_id))
                return false;
            if (used.indexOf(String(unit.id)) !== -1) return false;
            if (
                unit.reserved_by_booking_code &&
                String(unit.reserved_by_booking_code) !==
                    String(vehicle.booking_code)
            )
                return false;
            return true;
        });
    }

    function renderGroupUnitList(vehicle, query) {
        var listEl = document.querySelector(
            '[data-group-unit-list="' + vehicle.booking_id + '"]',
        );
        if (!listEl) return;
        var q = (query || "").trim().toLowerCase();
        var filtered = unitsForGroupVehicle(vehicle).filter(function (unit) {
            return (
                (unit.label || "").toLowerCase().indexOf(q) > -1 ||
                (unit.team_leader_name || "").toLowerCase().indexOf(q) > -1
            );
        });
        if (!filtered.length) {
            listEl.innerHTML =
                '<div class="rb-unit-empty-note">' +
                icon("mapPin", 24) +
                "<span>No compatible units ready right now.</span></div>";
            return;
        }
        listEl.innerHTML = filtered
            .map(function (unit) {
                return unitCardHtml(
                    unit,
                    false,
                    String(unit.id) === String(vehicle.selected_unit_id),
                );
            })
            .join("");
        listEl
            .querySelectorAll("[data-assign-unit]")
            .forEach(function (button) {
                button.addEventListener("click", function () {
                    vehicle.selected_unit_id =
                        String(vehicle.selected_unit_id) ===
                        String(button.dataset.assignUnit)
                            ? null
                            : button.dataset.assignUnit;
                    renderGroupUnitLists();
                });
            });
    }

    function submitGroupDispatch(vehicle) {
        if (!vehicle.selected_unit_id || vehicle.assignment_busy) return;
        vehicle.assignment_busy = true;
        renderGroupUnitLists();
        apiCall(
            fillRoute(window.RB_ROUTES.assign, vehicle.booking_code),
            "POST",
            {
                action: "accept",
                assigned_unit_id: vehicle.selected_unit_id,
            },
        )
            .then(function (res) {
                vehicle.assignment_busy = false;
                if (!res.ok) {
                    showDrawerNetworkError(res.data && res.data.message);
                    renderGroupUnitLists();
                    return;
                }
                vehicle.assigned_unit_id = vehicle.selected_unit_id;
                vehicle.status = res.data.status || vehicle.status;
                AVAILABLE_UNITS.forEach(function (unit) {
                    if (String(unit.id) === String(vehicle.selected_unit_id)) {
                        unit.reserved_by_booking_code = vehicle.booking_code;
                    }
                });
                renderDrawer();
            })
            .catch(function () {
                vehicle.assignment_busy = false;
                showDrawerNetworkError();
                renderGroupUnitLists();
            });
    }

    function renderGroupUnitLists() {
        (state.groupVehicles || []).forEach(function (vehicle) {
            var search = document.querySelector(
                '[data-group-search="' + vehicle.booking_id + '"]',
            );
            renderGroupUnitList(vehicle, search ? search.value : "");
            var dispatch = document.querySelector(
                '[data-group-dispatch="' + vehicle.booking_id + '"]',
            );
            if (dispatch) {
                dispatch.disabled =
                    !vehicle.selected_unit_id || vehicle.assignment_busy;
            }
        });
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

                var saveBtn = document.getElementById("rbSaveDraftBtn");
                if (saveBtn && effectiveStatus(s) === "draft") {
                    saveBtn.disabled = !hasStagedDraftChanges(s);
                }

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
            ? '<img src="' +
              esc(url) +
              '" alt="Vehicle photo" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'block\';">' +
              photoFallbackHtml("Unable to load photo", false)
            : photoFallbackHtml("No vehicle photo provided", true);
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

    function setBusy(disabled) {
        if (state && state.isMockPreview) {
            disabled = true;
        }
        isDrawerBusy = disabled;
        drawerEl.querySelectorAll(".rb-btn").forEach(function (b) {
            b.disabled = disabled;
        });

        if (!disabled && state && !hasStagedDraftChanges(state)) {
            var saveBtn = document.getElementById("rbSaveDraftBtn");
            if (saveBtn) saveBtn.disabled = true;
        }

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
        if (state && state.bookingCode) {
            try {
                sessionStorage.setItem(
                    "rbReopenBookingCode",
                    state.bookingCode,
                );
                var activeTabBtn = document.querySelector(
                    ".queue-filter-btn.is-active",
                );
                var activeFilter = activeTabBtn && activeTabBtn.dataset.filter;
                if (activeFilter) {
                    sessionStorage.setItem("rbReopenQueueFilter", activeFilter);
                }
            } catch (e) {
                /* ignore */
            }
        }
        window.location.reload();
    }

    function draftSavePayload(s) {
        var adj = adjustedTotal(s);
        return {
            price: Number(adj.total.toFixed(2)),
            additional_fee: Number(netAdjustment(s).toFixed(2)),
            adjustments: s.adjustments.map(function (a) {
                return {
                    type: a.amount >= 0 ? "add" : "deduct",
                    amount: Number(Math.abs(a.amount).toFixed(2)),
                    reason: a.reason,
                };
            }),
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
        var cards = document.querySelectorAll(
            ".incoming-card[data-booking-code], .jobs-row[data-booking-code]",
        );
        for (var i = 0; i < cards.length; i++) {
            if (cards[i].dataset.bookingCode === bookingCode) return cards[i];
        }
        return null;
    }

    function updateCardDatasetFromState(s) {
        var card = findCardByBookingCode(s.bookingCode);
        if (!card) return;
        card.dataset.quotationId = s.quotationId || "";
        card.dataset.quotationStatus = s.quotationStatus || "";
        card.dataset.currentPrice = s.currentPrice || 0;
        card.dataset.priceChangeLog = JSON.stringify(s.priceChangeLog || []);
        card.dataset.selectedUnit = s.selectedUnitId || "";
    }

    function refreshDrawerFromServer(newQuotationId) {
        var s = state;
        if (newQuotationId) s.quotationId = newQuotationId;
        if (!s.quotationId) return Promise.resolve();
        return fetchQuotationDetails(s.quotationId).then(function (fresh) {
            if (state !== s) return;
            mergeQuotationDetailsIntoState(fresh);
            renderDrawer();
            updateCardDatasetFromState(s);
        });
    }

    function applySaveDraftSuccess(data, committedPrice) {
        var s = state;
        s.currentPrice = committedPrice;
        s.adjustments = [];
        s.originalSelectedUnitId = s.selectedUnitId;
        if (data && data.quotation_id) s.quotationId = data.quotation_id;
        if (data && data.quotation_status)
            s.quotationStatus = data.quotation_status;
        updateAvailableUnitsReservation(s.bookingCode, s.selectedUnitId);
        renderDrawer();
        updateCardDatasetFromState(s);

        refreshDrawerFromServer().catch(function () {});
    }

    function submitSaveDraft() {
        var s = state;

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

    function applySendSuccess(committedPrice, newQuotationId) {
        var s = state;
        s.currentPrice = committedPrice;
        s.adjustments = [];
        s.originalSelectedUnitId = s.selectedUnitId;
        s.quotationStatus = "sent";
        updateAvailableUnitsReservation(s.bookingCode, s.selectedUnitId);
        renderDrawer();
        updateCardDatasetFromState(s);

        refreshDrawerFromServer(newQuotationId).catch(function () {});
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
                    if (!res) return;
                    if (!res.ok) {
                        showDrawerNetworkError(res.data && res.data.message);
                        return;
                    }
                    applySendSuccess(
                        payload.price,
                        res.data && res.data.quotation_id,
                    );
                })
                .catch(function () {
                    setBusy(false);
                    showDrawerNetworkError();
                });
        } else {
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
                    applySendSuccess(
                        s.currentPrice,
                        res.data && res.data.quotation_id,
                    );
                })
                .catch(function () {
                    setBusy(false);
                    showDrawerNetworkError();
                });
        }
    }

    async function submitCancelQuote() {
        var ok = await rbConfirm(
            "Cancel this quotation? The customer will no longer be able to accept it.",
            {
                title: "Cancel quotation",
                okLabel: "Cancel quotation",
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
                refreshDrawerFromServer(
                    res.data && res.data.quotation_id,
                ).catch(function () {});
            })
            .catch(function () {
                setBusy(false);
                showDrawerNetworkError();
            });
    }

    function submitUndoAdjustment(adjustmentId) {
        var s = state;
        rbConfirm(
            "Undo adjustment? This will remove the adjustment from the current quotation. The action will remain recorded in Price History.",
            { title: "Undo adjustment", okLabel: "Confirm Undo" },
        ).then(function (ok) {
            if (!ok) return;
            setBusy(true);
            apiCall(
                fillRoute(
                    window.RB_ROUTES.quoteUndoAdjustment,
                    s.quotationId,
                ).replace(":adjustment", adjustmentId),
                "POST",
                {},
            )
                .then(function (res) {
                    setBusy(false);
                    if (!res.ok) {
                        showDrawerNetworkError(res.data && res.data.message);
                        return;
                    }
                    refreshDrawerFromServer(
                        res.data && res.data.quotation_id,
                    ).catch(function () {});
                })
                .catch(function () {
                    setBusy(false);
                    showDrawerNetworkError();
                });
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
                refreshDrawerFromServer(
                    res.data && res.data.quotation_id,
                ).catch(function () {});
            })
            .catch(function () {
                setBusy(false);
                showDrawerNetworkError();
            });
    }

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
                refreshDrawerFromServer(
                    res.data && res.data.quotation_id,
                ).catch(function () {});
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
                    errEl.textContent =
                        "A reason is required to cancel this booking.";
                    errEl.style.display = "";
                    return;
                }
                closeRbModalWith(reason);
            };
        });
    }

    async function promptRejectReason() {
        var isSchedCancel =
            state && state.isScheduled && effectiveStatus(state) === "overdue";
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
            rejection_reason:
                reason ||
                (isSchedCancel
                    ? "Cancelled by dispatcher."
                    : "Rejected by dispatcher."),
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
                    errEl.textContent =
                        "A reason is required when rescheduling an overdue booking.";
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
            apiCall(
                fillRoute(window.RB_ROUTES.reschedule, s.bookingCode),
                "POST",
                {
                    new_scheduled_date: result.date,
                    new_scheduled_time: result.time,
                    reason: result.reason || null,
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
        });
    }

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

    (function openMockDrawerPreview() {
        if (window.location.search.indexOf("mockDrawer=1") === -1) return;

        var MOCK_BOOKINGS = [
            {
                label: "Book Now #1",
                id: "TM-0073",
                bookingCode: "TM-0073",
                status: "requested",
                customerName: "Juan Dela Cruz",
                customerPhone: "09123456789",
                customerEmail: "juan@example.com",
                pickup: "123 Rizal Street, Makati City",
                dropoff: "456 Bonifacio Avenue, Taguig City",
                distanceKm: "8.5",
                currentPrice: "1500",
                baseRate: "800",
                perKmRate: "75",
                truckType: "Light Duty",
                vehicleCategory: "4_wheeler",
                dispatchZone: "Metro Manila Zone A",
                quotationNumber: "QT-20260920-0001",
                quotationStatus: "pending",
            },
            {
                label: "Book Now #2",
                id: "TM-0081",
                bookingCode: "TM-0081",
                status: "requested",
                customerName: "Maria Santos",
                customerPhone: "09187654321",
                customerEmail: "maria@example.com",
                pickup: "789 Shaw Boulevard, Mandaluyong City",
                dropoff: "321 Ortigas Avenue, Pasig City",
                distanceKm: "5.2",
                currentPrice: "1200",
                baseRate: "800",
                perKmRate: "75",
                truckType: "Medium Duty",
                vehicleCategory: "4_wheeler",
                dispatchZone: "Metro Manila Zone B",
                quotationNumber: "QT-20260920-0005",
                quotationStatus: "sent",
                photos: '["/dispatcher/images/mock-broken-photo-does-not-exist.jpg"]',
            },
            {
                label: "Scheduled",
                id: "TM-0095",
                bookingCode: "TM-0095",
                status: "scheduled_confirmed",
                schedulingBucket: "upcoming",
                scheduledFor: new Date(Date.now() + 3 * 3600000).toISOString(),
                customerName: "Pedro Reyes",
                customerPhone: "09209876543",
                customerEmail: "pedro@example.com",
                pickup: "12 Katipunan Avenue, Quezon City",
                dropoff: "34 Commonwealth Avenue, Quezon City",
                distanceKm: "6.8",
                currentPrice: "1350",
                baseRate: "800",
                perKmRate: "75",
                truckType: "Light Duty",
                vehicleCategory: "4_wheeler",
                dispatchZone: "Metro Manila Zone A",
                quotationNumber: "QT-20260920-0009",
                quotationStatus: "accepted",
                photos: '["/dispatcher/images/jarz-logo.png"]',
            },
            {
                label: "Book Now #3 (Accepted)",
                id: "TM-0102",
                bookingCode: "TM-0102",
                status: "confirmed",
                customerName: "Ana Villanueva",
                customerPhone: "09171234567",
                customerEmail: "ana@example.com",
                pickup: "55 Aurora Boulevard, Quezon City",
                dropoff: "77 EDSA, Mandaluyong City",
                distanceKm: "9.4",
                currentPrice: "1600",
                baseRate: "800",
                perKmRate: "75",
                truckType: "Light Duty",
                vehicleCategory: "4_wheeler",
                dispatchZone: "Metro Manila Zone A",
                quotationNumber: "QT-20260920-0012",
                quotationStatus: "accepted",
            },
            {
                label: "Grouped (+ adjustment)",
                id: "TM-MOCKGRPA",
                bookingCode: "TM-MOCKGRPA",
                status: "requested",
                customerName: "Grouped Mock Customer A",
                customerPhone: "09000000001",
                customerEmail: "mock-group-a@example.com",
                pickup: "10 Mock Pickup Street, Quezon City",
                dropoff: "20 Mock Dropoff Avenue, Quezon City",
                distanceKm: "10",
                currentPrice: "0",
                baseRate: "1500",
                perKmRate: "60",
                truckType: "Light Duty",
                vehicleCategory: "4_wheeler",
                dispatchZone: "Metro Manila Zone A",
                quotationNumber: "QT-MOCK-GROUP-A",
                quotationStatus: "sent",
                isGroupMock: true,
                groupAdjustment: 300,
            },
            {
                label: "Grouped (− adjustment)",
                id: "TM-MOCKGRPB",
                bookingCode: "TM-MOCKGRPB",
                status: "requested",
                customerName: "Grouped Mock Customer B",
                customerPhone: "09000000002",
                customerEmail: "mock-group-b@example.com",
                pickup: "10 Mock Pickup Street, Quezon City",
                dropoff: "20 Mock Dropoff Avenue, Quezon City",
                distanceKm: "10",
                currentPrice: "0",
                baseRate: "1500",
                perKmRate: "60",
                truckType: "Light Duty",
                vehicleCategory: "4_wheeler",
                dispatchZone: "Metro Manila Zone A",
                quotationNumber: "QT-MOCK-GROUP-B",
                quotationStatus: "sent",
                isGroupMock: true,
                groupAdjustment: -200,
            },
        ];

        function buildMockCard(mock) {
            var card = document.createElement("tr");
            card.dataset.mockPreview = "1";
            card.dataset.createdAt = new Date(
                Date.now() - 10 * 60000,
            ).toISOString();
            Object.keys(mock).forEach(function (key) {
                if (key === "label") return;
                card.dataset[key] = mock[key];
            });
            return card;
        }

        function mockQueueRowHtml(mock, isScheduled) {
            var effStatus = isScheduled
                ? mock.schedulingBucket || "needs-quote"
                : "new";
            var statusText = isScheduled
                ? mock.schedulingBucket
                    ? mock.schedulingBucket.charAt(0).toUpperCase() +
                      mock.schedulingBucket.slice(1)
                    : "Needs Quote"
                : "Needs Quote";
            var statusClass =
                (isScheduled ? "sched-status-" : "bn-status-") +
                effStatus.replace(/_/g, "-");
            var whenLabel = isScheduled
                ? mock.scheduledFor
                    ? new Date(mock.scheduledFor).toLocaleString("en-PH", {
                          month: "short",
                          day: "numeric",
                          year: "numeric",
                          hour: "numeric",
                          minute: "2-digit",
                      })
                    : "Schedule pending"
                : "Just now";
            var whenSub = isScheduled ? "Starts soon" : "Requested just now";
            var attrs =
                'class="jobs-row rb-mock-row" onclick="window.openBookingDrawer(this)" tabindex="0" ' +
                'data-mock-preview="1" data-queue="' +
                (isScheduled ? "scheduled" : "book-now") +
                '" ' +
                'data-eff-status="' +
                esc(effStatus) +
                '" ' +
                (isScheduled
                    ? 'data-sched-bucket="' + esc(effStatus) + '" '
                    : "") +
                'data-created-at="' +
                new Date(Date.now() - 10 * 60000).toISOString() +
                '" ';
            Object.keys(mock).forEach(function (key) {
                if (key === "label") return;
                var attrName = key.replace(/([A-Z])/g, "-$1").toLowerCase();
                attrs += "data-" + attrName + '="' + esc(mock[key]) + '" ';
            });
            return (
                "<tr " +
                attrs +
                ">" +
                '<td><div class="jobs-cell-primary jobs-booking-code">' +
                esc(mock.bookingCode) +
                '<span class="rb-mock-badge">MOCK</span></div>' +
                '<div class="jobs-cell-secondary">' +
                esc(mock.customerName) +
                "</div></td>" +
                '<td><div class="jobs-cell-primary">' +
                esc(whenLabel) +
                "</div>" +
                '<div class="jobs-cell-secondary">' +
                esc(whenSub) +
                "</div></td>" +
                '<td><span class="jobs-status-text ' +
                statusClass +
                '">' +
                esc(statusText) +
                "</span></td>" +
                '<td class="jobs-route-cell" title="' +
                esc(mock.pickup) +
                " → " +
                esc(mock.dropoff) +
                '">' +
                '<div class="jobs-route-line">' +
                esc(mock.pickup) +
                "</div>" +
                '<div class="jobs-route-line jobs-route-line--drop">→ ' +
                esc(mock.dropoff) +
                "</div></td>" +
                '<td class="jobs-cell-secondary">' +
                esc(mock.truckType) +
                "</td>" +
                '<td class="jobs-cell-secondary">just now</td>' +
                "</tr>"
            );
        }

        function injectMockRow(panelId, mock, isScheduled) {
            var panel = document.getElementById(panelId);
            if (!panel) return;
            var tbody = panel.querySelector("tbody");
            if (!tbody) {
                panel.innerHTML =
                    '<div class="jobs-table-wrap"><table class="jobs-table"><thead><tr>' +
                    "<th>Booking / Customer</th><th>" +
                    (isScheduled ? "Schedule" : "Requested") +
                    "</th>" +
                    "<th>Status</th><th>Route</th><th>Truck Class</th><th>Updated</th>" +
                    "</tr></thead><tbody></tbody></table></div>";
                tbody = panel.querySelector("tbody");
            }
            tbody.insertAdjacentHTML(
                "afterbegin",
                mockQueueRowHtml(mock, isScheduled),
            );
        }

        function mockRound(value) {
            return Math.round(value * 100) / 100;
        }

        function buildMockGroupVehicle(
            bookingId,
            bookingCode,
            truckTypeName,
            baseRate,
            perKmRate,
            distanceKm,
            vatRate,
        ) {
            var distanceFee = mockRound(
                Math.max(0, distanceKm - 4) * perKmRate,
            );
            var subtotal = mockRound(baseRate + distanceFee);
            var vatAmount = mockRound(subtotal * vatRate);
            var finalTotal = mockRound(subtotal + vatAmount);
            return {
                booking_id: bookingId,
                booking_code: bookingCode,
                vehicle_type_id: null,
                vehicle_name: truckTypeName,
                truck_type_id: null,
                truck_type_name: truckTypeName,
                base_rate: baseRate,
                distance_fee: distanceFee,
                vat_exclusive_total: subtotal,
                vat_amount: vatAmount,
                vat_rate: vatRate,
                final_total: finalTotal,
                assigned_unit_id: null,
                selected_unit_id: null,
                status: "requested",
            };
        }

        function buildMockGroupQuotationDetails(groupAdjustment) {
            var vatRate = window.RB_VAT_RATE || 0.12;
            var vehicleA = buildMockGroupVehicle(
                900001,
                "TM-MOCKGRPA1",
                "Light Duty (MOCK)",
                1500,
                60,
                10,
                vatRate,
            );
            var vehicleB = buildMockGroupVehicle(
                900002,
                "TM-MOCKGRPA2",
                "Medium Duty (MOCK)",
                2000,
                70,
                10,
                vatRate,
            );
            var serviceTotal = mockRound(
                vehicleA.final_total + vehicleB.final_total,
            );
            var subtotal = mockRound(
                vehicleA.vat_exclusive_total + vehicleB.vat_exclusive_total,
            );
            var vatAmount = mockRound(
                vehicleA.vat_amount + vehicleB.vat_amount,
            );
            var estimatedPrice = Math.max(
                mockRound(serviceTotal + groupAdjustment),
                0,
            );
            return {
                group_vehicles: [vehicleA, vehicleB],
                estimated_price: estimatedPrice,
                additional_fee: groupAdjustment,
                discount: 0,
                subtotal: subtotal,
                vat_amount: vatAmount,
                vat_rate: vatRate,
                status: "sent",
                quotation_number:
                    groupAdjustment >= 0
                        ? "QT-MOCK-GROUP-A"
                        : "QT-MOCK-GROUP-B",
                distance_km: 10,
                counter_offer_amount: null,
                response_note: null,
            };
        }

        injectMockRow("bookNowPanel", MOCK_BOOKINGS[0], false);
        injectMockRow(
            "scheduledPanel",
            MOCK_BOOKINGS.filter(function (m) {
                return m.label === "Scheduled";
            })[0],
            true,
        );

        var panel = document.createElement("div");
        panel.id = "rbMockPreviewPanel";
        panel.style.cssText =
            "position:fixed;top:16px;left:16px;z-index:9999;background:#111111;padding:10px;border-radius:8px;display:flex;gap:8px;";
        MOCK_BOOKINGS.forEach(function (mock) {
            var btn = document.createElement("button");
            btn.type = "button";
            btn.textContent = mock.label;
            btn.style.cssText =
                "background:#FACC15;color:#111111;border:none;border-radius:6px;padding:6px 10px;font-size:12px;font-weight:700;cursor:pointer;";
            btn.addEventListener("click", function () {
                window.openBookingDrawer(buildMockCard(mock));
                if (mock.isGroupMock) {
                    mergeQuotationDetailsIntoState(
                        buildMockGroupQuotationDetails(mock.groupAdjustment),
                    );
                    renderDrawer();
                }
            });
            panel.appendChild(btn);
        });
        document.body.appendChild(panel);

        window.openBookingDrawer(buildMockCard(MOCK_BOOKINGS[0]));
    })();
})();
