document.addEventListener("DOMContentLoaded", function () {
    let pusher = null;
    let pollingInterval = null;

    function initializeViewToggle() {
        const incomingList = document.getElementById("incomingList");
        const viewButtons = document.querySelectorAll(".view-btn");

        const savedView = localStorage.getItem("dispatchView") || "list";
        setView(savedView);

        viewButtons.forEach((button) => {
            button.addEventListener("click", function () {
                const view = this.getAttribute("data-view");
                setView(view);
                localStorage.setItem("dispatchView", view);
            });
        });

        function setView(view) {
            viewButtons.forEach((btn) => {
                btn.classList.toggle(
                    "active",
                    btn.getAttribute("data-view") === view,
                );
            });

            if (view === "grid") {
                incomingList.classList.add("grid");
            } else {
                incomingList.classList.remove("grid");
            }
        }
    }

    initializeViewToggle();

    if (typeof lucide !== "undefined") {
        lucide.createIcons();
    }

    try {
        pusher = new Pusher(window.PusherConfig.key, {
            cluster: window.PusherConfig.cluster,
            encrypted: true,
        });

        const channel = pusher.subscribe("dispatch");
        channel.bind("booking.created", handleNewBooking);
    } catch (error) {
        startPolling();
    }

    function handleNewBooking(data) {
        const countElement = document.getElementById("requestCount");
        const currentCount = parseInt(countElement.textContent) || 0;
        countElement.textContent = currentCount + 1;

        const emptyState = document.getElementById("emptyState");
        if (emptyState) emptyState.style.display = "none";

        if (document.querySelector(`.incoming-card[data-id="${data.id}"]`))
            return;

        const incomingList = document.getElementById("incomingList");
        const newCard = document.createElement("div");
        newCard.className = "incoming-card new-booking";
        newCard.setAttribute("data-id", data.id);
        newCard.setAttribute(
            "data-created-at",
            data.created_at || new Date().toISOString(),
        );

        newCard.innerHTML = `
            <div class="incoming-left">
                <div class="incoming-route">
                    <strong>${data.pickup_address ?? "Unknown Pickup"}</strong>
                    <span class="arrow">→</span>
                    <span>${data.dropoff_address ?? "Unknown Dropoff"}</span>
                </div>
                <div class="incoming-details">
                    <span><strong>Customer:</strong> ${data.customer_name ?? "Guest"}</span>
                    <span><strong>Phone:</strong> ${data.customer_phone ?? "N/A"}</span>
                    <span><strong>Vehicle:</strong> ${data.truck_type_name ?? "Unknown"}</span>
                </div>
                <div class="incoming-meta">
                    <span class="time">${data.created_at_human ?? "Just now"}</span>
                    <span class="status-badge pending">Pending</span>
                </div>
            </div>
            <div class="incoming-actions">
                <button class="btn-accept" data-id="${data.id}">Accept</button>
                <button class="btn-reject" data-id="${data.id}">Reject</button>
            </div>
        `;

        insertBookingInQueueOrder(incomingList, newCard, data.created_at);
        playNotificationSound();
        showNotification("New booking request received!", "success");

        setTimeout(() => newCard.classList.remove("new-booking"), 3000);
    }

    function insertBookingInQueueOrder(container, newCard, createdAt) {
        const newCardTime = new Date(createdAt || new Date()).getTime();
        const existingCards = container.querySelectorAll(".incoming-card");

        let insertBeforeElement = null;

        for (const card of existingCards) {
            const cardTime = new Date(
                card.getAttribute("data-created-at") || "1970-01-01",
            ).getTime();
            if (newCardTime < cardTime) {
                insertBeforeElement = card;
                break;
            }
        }

        if (insertBeforeElement) {
            container.insertBefore(newCard, insertBeforeElement);
        } else {
            container.appendChild(newCard);
        }
    }

    function startPolling() {
        pollingInterval = setInterval(checkForNewBookings, 10000);
    }

    function checkForNewBookings() {
        fetch("/admin/pending-bookings-count")
            .then((response) => response.text())
            .then((count) => {
                const currentCount =
                    parseInt(
                        document.getElementById("requestCount").textContent,
                    ) || 0;

                if (parseInt(count) > currentCount) {
                    location.reload();
                }
            })
            .catch((error) => console.error("Polling error:", error));
    }

    document.addEventListener("click", function (e) {
        const target = e.target;
        if (target.classList.contains("btn-accept")) {
            reviewBooking(target.getAttribute("data-id"), "accept", target);
        } else if (target.classList.contains("btn-reject")) {
            const reason = prompt(
                "Please provide a short reason for rejecting this request.",
            );
            if (reason !== null)
                reviewBooking(
                    target.getAttribute("data-id"),
                    "reject",
                    target,
                    reason,
                );
        }
    });

    function reviewBooking(bookingId, action, button, rejectionReason = null) {
        const confirmationText =
            action === "accept"
                ? "Accept this booking and generate the quotation?"
                : "Reject this booking and cancel the request?";

        if (!confirm(confirmationText)) return;

        const originalText = button.textContent;
        button.textContent =
            action === "accept" ? "Accepting..." : "Rejecting...";
        button.disabled = true;

        fetch(`/admin-dashboard/booking/${bookingId}/assign`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document
                    .querySelector('meta[name="csrf-token"]')
                    .getAttribute("content"),
            },
            body: JSON.stringify({
                action: action,
                rejection_reason: rejectionReason,
            }),
        })
            .then((response) => response.json())
            .then((data) => {
                if (data.success) {
                    const card = button.closest(".incoming-card");
                    if (card) card.remove();

                    const countElement =
                        document.getElementById("requestCount");
                    const currentCount =
                        parseInt(countElement.textContent) || 0;
                    countElement.textContent = Math.max(0, currentCount - 1);

                    const message =
                        action === "accept"
                            ? `Booking accepted. Quotation ${data.quotation_number} generated.`
                            : "Booking rejected and cancelled.";
                    showNotification(
                        message,
                        action === "accept" ? "success" : "error",
                    );
                } else {
                    button.textContent = originalText;
                    button.disabled = false;
                    showNotification(
                        "Failed to process booking action.",
                        "error",
                    );
                }
            })
            .catch((error) => {
                console.error("Error:", error);
                button.textContent = originalText;
                button.disabled = false;
                showNotification("Error processing booking action.", "error");
            });
    }

    function playNotificationSound() {
        try {
            const audioContext = new (
                window.AudioContext || window.webkitAudioContext
            )();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();

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
        } catch (error) {}
    }

    function showNotification(message, type = "info") {
        const notification = document.createElement("div");
        notification.className = `notification notification-${type}`;
        notification.textContent = message;

        document.body.appendChild(notification);

        setTimeout(() => notification.classList.add("show"), 100);
        setTimeout(() => {
            notification.classList.remove("show");
            setTimeout(() => {
                if (document.body.contains(notification))
                    document.body.removeChild(notification);
            }, 300);
        }, 3000);
    }
});
