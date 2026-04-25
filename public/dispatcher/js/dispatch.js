// ===============================
// FIXED GLOBAL CLICK HANDLER
// ===============================
document.addEventListener("click", function (e) {
    const acceptBtn = e.target.closest(".btn-accept");
    const rejectBtn = e.target.closest(".btn-reject");
    const viewBtn = e.target.closest(".btn-view-quote");

    if (acceptBtn) openActionModalHandler(acceptBtn, "accept");
    if (rejectBtn) openActionModalHandler(rejectBtn, "reject");
    if (viewBtn) viewQuotation(viewBtn.dataset.id);
});

// ===============================
// OPEN MODAL + SET STATE
// ===============================
function openActionModalHandler(button, actionType) {
    const card = button.closest(".incoming-card");
    if (!card) return;

    window.currentAction = actionType;
    window.currentBookingId = card.dataset.id;
    window.currentCard = card;

    const modal = document.getElementById("actionModal");
    modal.classList.remove("hidden");

    document.getElementById("confirmActionBtn").disabled = false;

    if (typeof populateModalFromCard === "function") {
        populateModalFromCard(card);
    }
}

// ===============================
// SUBMIT ACTION (ACCEPT / REJECT)
// ===============================
async function submitDispatchAction(bookingId) {
    const payload = {
        action: window.currentAction,
        assigned_unit_id: document.getElementById("unitSelect")?.value || null,
        distance_km: document.getElementById("distanceInput")?.value || null,
        distance_fee: document.getElementById("distanceFeeInput")?.value || 0,
        additional_fee: document.getElementById("additionalFeeInput")?.value || 0,
        dispatcher_note: document.getElementById("dispatcherNoteInput")?.value || "",
        rejection_reason: document.getElementById("rejectReasonInput")?.value || "",
    };

    try {
        const response = await fetch(`/admin-dashboard/booking/${bookingId}/assign`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(payload),
        });

        const data = await response.json();

        if (!response.ok) {
            alert(data.message || "Validation failed.");
            return;
        }

        if (data.success) {
            alert("Quotation sent + email triggered ✅");
            location.reload();
        } else {
            alert(data.message || "Something went wrong");
        }
    } catch (err) {
        console.error(err);
        alert("Server error");
    }
}

