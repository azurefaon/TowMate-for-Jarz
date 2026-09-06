// ===============================
// FLOATING QUOTATIONS — LIGHTWEIGHT REFRESH
// ===============================
// Re-fetches and swaps just the Floating Quotations panel in place. Used
// everywhere an action used to trigger a full location.reload() — a full
// reload re-executes the Google Maps <script> tag and re-inits the live
// map, which is a billable Maps JS API load every time.
function refreshFloatingQuotationsPanel() {
    return fetch("/admin-dashboard/quotations/floating-panel")
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var section = document.getElementById("fqSection");
            if (section && data.html) section.outerHTML = data.html;
        })
        .catch(function () {});
}

// ===============================
// FIXED GLOBAL CLICK HANDLER
// ===============================
// Note: .btn-accept/.btn-reject clicks inside the main queue (#incomingList)
// are handled by handleQueueClick() further down, which calls
// event.stopPropagation() — so they never reach this delegate.
document.addEventListener("click", function (e) {
    const pqSendBtn = e.target.closest(".pq-send-btn");
    const pqCancelBtn = e.target.closest(".pq-cancel-btn");
    const viewBtn = e.target.closest(".btn-view-quote");

    if (pqSendBtn) openPendingQuotationModal(pqSendBtn.dataset.quotationId);
    if (pqCancelBtn)
        cancelPendingQuotation(pqCancelBtn.dataset.quotationId, pqCancelBtn);
    if (viewBtn) viewQuotation(viewBtn.dataset.id);
});

function openPendingQuotationModal(quotationId) {
    if (typeof viewQuotationDetails === "function") {
        viewQuotationDetails(quotationId);
    }
}

async function cancelPendingQuotation(quotationId, btn) {
    if (!confirm("Cancel this pending quotation?")) return;
    try {
        const resp = await fetch(
            "/admin-dashboard/quotations/" + quotationId + "/cancel",
            {
                method: "POST",
                headers: {
                    "X-CSRF-TOKEN":
                        document.querySelector('meta[name="csrf-token"]')
                            ?.content || "",
                    Accept: "application/json",
                    "Content-Type": "application/json",
                },
            },
        );
        const data = await resp.json();
        if (data.success || resp.ok) {
            const card = btn ? btn.closest(".incoming-card") : null;
            if (card) card.remove();
        } else {
            alert(data.message || "Failed to cancel quotation.");
        }
    } catch (err) {
        alert("An error occurred. Please try again.");
    }
}

