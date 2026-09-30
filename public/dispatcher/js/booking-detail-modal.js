(function () {
    "use strict";

    var overlayEl = null;
    var lastFocusedEl = null;
    // The most recently rendered detail-bundle response — reused by
    // viewQuotationDetails() so "View Quotation" never needs a second fetch
    // and always shows the exact same quotation identity Booking Details is
    // already displaying (including the consolidated quotation for a group).
    var lastBundleData = null;
    // Monotonic id per openBookingDetailModal() call, plus whether the modal
    // is currently open — together these let a fetch callback tell whether
    // it's still the latest request for a still-open modal before touching
    // the DOM, so a slow/out-of-order/late response can never overwrite a
    // newer booking's data or repopulate a closed modal.
    var requestSeq = 0;
    var isModalOpen = false;

    function esc(value) {
        if (value === null || value === undefined) return "";
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function fmt(amount) {
        var n = parseFloat(amount);
        if (isNaN(n)) return "—";
        return (
            "₱" +
            n.toLocaleString("en-PH", {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            })
        );
    }

    function fmtDate(value) {
        if (!value) return null;
        var d = new Date(value);
        if (isNaN(d.getTime())) return null;
        return d.toLocaleString("en-PH", {
            month: "short",
            day: "numeric",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
        });
    }

    function humanize(status) {
        if (!status) return "—";
        return String(status)
            .replace(/_/g, " ")
            .replace(/\b\w/g, function (c) {
                return c.toUpperCase();
            });
    }

    function row(label, value) {
        return (
            '<div class="bdm-row"><span class="bdm-row-label">' +
            esc(label) +
            '</span><span class="bdm-row-value">' +
            (value === null || value === undefined || value === "" ? "—" : esc(value)) +
            "</span></div>"
        );
    }

    function emptyState(message) {
        return '<div class="bdm-empty-state">' + esc(message) + "</div>";
    }

    function el(id) {
        return document.getElementById(id);
    }

    function renderHeader(data) {
        var booking = data.booking || {};
        el("bdmBookingCode").textContent = booking.booking_code || "—";
        el("bdmServiceType").textContent = booking.is_scheduled ? "Scheduled" : "Immediate";
        el("bdmStatus").textContent = humanize(booking.status);
    }

    function renderCustomer(customer) {
        if (!customer) {
            el("bdmCustomerSection").innerHTML = emptyState("No customer information available.");
            return;
        }
        el("bdmCustomerSection").innerHTML =
            row("Name", customer.full_name) +
            row("Phone", customer.phone) +
            row("Email", customer.email);
    }

    function renderTrip(data) {
        var booking = data.booking || {};
        var html =
            row("Pickup", booking.pickup_address) +
            row("Drop-off", booking.dropoff_address) +
            row("Distance", booking.distance_km ? parseFloat(booking.distance_km).toFixed(2) + " km" : null);

        if (booking.is_scheduled) {
            var schedText = booking.scheduled_date
                ? booking.scheduled_date + (booking.scheduled_time ? " · " + booking.scheduled_time : "")
                : null;
            html += row("Scheduled for", schedText);
        }

        if (booking.notes) {
            html += row("Notes", booking.notes);
        }

        el("bdmTripSection").innerHTML = html;
    }

    function renderVehicles(vehicles) {
        vehicles = vehicles || [];
        el("bdmVehiclesHeader").textContent = vehicles.length > 1 ? "Vehicles (" + vehicles.length + ")" : "Vehicle";

        if (vehicles.length === 0) {
            el("bdmVehiclesSection").innerHTML = emptyState("No vehicle information available.");
            return;
        }

        el("bdmVehiclesSection").innerHTML = vehicles
            .map(function (v) {
                var assignedUnit = v.assigned_unit ? v.assigned_unit.name : null;
                var assignedTl = v.assigned_team_leader ? v.assigned_team_leader.name : null;
                return (
                    '<div class="bdm-vehicle-card">' +
                    row("Booking", v.booking_code) +
                    row("Status", humanize(v.status)) +
                    row("Truck Type", v.truck_type_name) +
                    row("Vehicle Type", v.vehicle_type_name) +
                    row("Total", v.final_total ? fmt(v.final_total) : null) +
                    row("Unit / Team Leader", [assignedUnit, assignedTl].filter(Boolean).join(" · ") || null) +
                    "</div>"
                );
            })
            .join("");
    }

    function renderQuotation(quotation) {
        var section = el("bdmQuotationSection");

        if (!quotation) {
            section.innerHTML = emptyState("No quotation created yet.");
            return;
        }

        var statusBadge = humanize(quotation.status);
        var html =
            row("Quotation #", quotation.quotation_number) +
            row("Status", statusBadge) +
            row("Version", quotation.version) +
            row("Amount", fmt(quotation.estimated_price)) +
            row("Additional Fee", quotation.additional_fee ? fmt(quotation.additional_fee) : null) +
            row("Discount", quotation.discount ? fmt(quotation.discount) : null) +
            row("Sent", fmtDate(quotation.sent_at)) +
            row("Responded", fmtDate(quotation.responded_at));

        if (Array.isArray(quotation.versions) && quotation.versions.length > 1) {
            html += row("Price Revisions", quotation.versions.length + " version(s) on record");
        }

        if (typeof window.viewQuotationDetails === "function") {
            html +=
                '<button type="button" class="bdm-action-link" onclick="window.viewQuotationDetails(' +
                Number(quotation.id) +
                ')">View Quotation</button>';
        }

        section.innerHTML = html;
    }

    function renderAssignment(assignment) {
        assignment = assignment || {};
        var unitName = assignment.unit ? assignment.unit.name : null;
        var tlName = assignment.team_leader ? assignment.team_leader.name : null;

        if (!unitName && !tlName) {
            el("bdmAssignmentSection").innerHTML =
                emptyState("Not yet assigned.") + row("Service Status", humanize(assignment.service_status));
            return;
        }

        el("bdmAssignmentSection").innerHTML =
            row("Unit", unitName) + row("Team Leader", tlName) + row("Service Status", humanize(assignment.service_status));
    }

    // Booking statuses where InvoiceController@void (Void & Replace) is
    // reachable — mirrors CORRECTABLE_BOOKING_STATUSES server-side. This is
    // only a UI gate; the backend enforces it independently.
    var VOID_REPLACE_ELIGIBLE_STATUSES = ["waiting_verification", "completed"];

    function renderInvoice(invoice, data) {
        var section = el("bdmInvoiceSection");

        if (!invoice) {
            section.innerHTML = emptyState("Invoice will be generated after the applicable service stage.");
            return;
        }

        var statusLabel = invoice.status === "voided" ? "Voided" : invoice.is_current ? "Current" : humanize(invoice.status);
        var html =
            row("Invoice #", invoice.invoice_number) +
            row("Status", statusLabel) +
            row("Total", fmt(invoice.total)) +
            row("Subtotal", fmt(invoice.subtotal)) +
            row("Additional Fee", invoice.additional_fee ? fmt(invoice.additional_fee) : null) +
            row("Discount", invoice.discount ? fmt(invoice.discount) : null);

        if (invoice.status === "voided") {
            html += row("Void Reason", invoice.void_reason);
            html += row("Voided At", fmtDate(invoice.voided_at));
        }

        if (invoice.previous_invoice_number) {
            html += row("Replaces", invoice.previous_invoice_number);
        }
        if (invoice.original_invoice_number && invoice.original_invoice_number !== invoice.previous_invoice_number) {
            html += row("Original Invoice", invoice.original_invoice_number);
        }

        if (invoice.pdf_url) {
            html += '<a class="bdm-action-link" href="' + esc(invoice.pdf_url) + '" target="_blank" rel="noopener">View Invoice</a>';
        }

        var booking = (data && data.booking) || {};
        var vehicles = (data && data.vehicles) || [];
        var isGrouped = !!booking.group_code || vehicles.length > 1;
        // Eligibility follows the server's own receipt-lock decision, NOT
        // data.receipt: that is a group_code-wide display lookup and can be an
        // unrelated legacy sibling's receipt. correction_locked_by_receipt is
        // computed with the same transaction/member semantics the Void & Replace
        // endpoint enforces (Invoice::hasIssuedReceipt), so the UI never offers a
        // correction the backend rejects nor hides one it allows. The backend
        // still enforces the rule independently.
        var lockedByReceipt = !!(data && data.correction_locked_by_receipt);
        var statusEligible =
            invoice.is_current &&
            invoice.status !== "voided" &&
            VOID_REPLACE_ELIGIBLE_STATUSES.indexOf(booking.status) !== -1;
        var isEligible = statusEligible && !lockedByReceipt;

        if (isEligible) {
            html += voidReplaceFormHtml(booking, invoice, vehicles, isGrouped);
        } else if (statusEligible && lockedByReceipt) {
            html += emptyState("This transaction was finalized when its receipt was issued, so the invoice can no longer be corrected.");
        }

        section.innerHTML = html;

        if (isEligible) {
            wireVoidReplaceForm(booking, invoice, isGrouped);
        }
    }

    // A vehicle is selectable for a per-vehicle correction only while it's
    // still an active member of the group — matches the backend's own
    // active-member filter (excludes cancelled/rejected) in
    // InvoiceController::voidGroup().
    var VOID_REPLACE_EXCLUDED_VEHICLE_STATUSES = ["cancelled", "rejected"];

    function voidReplaceFormHtml(booking, invoice, vehicles, isGrouped) {
        var showPaymentVerified = booking.payment_method && booking.payment_method !== "cash";
        var vehiclePickerHtml = "";

        if (isGrouped) {
            var activeVehicles = (vehicles || []).filter(function (v) {
                return VOID_REPLACE_EXCLUDED_VEHICLE_STATUSES.indexOf(v.status) === -1;
            });
            var options =
                '<option value="">No vehicle change — reason only</option>' +
                activeVehicles
                    .map(function (v) {
                        return (
                            '<option value="' +
                            v.booking_id +
                            '">' +
                            esc(v.booking_code) +
                            (v.truck_type_name ? " — " + esc(v.truck_type_name) : "") +
                            " (current " +
                            fmt(v.final_total) +
                            ")</option>"
                        );
                    })
                    .join("");
            vehiclePickerHtml =
                '<div class="bdm-row"><span class="bdm-row-label">Vehicle</span></div>' +
                '<select id="bdmVoidVehicleSelect" style="width:100%;">' +
                options +
                "</select>" +
                '<div class="bdm-empty-state" id="bdmVoidScopeHint" style="margin:6px 0 0;">This is a consolidated invoice for the whole group — pick a vehicle to correct only its own price, or leave "reason only" to reissue the invoice unchanged.</div>';
        }

        return (
            '<button type="button" class="bdm-action-link" id="bdmVoidReplaceToggleBtn">Void &amp; Replace Invoice</button>' +
            '<div class="bdm-void-form" id="bdmVoidReplaceForm" style="display:none;">' +
            vehiclePickerHtml +
            '<div class="bdm-row"><span class="bdm-row-label">Reason <span style="color:#D8402C;">*</span></span></div>' +
            '<textarea id="bdmVoidReason" placeholder="Explain the billing error being corrected" style="width:100%;"></textarea>' +
            '<div class="bdm-adj-form-row">' +
            '<input type="text" id="bdmVoidAdditionalFee" placeholder="Additional charge, e.g. 200.50" inputmode="decimal"' +
            (isGrouped ? " disabled" : "") +
            ">" +
            '<input type="text" id="bdmVoidDiscountPercent" placeholder="Discount %" inputmode="decimal"' +
            (isGrouped ? " disabled" : "") +
            ">" +
            "</div>" +
            (isGrouped
                ? '<div class="bdm-empty-state" id="bdmVoidAmountScopeNote" style="margin:2px 0 0;">Select a vehicle above to enable an amount correction — it will apply to that vehicle only.</div>'
                : "") +
            (showPaymentVerified
                ? '<label class="bdm-row"><input type="checkbox" id="bdmVoidPaymentVerified"> I have manually verified the corrected total against the ' +
                  esc(humanize(booking.payment_method)) +
                  " payment proof</label>"
                : "") +
            '<div class="bdm-void-error" id="bdmVoidError" style="display:none;color:#D8402C;"></div>' +
            '<div class="bdm-adj-form-actions">' +
            '<button type="button" class="bdm-btn-secondary" id="bdmVoidCancelBtn">Cancel</button>' +
            '<button type="button" class="bdm-btn-primary" id="bdmVoidSubmitBtn">Void &amp; Replace</button>' +
            "</div></div>"
        );
    }

    function wireVoidReplaceForm(booking, invoice, isGrouped) {
        var toggleBtn = el("bdmVoidReplaceToggleBtn");
        var form = el("bdmVoidReplaceForm");
        var cancelBtn = el("bdmVoidCancelBtn");
        var submitBtn = el("bdmVoidSubmitBtn");
        var errorEl = el("bdmVoidError");
        var vehicleSelect = el("bdmVoidVehicleSelect");
        var additionalFeeEl = el("bdmVoidAdditionalFee");
        var discountEl = el("bdmVoidDiscountPercent");
        if (!toggleBtn || !form || !submitBtn) return;

        // "No vehicle change" (empty value) keeps the amount inputs disabled
        // and blank — genuinely reason-only, never silently submitting a
        // stale amount typed in before switching back to that option.
        if (vehicleSelect) {
            vehicleSelect.addEventListener("change", function () {
                var noVehicleSelected = vehicleSelect.value === "";
                additionalFeeEl.disabled = noVehicleSelected;
                discountEl.disabled = noVehicleSelected;
                if (noVehicleSelected) {
                    additionalFeeEl.value = "";
                    discountEl.value = "";
                }
            });
        }

        toggleBtn.addEventListener("click", function () {
            toggleBtn.style.display = "none";
            form.style.display = "";
        });
        if (cancelBtn) {
            cancelBtn.addEventListener("click", function () {
                form.style.display = "none";
                toggleBtn.style.display = "";
                errorEl.style.display = "none";
            });
        }

        submitBtn.addEventListener("click", function () {
            var reason = (el("bdmVoidReason").value || "").trim();
            if (!reason) {
                errorEl.textContent = "A reason is required.";
                errorEl.style.display = "";
                return;
            }

            var payload = { reason: reason };
            if (vehicleSelect && vehicleSelect.value !== "") {
                payload.member_booking_id = parseInt(vehicleSelect.value, 10);
            }
            // Disabled inputs (no vehicle selected — reason only) are never
            // read here, so an amount typed before switching back to "reason
            // only" can't leak into the payload even if the change handler's
            // own clearing were ever bypassed.
            if (!additionalFeeEl.disabled) {
                var additionalFeeRaw = (additionalFeeEl.value || "").trim();
                if (additionalFeeRaw !== "") payload.additional_fee = parseFloat(additionalFeeRaw) || 0;
            }
            if (!discountEl.disabled) {
                var discountRaw = (discountEl.value || "").trim();
                if (discountRaw !== "") payload.discount_percentage = parseFloat(discountRaw) || 0;
            }
            var verifiedEl = el("bdmVoidPaymentVerified");
            if (verifiedEl) payload.payment_verified = !!verifiedEl.checked;

            submitBtn.disabled = true;
            errorEl.style.display = "none";

            var url = (window.RB_ROUTES && window.RB_ROUTES.voidInvoice
                ? window.RB_ROUTES.voidInvoice
                : "/admin-dashboard/invoices/:invoice/void"
            ).replace(":invoice", encodeURIComponent(invoice.id));

            fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": window.RB_CSRF || "",
                },
                credentials: "same-origin",
                body: JSON.stringify(payload),
            })
                .then(function (res) {
                    return res.json().then(function (data) {
                        return { ok: res.ok, data: data };
                    });
                })
                .then(function (result) {
                    submitBtn.disabled = false;
                    if (!result.ok || !result.data || !result.data.success) {
                        errorEl.textContent = (result.data && result.data.message) || "Unable to void this invoice.";
                        errorEl.style.display = "";
                        return;
                    }
                    if (window.openBookingDetailModal && booking.booking_code) {
                        window.openBookingDetailModal(booking.booking_code);
                    }
                })
                .catch(function () {
                    submitBtn.disabled = false;
                    errorEl.textContent = "Unable to void this invoice.";
                    errorEl.style.display = "";
                });
        });
    }

    function renderReceipt(receipt, payment) {
        var section = el("bdmReceiptSection");
        payment = payment || {};

        if (!receipt) {
            var pendingHtml = emptyState("Receipt not yet generated.");
            if (payment.payment_method) {
                pendingHtml += row("Payment Method", humanize(payment.payment_method));
            }
            if (payment.cash_received) {
                pendingHtml += row("Cash Received", fmt(payment.cash_received));
            }
            section.innerHTML = pendingHtml;
            return;
        }

        var html =
            row("Receipt #", receipt.receipt_number || receipt.receipt_code) +
            row("Payment Method", payment.payment_method ? humanize(payment.payment_method) : null) +
            row("Sent to Customer", receipt.email_sent ? "Yes" : "No") +
            row("Generated", fmtDate(receipt.created_at));

        if (receipt.pdf_url) {
            html += '<a class="bdm-action-link" href="' + esc(receipt.pdf_url) + '" target="_blank" rel="noopener">View Receipt</a>';
        }

        section.innerHTML = html;
    }

    function render(data) {
        lastBundleData = data;
        renderHeader(data);
        renderCustomer(data.customer);
        renderTrip(data);
        renderVehicles(data.vehicles);
        renderQuotation(data.quotation);
        renderAssignment(data.assignment);
        renderInvoice(data.invoice, data);
        renderReceipt(data.receipt, data.payment);
    }

    function showError(message) {
        el("bdmCustomerSection").innerHTML = emptyState(message);
        [
            "bdmTripSection",
            "bdmVehiclesSection",
            "bdmQuotationSection",
            "bdmAssignmentSection",
            "bdmInvoiceSection",
            "bdmReceiptSection",
        ].forEach(function (id) {
            el(id).innerHTML = "";
        });
    }

    function resetModalContent() {
        lastBundleData = null;
        el("bdmBookingCode").textContent = "Loading…";
        el("bdmServiceType").textContent = "—";
        el("bdmStatus").textContent = "—";
        el("bdmVehiclesHeader").textContent = "Vehicle";
        [
            "bdmCustomerSection",
            "bdmTripSection",
            "bdmVehiclesSection",
            "bdmQuotationSection",
            "bdmAssignmentSection",
            "bdmInvoiceSection",
            "bdmReceiptSection",
        ].forEach(function (id) {
            el(id).innerHTML = emptyState("Loading…");
        });
    }

    function lockScroll() {
        document.body.style.overflow = "hidden";
    }

    function unlockScroll() {
        document.body.style.overflow = "";
    }

    window.openBookingDetailModal = function (bookingCode) {
        if (!bookingCode) return;

        overlayEl = el("bookingDetailModalOverlay");
        if (!overlayEl) return;

        lastFocusedEl = document.activeElement;

        var requestId = ++requestSeq;
        isModalOpen = true;
        resetModalContent();

        overlayEl.style.display = "flex";
        overlayEl.setAttribute("aria-hidden", "false");
        lockScroll();

        var closeBtn = overlayEl.querySelector(".bdm-close-btn");
        if (closeBtn) closeBtn.focus();

        var url = (window.RB_ROUTES && window.RB_ROUTES.detailBundle
            ? window.RB_ROUTES.detailBundle
            : "/admin-dashboard/booking/:booking/detail-bundle"
        ).replace(":booking", encodeURIComponent(bookingCode));

        fetch(url, {
            headers: { Accept: "application/json" },
            credentials: "same-origin",
        })
            .then(function (res) {
                if (!res.ok) throw new Error("HTTP " + res.status);
                return res.json();
            })
            .then(function (data) {
                if (requestId !== requestSeq || !isModalOpen) return;
                if (!data || !data.success) {
                    showError("Unable to load booking details.");
                    return;
                }
                render(data);
            })
            .catch(function () {
                if (requestId !== requestSeq || !isModalOpen) return;
                showError("Unable to load booking details.");
            });
    };

    window.closeBookingDetailModal = function () {
        if (!overlayEl) overlayEl = el("bookingDetailModalOverlay");
        if (!overlayEl) return;

        isModalOpen = false;
        overlayEl.style.display = "none";
        overlayEl.setAttribute("aria-hidden", "true");
        unlockScroll();

        if (lastFocusedEl && typeof lastFocusedEl.focus === "function") {
            lastFocusedEl.focus();
        }
    };

    // "View Quotation" — reuses the SAME unified quotation drawer/state/render
    // pipeline as every other entry point (Book Now row, Scheduled row). Does
    // NOT close Booking Details: the quotation drawer renders on top of it
    // (see the rb-drawer-overlay z-index, raised above the booking-detail
    // overlay specifically for this stacking), and closing the quotation
    // drawer (its own existing close button/backdrop/Escape handling — all
    // untouched) simply reveals the still-open Booking Details modal again.
    // No second quotation component, no duplicated pricing markup/formulas —
    // this only maps the already-fetched bundle response onto the same
    // dataset shape buildStateFromCard() already reads from a real queue row;
    // openBookingDrawer() then hydrates it from GET /quotations/{id}/details
    // exactly as it does for every other entry point, which is what makes the
    // grouped/canonical-ordering/accepted-read-only/etc. behavior identical.
    window.viewQuotationDetails = function (quotationId) {
        var data = lastBundleData;
        if (!data || !data.quotation || Number(data.quotation.id) !== Number(quotationId)) return;
        if (typeof window.openBookingDrawer !== "function") return;

        var booking = data.booking || {};
        var customer = data.customer || {};
        var quotation = data.quotation;
        var ownVehicle = (data.vehicles || []).filter(function (v) {
            return v.booking_id === booking.id;
        })[0];

        var card = document.createElement("tr");
        card.dataset.bookingCode = booking.booking_code || "";
        card.dataset.status = booking.status || "";
        card.dataset.createdAt = booking.created_at || "";
        card.dataset.customerName = customer.full_name || "Guest";
        card.dataset.customerPhone = customer.phone || "";
        card.dataset.customerEmail = customer.email || "";
        card.dataset.pickup = booking.pickup_address || "";
        card.dataset.dropoff = booking.dropoff_address || "";
        card.dataset.distanceKm = booking.distance_km || "";
        card.dataset.customerNote = booking.notes || "";
        card.dataset.currentPrice = quotation.estimated_price || booking.final_total || 0;
        card.dataset.currentAdditional = quotation.additional_fee || 0;
        card.dataset.vatRate = quotation.vat_rate || 0.12;
        card.dataset.truckType = (ownVehicle && ownVehicle.truck_type_name) || "";
        if (booking.is_scheduled) {
            card.dataset.scheduledFor = booking.scheduled_date
                ? booking.scheduled_date + "T" + (booking.scheduled_time || "00:00")
                : "";
        }
        card.dataset.quotationId = quotation.id;
        card.dataset.quotationNumber = quotation.quotation_number || "";
        card.dataset.quotationStatus = quotation.status || "";

        // No data-group-roster here — a quotation already exists for this
        // booking (that is the only way to reach "View Quotation"), so
        // openBookingDrawer()'s existing hydration from /quotations/{id}/details
        // is the sole and authoritative source for group_vehicles, exactly as
        // it already is for every other already-quoted booking opened from any
        // queue row. This does not skip or duplicate that pipeline in any way.
        window.openBookingDrawer(card);
    };

    document.addEventListener("DOMContentLoaded", function () {
        var overlay = el("bookingDetailModalOverlay");
        if (!overlay) return;

        overlay.addEventListener("click", function (e) {
            if (e.target === overlay) {
                window.closeBookingDetailModal();
            }
        });
    });

    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        var overlay = el("bookingDetailModalOverlay");
        if (overlay && overlay.style.display !== "none" && overlay.getAttribute("aria-hidden") === "false") {
            window.closeBookingDetailModal();
        }
    });
})();