document
    .getElementById("confirmActionBtn")
    ?.addEventListener("click", function () {
        if (!window.currentBookingId || !window.currentAction) return;
        submitDispatchAction(window.currentBookingId);
    });


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

    // Discount logic removed

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

    function initializeViewToggle() {
        // No view-toggle UI exists on this page — no-op stub kept for compatibility.
    }

    function initializeQueueFilters() {
        var filterBtns = document.querySelectorAll(".queue-filter-btn");
        var cards = document.querySelectorAll(".incoming-card");

        if (!filterBtns.length) return;

        function applyFilter(filter) {
            filterBtns.forEach(function (btn) {
                btn.classList.toggle("is-active", btn.dataset.filter === filter);
            });
            cards.forEach(function (card) {
                if (filter === "all") {
                    card.style.display = "";
                } else {
                    card.style.display = card.dataset.queue === filter ? "" : "none";
                }
            });
        }

        filterBtns.forEach(function (btn) {
            btn.addEventListener("click", function () {
                applyFilter(this.dataset.filter);
            });
        });

        // Apply the default filter from the list's data attribute, or the first active button
        var list = document.getElementById("incomingList");
        var defaultFilter = (list && list.dataset.defaultFilter) || "returned";
        applyFilter(defaultFilter);
    }
    initializeRealtimeUpdates();
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

    if (confirmActionBtn) {
        confirmActionBtn.addEventListener("click", handleModalConfirm);
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
    // ...existing code...

    // --- POPULATE SUMMARY FROM DATABASE ---
    function populateSummaryFromDB(data) {
        document.getElementById("summaryDistance").innerText =
            data.distance_km + " km";
        document.getElementById("summaryBase").innerText = "₱" + data.base_rate;
        document.getElementById("summaryDistanceFee").innerText =
            "₱" + data.distance_fee;
        document.getElementById("summaryAdditional").innerText =
            "₱" + data.additional_fee;
        document.getElementById("summaryTotal").innerText =
            "₱" + data.final_total;
    }

    // // Call populateModalFromCard when opening modal
    // function openActionModal() {
    //     // ...existing code...
    //     if (state.selectedCard) {
    //         populateModalFromCard(state.selectedCard);
    //     }
    //     // ...existing code...
    // }

    function openActionModal() {
        // ...existing code...

        if (state.selectedCard) {
            populateModalFromCard(state.selectedCard);
        }

        // ...existing code...
    }

    // apply filter
    var savedFilter = localStorage.getItem("dispatchQueueFilter") || state.activeFilter || "returned";
    window.applyDispatchQueueFilter = applyQueueFilter;
    applyQueueFilter(savedFilter);

    function applyQueueFilter(filter) {
        var filterButtons = document.querySelectorAll(".queue-filter-btn");
        var cards = queueList.querySelectorAll(".incoming-card");
        state.activeFilter = filter || "book-now";

        localStorage.setItem("dispatchQueueFilter", state.activeFilter);

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
    }

    function updateTabBadges() {
        var cards = queueList.querySelectorAll(".incoming-card");
        var counts = {
            returned: 0,
            "book-now": 0,
            scheduled: 0,
            delayed: 0,
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
                cluster: window.PusherConfig.cluster,
                encrypted: true,
            });

            var channel = pusher.subscribe("dispatch");
            channel.bind("booking.created", handleNewBooking);
            channel.bind("booking.updated", handleBookingUpdate);
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

    function initializeUnitSelector() {
        if (!unitSelect) {
            return;
        }

        unitSelect.addEventListener("change", function () {
            clearFieldError(this);
            updateUnitHelper();
            updateConfirmButtonState();
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
        var expectedDistanceFee = roundValue(
            distanceValue * (state.reviewData ? state.reviewData.perKmRate : 0),
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

        if (!Number.isFinite(distanceValue) || distanceValue <= 0) {
            rememberError(distanceInput, "Distance is required.");
        }

        if (Math.abs(distanceFeeValue - expectedDistanceFee) > 0.11) {
            rememberError(
                distanceFeeInput,
                "Distance fee must match the distance and per KM rate.",
            );
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

        if (!button || !queueList.contains(button)) {
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

        newCard.innerHTML =
            '<div class="incoming-left">' +
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
            '<div class="incoming-details" style="margin-top: 10px;">' +
            "<span><strong>Dispatch Timing:</strong> " +
            escapeHtml(
                data.schedule_window_label ||
                    (isScheduled ? "Scheduled pickup" : "Immediate dispatch"),
            ) +
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

        playNotificationSound();
        showNotification("New booking request received!", "success");

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

    function startPolling() {
        if (state.pollingInterval) {
            window.clearInterval(state.pollingInterval);
        }

        state.pollingInterval = window.setInterval(checkForNewBookings, 8000);
    }

    function handleBookingUpdate(data) {
        var isCompleted = data.status === "completed";
        var isReturned = data.is_returned === true;

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

            playNotificationSound();
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

            playNotificationSound();
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

                if (serverCount > currentCount) {
                    if (
                        actionModal &&
                        actionModal.classList.contains("is-open")
                    ) {
                        console.log(
                            "New booking detected, but modal is open — skipping reload",
                        );
                        return;
                    }

                    window.location.reload();
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

        button.textContent =
            action === "accept"
                ? isReturnedTask
                    ? "Reassigning..."
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
                            : "Quotation sent for Booking #" + bookingId,
                        body: isReturnedTask
                            ? "Dispatch reassigned the returned task to a ready field unit."
                            : "Dispatch reviewed the request and emailed the quotation to the customer for approval.",
                        time: "Just now",
                    });
                }

                showNotification(
                    result.data.message || "Booking action completed.",
                    action === "accept" ? "success" : "error",
                );

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

    function playNotificationSound() {
        try {
            var AudioContextClass =
                window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) {
                return;
            }

            var audioContext = new AudioContextClass();
            var oscillator = audioContext.createOscillator();
            var gainNode = audioContext.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);

            oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
            oscillator.frequency.setValueAtTime(
                600,
                audioContext.currentTime + 0.1,
            );

            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(
                0.01,
                audioContext.currentTime + 0.3,
            );

            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.3);
        } catch (error) {
            return;
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
                : currentStatus === "reviewed"
                  ? "Update Quotation"
                  : "Review & Send Quotation";
            modalText.innerText = isReturnedTask
                ? "This booking was returned from the field. Choose a ready unit so dispatch can reassign it immediately."
                : "Review the automatic pricing, add an optional dispatcher adjustment, and reserve a ready unit for the team leader.";
            if (quotationReviewGrid) {
                quotationReviewGrid.style.display = "grid";
            }
            if (priceWrapper) {
                priceWrapper.style.display = "block";
            }
            if (dispatcherNoteWrapper) {
                dispatcherNoteWrapper.style.display = "block";
            }
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
                window.setTimeout(function () {
                    priceInput.focus();
                    priceInput.select();
                }, 30);
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
                    : currentStatus === "reviewed"
                      ? "Update Quote"
                      : "Send Quote";
            }
            updateQuotationPreview(priceInput ? priceInput.value : "");
            updateConfirmButtonState();
        } else {
            modalTitle.innerText = "Reject Booking";
            modalText.innerText =
                "This will email the customer with the rejection reason and close the request.";
            state.reviewData = null;
            if (quotationReviewGrid) {
                quotationReviewGrid.style.display = "none";
            }
            if (priceWrapper) {
                priceWrapper.style.display = "none";
            }
            if (dispatcherNoteWrapper) {
                dispatcherNoteWrapper.style.display = "none";
            }
            if (negotiationHint) {
                negotiationHint.style.display = "none";
            }
            rejectReasonWrapper.style.display = "block";
            if (confirmActionBtn) {
                confirmActionBtn.textContent = "Reject Booking";
                confirmActionBtn.disabled = false;
            }
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

        var distanceFee = roundValue(distanceKm * state.reviewData.perKmRate);
        var computedTotal = roundValue(state.reviewData.baseRate + distanceFee);
        var discountAmount = roundValue(computedTotal * (discountRate / 100));
        var finalTotal = Math.max(
            roundValue(computedTotal - discountAmount + additionalFee),
            0,
        );

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
            "₱" + formatCurrencyValue(state.reviewData.baseRate),
        );
        setText(
            "summaryPerKmRate",
            "₱" + formatCurrencyValue(state.reviewData.perKmRate),
        );
        setText(
            "summaryCustomerType",
            state.reviewData.customerType || "Regular",
        );
        setText(
            "summaryBaseFee",
            "₱" + formatCurrencyValue(state.reviewData.baseRate),
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
});