// --- VIEW QUOTATION (WORKING VERSION) ---
async function viewQuotation(quotationId) {
    try {
        const response = await fetch(`/admin/quotation/${quotationId}`);
        const data = await response.json();

        if (!data.success) {
            alert("Failed to load quotation");
            return;
        }

        const q = data.quotation;
        document.getElementById("quoteCustomer").innerText = q.customer_name;
        document.getElementById("quotePhone").innerText = q.customer_phone;
        document.getElementById("quotePickup").innerText = q.pickup_address;
        document.getElementById("quoteDropoff").innerText = q.dropoff_address;
        document.getElementById("quoteDistance").innerText =
            q.distance_km_formatted + " km";
        document.getElementById("quotePrice").innerText =
            "₱" + q.estimated_price;
        document.getElementById("quotationModal").classList.remove("hidden");
    } catch (err) {
        console.error(err);
        alert("Error loading quotation");
    }
}
document.addEventListener("DOMContentLoaded", function () {
    var state = {
        selectedBookingId: null,
        selectedAction: null,
        selectedButton: null,
        selectedCard: null,
        pollingInterval: null,
        reviewData: null,
        activeFilter: "book-now",
    };

    var actionModal = document.getElementById("actionModal");
    var queueList = document.getElementById("incomingList");
    var confirmActionBtn = document.getElementById("confirmActionBtn");
    var cancelModalBtn = document.getElementById("cancelModalBtn");
    var rejectReasonInput = document.getElementById("rejectReasonInput");
    var priceInput = document.getElementById("priceInput");
    var priceHelper = document.getElementById("priceHelper");
    var dispatcherNoteInput = document.getElementById("dispatcherNoteInput");
    var unitSelect = document.getElementById("unitSelect");
    var unitHelper = document.getElementById("unitHelper");
    var quotationReviewGrid = document.getElementById("quotationReviewGrid");
    var confirmedBookingPanel = document.getElementById(
        "confirmedBookingPanel",
    );
    var unitWrapper = document.getElementById("unitWrapper");
    var finalTotalPreview = document.getElementById("finalTotalPreview");
    var discountLabel = document.getElementById("discountLabel");
    var discountBadge = document.getElementById("discountBadge");
    var distanceInput = document.getElementById("distanceInput");
    var distanceFeeInput = document.getElementById("distanceFeeInput");
    var discountPercentInput = document.getElementById("discountPercentInput");
    var quoteValidationSummary = document.getElementById(
        "quoteValidationSummary",
    );
    function clearZeroLikeOnFocus(input) {
        if (!input) {
            return;
        }

        input.addEventListener("focus", function () {
            var raw = String(this.value || "")
                .replace(/,/g, "")
                .trim();

            if (raw === "0" || raw === "0.0" || raw === "0.00") {
                this.value = "";
            }
        });
    }

    function isRegularCustomerType() {
        return (
            String(
                (state.reviewData && state.reviewData.customerType) ||
                    "Regular",
            )
                .trim()
                .toLowerCase() === "regular"
        );
    }

    function syncDiscountInputState() {
        if (!discountPercentInput) return;
        if (isRegularCustomerType()) {
            discountPercentInput.disabled = true;
            discountPercentInput.readOnly = true;
            discountPercentInput.classList.add("is-locked");
            discountPercentInput.setAttribute("aria-disabled", "true");
            discountPercentInput.value = "0.00";
        } else {
            discountPercentInput.disabled = false;
            discountPercentInput.readOnly = false;
            discountPercentInput.classList.remove("is-locked");
            discountPercentInput.setAttribute("aria-disabled", "false");
        }
    }

    if (!queueList) {
        return;
    }

    if (actionModal) {
        actionModal.classList.add("hidden");
        actionModal.classList.remove("is-open");
        actionModal.setAttribute("aria-hidden", "true");
    }

    initializeViewToggle();
    initializeQueueFilters();
    initializeBookNowFilter();
    initializeScheduledFilter();
    initializeRowKeyboardAccess();

    function initializeViewToggle() {
        // No view-toggle UI exists on this page — no-op stub kept for compatibility.
    }

    function initializeQueueFilters() {
        var filterBtns = document.querySelectorAll(".queue-filter-btn");

        if (!filterBtns.length) return;

        // Delegate to applyQueueFilter (the single authoritative filter system)
        // which correctly toggles is-hidden, updates emptyState, and tab badges.
        filterBtns.forEach(function (btn) {
            btn.addEventListener("click", function () {
                if (typeof window.applyDispatchQueueFilter === "function") {
                    window.applyDispatchQueueFilter(btn.dataset.filter);
                }
            });
        });
    }
    initializeRealtimeUpdates();

    // Initialize live tracking map (loaded once — markers are moved on each
    // poll tick, the map itself is never re-created; see poll() below)
    var liveMap = null;
    var liveMapContainer = document.getElementById("dispatchLiveMap");
    if (liveMapContainer && typeof google !== "undefined" && google.maps) {
        liveMap = new google.maps.Map(liveMapContainer, {
            center: { lat: 14.5995, lng: 120.9842 },
            zoom: 11,
        });
    }

    // Collapsible tracking panel toggle
    var trackingToggleBtn = document.getElementById("trackingToggleBtn");
    var trackingBody = document.getElementById("trackingBody");
    var trackingToggleLabel = document.getElementById("trackingToggleLabel");
    if (trackingToggleBtn && trackingBody) {
        trackingToggleBtn.addEventListener("click", function () {
            var collapsed = trackingBody.classList.toggle("is-collapsed");
            trackingToggleLabel.textContent = collapsed ? "show" : "hide";
            if (!collapsed && liveMap) {
                setTimeout(function () { google.maps.event.trigger(liveMap, "resize"); }, 50);
            }
        });
    }

    startLocationPolling();
    initWaitTimeBadges();
    updateReturnBanner();
    initializePriceInput();
    initializeUnitSelector();
    initializeComputationInputs();

    clearZeroLikeOnFocus(distanceInput);
    clearZeroLikeOnFocus(discountPercentInput);
    clearZeroLikeOnFocus(priceInput);

    if (
        typeof lucide !== "undefined" &&
        lucide &&
        typeof lucide.createIcons === "function"
    ) {
        lucide.createIcons();
    }

    queueList.addEventListener("click", handleQueueClick);
    document.getElementById("bookNowPanel")?.addEventListener("click", handleQueueClick);

    if (confirmActionBtn) {
        confirmActionBtn.addEventListener("click", handleModalConfirm);
    }

    var saveDraftBtn = document.getElementById("saveDraftBtn");
    if (saveDraftBtn) {
        saveDraftBtn.addEventListener("click", handleSaveAsDraft);
    }

    if (cancelModalBtn) {
        cancelModalBtn.addEventListener("click", closeActionModal);
    }

    if (actionModal) {
        actionModal.addEventListener("click", function (event) {
            if (event.target.classList.contains("modal-overlay")) {
                closeActionModal();
            }
        });
    }

    // --- POPULATE SUMMARY FROM DATABASE ---
    function populateSummaryFromDB(data) {
        document.getElementById("summaryDistance").innerText =
            data.distance_km + " km";
        var baseRateEl = document.getElementById("summaryBase");
        if (baseRateEl) {
            baseRateEl.textContent =
                data.base_rate > 0
                    ? "₱" + parseFloat(data.base_rate).toFixed(2)
                    : "TBD";
        }
        document.getElementById("summaryDistanceFee").innerText =
            "₱" + data.distance_fee;
        document.getElementById("summaryAdditional").innerText =
            "₱" + data.additional_fee;
        document.getElementById("summaryTotal").innerText =
            "₱" + data.final_total;
    }

    function openActionModal() {
        if (state.selectedCard) {
            populateModalFromCard(state.selectedCard);
        }
    }

    // apply filter — defaults to Book Now, unless a mutating drawer action
    // (see booking-drawer.js's reloadAfterSuccess()) just reloaded the page
    // and stashed which tab was active beforehand, so that tab is restored
    // instead of silently bouncing the dispatcher back to Book Now.
    window.applyDispatchQueueFilter = applyQueueFilter;
    (function () {
        var initialFilter = "book-now";
        try {
            var stashed = sessionStorage.getItem("rbReopenQueueFilter");
            sessionStorage.removeItem("rbReopenQueueFilter");
            if (stashed) initialFilter = stashed;
        } catch (e) {
            /* ignore */
        }
        applyQueueFilter(initialFilter);
    })();
    applyNotificationDeepLink();

    function applyQueueFilter(filter) {
        var filterButtons = document.querySelectorAll(".queue-filter-btn");
        var cards = queueList.querySelectorAll(".incoming-card");
        state.activeFilter = filter || "active";

        // Panels for the separate book-now / scheduled queues
        var bookNowPanel = document.getElementById("bookNowPanel");
        var scheduledPanel = document.getElementById("scheduledPanel");

        // Show/hide the main incomingList vs separate panels
        var isBookNow = state.activeFilter === "book-now";
        var isScheduled = state.activeFilter === "scheduled";

        if (queueList) {
            queueList.style.display = isBookNow || isScheduled ? "none" : "block";
        }
        if (bookNowPanel) bookNowPanel.style.display = isBookNow ? "block" : "none";
        if (scheduledPanel)
            scheduledPanel.style.display = isScheduled ? "block" : "none";

        Array.prototype.forEach.call(filterButtons, function (button) {
            var isActive =
                (button.getAttribute("data-filter") || "book-now") ===
                state.activeFilter;
            button.classList.toggle("is-active", isActive);
        });

        Array.prototype.forEach.call(cards, function (card) {
            var queueType = card.getAttribute("data-queue") || "book-now";
            var matches =
                state.activeFilter === "all" ||
                queueType === state.activeFilter;
            card.classList.toggle("is-hidden", !matches);
        });

        updateFilteredCount();
        updateTabBadges();
        updateEmptyState();
        if (typeof updateReturnBanner === "function") updateReturnBanner();
    }

    function updateTabBadges() {
        var cards = queueList.querySelectorAll(".incoming-card");
        var bookNowPanel = document.getElementById("bookNowPanel");
        var scheduledPanel = document.getElementById("scheduledPanel");
        // Both Book Now and Scheduled render as .jobs-table rows now, queried
        // the same way (.jobs-row) — Book Now rows deliberately do NOT carry
        // .incoming-card (dispatch.css's base .incoming-card rule sets
        // display:flex/padding/border for the old card layout, which breaks
        // table-row rendering when applied to a <tr> with no counteracting
        // class). The badge always shows the total, never the currently-
        // filtered count (see #schedFilter wiring below).
        var bookNowCards = bookNowPanel ? bookNowPanel.querySelectorAll(".jobs-row") : [];
        var scheduledCards = scheduledPanel ? scheduledPanel.querySelectorAll(".jobs-row") : [];
        var counts = {
            returned: 0,
            active: 0,
            "book-now": bookNowCards.length,
            scheduled: scheduledCards.length,
            delayed: 0,
            ready_completion: 0,
            not_responding: 0,
            all: cards.length,
        };

        Array.prototype.forEach.call(cards, function (card) {
            var queueType = card.getAttribute("data-queue") || "book-now";

            if (Object.prototype.hasOwnProperty.call(counts, queueType)) {
                counts[queueType] += 1;
            }
        });

        Object.keys(counts).forEach(function (key) {
            var badge = document.querySelector(
                '.queue-tab-count[data-count-for="' + key + '"]',
            );

            if (!badge) {
                return;
            }

            badge.textContent = String(counts[key]);
            badge.classList.toggle("has-count", counts[key] > 0);
        });
    }

    // Scheduled tab's own 7-item filter (needs-quote/quote-sent/confirmed/
    // upcoming/ready/overdue) + search — filters .jobs-row rows by
    // data-sched-bucket and customer/booking text. The tab's own count badge
    // (updateTabBadges() above) intentionally always shows the total, not
    // this filtered count.
    function initializeScheduledFilter() {
        var filterSelect = document.getElementById("schedFilter");
        var searchInput = document.getElementById("schedSearch");
        var tbody = document.getElementById("schedTableBody");
        if (!tbody) return;

        function applySchedFilter() {
            var bucket = filterSelect ? filterSelect.value : "all";
            var query = (searchInput ? searchInput.value : "").trim().toLowerCase();
            var rows = tbody.querySelectorAll(".jobs-row");

            Array.prototype.forEach.call(rows, function (row) {
                var matchesBucket = bucket === "all" || row.getAttribute("data-sched-bucket") === bucket;
                var text = (row.textContent || "").toLowerCase();
                var matchesQuery = !query || text.indexOf(query) > -1;
                row.style.display = matchesBucket && matchesQuery ? "" : "none";
            });
        }

        if (filterSelect) filterSelect.addEventListener("change", applySchedFilter);
        if (searchInput) searchInput.addEventListener("input", applySchedFilter);
    }

    // Book Now tab's own status filter + search — same pattern as
    // initializeScheduledFilter() above, filtering by the row's
    // data-eff-status (mirrors $bnEffStatus in dispatch.blade.php, which
    // already matches #rbBnFilter's option values 1:1) and free-text search.
    function initializeBookNowFilter() {
        var filterSelect = document.getElementById("rbBnFilter");
        var searchInput = document.getElementById("bnSearch");
        var panel = document.getElementById("bookNowPanel");
        if (!panel) return;

        function applyBnFilter() {
            var status = filterSelect ? filterSelect.value : "all";
            var query = (searchInput ? searchInput.value : "").trim().toLowerCase();
            var rows = panel.querySelectorAll(".jobs-row");

            Array.prototype.forEach.call(rows, function (row) {
                var matchesStatus = status === "all" || row.getAttribute("data-eff-status") === status;
                var text = (row.textContent || "").toLowerCase();
                var matchesQuery = !query || text.indexOf(query) > -1;
                row.style.display = matchesStatus && matchesQuery ? "" : "none";
            });
        }

        if (filterSelect) filterSelect.addEventListener("change", applyBnFilter);
        if (searchInput) searchInput.addEventListener("input", applyBnFilter);
    }

    // Whole-row click already opens the drawer via the row's own onclick
    // attribute (window.openBookingDrawer(this)) — this only adds keyboard
    // parity for the tabindex="0" rows: Enter/Space triggers the same click.
    function initializeRowKeyboardAccess() {
        ["bookNowPanel", "scheduledPanel"].forEach(function (panelId) {
            var panel = document.getElementById(panelId);
            if (!panel) return;

            panel.addEventListener("keydown", function (event) {
                var row = event.target.closest && event.target.closest(".jobs-row");
                if (!row || !panel.contains(row)) return;
                if (event.key !== "Enter" && event.key !== " ") return;

                event.preventDefault();
                row.click();
            });
        });
    }

    // ------------------------------------------------------------------
    // Notification deep-link: /admin-dashboard/dispatch?type=book-now&booking=TM-00128
    // (or type=scheduled) — see App\Models\DispatcherNotification::getTargetUrlAttribute().
    // Activates the right tab, locates the row by its existing
    // data-booking-code, gives it a brief neutral highlight, and reuses the
    // exact same window.openBookingDrawer() the row's own click already
    // uses — no second booking-details implementation.
    // ------------------------------------------------------------------
    function applyNotificationDeepLink() {
        var params;
        try {
            params = new URLSearchParams(window.location.search);
        } catch (e) {
            return;
        }

        var type = params.get("type");
        var bookingCode = params.get("booking");
        if ((type !== "book-now" && type !== "scheduled") || !bookingCode) return;

        if (typeof window.applyDispatchQueueFilter === "function") {
            window.applyDispatchQueueFilter(type);
        }

        var panel = document.getElementById(type === "book-now" ? "bookNowPanel" : "scheduledPanel");
        if (!panel) return;

        var row = panel.querySelector('.jobs-row[data-booking-code="' + bookingCode.replace(/"/g, '') + '"]');
        if (!row) return;

        row.scrollIntoView({ block: "center" });
        row.classList.add("jobs-row--deep-link");
        setTimeout(function () {
            row.classList.remove("jobs-row--deep-link");
        }, 1600);

        if (typeof window.openBookingDrawer === "function") {
            window.openBookingDrawer(row);
        }

        // One-shot: don't reopen the same booking again on a later manual refresh.
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete("type");
            url.searchParams.delete("booking");
            window.history.replaceState({}, "", url.toString());
        } catch (e) {
            /* ignore */
        }
    }

    function initializeRealtimeUpdates() {
        if (
            typeof Pusher === "undefined" ||
            !window.PusherConfig ||
            !window.PusherConfig.key
        ) {
            startPolling();
            return;
        }

        try {
            var pusher = new Pusher(window.PusherConfig.key, {
                wsHost: window.PusherConfig.wsHost,
                wsPort: window.PusherConfig.wsPort,
                wssPort: window.PusherConfig.wssPort,
                forceTLS: window.PusherConfig.forceTLS,
                enabledTransports: ["ws", "wss"],
                disableStats: true,
            });

            var channel = pusher.subscribe("dispatch");
            channel.bind("booking.created", handleNewBooking);
            channel.bind("booking.updated", handleBookingUpdate);
            channel.bind("customer.inquiry", handleCustomerInquiry);
        } catch (error) {
            startPolling();
        }
    }

    function initializePriceInput() {
        if (!priceInput) {
            return;
        }

        priceInput.addEventListener("keydown", function (event) {
            var allowedKeys = [
                "Backspace",
                "Delete",
                "Tab",
                "ArrowLeft",
                "ArrowRight",
                "Home",
                "End",
                "Enter",
            ];

            if (
                allowedKeys.indexOf(event.key) !== -1 ||
                ((event.ctrlKey || event.metaKey) &&
                    ["a", "c", "v", "x"].indexOf(event.key.toLowerCase()) !==
                        -1)
            ) {
                return;
            }

            if (!/[0-9.]/.test(event.key)) {
                event.preventDefault();
                return;
            }

            if (event.key === "." && this.value.indexOf(".") !== -1) {
                event.preventDefault();
            }
        });

        priceInput.addEventListener("input", function () {
            var digitsBeforeCursor = countDigitsBeforeCursor(this);
            this.value = formatPriceInputValue(this.value, false);
            restoreCursorByDigitCount(this, digitsBeforeCursor);
            clearFieldError(this);
            updatePriceHelper(this.value);
            updateConfirmButtonState();
        });

        priceInput.addEventListener("blur", function () {
            var parsed = parseNumericPrice(this.value);

            if (parsed > 0) {
                this.value = formatPriceInputValue(parsed.toFixed(2), true);
            }

            clearFieldError(this);
            updatePriceHelper(this.value);
            updateConfirmButtonState();
        });
    }

    function getSelectedUnitBaseRate() {
        if (!unitSelect || !unitSelect.value) return 0;
        var opt = unitSelect.options[unitSelect.selectedIndex];
        return parseNumericPrice(
            opt ? opt.getAttribute("data-base-rate") || "0" : "0",
        );
    }

    function getSelectedUnitPerKmRate() {
        if (!unitSelect || !unitSelect.value) return 0;
        var opt = unitSelect.options[unitSelect.selectedIndex];
        return parseNumericPrice(
            opt ? opt.getAttribute("data-per-km-rate") || "0" : "0",
        );
    }

    function initializeUnitSelector() {
        if (!unitSelect) {
            return;
        }

        unitSelect.addEventListener("change", function () {
            clearFieldError(this);
            updateUnitHelper();
            updateConfirmButtonState();
            updateQuotationPreview(priceInput ? priceInput.value : "");
            var baseEl = document.getElementById("summaryBase");
            if (baseEl) {
                var rate = getSelectedUnitBaseRate();
                baseEl.textContent = this.value ? "₱" + rate.toFixed(2) : "TBD";
            }
        });
    }

    function initializeComputationInputs() {
        if (distanceInput) {
            distanceInput.addEventListener("input", function () {
                clearFieldError(this);
                updateQuotationPreview(priceInput ? priceInput.value : "");
                updateConfirmButtonState();
            });
        }

        if (discountPercentInput) {
            discountPercentInput.addEventListener("input", function () {
                clearFieldError(this);
                updateQuotationPreview(priceInput ? priceInput.value : "");
                updateConfirmButtonState();
            });
        }
    }

    function showValidationSummary(messages) {
        if (!quoteValidationSummary) {
            return;
        }

        quoteValidationSummary.innerHTML = "";
        quoteValidationSummary.classList.remove("show");
    }

    function clearValidationSummary() {
        if (!quoteValidationSummary) {
            return;
        }

        quoteValidationSummary.innerHTML = "";
        quoteValidationSummary.classList.remove("show");
    }

    function setFieldError(field, message) {
        if (!field) {
            return;
        }

        field.classList.add("is-invalid");
        field.setCustomValidity(message || "Invalid value.");

        var errorNode = document.getElementById(field.id + "Error");
        if (errorNode) {
            errorNode.textContent = message || "Invalid value.";
            errorNode.classList.add("show");
        }
    }

    function clearFieldError(field) {
        if (!field) {
            return;
        }

        field.classList.remove("is-invalid");
        field.setCustomValidity("");

        var errorNode = document.getElementById(field.id + "Error");
        if (errorNode) {
            errorNode.textContent = "";
            errorNode.classList.remove("show");
        }
    }

    // Discount validation removed
    function validateAcceptForm(showErrors) {
        if (state.selectedAction !== "accept") {
            clearValidationSummary();
            return true;
        }

        var shouldShowErrors = showErrors === true;
        var messages = [];
        var firstInvalidField = null;

        var distanceValue = parseFloat(distanceInput ? distanceInput.value : 0);
        var distanceFeeValue = parseNumericPrice(
            distanceFeeInput ? distanceFeeInput.value : 0,
        );
        var selectedPerKmRate = getSelectedUnitPerKmRate();
        var expectedDistanceFee = roundValue(
            Math.max(0, distanceValue - 4) * selectedPerKmRate,
        );

        if (unitSelect) {
            clearFieldError(unitSelect);
        }
        if (distanceInput) {
            clearFieldError(distanceInput);
        }
        if (distanceFeeInput) {
            clearFieldError(distanceFeeInput);
        }

        function rememberError(field, message) {
            messages.push(message);

            if (shouldShowErrors) {
                setFieldError(field, message);
            }

            if (!firstInvalidField && field) {
                firstInvalidField = field;
            }
        }

        if (!unitSelect || !unitSelect.value) {
            rememberError(unitSelect, "Available unit is required.");
        } else if (
            unitSelect.options[unitSelect.selectedIndex] &&
            unitSelect.options[unitSelect.selectedIndex].getAttribute(
                "data-selectable",
            ) === "0"
        ) {
            rememberError(
                unitSelect,
                "Selected unit does not have an available team leader.",
            );
        }

        var _cs = state.selectedCard && state.selectedCard.getAttribute("data-status");
        var isConfirmedStatus = _cs === "confirmed" || _cs === "scheduled_confirmed";

        if (!isConfirmedStatus) {
            if (!Number.isFinite(distanceValue) || distanceValue <= 0) {
                rememberError(distanceInput, "Distance is required.");
            }

            if (Math.abs(distanceFeeValue - expectedDistanceFee) > 0.11) {
                rememberError(
                    distanceFeeInput,
                    "Distance fee must match the unit's per-km rate (₱" +
                        selectedPerKmRate.toFixed(2) +
                        "/km after the first 4km).",
                );
            }
        }

        if (messages.length) {
            showValidationSummary(messages);

            if (shouldShowErrors && firstInvalidField) {
                firstInvalidField.focus();
            }

            return false;
        }

        clearValidationSummary();
        return true;
    }

    function updateConfirmButtonState() {
        if (!confirmActionBtn) {
            return;
        }

        if (state.selectedAction !== "accept") {
            confirmActionBtn.disabled = false;
            clearValidationSummary();
            return;
        }

        var hasUnit =
            unitSelect &&
            unitSelect.value &&
            unitSelect.options[unitSelect.selectedIndex] &&
            unitSelect.options[unitSelect.selectedIndex].getAttribute(
                "data-selectable",
            ) !== "0";

        confirmActionBtn.disabled = !hasUnit;

        clearValidationSummary();
    }

    function handleQueueClick(event) {
        var target = event.target;

        if (!(target instanceof Element)) {
            target =
                target && target.parentElement ? target.parentElement : null;
        }

        if (!target) {
            return;
        }

        var button = target.closest(".btn-accept, .btn-reject");

        var bookNowPanel = document.getElementById("bookNowPanel");
        var inQueueList = button && queueList.contains(button);
        var inBookNowPanel = button && bookNowPanel && bookNowPanel.contains(button);
        if (!button || (!inQueueList && !inBookNowPanel)) {
            return;
        }

        // Skip if button is disabled
        if (button.disabled) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        state.selectedButton = button;
        state.selectedCard = button.closest(".incoming-card");
        state.selectedBookingId = button.getAttribute("data-id");
        state.selectedAction =
            button.getAttribute("data-action") ||
            (button.classList.contains("btn-accept") ? "accept" : "reject");

        openActionModal();
    }

    function handleNewBooking(data) {
        var countElement = document.getElementById("requestCount");
        var emptyState = document.getElementById("emptyState");

        if (countElement) {
            var currentCount = parseInt(countElement.textContent, 10) || 0;
            countElement.textContent = String(currentCount + 1);
        }

        if (emptyState) {
            emptyState.style.display = "none";
        }

        if (
            document.querySelector('.incoming-card[data-id="' + data.id + '"]')
        ) {
            return;
        }

        var newCard = document.createElement("div");
        var serviceType = data.service_type || "book_now";
        var scheduledFor = data.scheduled_for
            ? new Date(data.scheduled_for)
            : null;
        var isScheduled = serviceType === "schedule";
        var isDueNow =
            Boolean(scheduledFor) && scheduledFor.getTime() <= Date.now();
        var queueType = isScheduled && !isDueNow ? "scheduled" : "book-now";
        var timingLabel =
            data.service_mode_label ||
            (isScheduled ? "Schedule Later" : "Book Now");
        var statusLabel = isScheduled ? "Scheduled Booking" : "Requested";
        var statusTone = isScheduled ? "scheduled" : "pending";
        var reviewButtonLabel =
            isScheduled && !isDueNow
                ? "Await Scheduled Time"
                : "Review & Quote";
        var reviewButtonDisabled = isScheduled && !isDueNow ? " disabled" : "";

        if (isDueNow) {
            timingLabel = "Due Now";
        }

        newCard.className =
            "incoming-card new-booking" +
            (isScheduled ? " incoming-card--scheduled" : "");
        newCard.setAttribute("data-id", data.id);
        newCard.setAttribute("data-queue", queueType);
        newCard.setAttribute(
            "data-service-mode",
            isScheduled ? "schedule" : "book_now",
        );
        newCard.setAttribute("data-scheduled-for", data.scheduled_for || "");
        newCard.setAttribute(
            "data-created-at",
            data.created_at || new Date().toISOString(),
        );

        var thumbHtml = data.vehicle_image_url
            ? '<img class="incoming-vehicle-thumb" src="' +
              escapeHtml(data.vehicle_image_url) +
              '" alt="Vehicle photo">'
            : "";

        newCard.innerHTML =
            '<div class="incoming-left">' +
            thumbHtml +
            '<div class="incoming-route">' +
            "<strong>" +
            escapeHtml(data.pickup_address || "Unknown Pickup") +
            "</strong>" +
            '<span class="arrow">→</span>' +
            "<span>" +
            escapeHtml(data.dropoff_address || "Unknown Dropoff") +
            "</span>" +
            "</div>" +
            '<div class="incoming-details">' +
            "<span><strong>Customer:</strong> " +
            escapeHtml(data.customer_name || "Guest") +
            "</span>" +
            "<span><strong>Phone:</strong> " +
            escapeHtml(data.customer_phone || "N/A") +
            "</span>" +
            "<span><strong>Vehicle:</strong> " +
            escapeHtml(data.truck_type_name || "Unknown") +
            "</span>" +
            "</div>" +
            '<div class="incoming-meta">' +
            '<span class="time">' +
            escapeHtml(data.created_at_human || "Just now") +
            "</span>" +
            '<span class="queue-chip ' +
            (isDueNow ? "due-now" : queueType) +
            '">' +
            escapeHtml(timingLabel) +
            "</span>" +
            '<span class="status-badge ' +
            statusTone +
            '">' +
            escapeHtml(statusLabel) +
            "</span>" +
            "</div>" +
            "</div>" +
            '<div class="incoming-actions">' +
            '<button type="button" class="btn-accept" data-id="' +
            data.id +
            '" data-action="accept"' +
            reviewButtonDisabled +
            ">" +
            escapeHtml(reviewButtonLabel) +
            "</button>" +
            '<button type="button" class="btn-reject" data-id="' +
            data.id +
            '" data-action="reject">Reject</button>' +
            "</div>";

        insertBookingInQueueOrder(queueList, newCard, data.created_at);

        if (typeof window.applyDispatchQueueFilter === "function") {
            window.applyDispatchQueueFilter(state.activeFilter || "book-now");
        } else {
            updateFilteredCount();
            updateEmptyState();
        }

        if (typeof updateAllWaitTimes === "function") updateAllWaitTimes();
        if (typeof updateReturnBanner === "function") updateReturnBanner();

        setTimeout(function () {
            newCard.classList.remove("new-booking");
        }, 3000);
    }

    function insertBookingInQueueOrder(container, newCard, createdAt) {
        var newCardTime = new Date(createdAt || new Date()).getTime();
        var existingCards = container.querySelectorAll(".incoming-card");
        var insertBeforeElement = null;

        Array.prototype.some.call(existingCards, function (card) {
            var cardTime = new Date(
                card.getAttribute("data-created-at") || "1970-01-01",
            ).getTime();

            if (newCardTime < cardTime) {
                insertBeforeElement = card;
                return true;
            }

            return false;
        });

        if (insertBeforeElement) {
            container.insertBefore(newCard, insertBeforeElement);
        } else {
            container.appendChild(newCard);
        }
    }

    function startLocationPolling() {
        var unitMarkers = {};
        var lastUnits = [];
        var activeSortLat = null, activeSortLng = null;
        var activeZoneFilter = null;

        function getZoneFilteredUnits() {
            if (!activeZoneFilter) return lastUnits;
            return lastUnits.filter(function (u) { return u.zone_name === activeZoneFilter; });
        }

        // Chips are derived purely from whichever zones currently have an
        // online unit — never a hand-maintained list, so a zone with nobody
        // online simply never shows a chip.
        function renderZoneChips(units) {
            var container = document.getElementById("zoneFilterChips");
            if (!container) return;

            var zones = Array.from(new Set(
                units.map(function (u) { return u.zone_name; }).filter(Boolean)
            )).sort();

            if (activeZoneFilter && zones.indexOf(activeZoneFilter) === -1) {
                activeZoneFilter = null;
            }

            if (!zones.length) {
                container.innerHTML = "";
                return;
            }

            var chipsHtml = '<button type="button" class="zone-chip' +
                (!activeZoneFilter ? " is-active" : "") + '" data-zone="">All</button>';
            zones.forEach(function (zone) {
                chipsHtml += '<button type="button" class="zone-chip' +
                    (activeZoneFilter === zone ? " is-active" : "") + '" data-zone="' +
                    escapeHtml(zone) + '">' + escapeHtml(zone) + "</button>";
            });
            container.innerHTML = chipsHtml;

            container.querySelectorAll(".zone-chip").forEach(function (chip) {
                chip.addEventListener("click", function () {
                    activeZoneFilter = chip.dataset.zone || null;
                    applyZoneFilter();
                });
            });
        }

        function applyZoneFilter() {
            renderZoneChips(lastUnits);
            updateTrackingMeta(lastUnits);
            Object.keys(unitMarkers).forEach(function (id) {
                var u = lastUnits.find(function (x) { return String(x.unit_id) === String(id); });
                var visible = !activeZoneFilter || (u && u.zone_name === activeZoneFilter);
                unitMarkers[id].marker.setVisible(visible);
            });
            renderRoster(getZoneFilteredUnits(), activeSortLat, activeSortLng);
        }

        function gpsAgeLabel(secsAgo) {
            if (secsAgo === null || secsAgo === undefined) return { text: "no gps", cls: "urc-gps--old" };
            if (secsAgo < 30)  return { text: "updated " + secsAgo + "s ago", cls: "urc-gps--live" };
            if (secsAgo < 300) return { text: "updated " + Math.round(secsAgo / 60) + "m ago", cls: "urc-gps--recent" };
            return { text: "gps offline", cls: "urc-gps--old" };
        }

        function haversineKm(lat1, lng1, lat2, lng2) {
            var R = 6371;
            var dLat = (lat2 - lat1) * Math.PI / 180;
            var dLng = (lng2 - lng1) * Math.PI / 180;
            var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                    Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                    Math.sin(dLng / 2) * Math.sin(dLng / 2);
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        }

        function renderRoster(units, sortLat, sortLng) {
            var roster = document.getElementById("trackingRoster");
            if (!roster) return;

            var withDist = units.map(function (u) {
                var dist = (sortLat && sortLng && u.lat && u.lng)
                    ? haversineKm(sortLat, sortLng, u.lat, u.lng)
                    : null;
                return Object.assign({}, u, { _dist: dist });
            });

            if (sortLat && sortLng) {
                withDist.sort(function (a, b) {
                    if (a._dist === null && b._dist === null) return 0;
                    if (a._dist === null) return 1;
                    if (b._dist === null) return -1;
                    return a._dist - b._dist;
                });
            }

            roster.innerHTML = withDist.map(function (u) {
                var gps = gpsAgeLabel(u.updated_seconds_ago);
                // Real, granular job status (from the TL's live booking) — never
                // the stale Unit.status column, which only ever changes at
                // coarse assignment/override/completion moments and would
                // otherwise stay stuck on "available" for the whole job.
                var hasJob = !!u.job_status;
                var statusCls = hasJob ? "urc-status--on_job" : "urc-status--available";
                var statusText = hasJob ? u.job_status_label : "Available";
                var distHtml = u._dist !== null
                    ? '<div class="urc-distance">' + u._dist.toFixed(1) + " km from pickup</div>"
                    : "";
                var plateLine = u.plate_number ? " · " + u.plate_number : "";
                var typeLine  = u.truck_type_name || "";

                return '<div class="unit-roster-card" data-unit-id="' + u.unit_id + '" data-lat="' + (u.lat || "") + '" data-lng="' + (u.lng || "") + '">' +
                    '<div class="urc-name">' + (u.unit_name || "Unit") + plateLine + "</div>" +
                    '<div class="urc-tl">' + (u.team_leader_name || "") + (typeLine ? " — " + typeLine : "") + "</div>" +
                    '<div class="urc-row">' +
                      '<span class="urc-status ' + statusCls + '">' + statusText + "</span>" +
                      '<span class="urc-gps ' + gps.cls + '">' + gps.text + "</span>" +
                    "</div>" +
                    distHtml +
                "</div>";
            }).join("");

            // Click a unit card → pan map to that unit
            roster.querySelectorAll(".unit-roster-card").forEach(function (card) {
                card.addEventListener("click", function () {
                    var lat = parseFloat(card.dataset.lat);
                    var lng = parseFloat(card.dataset.lng);
                    if (liveMap && !isNaN(lat) && !isNaN(lng)) {
                        liveMap.setCenter({ lat: lat, lng: lng });
                        liveMap.setZoom(14);
                    }
                });
            });

            var label = document.getElementById("rosterSortLabel");
            if (label) {
                label.textContent = (sortLat && sortLng) ? "nearest to pickup" : "all units";
            }
        }

        // Always reflects the true total (never the zone-filtered subset),
        // so the header summary doesn't make it look like fewer units are
        // online than really are just because a zone filter is active.
        function updateTrackingMeta(units) {
            var onlineCount = units.filter(function (u) { return u.is_online; }).length;
            var meta = document.getElementById("trackingMeta");
            if (meta) {
                meta.textContent = units.length + " unit" + (units.length !== 1 ? "s" : "") + " · " + onlineCount + " online";
            }
        }

        // Exposed for booking card click handler
        window.sortRosterByPickup = function (lat, lng) {
            activeSortLat = lat;
            activeSortLng = lng;
            renderRoster(getZoneFilteredUnits(), lat, lng);
        };

        function poll() {
            fetch("/admin-dashboard/units/locations", {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            })
            .then(function (r) { return r.json(); })
            .then(function (units) {
                lastUnits = units;

                // Update map markers — the map itself is loaded once (see init
                // above); each poll tick only moves/creates/removes markers.
                units.forEach(function (u) {
                    if (!u.lat || !u.lng) return;
                    var tooltipText = (u.unit_name || "Unit") + " · " + u.team_leader_name +
                        (u.job_status_label ? " — " + u.job_status_label : "");
                    var position = { lat: u.lat, lng: u.lng };
                    if (liveMap) {
                        if (unitMarkers[u.unit_id]) {
                            unitMarkers[u.unit_id].marker.setPosition(position);
                            unitMarkers[u.unit_id].infoWindow.setContent(tooltipText);
                        } else {
                            var marker = new google.maps.Marker({
                                position: position,
                                map: liveMap,
                                icon: {
                                    path: google.maps.SymbolPath.CIRCLE,
                                    scale: 7,
                                    fillColor: "#FFCC14",
                                    fillOpacity: 1,
                                    strokeColor: "#111",
                                    strokeWeight: 1.5,
                                },
                            });
                            var infoWindow = new google.maps.InfoWindow({
                                content: tooltipText,
                                disableAutoPan: true,
                            });
                            marker.addListener("mouseover", function () {
                                infoWindow.open({ anchor: marker, map: liveMap });
                            });
                            marker.addListener("mouseout", function () {
                                infoWindow.close();
                            });
                            unitMarkers[u.unit_id] = { marker: marker, infoWindow: infoWindow };
                        }
                    }
                });

                // Remove stale markers
                var activeIds = units.map(function (u) { return u.unit_id; });
                Object.keys(unitMarkers).forEach(function (id) {
                    if (!activeIds.includes(Number(id))) {
                        unitMarkers[id].marker.setMap(null);
                        unitMarkers[id].infoWindow.close();
                        delete unitMarkers[id];
                    }
                });

                applyZoneFilter();
            })
            .catch(function () {});
        }

        poll(); // run immediately on load
        window.setInterval(poll, 10000);
    }

    function startPolling() {
        if (state.pollingInterval) {
            window.clearInterval(state.pollingInterval);
        }

        state.pollingInterval = window.setInterval(checkForNewBookings, 8000);
    }

    function handleCustomerInquiry(data) {
        var customerName = data.customer_name || "Customer";
        var message      = data.message || "";
        var bookingCode  = data.booking_code ? " · " + data.booking_code : "";

        if (window.dispatcherNotifications) {
            window.dispatcherNotifications.add({
                title: customerName + " asked about their price" + bookingCode,
                body:  message,
                time:  "Just now",
            });
        }

        showNotification(customerName + " sent a price inquiry", "info");
    }

    function handleBookingUpdate(data) {
        var isCompleted = data.status === "completed";
        var isReturned = data.is_returned === true;
        var isConfirmed = data.status === "confirmed" && !isReturned;

        if (isConfirmed) {
            showNotification(
                "Customer accepted quotation. Booking is now in active queue.",
                "success",
            );
            refreshFloatingQuotationsPanel();
            return;
        }

        if (isReturned) {
            // Task returned by team leader — needs dispatcher reassignment
            var tlName = data.team_leader_name || "Team Leader";
            var jobCode = data.booking_code || data.id || "Job";
            var returnReason = data.return_reason || "No reason provided.";

            if (
                window.dispatcherNotifications &&
                typeof window.dispatcherNotifications.add === "function"
            ) {
                window.dispatcherNotifications.add({
                    title: "↩ Job " + jobCode + " returned by " + tlName,
                    body:
                        "Reason: " +
                        returnReason +
                        " — unit is now available for reassignment.",
                    time: data.updated_at_human || "Just now",
                });
            }

            showNotification(
                "Job " +
                    jobCode +
                    " was returned by " +
                    tlName +
                    ". Ready for reassignment.",
                "error",
            );
        } else if (isCompleted) {
            // Prominent alert for job completion — TL is now available
            var tlName = data.team_leader_name || "Team Leader";
            var unitName = data.unit_name || "Unit";
            var jobCode = data.booking_code || data.id || "Job";

            if (
                window.dispatcherNotifications &&
                typeof window.dispatcherNotifications.add === "function"
            ) {
                window.dispatcherNotifications.add({
                    title: "✅ Job " + jobCode + " completed — unit available",
                    body:
                        tlName +
                        " (" +
                        unitName +
                        ") has finished the job and is now available for the next dispatch.",
                    time: data.updated_at_human || "Just now",
                });
            }

            showNotification(
                tlName +
                    " completed job " +
                    jobCode +
                    ". Unit " +
                    unitName +
                    " is now available.",
                "success",
            );
        } else {
            if (
                window.dispatcherNotifications &&
                typeof window.dispatcherNotifications.add === "function"
            ) {
                window.dispatcherNotifications.add({
                    title: (data.booking_code || data.id || "Job") + " updated",
                    body:
                        (data.team_leader_name || "Field crew") +
                        " is now " +
                        (data.status_label || data.status || "active") +
                        ".",
                    time: data.updated_at_human || "Just now",
                });
            }
        }

        checkForNewBookings();
    }

    function checkForNewBookings() {
        fetch("/admin-dashboard/pending-bookings-count", {
            headers: {
                "X-Requested-With": "XMLHttpRequest",
            },
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                var countElement = document.getElementById("requestCount");
                var currentCount = countElement
                    ? parseInt(countElement.textContent, 10) || 0
                    : 0;
                var serverCount = Number(payload.count) || 0;

                var scheduledEl = document.querySelector('[data-count-for="scheduled"]');
                var currentScheduled = scheduledEl
                    ? parseInt(scheduledEl.textContent, 10) || 0
                    : 0;
                var serverScheduled = Number(payload.scheduled_count) || 0;

                if (serverCount > currentCount || serverScheduled > currentScheduled) {
                    // Real-time updates (handleNewBooking) normally handle this
                    // already — this poller is only a fallback for missed events,
                    // so just sync the counters instead of a full page reload
                    // (which would re-trigger a billable Google Maps API load).
                    if (countElement) countElement.textContent = String(serverCount);
                    if (scheduledEl) scheduledEl.textContent = String(serverScheduled);
                }
            })
            .catch(function () {
                return null;
            });
    }

    function getAssignUrl(bookingId) {
        var urlTemplate = queueList.getAttribute("data-assign-url-template");

        if (urlTemplate) {
            return urlTemplate.replace("__BOOKING__", bookingId);
        }

        return "/admin-dashboard/booking/" + bookingId + "/assign";
    }

    function reviewBooking(
        bookingId,
        action,
        button,
        rejectionReason,
        quotedPrice,
        dispatcherNote,
        assignedUnitId,
        distanceKm,
        distanceFee,
        discountPercentage,
    ) {
        if (!button) {
            showNotification("Error: Button reference lost.", "error");
            return;
        }

        var originalText = button.textContent;
        var csrfNode = document.querySelector('meta[name="csrf-token"]');
        var headers = {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
        };

        if (csrfNode) {
            headers["X-CSRF-TOKEN"] = csrfNode.getAttribute("content") || "";
        }

        var isReturnedTask =
            state.selectedCard &&
            state.selectedCard.getAttribute("data-queue") === "returned";

        var _cbStatus = state.selectedCard && state.selectedCard.getAttribute("data-status");
        var isConfirmedBooking = _cbStatus === "confirmed" || _cbStatus === "scheduled_confirmed";
        button.textContent =
            action === "accept"
                ? isReturnedTask
                    ? "Reassigning..."
                    : isConfirmedBooking
                      ? "Starting job..."
                      : "Sending quote..."
                : isReturnedTask
                  ? "Cancelling..."
                  : "Rejecting...";
        button.disabled = true;

        fetch(getAssignUrl(bookingId), {
            method: "POST",
            headers: headers,
            body: JSON.stringify({
                action: action,
                price: quotedPrice,
                additional_fee: quotedPrice,
                assigned_unit_id: assignedUnitId,
                distance_km: distanceKm,
                distance_fee: parseNumericPrice(distanceFee).toFixed(2),
                discount_percentage: discountPercentage,
                remarks: dispatcherNote,
                dispatcher_note: dispatcherNote,
                rejection_reason: rejectionReason,
                reason: rejectionReason,
            }),
        })
            .then(function (response) {
                return response
                    .json()
                    .catch(function () {
                        return {};
                    })
                    .then(function (data) {
                        return {
                            ok: response.ok,
                            data: data,
                        };
                    });
            })
            .then(function (result) {
                if (!result.ok || !result.data.success) {
                    throw new Error(
                        result.data.message ||
                            "Failed to process booking action.",
                    );
                }

                var card = button.closest(".incoming-card");
                if (card) {
                    card.style.transition = "opacity 0.3s";
                    card.style.opacity = "0";
                    setTimeout(function () {
                        card.remove();
                        updateQueueCount(-1);
                        updateTabBadges();
                        updateEmptyState();
                    }, 300);
                }

                resetBookingState();
                updateQueueCount(-1);
                updateEmptyState();

                if (
                    action === "accept" &&
                    window.dispatcherNotifications &&
                    typeof window.dispatcherNotifications.add === "function"
                ) {
                    window.dispatcherNotifications.add({
                        title: isReturnedTask
                            ? "Task reassigned for Booking #" + bookingId
                            : isConfirmedBooking
                              ? "Job started for Booking #" + bookingId
                              : "Quotation sent for Booking #" + bookingId,
                        body: isReturnedTask
                            ? "Dispatch reassigned the returned task to a ready field unit."
                            : isConfirmedBooking
                              ? "The job has been assigned. Team leader can now accept the task."
                              : "Dispatch reviewed the request and emailed the quotation to the customer for approval.",
                        time: "Just now",
                    });
                }

                showNotification(
                    result.data.message || "Booking action completed.",
                    action === "accept" ? "success" : "error",
                );

                // After starting a job (confirmed booking), redirect to active jobs page
                if (action === "accept" && isConfirmedBooking) {
                    setTimeout(function () {
                        window.location.href = "/admin-dashboard/jobs";
                    }, 1200);
                    return;
                }

                // After sending a quotation, redirect to the assigned team leader's card on the drivers page
                if (
                    action === "accept" &&
                    !isReturnedTask &&
                    result.data.drivers_url &&
                    result.data.assigned_team_leader_id
                ) {
                    setTimeout(function () {
                        window.location.href =
                            result.data.drivers_url +
                            "?focus=" +
                            result.data.assigned_team_leader_id;
                    }, 900);
                }
            })
            .catch(function (error) {
                button.textContent = originalText;
                button.disabled = false;
                resetBookingState();
                showNotification(
                    error.message || "Error processing booking action.",
                    "error",
                );
            });
    }

    function updateQueueCount() {
        updateFilteredCount();
    }

    function updateFilteredCount() {
        var countElement = document.getElementById("requestCount");

        if (!countElement) {
            return;
        }

        var visibleCards = Array.prototype.filter.call(
            queueList.querySelectorAll(".incoming-card"),
            function (card) {
                return !card.classList.contains("is-hidden");
            },
        ).length;

        countElement.textContent = String(visibleCards);
    }

    function updateEmptyState() {
        var emptyState = document.getElementById("emptyState");
        var visibleCards = Array.prototype.filter.call(
            queueList.querySelectorAll(".incoming-card"),
            function (card) {
                return !card.classList.contains("is-hidden");
            },
        ).length;

        if (!emptyState) {
            if (!visibleCards) {
                emptyState = document.createElement("div");
                emptyState.className = "empty-state";
                emptyState.id = "emptyState";
                emptyState.innerHTML =
                    "<p>No bookings in this queue right now.</p>";
                queueList.appendChild(emptyState);
            }
            return;
        }

        var message = "No bookings in this queue right now.";

        if (state.activeFilter === "scheduled") {
            message = "No scheduled bookings are waiting yet.";
        } else if (state.activeFilter === "delayed") {
            message = "No delayed bookings are waiting right now.";
        } else if (state.activeFilter === "negotiation") {
            message = "No negotiation requests need review right now.";
        } else if (state.activeFilter === "returned") {
            message = "No returned tasks need reassignment right now.";
        } else if (state.activeFilter === "ready_completion") {
            message =
                "No tasks are awaiting completion confirmation right now.";
        } else if (state.activeFilter === "book-now") {
            message = "No urgent Book Now requests are waiting right now.";
        }

        var copy = emptyState.querySelector("p");
        if (copy) {
            copy.textContent = message;
        }

        emptyState.style.display = visibleCards ? "none" : "block";

        if (!visibleCards && !queueList.contains(emptyState)) {
            queueList.appendChild(emptyState);
        }
    }

    function showNotification(message, type) {
        var notification = document.createElement("div");
        notification.className =
            "notification notification-" + (type || "info");
        notification.textContent = message;

        document.body.appendChild(notification);

        window.setTimeout(function () {
            notification.classList.add("show");
        }, 100);

        window.setTimeout(function () {
            notification.classList.remove("show");
            window.setTimeout(function () {
                if (document.body.contains(notification)) {
                    document.body.removeChild(notification);
                }
            }, 300);
        }, 3000);
    }

    function openActionModal() {
        var modalTitle = document.getElementById("modalTitle");
        var modalText = document.getElementById("modalText");
        var rejectReasonWrapper = document.getElementById(
            "rejectReasonWrapper",
        );
        var priceWrapper = document.getElementById("priceWrapper");
        var dispatcherNoteWrapper = document.getElementById(
            "dispatcherNoteWrapper",
        );
        var negotiationHint = document.getElementById("negotiationHint");
        var negotiationHintText = document.getElementById(
            "negotiationHintText",
        );
        if (!actionModal || !modalTitle || !modalText || !rejectReasonWrapper) {
            if (state.selectedButton) {
                reviewBooking(
                    state.selectedBookingId,
                    state.selectedAction,
                    state.selectedButton,
                    null,
                    priceInput ? priceInput.value.trim() : null,
                    dispatcherNoteInput
                        ? dispatcherNoteInput.value.trim()
                        : null,
                    unitSelect ? unitSelect.value : null,
                );
            }
            return;
        }

        if (state.selectedAction === "accept") {
            var currentStatus = state.selectedCard
                ? state.selectedCard.getAttribute("data-status")
                : "requested";
            var isReturnedTask = state.selectedCard
                ? state.selectedCard.getAttribute("data-queue") === "returned"
                : false;
            var currentPrice = state.selectedCard
                ? state.selectedCard.getAttribute("data-current-price")
                : "";
            var currentAdditional = state.selectedCard
                ? state.selectedCard.getAttribute("data-current-additional")
                : "";
            var customerNote = state.selectedCard
                ? state.selectedCard.getAttribute("data-customer-note")
                : "";
            var counterOffer = state.selectedCard
                ? state.selectedCard.getAttribute("data-counter-offer")
                : "";
            var currentDispatcherNote = state.selectedCard
                ? state.selectedCard.getAttribute("data-dispatcher-note")
                : "";
            var returnReason = state.selectedCard
                ? state.selectedCard.getAttribute("data-return-reason")
                : "";
            var returnedBy = state.selectedCard
                ? state.selectedCard.getAttribute("data-returned-by")
                : "";
            var assignedUnitId = state.selectedCard
                ? state.selectedCard.getAttribute("data-assigned-unit")
                : "";
            var recommendedUnitId = state.selectedCard
                ? state.selectedCard.getAttribute("data-recommended-unit")
                : "";
            var dispatchZone = state.selectedCard
                ? state.selectedCard.getAttribute("data-dispatch-zone")
                : "General Dispatch Zone";
            var recommendedSummary = state.selectedCard
                ? state.selectedCard.getAttribute("data-recommended-summary")
                : "";

            var counterOfferValue = parseNumericPrice(counterOffer);
            var currentPriceValue = parseNumericPrice(currentPrice);
            var currentAdditionalValue = parseNumericPrice(currentAdditional);
            var suggestedPrice =
                currentAdditionalValue > 0 ? currentAdditionalValue : 0;

            state.reviewData = {
                truckType: state.selectedCard
                    ? state.selectedCard.getAttribute("data-truck-type")
                    : "Unknown",
                distanceKm: parseNumericPrice(
                    state.selectedCard
                        ? state.selectedCard.getAttribute("data-distance-km")
                        : 0,
                ),
                baseRate: parseNumericPrice(
                    state.selectedCard
                        ? state.selectedCard.getAttribute("data-base-rate")
                        : 0,
                ),
                perKmRate: parseNumericPrice(
                    state.selectedCard
                        ? state.selectedCard.getAttribute("data-per-km-rate")
                        : 0,
                ),
                distanceFee: parseNumericPrice(
                    state.selectedCard
                        ? state.selectedCard.getAttribute("data-distance-fee")
                        : 0,
                ),
                discount: parseNumericPrice(
                    state.selectedCard
                        ? state.selectedCard.getAttribute("data-discount")
                        : 0,
                ),
                discountRate: parseNumericPrice(
                    state.selectedCard
                        ? state.selectedCard.getAttribute("data-discount-rate")
                        : 0,
                ),
                customerType: state.selectedCard
                    ? state.selectedCard.getAttribute("data-customer-type")
                    : "Regular",
                dispatchZone: dispatchZone || "General Dispatch Zone",
                recommendedSummary: recommendedSummary || "",
            };

            modalTitle.innerText = isReturnedTask
                ? "Reassign Returned Task"
                : (currentStatus === "confirmed" || currentStatus === "scheduled_confirmed")
                  ? "Start Job"
                  : currentStatus === "reviewed"
                    ? "Update Quotation"
                    : "Review & Send Quotation";
            modalText.innerText = isReturnedTask
                ? "This booking was returned from the field. Choose a ready unit so dispatch can reassign it immediately."
                : (currentStatus === "confirmed" || currentStatus === "scheduled_confirmed")
                  ? "The customer has already accepted the quotation. Select a unit to assign and start the job immediately."
                  : "Review the automatic pricing, add an optional dispatcher adjustment, and reserve a ready unit for the team leader.";
            if ((currentStatus === "confirmed" || currentStatus === "scheduled_confirmed") && !isReturnedTask) {
                if (confirmedBookingPanel) {
                    var cfCustomerName =
                        state.selectedCard.getAttribute("data-customer-name") ||
                        "—";
                    var cfPhone =
                        state.selectedCard.getAttribute(
                            "data-customer-phone",
                        ) || "—";
                    var cfPickup =
                        state.selectedCard.getAttribute("data-pickup") || "";
                    var cfDropoff =
                        state.selectedCard.getAttribute("data-dropoff") || "";
                    var cfKm = parseNumericPrice(
                        state.selectedCard.getAttribute("data-distance-km") ||
                            0,
                    );
                    var cfTotal = parseNumericPrice(
                        state.selectedCard.getAttribute("data-current-price") ||
                            0,
                    );
                    var cfTruck =
                        state.selectedCard.getAttribute("data-truck-type") ||
                        "—";
                    var cfNameEl = document.getElementById("cfCustomerName");
                    var cfPhoneEl = document.getElementById("cfCustomerPhone");
                    var cfRouteEl = document.getElementById("cfRoute");
                    var cfTruckEl = document.getElementById("cfTruckType");
                    var cfDistEl = document.getElementById("cfDistance");
                    var cfTotalEl = document.getElementById("cfAgreedTotal");
                    if (cfNameEl) cfNameEl.textContent = cfCustomerName;
                    if (cfPhoneEl) cfPhoneEl.textContent = cfPhone;
                    if (cfRouteEl)
                        cfRouteEl.textContent =
                            cfPickup + (cfDropoff ? " → " + cfDropoff : "");
                    if (cfTruckEl) cfTruckEl.textContent = cfTruck;
                    if (cfDistEl)
                        cfDistEl.textContent =
                            cfKm > 0 ? cfKm.toFixed(2) + " km" : "—";
                    if (cfTotalEl)
                        cfTotalEl.textContent =
                            cfTotal > 0
                                ? "₱" + formatCurrencyValue(cfTotal)
                                : "—";

                    var recommendedUnitId =
                        state.selectedCard.getAttribute(
                            "data-recommended-unit",
                        ) || "";
                    var cfUnitBox = document.getElementById("cfUnitBox");
                    var cfUnitName = document.getElementById("cfUnitName");
                    var cfUnitType = document.getElementById("cfUnitType");
                    var cfUnitTl = document.getElementById("cfUnitTl");

                    if (recommendedUnitId && unitSelect) {
                        var matchOpt = unitSelect.querySelector(
                            "option[value='" + recommendedUnitId + "']",
                        );
                        if (matchOpt) {
                            unitSelect.value = recommendedUnitId;
                            var optText = matchOpt.textContent.trim();
                            var optTl =
                                matchOpt.getAttribute("data-team-leader") ||
                                "—";
                            var typeMatch = optText.match(
                                /\b(heavy|medium|light)\b/i,
                            );
                            var unitType = typeMatch
                                ? typeMatch[0].charAt(0).toUpperCase() +
                                  typeMatch[0].slice(1).toLowerCase()
                                : cfTruck;
                            var unitLabel = optText.split("·")[0].trim();
                            if (cfUnitName) cfUnitName.textContent = unitLabel;
                            if (cfUnitType) cfUnitType.textContent = unitType;
                            if (cfUnitTl) cfUnitTl.textContent = optTl;
                            if (cfUnitBox) cfUnitBox.style.display = "block";
                        } else {
                            if (cfUnitBox) cfUnitBox.style.display = "none";
                        }
                    } else {
                        if (cfUnitBox) cfUnitBox.style.display = "none";
                    }

                    confirmedBookingPanel.style.display = "block";
                }
                if (quotationReviewGrid)
                    quotationReviewGrid.style.display = "none";
                if (priceWrapper) priceWrapper.style.display = "none";
                if (unitWrapper) unitWrapper.style.display = "block";
            } else {
                if (confirmedBookingPanel)
                    confirmedBookingPanel.style.display = "none";
                if (quotationReviewGrid)
                    quotationReviewGrid.style.display = "grid";
                if (priceWrapper) priceWrapper.style.display = "block";
                if (unitWrapper) unitWrapper.style.display = "block";
            }
            if (dispatcherNoteWrapper)
                dispatcherNoteWrapper.style.display = "block";
            rejectReasonWrapper.style.display = "none";
            if (negotiationHint && negotiationHintText) {
                if (isReturnedTask && (returnReason || returnedBy)) {
                    negotiationHint.style.display = "block";
                    negotiationHintText.innerText =
                        "Returned by " +
                        (returnedBy || "the team leader") +
                        ": " +
                        (returnReason || "Needs reassignment.");
                } else if (customerNote || counterOffer) {
                    negotiationHint.style.display = "block";
                    negotiationHintText.innerText =
                        "Customer request: " +
                        (counterOffer
                            ? "Counter-offer ₱" + counterOffer + ". "
                            : "") +
                        (customerNote ||
                            "Please review the latest adjustment.");
                } else {
                    negotiationHint.style.display = "none";
                    negotiationHintText.innerText = "";
                }
            }
            if (distanceInput) {
                distanceInput.value =
                    state.reviewData.distanceKm > 0
                        ? (state.reviewData.distanceKm || 0).toFixed(2)
                        : "";
                clearFieldError(distanceInput);
            }
            if (distanceFeeInput) {
                distanceFeeInput.value = formatPriceInputValue(
                    (state.reviewData.distanceFee || 0).toFixed(2),
                    true,
                );
                clearFieldError(distanceFeeInput);
            }
            if (discountPercentInput) {
                discountPercentInput.value =
                    state.reviewData.discountRate > 0
                        ? (state.reviewData.discountRate || 0).toFixed(2)
                        : "0.00";
                clearFieldError(discountPercentInput);
                syncDiscountInputState();
            }
            if (priceInput) {
                priceInput.value =
                    suggestedPrice > 0
                        ? formatPriceInputValue(suggestedPrice.toFixed(2), true)
                        : "";
                clearFieldError(priceInput);
                updatePriceHelper(
                    priceInput.value,
                    counterOfferValue,
                    currentPriceValue,
                );
                if (currentStatus !== "confirmed" && currentStatus !== "scheduled_confirmed") {
                    window.setTimeout(function () {
                        priceInput.focus();
                        priceInput.select();
                    }, 30);
                } else {
                    window.setTimeout(function () {
                        if (unitSelect) unitSelect.focus();
                    }, 30);
                }
            }
            if (unitSelect) {
                var preferredUnitId = assignedUnitId || recommendedUnitId || "";
                unitSelect.value = preferredUnitId;

                // Float the pre-selected unit to the top of the list so it's immediately visible
                if (preferredUnitId) {
                    var placeholder = unitSelect.options[0];
                    var matchedIndex = -1;
                    for (var oi = 0; oi < unitSelect.options.length; oi++) {
                        if (
                            String(unitSelect.options[oi].value) ===
                            String(preferredUnitId)
                        ) {
                            matchedIndex = oi;
                            break;
                        }
                    }
                    if (matchedIndex > 1) {
                        var matchedOption = unitSelect.options[matchedIndex];
                        unitSelect.removeChild(matchedOption);
                        // Insert right after the blank placeholder (index 0)
                        unitSelect.insertBefore(
                            matchedOption,
                            unitSelect.options[1] || null,
                        );
                        unitSelect.value = preferredUnitId;
                    }
                }

                clearFieldError(unitSelect);
                updateUnitHelper();
            }
            if (dispatcherNoteInput) {
                dispatcherNoteInput.value = currentDispatcherNote || "";
            }

            var dispatchZoneDisplay = document.getElementById(
                "dispatchZoneDisplay",
            );
            if (dispatchZoneDisplay && state.reviewData) {
                dispatchZoneDisplay.value =
                    state.reviewData.dispatchZone || "General Dispatch Zone";
            }

            if (confirmActionBtn) {
                confirmActionBtn.textContent = isReturnedTask
                    ? "Reassign Task"
                    : (currentStatus === "confirmed" || currentStatus === "scheduled_confirmed")
                      ? "Start Job"
                      : currentStatus === "reviewed"
                        ? "Update Quote"
                        : "Send Quote";
            }
            var saveDraftBtn = document.getElementById("saveDraftBtn");
            if (saveDraftBtn) {
                saveDraftBtn.style.display = (!isReturnedTask && currentStatus !== "confirmed" && currentStatus !== "scheduled_confirmed") ? "inline-block" : "none";
            }
            updateQuotationPreview(priceInput ? priceInput.value : "");
            updateConfirmButtonState();
        } else {
            modalTitle.innerText = "Reject Booking";
            modalText.innerText =
                "This will email the customer with the rejection reason and close the request.";
            state.reviewData = null;
            if (confirmedBookingPanel)
                confirmedBookingPanel.style.display = "none";
            if (quotationReviewGrid) quotationReviewGrid.style.display = "none";
            if (priceWrapper) priceWrapper.style.display = "none";
            if (unitWrapper) unitWrapper.style.display = "none";
            if (dispatcherNoteWrapper)
                dispatcherNoteWrapper.style.display = "none";
            if (negotiationHint) negotiationHint.style.display = "none";
            rejectReasonWrapper.style.display = "block";
            if (confirmActionBtn) {
                confirmActionBtn.textContent = "Reject Booking";
                confirmActionBtn.disabled = false;
            }
            var saveDraftBtn2 = document.getElementById("saveDraftBtn");
            if (saveDraftBtn2) saveDraftBtn2.style.display = "none";
            if (rejectReasonInput) {
                window.setTimeout(function () {
                    rejectReasonInput.focus();
                }, 30);
            }
        }

        actionModal.classList.remove("hidden");
        actionModal.classList.add("is-open");
        actionModal.setAttribute("aria-hidden", "false");
        document.body.classList.add("modal-open");
    }

    function closeActionModal() {
        if (actionModal) {
            actionModal.classList.remove("is-open");
            actionModal.classList.add("hidden");
            actionModal.setAttribute("aria-hidden", "true");
        }

        document.body.classList.remove("modal-open");

        if (rejectReasonInput) {
            rejectReasonInput.value = "";
        }

        if (priceInput) {
            priceInput.value = "";
            clearFieldError(priceInput);
        }

        if (distanceInput) {
            distanceInput.value = "";
            clearFieldError(distanceInput);
        }

        if (distanceFeeInput) {
            distanceFeeInput.value = "";
            clearFieldError(distanceFeeInput);
        }

        if (discountPercentInput) {
            discountPercentInput.value = "";
            discountPercentInput.disabled = false;
            discountPercentInput.readOnly = false;
            discountPercentInput.classList.remove("is-locked");
            discountPercentInput.setAttribute("aria-disabled", "false");
            clearFieldError(discountPercentInput);
        }

        if (dispatcherNoteInput) {
            dispatcherNoteInput.value = "";
        }

        if (unitSelect) {
            unitSelect.value = "";
            clearFieldError(unitSelect);
            updateUnitHelper();
        }

        if (confirmedBookingPanel) confirmedBookingPanel.style.display = "none";
        if (unitWrapper) unitWrapper.style.display = "none";

        state.reviewData = null;
        clearValidationSummary();

        if (confirmActionBtn) {
            confirmActionBtn.disabled = false;
        }

        // Reset booking state when modal is cancelled
        resetBookingState();
    }

    function resetBookingState() {
        state.selectedBookingId = null;
        state.selectedAction = null;
        state.selectedButton = null;
        state.selectedCard = null;
    }

    function handleSaveAsDraft() {
        if (state.selectedAction !== "accept") return;
        if (!state.selectedBookingId) {
            showNotification("Please select a booking first.", "error");
            return;
        }

        var quotedPrice = priceInput ? priceInput.value.trim() : "";
        var dispatcherNote = dispatcherNoteInput ? dispatcherNoteInput.value.trim() : "";
        var selectedUnitId = unitSelect ? unitSelect.value : "";
        var distanceKm = distanceInput ? distanceInput.value.trim() : "";

        if (!distanceKm || parseFloat(distanceKm) <= 0) {
            showNotification("Distance is required before saving as draft.", "error");
            if (distanceInput) distanceInput.focus();
            return;
        }

        var bookingId = state.selectedBookingId;
        var btn = document.getElementById("saveDraftBtn");
        if (btn) { btn.disabled = true; btn.textContent = "Saving..."; }

        var csrfNode = document.querySelector('meta[name="csrf-token"]');
        var headers = { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" };
        if (csrfNode) headers["X-CSRF-TOKEN"] = csrfNode.getAttribute("content") || "";

        var distanceFeeVal = parseFloat(distanceFeeInput ? distanceFeeInput.value : 0);
        var baseRate = getSelectedUnitBaseRate();
        var extraDist = Math.max(0, parseFloat(distanceKm) - 4);
        var dFee = roundValue(extraDist * getSelectedUnitPerKmRate());
        var computedPrice = roundValue((baseRate + dFee) * 1.12);
        var price = parseNumericPrice(quotedPrice) > 0 ? parseNumericPrice(quotedPrice) : computedPrice;

        fetch("/admin-dashboard/booking/" + bookingId + "/save-draft", {
            method: "POST",
            headers: headers,
            body: JSON.stringify({
                price: price,
                additional_fee: 0,
                assigned_unit_id: selectedUnitId || null,
                dispatcher_note: dispatcherNote || null,
                distance_km: parseFloat(distanceKm),
            }),
        })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showNotification(data.message || "Quotation saved as draft.", "success");
                    closeActionModal();

                    var card = state.selectedCard;
                    if (card) {
                        card.style.transition = "opacity 0.3s";
                        card.style.opacity = "0";
                        setTimeout(function () {
                            card.remove();
                            updateQueueCount(-1);
                            updateTabBadges();
                            updateEmptyState();
                        }, 300);
                    }
                    resetBookingState();

                    refreshFloatingQuotationsPanel();
                } else {
                    showNotification(data.message || "Failed to save draft.", "error");
                    if (btn) { btn.disabled = false; btn.textContent = "Save as Draft"; }
                }
            })
            .catch(function() {
                showNotification("Error saving draft.", "error");
                if (btn) { btn.disabled = false; btn.textContent = "Save as Draft"; }
            });
    }

    function handleModalConfirm() {
        var reason = rejectReasonInput ? rejectReasonInput.value.trim() : "";
        var quotedPrice = priceInput ? priceInput.value.trim() : "";
        var dispatcherNote = dispatcherNoteInput
            ? dispatcherNoteInput.value.trim()
            : "";
        var selectedUnitId = unitSelect ? unitSelect.value : "";
        var distanceKm = distanceInput ? distanceInput.value.trim() : "";
        var distanceFee = distanceFeeInput ? distanceFeeInput.value.trim() : "";
        var discountPercentage = discountPercentInput
            ? discountPercentInput.value.trim()
            : "";
        var parsedQuote = parseNumericPrice(quotedPrice);

        if (state.selectedAction === "reject" && !reason) {
            showNotification(
                "Please enter a rejection reason before rejecting the booking.",
                "error",
            );

            if (rejectReasonInput) {
                rejectReasonInput.focus();
            }
            return;
        }

        if (state.selectedAction === "accept" && !validateAcceptForm(true)) {
            showNotification(
                "Please complete the required quotation details before sending.",
                "error",
            );
            return;
        }

        if (!state.selectedBookingId || !state.selectedButton) {
            showNotification("Please select a booking first.", "error");
            closeActionModal();
            return;
        }

        confirmActionBtn.disabled = true;

        // Store references before closing modal
        var bookingId = state.selectedBookingId;
        var action = state.selectedAction;
        var button = state.selectedButton;

        closeActionModal();

        reviewBooking(
            bookingId,
            action,
            button,
            reason || null,
            parsedQuote > 0 ? parsedQuote.toFixed(2) : null,
            dispatcherNote || null,
            selectedUnitId || null,
            distanceKm || null,
            distanceFee || null,
            discountPercentage || null,
        );
    }

    function parseNumericPrice(value) {
        var parsed = parseFloat(String(value || "").replace(/[^\d.]/g, ""));

        return Number.isFinite(parsed) ? parsed : 0;
    }

    function formatPriceInputValue(value, forceTwoDecimals) {
        var sanitized = String(value || "")
            .replace(/[^\d.]/g, "")
            .replace(/\.(?=.*\.)/g, "");

        if (!sanitized) {
            return "";
        }

        var parts = sanitized.split(".");
        var integerPart = (parts[0] || "0").replace(/^0+(?=\d)/, "");
        var decimalPart = (parts[1] || "").slice(0, 2);
        var formattedInteger = integerPart.replace(
            /\B(?=(\d{3})+(?!\d))/g,
            ",",
        );

        if (forceTwoDecimals) {
            return (
                formattedInteger + "." + (decimalPart || "00").padEnd(2, "0")
            );
        }

        return decimalPart.length > 0
            ? formattedInteger + "." + decimalPart
            : formattedInteger;
    }

    function countDigitsBeforeCursor(input) {
        var cursor = input.selectionStart || 0;

        return input.value.slice(0, cursor).replace(/\D/g, "").length;
    }

    function restoreCursorByDigitCount(input, digitCount) {
        if (typeof input.setSelectionRange !== "function") {
            return;
        }

        var nextPosition = 0;
        var digitsSeen = 0;

        while (nextPosition < input.value.length && digitsSeen < digitCount) {
            if (/\d/.test(input.value.charAt(nextPosition))) {
                digitsSeen += 1;
            }
            nextPosition += 1;
        }

        input.setSelectionRange(nextPosition, nextPosition);
    }

    function formatCurrencyValue(value) {
        return new Intl.NumberFormat("en-PH", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(parseNumericPrice(value));
    }

    function formatNumberValue(value) {
        return new Intl.NumberFormat("en-PH", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(Number.isFinite(Number(value)) ? Number(value) : 0);
    }

    function roundValue(value) {
        return Math.round((Number(value) || 0) * 100) / 100;
    }

    function updatePriceHelper(value, counterOffer, currentPrice) {
        if (!priceHelper) {
            return;
        }

        var parsedValue = parseNumericPrice(value);
        var counterOfferValue = parseNumericPrice(counterOffer);
        var currentPriceValue = parseNumericPrice(currentPrice);

        if (parsedValue > 0) {
            priceHelper.textContent =
                "Additional dispatcher fee: ₱" +
                formatCurrencyValue(parsedValue);
        } else if (counterOfferValue > 0) {
            priceHelper.textContent =
                "Customer counter-offer: ₱" +
                formatCurrencyValue(counterOfferValue);
        } else if (currentPriceValue > 0) {
            priceHelper.textContent =
                "Current final quote: ₱" +
                formatCurrencyValue(currentPriceValue);
        } else {
            priceHelper.textContent =
                "Leave blank to keep the auto-computed quotation total.";
        }

        updateQuotationPreview(value);
    }

    function setText(id, value) {
        var element = document.getElementById(id);

        if (element) {
            element.textContent = value;
        }
    }

    function updateUnitHelper() {
        if (!unitSelect || !unitHelper) {
            return;
        }

        var selectedOption = unitSelect.options[unitSelect.selectedIndex];

        if (!selectedOption || !selectedOption.value) {
            unitHelper.textContent =
                "Choose a unit first. Only online, ready team leaders are listed here.";
            return;
        }

        var statusSummary = selectedOption.getAttribute("data-summary") || "";
        var teamLeaderName =
            selectedOption.getAttribute("data-team-leader") || "No team leader";
        var driverName =
            selectedOption.getAttribute("data-driver") || "No saved driver";
        var coverageZones = selectedOption.getAttribute("data-zones") || "";
        var bookingZone =
            (state.reviewData && state.reviewData.dispatchZone) ||
            "General Dispatch Zone";
        var zoneSummary = coverageZones
            ? "Coverage: " + coverageZones
            : "No saved zone history yet.";

        if (coverageZones && coverageZones.indexOf(bookingZone) !== -1) {
            zoneSummary =
                "Recommended for " + bookingZone + " · " + zoneSummary;
        }

        if (state.reviewData && state.reviewData.recommendedSummary) {
            zoneSummary =
                state.reviewData.recommendedSummary + " · " + zoneSummary;
        }
    }

    function updateQuotationPreview(value) {
        if (!state.reviewData) {
            return;
        }

        var additionalFee = parseNumericPrice(value);
        var distanceKm = parseFloat(distanceInput ? distanceInput.value : 0);
        var discountRate = parseFloat(
            discountPercentInput ? discountPercentInput.value : 0,
        );

        distanceKm =
            Number.isFinite(distanceKm) && distanceKm > 0 ? distanceKm : 0;
        discountRate =
            Number.isFinite(discountRate) && discountRate >= 0
                ? discountRate
                : 0;

        var extraDistance = Math.max(0, distanceKm - 4);
        var selectedUnitPerKmRate = getSelectedUnitPerKmRate();
        var distanceFee = roundValue(extraDistance * selectedUnitPerKmRate);
        var selectedUnitBaseRate = getSelectedUnitBaseRate();
        var computedTotal = roundValue(selectedUnitBaseRate + distanceFee);
        var discountAmount = roundValue(computedTotal * (discountRate / 100));
        var subtotal = Math.max(roundValue(computedTotal - discountAmount + additionalFee), 0);
        var finalTotal = roundValue(subtotal * 1.12);

        state.reviewData.distanceKm = distanceKm;
        state.reviewData.distanceFee = distanceFee;
        state.reviewData.discountRate = discountRate;
        state.reviewData.discount = discountAmount;

        if (distanceFeeInput) {
            distanceFeeInput.value = formatPriceInputValue(
                distanceFee.toFixed(2),
                true,
            );
        }

        setText("summaryTruckType", state.reviewData.truckType || "Unknown");
        setText("summaryDistanceKm", formatNumberValue(distanceKm) + " km");
        setText(
            "summaryBaseRate",
            unitSelect && unitSelect.value
                ? "₱" + formatCurrencyValue(selectedUnitBaseRate)
                : "TBD (assign unit first)",
        );
        setText(
            "summaryPerKmRate",
            formatNumberValue(extraDistance) +
                " km extra × ₱" +
                formatCurrencyValue(selectedUnitPerKmRate),
        );
        setText(
            "summaryCustomerType",
            state.reviewData.customerType || "Regular",
        );
        setText(
            "summaryBaseFee",
            unitSelect && unitSelect.value
                ? "₱" + formatCurrencyValue(selectedUnitBaseRate)
                : "TBD",
        );
        setText("summaryDistanceFee", "₱" + formatCurrencyValue(distanceFee));
        setText("summaryDiscount", "- ₱" + formatCurrencyValue(discountAmount));
        setText(
            "summaryAdditionalFee",
            "₱" + formatCurrencyValue(additionalFee),
        );
        setText("summaryFinalTotal", "₱" + formatCurrencyValue(finalTotal));

        syncDiscountInputState();

        if (discountLabel) {
            if (isRegularCustomerType()) {
                discountLabel.textContent =
                    "Regular customer selected. Discount is locked and cannot be edited.";
            } else if (discountAmount > 0) {
                discountLabel.textContent =
                    (state.reviewData.customerType || "Customer") +
                    " discount is open and currently set to " +
                    formatNumberValue(discountRate) +
                    "%.";
            } else {
                discountLabel.textContent =
                    "PWD or Senior selected. You can enter the discount percentage here.";
            }
        }

        if (discountBadge) {
            discountBadge.textContent =
                "- ₱" + formatCurrencyValue(discountAmount);
        }

        if (finalTotalPreview) {
            finalTotalPreview.textContent =
                "₱" + formatCurrencyValue(finalTotal);
        }
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function toggleOps(id) {
        const el = document.getElementById("ops-" + id);
        if (!el) return;

        el.style.display = el.style.display === "none" ? "block" : "none";
    }

    // Return feature functions
    function handleReturnReject(bookingId, button) {
        var card = button.closest(".incoming-card");
        var returnReason = card ? card.getAttribute("data-return-reason") : "";

        state.selectedButton = button;
        state.selectedCard = card;
        state.selectedBookingId = bookingId;
        state.selectedAction = "reject";

        openActionModal();

        if (rejectReasonInput && returnReason) {
            rejectReasonInput.value = "Cancelled after return: " + returnReason;
        }
    }

    function handleReturnReassign(bookingId, button) {
        var card = button.closest(".incoming-card");
        var returnReason = card ? card.getAttribute("data-return-reason") : "";
        var returnedBy = card ? card.getAttribute("data-returned-by") : "";

        state.selectedButton = button;
        state.selectedCard = card;
        state.selectedBookingId = bookingId;
        state.selectedAction = "accept";

        openActionModal();

        if (dispatcherNoteInput && returnReason) {
            dispatcherNoteInput.value =
                "Reassigned after return by " +
                (returnedBy || "team leader") +
                ": " +
                returnReason;
        }
    }

    window.handleReturnReject = handleReturnReject;
    window.handleReturnReassign = handleReturnReassign;

    // ── Wait time badges ──────────────────────────────────────────────────────
    function initWaitTimeBadges() {
        updateAllWaitTimes();
        window.setInterval(updateAllWaitTimes, 60000);
    }

    function updateAllWaitTimes() {
        document.querySelectorAll("[data-created-at]").forEach(function (card) {
            var badge = card.querySelector("[data-wait]");
            if (!badge) return;
            var created = new Date(card.dataset.createdAt);
            if (isNaN(created.getTime())) return;
            var mins = Math.floor((Date.now() - created.getTime()) / 60000);
            badge.textContent =
                mins < 1 ? "just now" :
                mins < 60 ? mins + " min" :
                Math.floor(mins / 60) + "h " + (mins % 60) + "min";
            badge.className =
                "wait-badge " +
                (mins > 30 ? "wait-urgent" : mins > 10 ? "wait-warn" : "wait-ok");
        });
    }

    // ── Return task alert banner ──────────────────────────────────────────────
    function updateReturnBanner() {
        var count = document.querySelectorAll("[data-queue=\"returned\"]").length;
        var banner = document.getElementById("returnAlertBanner");
        var text   = document.getElementById("returnAlertText");
        if (!banner || !text) return;
        if (count > 0) {
            text.textContent = count === 1
                ? "1 task was returned and needs reassignment."
                : count + " tasks were returned and need reassignment.";
            banner.style.display = "flex";
        } else {
            banner.style.display = "none";
        }
    }

    window.showReturnedQueue = function () {
        var tab = document.querySelector("[data-filter=\"returned\"]");
        if (tab) tab.click();
        var banner = document.getElementById("returnAlertBanner");
        if (banner) banner.style.display = "none";
    };

    window.updateReturnBanner = updateReturnBanner;
    window.updateAllWaitTimes = updateAllWaitTimes;
});

// ── Customer Booking Slide-out Panel ─────────────────────────────────────────
function openCustomerBookingPanel(cardEl) {
    if (!cardEl) return;

    const siblings  = JSON.parse(cardEl.getAttribute('data-group-siblings') || '[]');
    const groupCode = cardEl.getAttribute('data-group-code') || '';
    const customer  = cardEl.getAttribute('data-customer') || '—';
    const phone     = cardEl.getAttribute('data-phone') || '—';
    const ref       = cardEl.getAttribute('data-ref') || cardEl.getAttribute('data-id') || '—';
    const price     = cardEl.getAttribute('data-final-total') || '0';
    const truck     = cardEl.getAttribute('data-truck') || '—';
    const status    = cardEl.getAttribute('data-status') || '—';
    const queue     = cardEl.getAttribute('data-queue') || 'book-now';

    document.getElementById('cbpGroupCode').textContent = groupCode || ref;
    document.getElementById('cbpCustomerInfo').innerHTML =
        '<div style="font-size:0.88rem; font-weight:600; color:#0f172a;">' + customer + '</div>' +
        '<div style="font-size:0.82rem; color:#64748b;">' + phone + '</div>';

    const allBookings = [
        { booking_code: ref, truck_type: truck, status: status,
          service_type: queue === 'scheduled' ? 'schedule' : 'book_now',
          final_total: price, scheduled_date: null, scheduled_time: null, is_primary: true },
        ...siblings.map(function(s) { return Object.assign({}, s, { is_primary: false }); })
    ];
    allBookings.sort(function(a, b) { return a.service_type === 'book_now' ? -1 : 1; });

    var statusColors = {
        scheduled:      { bg:'#f1f5f9', c:'#475569', t:'Scheduled' },
        scheduled_confirmed: { bg:'#dcfce7', c:'#15803d', t:'Confirmed Sched.' },
        in_progress:    { bg:'#fef9c3', c:'#a16207', t:'In Progress' },
        completed:      { bg:'#dcfce7', c:'#15803d', t:'Completed' },
        cancelled:      { bg:'#fee2e2', c:'#dc2626', t:'Cancelled' },
        requested:      { bg:'#eff6ff', c:'#1d4ed8', t:'Pending Quote' },
        quoted:         { bg:'#eff6ff', c:'#1d4ed8', t:'Quoted' },
        quotation_sent: { bg:'#fef3c7', c:'#92400e', t:'Sent to Customer' },
        confirmed:      { bg:'#dcfce7', c:'#15803d', t:'Confirmed' },
        pending:        { bg:'#f1f5f9', c:'#475569', t:'Pending' },
        sent:           { bg:'#fef3c7', c:'#92400e', t:'Sent' },
        negotiating:    { bg:'#f3e8ff', c:'#7e22ce', t:'Negotiating' },
    };

    document.getElementById('cbpBookingsList').innerHTML = allBookings.map(function(b) {
        var sc = statusColors[b.status] || { bg:'#f1f5f9', c:'#475569', t: b.status };
        var modeLabel = b.service_type === 'schedule' ? 'Scheduled' : 'Book Now';
        var modeBg    = b.service_type === 'schedule' ? '#e0f2fe' : '#dcfce7';
        var modeFg    = b.service_type === 'schedule' ? '#075985' : '#15803d';
        var priceStr  = parseFloat(b.final_total || 0) > 0
            ? '₱' + parseFloat(b.final_total).toLocaleString('en-PH', { minimumFractionDigits:2, maximumFractionDigits:2 })
            : '—';
        var schedLine = b.scheduled_date
            ? '<div style="font-size:0.75rem; color:#64748b; margin-top:3px;">' + fmtCbpDate(b.scheduled_date, b.scheduled_time) + '</div>'
            : '';
        return '<div style="border:1px solid #e2e8f0; padding:12px 14px;">' +
            '<div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">' +
                '<span style="font-family:monospace; font-weight:700; font-size:0.88rem; color:#0f172a;">' + b.booking_code + '</span>' +
                '<span style="font-size:0.65rem; font-weight:700; padding:2px 7px; background:' + modeBg + '; color:' + modeFg + ';">' + modeLabel + '</span>' +
                '<span style="margin-left:auto; font-size:0.68rem; font-weight:700; padding:2px 8px; border-radius:999px; background:' + sc.bg + '; color:' + sc.c + ';">' + sc.t + '</span>' +
            '</div>' +
            '<div style="font-size:0.82rem; color:#374151;">' + (b.truck_type || '—') + '</div>' +
            schedLine +
            '<div style="margin-top:6px; font-size:0.88rem; font-weight:700; color:#0f172a;">' + priceStr + '</div>' +
        '</div>';
    }).join('');

    document.getElementById('customerBookingPanel').style.right = '0';
    document.getElementById('customerBookingOverlay').style.display = 'block';
}

function closeCustomerBookingPanel() {
    document.getElementById('customerBookingPanel').style.right = '-420px';
    document.getElementById('customerBookingOverlay').style.display = 'none';
}

function fmtCbpDate(date, time) {
    if (!date) return '';
    var parts = date.split('-').map(Number);
    var y = parts[0], m = parts[1], d = parts[2];
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var s = months[m - 1] + ' ' + d + ', ' + y;
    if (time) {
        var tp = time.split(':').map(Number);
        var h = tp[0], min = tp[1];
        var ampm = h >= 12 ? 'PM' : 'AM';
        var hr = h % 12 || 12;
        s += ' · ' + hr + ':' + String(min).padStart(2, '0') + ' ' + ampm;
    }
    return s;
}
