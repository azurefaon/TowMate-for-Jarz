document.addEventListener("DOMContentLoaded", function () {
    var state = {
        selectedBookingId: null,
        selectedAction: null,
        selectedButton: null,
        selectedCard: null,
        pollingInterval: null,
    };

    var actionModal = document.getElementById("actionModal");
    var queueList = document.getElementById("incomingList");
    var confirmActionBtn = document.getElementById("confirmActionBtn");
    var cancelModalBtn = document.getElementById("cancelModalBtn");
    var rejectReasonInput = document.getElementById("rejectReasonInput");
    var priceInput = document.getElementById("priceInput");
    var priceHelper = document.getElementById("priceHelper");
    var dispatcherNoteInput = document.getElementById("dispatcherNoteInput");

    if (!queueList) {
        return;
    }

    if (actionModal) {
        actionModal.classList.add("hidden");
        actionModal.classList.remove("is-open");
        actionModal.setAttribute("aria-hidden", "true");
    }

    initializeViewToggle();
    initializeRealtimeUpdates();
    initializePriceInput();

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
            if (event.target === actionModal) {
                closeActionModal();
            }
        });
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeActionModal();
        }
    });

    function initializeViewToggle() {
        var viewButtons = document.querySelectorAll(".view-btn");

        if (!viewButtons.length) {
            return;
        }

        var savedView = localStorage.getItem("dispatchView") || "list";
        setView(savedView);

        Array.prototype.forEach.call(viewButtons, function (button) {
            button.addEventListener("click", function () {
                var view = this.getAttribute("data-view") || "list";
                setView(view);
                localStorage.setItem("dispatchView", view);
            });
        });

        function setView(view) {
            Array.prototype.forEach.call(viewButtons, function (button) {
                var isActive = button.getAttribute("data-view") === view;
                button.classList.toggle("active", isActive);
            });

            if (view === "grid") {
                queueList.classList.add("grid");
            } else {
                queueList.classList.remove("grid");
            }
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
            updatePriceHelper(this.value);
        });

        priceInput.addEventListener("blur", function () {
            var parsed = parseNumericPrice(this.value);

            if (parsed > 0) {
                this.value = formatPriceInputValue(parsed.toFixed(2), true);
            }

            updatePriceHelper(this.value);
        });
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

        var button = target.closest(".btn-accept, .btn-reject, [data-action]");

        if (!button || !queueList.contains(button)) {
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
        newCard.className = "incoming-card new-booking";
        newCard.setAttribute("data-id", data.id);
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
            '<span class="status-badge pending">Pending</span>' +
            "</div>" +
            "</div>" +
            '<div class="incoming-actions">' +
            '<button type="button" class="btn-accept" data-id="' +
            data.id +
            '" data-action="accept">Review & Quote</button>' +
            '<button type="button" class="btn-reject" data-id="' +
            data.id +
            '" data-action="reject">Reject</button>' +
            "</div>";

        insertBookingInQueueOrder(queueList, newCard, data.created_at);
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
    ) {
        var originalText = button.textContent;
        var csrfNode = document.querySelector('meta[name="csrf-token"]');
        var headers = {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
        };

        if (csrfNode) {
            headers["X-CSRF-TOKEN"] = csrfNode.getAttribute("content") || "";
        }

        button.textContent =
            action === "accept" ? "Sending quote..." : "Rejecting...";
        button.disabled = true;

        fetch(getAssignUrl(bookingId), {
            method: "POST",
            headers: headers,
            body: JSON.stringify({
                action: action,
                price: quotedPrice,
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
                    card.remove();
                }

                updateQueueCount(-1);
                updateEmptyState();

                if (
                    action === "accept" &&
                    window.dispatcherNotifications &&
                    typeof window.dispatcherNotifications.add === "function"
                ) {
                    window.dispatcherNotifications.add({
                        title: "Quotation sent for Booking #" + bookingId,
                        body: "Dispatch reviewed the request and emailed the quotation to the customer for approval.",
                        time: "Just now",
                    });
                }

                showNotification(
                    result.data.message || "Booking action completed.",
                    action === "accept" ? "success" : "error",
                );
            })
            .catch(function (error) {
                button.textContent = originalText;
                button.disabled = false;
                showNotification(
                    error.message || "Error processing booking action.",
                    "error",
                );
            });
    }

    function updateQueueCount(change) {
        var countElement = document.getElementById("requestCount");
        if (!countElement) {
            return;
        }

        var currentCount = parseInt(countElement.textContent, 10) || 0;
        countElement.textContent = String(Math.max(0, currentCount + change));
    }

    function updateEmptyState() {
        var emptyState = document.getElementById("emptyState");
        var hasCards = queueList.querySelectorAll(".incoming-card").length > 0;

        if (!emptyState) {
            if (!hasCards) {
                emptyState = document.createElement("div");
                emptyState.className = "empty-state";
                emptyState.id = "emptyState";
                emptyState.innerHTML = "<p>No incoming requests</p>";
                queueList.appendChild(emptyState);
            }
            return;
        }

        emptyState.style.display = hasCards ? "none" : "block";

        if (!hasCards && !queueList.contains(emptyState)) {
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
        var modalIcon = document.getElementById("modalIcon");

        if (
            !actionModal ||
            !modalTitle ||
            !modalText ||
            !rejectReasonWrapper ||
            !modalIcon
        ) {
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
                );
            }
            return;
        }

        modalIcon.className = "modal-icon";

        if (state.selectedAction === "accept") {
            var currentStatus = state.selectedCard
                ? state.selectedCard.getAttribute("data-status")
                : "requested";
            var currentPrice = state.selectedCard
                ? state.selectedCard.getAttribute("data-current-price")
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

            var counterOfferValue = parseNumericPrice(counterOffer);
            var currentPriceValue = parseNumericPrice(currentPrice);
            var suggestedPrice =
                currentPriceValue > 0 ? currentPriceValue : counterOfferValue;

            modalTitle.innerText =
                currentStatus === "reviewed"
                    ? "Update Quotation"
                    : "Review & Send Quotation";
            modalText.innerText =
                "Set the final numeric price and send the quote to the customer for approval.";
            if (priceWrapper) {
                priceWrapper.style.display = "block";
            }
            if (dispatcherNoteWrapper) {
                dispatcherNoteWrapper.style.display = "block";
            }
            rejectReasonWrapper.style.display = "none";
            if (negotiationHint && negotiationHintText) {
                if (customerNote || counterOffer) {
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
            if (priceInput) {
                priceInput.value =
                    suggestedPrice > 0
                        ? formatPriceInputValue(suggestedPrice.toFixed(2), true)
                        : "";
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
            if (dispatcherNoteInput) {
                dispatcherNoteInput.value = currentDispatcherNote || "";
            }
            if (confirmActionBtn) {
                confirmActionBtn.textContent =
                    currentStatus === "reviewed"
                        ? "Update Quote"
                        : "Send Quote";
            }
            modalIcon.classList.add("accept");
            modalIcon.innerHTML = "₱";
        } else {
            modalTitle.innerText = "Reject Booking";
            modalText.innerText =
                "This will email the customer with the rejection reason and close the request.";
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
            }
            if (rejectReasonInput) {
                window.setTimeout(function () {
                    rejectReasonInput.focus();
                }, 30);
            }
            modalIcon.classList.add("reject");
            modalIcon.innerHTML = "!";
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
        }

        if (dispatcherNoteInput) {
            dispatcherNoteInput.value = "";
        }

        if (confirmActionBtn) {
            confirmActionBtn.disabled = false;
        }
    }

    function handleModalConfirm() {
        var reason = rejectReasonInput ? rejectReasonInput.value.trim() : "";
        var quotedPrice = priceInput ? priceInput.value.trim() : "";
        var dispatcherNote = dispatcherNoteInput
            ? dispatcherNoteInput.value.trim()
            : "";
        var parsedQuote = parseNumericPrice(quotedPrice);

        if (state.selectedAction === "accept" && parsedQuote <= 0) {
            showNotification(
                "Enter a valid numeric quotation amount before sending it to the customer.",
                "error",
            );

            if (priceInput) {
                priceInput.focus();
            }
            return;
        }

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

        if (!state.selectedBookingId || !state.selectedButton) {
            showNotification("Please select a booking first.", "error");
            closeActionModal();
            return;
        }

        confirmActionBtn.disabled = true;

        closeActionModal();
        reviewBooking(
            state.selectedBookingId,
            state.selectedAction,
            state.selectedButton,
            reason || null,
            parsedQuote > 0 ? parsedQuote.toFixed(2) : null,
            dispatcherNote || null,
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

    function updatePriceHelper(value, counterOffer, currentPrice) {
        if (!priceHelper) {
            return;
        }

        var parsedValue = parseNumericPrice(value);
        var counterOfferValue = parseNumericPrice(counterOffer);
        var currentPriceValue = parseNumericPrice(currentPrice);

        if (parsedValue > 0) {
            priceHelper.textContent =
                "Quote to send: ₱" + formatCurrencyValue(parsedValue);
            return;
        }

        if (counterOfferValue > 0) {
            priceHelper.textContent =
                "Customer counter-offer: ₱" +
                formatCurrencyValue(counterOfferValue);
            return;
        }

        if (currentPriceValue > 0) {
            priceHelper.textContent =
                "Current quote: ₱" + formatCurrencyValue(currentPriceValue);
            return;
        }

        priceHelper.textContent = "";
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});
