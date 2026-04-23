const geoConfig = window.bookingGeoConfig || {
    searchUrl: "/geo/search",
    reverseUrl: "/geo/reverse",
    routeUrl: "/geo/route",
    pricingPreviewUrl: "/geo/pricing-preview",
    csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || "",
};

let map;
let previewMap;
let pickupMarker;
let dropMarker;
let previewMarker;
let pickupCoords = null;
let dropCoords = null;
let pickupConfirmed = false;
let dropoffConfirmed = false;
let activeLocationTarget = "pickup";
let debounceTimer;
let routeLayer;
let currentRate = 0;
let currentDistanceKm = 0;
let currentEtaMinutes = 0;
let currentEstimateTotal = 0;
let latestAvailability = { book_now_enabled: true };
let lastPricingSnapshot = null;

function ensureGoogleMapsBridge() {
    // Google Maps bridge is disabled while Leaflet is the active map renderer.
    return;
}

function createPricingSnapshot(overrides = {}) {
    return {
        baseRateText: "₱0.00",
        distanceText: "0 km",
        etaText: "Pending route",
        perKmRateText: "₱0.00",
        distanceFeeText: "₱0.00",
        excessKmText: "0 km",
        excessFeeText: "₱0.00",
        additionalFeeText: "₱0.00",
        totalText: "₱0.00",
        discountAmountText: "₱0.00",
        discountPercentage: 0,
        discountReason: "",
        ...overrides,
    };
}

function applyPricingSnapshot(snapshot) {
    lastPricingSnapshot = createPricingSnapshot(snapshot);

    setText("baseRate", lastPricingSnapshot.baseRateText);
    setText("distance", lastPricingSnapshot.distanceText);
    setText("eta", lastPricingSnapshot.etaText);
    setText("rate", lastPricingSnapshot.perKmRateText);
    setText("distanceFee", lastPricingSnapshot.distanceFeeText);
    setText("excessKm", lastPricingSnapshot.excessKmText);
    setText("excessFee", lastPricingSnapshot.excessFeeText);
    setText("additionalFee", lastPricingSnapshot.additionalFeeText);
    setText("discountAmount", lastPricingSnapshot.discountAmountText);
    setText(
        "discountMeta",
        buildDiscountMetaText(
            lastPricingSnapshot.discountPercentage,
            lastPricingSnapshot.discountReason,
        ),
    );
    setText("price", lastPricingSnapshot.totalText);
}

function getPricingSnapshot() {
    return (
        lastPricingSnapshot ||
        createPricingSnapshot({
            baseRateText:
                document.getElementById("baseRate")?.innerText || "₱0.00",
            distanceText:
                document.getElementById("distance")?.innerText || "0 km",
            etaText:
                document.getElementById("eta")?.innerText || "Pending route",
            perKmRateText:
                document.getElementById("rate")?.innerText || "₱0.00",
            distanceFeeText:
                document.getElementById("distanceFee")?.innerText || "₱0.00",
            excessKmText:
                document.getElementById("excessKm")?.innerText || "0 km",
            excessFeeText:
                document.getElementById("excessFee")?.innerText || "₱0.00",
            additionalFeeText:
                document.getElementById("additionalFee")?.innerText || "₱0.00",
            totalText: document.getElementById("price")?.innerText || "₱0.00",
            discountAmountText:
                document.getElementById("discountAmount")?.innerText || "₱0.00",
        })
    );
}

document.addEventListener("DOMContentLoaded", () => {
    const mapElement = document.getElementById("map");

    if (!mapElement || typeof L === "undefined") {
        return;
    }

    elements = {
        bookingForm: document.getElementById("bookingForm"),
        pickupInput: getElement("pickup", "pickup_address"),
        dropoffInput: getElement("dropoff", "dropoff_address"),
        pickupLatInput: document.getElementById("pickup_lat"),
        pickupLngInput: document.getElementById("pickup_lng"),
        dropLatInput: document.getElementById("drop_lat"),
        dropLngInput: document.getElementById("drop_lng"),
        pickupNotesInput:
            document.getElementById("pickupNotes") ||
            document.querySelector('[name="pickup_notes"]'),
        discountCodeInput:
            document.getElementById("discountCode") ||
            document.querySelector('[name="discount_code"]'),
        vehicleSelect: getElement("vehicleType", "truck_type_id"),
        vehicleCategorySelect: getElement(
            "vehicleCategory",
            "vehicle_category",
        ),
        serviceTypeSelect: getElement("serviceType", "service_type"),
        scheduledDateInput: getElement("scheduledDate", "scheduled_date"),
        scheduledTimeInput: getElement("scheduledTime", "scheduled_time"),
        scheduleFields: document.getElementById("scheduleFields"),
        bookBtn: getElement("bookBtn", "submitBookingBtn"),
        pickupConfirmedInput: document.getElementById("pickupConfirmedInput"),
        dropoffConfirmedInput: document.getElementById("dropoffConfirmedInput"),
        confirmModal: document.getElementById("confirmModal"),
        pickupConfirmModal: document.getElementById("pickupConfirmModal"),
        pickupStatusWrap: document.getElementById("pickupStatusWrap"),
        pickupStatusText: document.getElementById("pickupStatusText"),
        pickupStatusBadge: document.getElementById("pickupStatusBadge"),
        dropoffStatusWrap: document.getElementById("dropoffStatusWrap"),
        dropoffStatusText: document.getElementById("dropoffStatusText"),
        dropoffStatusBadge: document.getElementById("dropoffStatusBadge"),
        locationConfirmTitle: document.getElementById("locationConfirmTitle"),
        locationConfirmSubtitle: document.getElementById(
            "locationConfirmSubtitle",
        ),
        openPickupPicker: document.getElementById("openPickupPicker"),
        openDropoffPicker: document.getElementById("openDropoffPicker"),
        useCurrentLocationBtn: document.getElementById("useCurrentLocationBtn"),
        pickupPreviewAddress: document.getElementById("pickupPreviewAddress"),
        pickupPreviewNotes: document.getElementById("pickupPreviewNotes"),
    };

    const initialLat = parseFloat(elements.pickupLatInput?.value || "14.5995");
    const initialLng = parseFloat(elements.pickupLngInput?.value || "120.9842");

    map = L.map("map").setView([initialLat, initialLng], 13);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "&copy; OpenStreetMap",
    }).addTo(map);

    initClientValidation();
    hydrateStoredCoordinates();
    bindMapEvents();
    bindFormEvents();
    toggleScheduleFields();
    updateLocationStatus(
        "pickup",
        pickupConfirmed,
        pickupConfirmed ? "Pickup location confirmed." : "",
    );
    updateLocationStatus(
        "dropoff",
        dropoffConfirmed,
        dropoffConfirmed ? "Dropoff location confirmed." : "",
    );

    prepareBookingData();
    calculateEstimate();
    updateBookingModeButtonLabel();
    toggleBookBtn();
});

function getElement(...ids) {
    return ids.map((id) => document.getElementById(id)).find(Boolean) || null;
}

function hydrateStoredCoordinates() {
    const pickupLat = parseFloat(elements.pickupLatInput?.value || "");
    const pickupLng = parseFloat(elements.pickupLngInput?.value || "");
    const dropLat = parseFloat(elements.dropLatInput?.value || "");
    const dropLng = parseFloat(elements.dropLngInput?.value || "");

    if (Number.isFinite(pickupLat) && Number.isFinite(pickupLng)) {
        pickupCoords = [pickupLng, pickupLat];
        pickupMarker = buildMarker("pickup", pickupLat, pickupLng);
        map.setView([pickupLat, pickupLng], 15);
    }

    if (Number.isFinite(dropLat) && Number.isFinite(dropLng)) {
        dropCoords = [dropLng, dropLat];
        dropMarker = buildMarker("dropoff", dropLat, dropLng);
    }

    pickupConfirmed =
        elements.pickupConfirmedInput?.value === "1" ||
        Boolean(pickupCoords && elements.pickupInput?.value?.trim());

    dropoffConfirmed =
        elements.dropoffConfirmedInput?.value === "1" ||
        Boolean(dropCoords && elements.dropoffInput?.value?.trim());

    if (pickupCoords && dropCoords) {
        fitBothMarkers();
        updateRoutePreview();
    }
}

function bindMapEvents() {
    map.on("click", async (e) => {
        if (!pickupCoords) {
            await setPickupLocation(e.latlng.lat, e.latlng.lng);
            return;
        }

        await setDropoffLocation(e.latlng.lat, e.latlng.lng);
    });
}

function bindFormEvents() {
    const customerType = document.querySelector('select[name="customer_type"]');

    elements.vehicleSelect?.addEventListener("change", () => {
        calculateEstimate();
        toggleBookBtn();
    });

    elements.vehicleCategorySelect?.addEventListener("change", () => {
        calculateEstimate();
        toggleBookBtn();
    });

    document
        .querySelector('select[name="customer_type"]')
        ?.addEventListener("change", () => {
            calculateEstimate();
            toggleBookBtn();
        });
    elements.serviceTypeSelect?.addEventListener("change", () => {
        clearScheduleFallbackAcceptance();
        toggleScheduleFields();
        updateAvailabilityMessage(latestAvailability);
        updateBookingModeButtonLabel();
        calculateEstimate();
    });

    elements.scheduledDateInput?.addEventListener("change", () => {
        clearScheduleFallbackAcceptance();
        toggleBookBtn();
    });
    elements.scheduledTimeInput?.addEventListener("change", () => {
        clearScheduleFallbackAcceptance();
        toggleBookBtn();
    });

    elements.pickupInput?.addEventListener("input", handleInput);
    elements.dropoffInput?.addEventListener("input", handleInput);
    elements.pickupInput?.addEventListener("paste", hideSuggestions);
    elements.dropoffInput?.addEventListener("paste", hideSuggestions);
    elements.pickupInput?.addEventListener("blur", () => {
        void resolveTypedAddressIfNeeded("pickup");
    });
    elements.dropoffInput?.addEventListener("blur", () => {
        void resolveTypedAddressIfNeeded("dropoff");
    });
    elements.pickupNotesInput?.addEventListener("input", () => {
        clearLocationFieldError(elements.pickupNotesInput);
    });

    elements.discountCodeInput?.addEventListener("input", () => {
        elements.discountCodeInput.value = elements.discountCodeInput.value
            .toUpperCase()
            .replace(/[^A-Z0-9\-\s]/g, "");

        clearLocationFieldError(elements.discountCodeInput);
        calculateEstimate();
    });

    document.addEventListener("click", (e) => {
        const isInsideInput = e.target.closest(".input-map-wrapper");
        const isSuggestion = e.target.closest(".suggestions");

        if (!isInsideInput && !isSuggestion) {
            hideSuggestions();
        }
    });

    elements.openPickupPicker?.addEventListener("click", () =>
        openLocationConfirmModal("pickup"),
    );
    elements.openDropoffPicker?.addEventListener("click", () =>
        openLocationConfirmModal("dropoff"),
    );
    elements.useCurrentLocationBtn?.addEventListener(
        "click",
        movePickerToCurrentLocation,
    );

    document
        .getElementById("confirmPickupBtn")
        ?.addEventListener("click", () => openLocationConfirmModal("pickup"));
    document
        .getElementById("adjustPickupBtn")
        ?.addEventListener("click", () => {
            closePickupConfirmModal();
            markLocationNeedsConfirmation(
                "pickup",
                "Tap the map button to fine-tune the exact roadside spot.",
            );
        });
    document
        .getElementById("confirmDropoffBtn")
        ?.addEventListener("click", () => openLocationConfirmModal("dropoff"));
    document
        .getElementById("adjustDropoffBtn")
        ?.addEventListener("click", () => {
            closePickupConfirmModal();
            markLocationNeedsConfirmation(
                "dropoff",
                "Tap the map button to re-pin the destination.",
            );
        });
    document
        .getElementById("pickupAdjustModalBtn")
        ?.addEventListener("click", closePickupConfirmModal);
    document
        .getElementById("pickupConfirmModalBtn")
        ?.addEventListener("click", confirmActiveLocation);

    if (elements.confirmModal && elements.bookBtn?.id === "bookBtn") {
        elements.bookBtn.addEventListener("click", async () => {
            const form = elements.bookingForm;

            if (!form?.checkValidity()) {
                form?.reportValidity();
                return;
            }

            if (!elements.vehicleSelect?.value) {
                elements.vehicleSelect?.focus();
                elements.vehicleSelect?.reportValidity?.();
                return;
            }

            if (!elements.vehicleCategorySelect?.value) {
                elements.vehicleCategorySelect?.focus();
                elements.vehicleCategorySelect?.reportValidity?.();
                return;
            }

            if (elements.serviceTypeSelect?.value === "schedule") {
                if (!elements.scheduledDateInput?.value) {
                    elements.scheduledDateInput?.focus();
                    elements.scheduledDateInput?.reportValidity?.();
                    return;
                }

                if (!elements.scheduledTimeInput?.value) {
                    elements.scheduledTimeInput?.focus();
                    elements.scheduledTimeInput?.reportValidity?.();
                    return;
                }
            }

            await resolveTypedAddressIfNeeded("pickup");
            await resolveTypedAddressIfNeeded("dropoff");

            if (!pickupCoords || !dropCoords) {
                validateLocationState();
                const firstInvalidLocation = !pickupCoords
                    ? elements.pickupInput
                    : elements.dropoffInput;
                firstInvalidLocation?.focus();
                firstInvalidLocation?.reportValidity();
                return;
            }

            elements.bookBtn.disabled = true;
            setText("availabilityStatus", "Refreshing estimate...");

            await calculateEstimate();

            if (!(await ensureDispatchAvailabilityForBooking())) {
                toggleBookBtn();
                return;
            }

            const totalAmount = parseCurrencyValue(
                document.getElementById("price")?.innerText,
            );

            if (totalAmount <= 0 || currentDistanceKm <= 0) {
                setText("availabilityStatus", "Live estimate unavailable");
                setText(
                    "availabilityNote",
                    "Please confirm different pickup and dropoff points plus the towing setup so the fare can be calculated.",
                );
                toggleBookBtn();
                return;
            }

            prepareBookingData();
            populateBookingSummary();
            showModal(elements.confirmModal);
            toggleBookBtn();
        });
    }

    document.getElementById("cancelBtn")?.addEventListener("click", closeModal);

    document.getElementById("confirmBtn")?.addEventListener("click", () => {
        const btn = document.getElementById("confirmBtn");
        const form = elements.bookingForm;
        const pickupInput = elements.pickupInput;
        const dropoffInput = elements.dropoffInput;

        btn.innerText = "Processing...";
        btn.disabled = true;

        clearLocationFieldError(pickupInput);
        clearLocationFieldError(dropoffInput);

        if (!form?.checkValidity()) {
            closeModal();
            form?.reportValidity();
            btn.innerText =
                elements.serviceTypeSelect?.value === "schedule"
                    ? "Confirm Scheduled Booking"
                    : "Confirm Book Now";
            btn.disabled = false;
            return;
        }

        if (!pickupCoords || !dropCoords) {
            validateLocationState();
            closeModal();

            const firstInvalidLocation = !pickupCoords
                ? pickupInput
                : dropoffInput;
            firstInvalidLocation?.focus();
            firstInvalidLocation?.reportValidity();

            btn.innerText =
                elements.serviceTypeSelect?.value === "schedule"
                    ? "Confirm Scheduled Booking"
                    : "Confirm Book Now";
            btn.disabled = false;
            return;
        }

        prepareBookingData();

        if (typeof form.requestSubmit === "function") {
            form.requestSubmit();
            return;
        }

        form.submit();
    });

    elements.confirmModal?.addEventListener("click", (e) => {
        if (e.target.id === "confirmModal") closeModal();
    });

    elements.pickupConfirmModal?.addEventListener("click", (e) => {
        if (
            e.target.id === "pickupConfirmModal" ||
            e.target === elements.pickupConfirmModal
        ) {
            closePickupConfirmModal();
        }
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
            closeModal();
            closePickupConfirmModal();
        }
    });
}

function initClientValidation() {
    const bookingForm = elements.bookingForm;
    const phoneInput = document.getElementById("customer_phone");
    const imageInput = document.querySelector('input[name="vehicle_image"]');

    window.showBookingFieldError = setFieldError;
    window.clearBookingFieldError = clearFieldError;

    bookingForm
        ?.querySelectorAll("input, select, textarea")
        .forEach((field) => {
            const eventName =
                field.type === "file" || field.tagName === "SELECT"
                    ? "change"
                    : "input";

            field.addEventListener(eventName, () => clearFieldError(field));
        });

    phoneInput?.addEventListener("blur", function () {
        const value = this.value.trim();

        if (/^9\d{9}$/.test(value)) {
            this.value = `0${value}`;
        }

        if (this.value && !/^(09\d{9}|\+639\d{9})$/.test(this.value)) {
            setFieldError(
                this,
                "Please enter a valid Philippine phone number.",
            );
            this.reportValidity();
            return;
        }

        clearFieldError(this);
    });

    imageInput?.addEventListener("change", function () {
        clearFieldError(this);

        const file = this.files?.[0];
        const allowedTypes = ["image/jpeg", "image/png"];

        if (file && !allowedTypes.includes(file.type)) {
            this.value = "";
            setFieldError(
                this,
                "Vehicle image must be a JPG or PNG file only.",
            );
            this.reportValidity();
        }
    });

    elements.discountCodeInput?.addEventListener("blur", function () {
        const value = this.value.trim();

        if (value && !/^[A-Z0-9][A-Z0-9\-\s]{2,49}$/.test(value)) {
            setFieldError(
                this,
                "Use letters, numbers, spaces, or dashes only for the discount code.",
            );
            this.reportValidity();
            return;
        }

        clearFieldError(this);
    });
}

function ensureFieldErrorElement(input) {
    const container =
        input?.closest(".input-group") || input?.closest(".input-wrapper");

    if (!container) {
        return null;
    }

    let errorElement = container.querySelector(".client-error-message");

    if (!errorElement) {
        errorElement = document.createElement("small");
        errorElement.className = "client-error-message";
        errorElement.style.display = "block";
        errorElement.style.marginTop = "6px";
        errorElement.style.color = "#dc2626";
        container.appendChild(errorElement);
    }

    return errorElement;
}

function setFieldError(input, message) {
    if (!input) {
        return;
    }

    input.classList.add("input-error");
    input.setAttribute("aria-invalid", "true");
    input.setCustomValidity(message);

    const errorElement = ensureFieldErrorElement(input);
    if (errorElement) {
        errorElement.textContent = message;
    }
}

function clearFieldError(input) {
    if (!input) {
        return;
    }

    input.classList.remove("input-error");
    input.removeAttribute("aria-invalid");
    input.setCustomValidity("");

    const container =
        input.closest(".input-group") || input.closest(".input-wrapper");
    const errorElement = container?.querySelector(".client-error-message");

    if (errorElement) {
        errorElement.textContent = "";
    }
}

function escapeSummaryValue(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function renderSummarySection(title, items, options = {}) {
    const rows = items
        .filter((item) => item && String(item.value ?? "").trim() !== "")
        .map(
            (item) => `
                <div class="summary-row${item.wide ? " summary-row-wide" : ""}">
                    <span>${escapeSummaryValue(item.label)}</span>
                    <strong>${escapeSummaryValue(item.value)}</strong>
                </div>
            `,
        )
        .join("");

    const totalMarkup = options.totalValue
        ? `
            <div class="summary-total">
                <span>${escapeSummaryValue(options.totalLabel || "Estimated Total")}</span>
                <h2>${escapeSummaryValue(options.totalValue)}</h2>
            </div>
        `
        : "";

    const helperMarkup = options.helperNote
        ? `<p class="summary-helper-note">${escapeSummaryValue(options.helperNote)}</p>`
        : "";

    return `
        <div class="summary-section">
            <div class="summary-section-title">${escapeSummaryValue(title)}</div>
            <div class="summary-grid">
                ${rows}
                ${totalMarkup}
            </div>
            ${helperMarkup}
        </div>
    `;
}

function populateBookingSummary() {
    const firstName =
        document.querySelector('[name="first_name"]')?.value?.trim() || "";
    const middleName =
        document.querySelector('[name="middle_name"]')?.value?.trim() || "";
    const lastName =
        document.querySelector('[name="last_name"]')?.value?.trim() || "";
    const fullName = [firstName, middleName, lastName]
        .filter(Boolean)
        .join(" ");
    const age = document.querySelector('[name="age"]')?.value?.trim() || "";
    const phone = document.querySelector('[name="phone"]')?.value?.trim() || "";
    const email = document.querySelector('[name="email"]')?.value?.trim() || "";
    const customerType =
        document.querySelector('select[name="customer_type"]')
            ?.selectedOptions?.[0]?.text || "Regular";
    const vehicle =
        elements.vehicleSelect?.selectedOptions?.[0]?.text || "Not selected";
    const vehicleCategory =
        elements.vehicleCategorySelect?.selectedOptions?.[0]?.text || "";
    const pickup = elements.pickupInput?.value?.trim() || "";
    const dropoff = elements.dropoffInput?.value?.trim() || "";
    const pickupNote = elements.pickupNotesInput?.value?.trim() || "";
    const notes = document.querySelector('[name="notes"]')?.value?.trim() || "";
    const discountCode = elements.discountCodeInput?.value?.trim() || "";
    const isScheduled =
        (elements.serviceTypeSelect?.value || "book_now") === "schedule";
    const serviceLabel = isScheduled ? "Scheduled booking" : "Book now";
    const scheduledDate = elements.scheduledDateInput?.value || "";
    const scheduledTime = elements.scheduledTimeInput?.value || "";
    const scheduleLabel = isScheduled
        ? [scheduledDate, scheduledTime].filter(Boolean).join(" at ") ||
          "Scheduled request"
        : "Immediate dispatch requested; unit availability will be reviewed.";

    const pricingSnapshot = getPricingSnapshot();
    const distance = pricingSnapshot.distanceText;
    const eta = pricingSnapshot.etaText;
    const baseRate = pricingSnapshot.baseRateText;
    const rate = pricingSnapshot.perKmRateText;
    const distanceFee = pricingSnapshot.distanceFeeText;
    const excessFee = pricingSnapshot.excessFeeText;
    const additionalFee = pricingSnapshot.additionalFeeText;
    const discount = pricingSnapshot.discountAmountText;
    const discountMeta =
        document.getElementById("discountMeta")?.innerText || "";
    const total = pricingSnapshot.totalText;

    const summaryRoot = document.getElementById("bookingSummaryContent");
    if (summaryRoot) {
        const customerItems = [
            { label: "Name", value: fullName || "Not provided", wide: true },
            phone ? { label: "Phone", value: phone } : null,
            email ? { label: "Email", value: email } : null,
            { label: "Customer Type", value: customerType },
        ];

        const tripItems = [
            { label: "Booking Mode", value: serviceLabel },
            { label: "Preferred Dispatch", value: scheduleLabel },
            { label: "Estimated ETA", value: eta },
            { label: "Vehicle Type", value: vehicle },
            vehicleCategory
                ? { label: "Customer Vehicle", value: vehicleCategory }
                : null,
            { label: "Pickup", value: pickup || "Not selected", wide: true },
            { label: "Drop-off", value: dropoff || "Not selected", wide: true },
            pickupNote
                ? {
                      label: "Landmark / Pickup Note",
                      value: pickupNote,
                      wide: true,
                  }
                : null,
            notes ? { label: "Notes", value: notes, wide: true } : null,
            discountCode
                ? { label: "Discount Code", value: discountCode }
                : null,
        ];

        const fareItems = [
            { label: "Base Rate", value: baseRate },
            { label: "Distance", value: distance },
            rate ? { label: "Rate per KM", value: rate } : null,
            { label: "Distance Fee", value: distanceFee },
            { label: "Excess Fee", value: excessFee },
            { label: "Additional Fees", value: additionalFee },
            discount && discount !== "₱0.00"
                ? { label: "Discount", value: discount }
                : null,
        ];

        summaryRoot.innerHTML = `
            ${renderSummarySection("Customer Information", customerItems)}
            ${renderSummarySection("Trip Details", tripItems)}
            ${renderSummarySection("Fare Summary", fareItems, {
                totalValue: total,
                helperNote:
                    discountMeta ||
                    "This estimate updates automatically from your selected route and towing setup.",
            })}
        `;
    }

    const modalTitle = document.getElementById("summaryModalTitle");
    const modalSubtitle = document.getElementById("summaryModalSubtitle");
    const confirmBtn = document.getElementById("confirmBtn");

    if (modalTitle) {
        modalTitle.innerText = isScheduled ? "Schedule Booking" : "Book Now";
    }

    if (modalSubtitle) {
        modalSubtitle.innerText =
            "Review the details by category before sending the request.";
    }

    if (confirmBtn) {
        confirmBtn.innerText = isScheduled
            ? "Confirm Scheduled Booking"
            : "Confirm Book Now";
    }
}

function handleInput(e) {
    clearTimeout(debounceTimer);
    clearLocationFieldError(e.target);

    const value = e.target.value.trim();
    const isPickup = e.target === elements.pickupInput;
    const containerId = isPickup ? "pickupSuggestions" : "dropSuggestions";
    const container = document.getElementById(containerId);

    if (!value) {
        if (container) {
            container.innerHTML = "";
        }

        if (isPickup) {
            pickupCoords = null;
            if (pickupMarker) map.removeLayer(pickupMarker);
            pickupMarker = null;
            markLocationNeedsConfirmation(
                "pickup",
                "Set and confirm a pickup pin to continue.",
            );
        } else {
            dropCoords = null;
            if (dropMarker) map.removeLayer(dropMarker);
            dropMarker = null;
            markLocationNeedsConfirmation(
                "dropoff",
                "Set and confirm a dropoff pin to continue.",
            );
        }

        prepareBookingData();
        resetEstimatePreview();
        toggleBookBtn();
        return;
    }

    if (isPickup) {
        markLocationNeedsConfirmation(
            "pickup",
            "Pickup text changed. Choose a map pin and confirm the location again.",
        );
    } else {
        markLocationNeedsConfirmation(
            "dropoff",
            "Dropoff text changed. Re-pin and confirm the destination again.",
        );
    }

    if (value.length < 3) {
        if (container) {
            container.innerHTML = "";
        }
        toggleBookBtn();
        return;
    }

    debounceTimer = setTimeout(() => {
        getSuggestions(value, containerId, isPickup ? "pickup" : "dropoff");
    }, 400);

    toggleBookBtn();
}

function hideSuggestions() {
    const pickup = document.getElementById("pickupSuggestions");
    const drop = document.getElementById("dropSuggestions");

    if (pickup) pickup.innerHTML = "";
    if (drop) drop.innerHTML = "";
}

function showModal(modal) {
    if (!modal) return;

    modal.classList.remove("hidden");
    modal.classList.add("modal-open");
}

function hideModal(modal) {
    if (!modal) return;

    modal.classList.remove("modal-open");
    modal.classList.add("hidden");
}

function closeModal() {
    const confirmBtn = document.getElementById("confirmBtn");

    hideModal(elements.confirmModal);

    if (confirmBtn) {
        confirmBtn.innerText =
            elements.serviceTypeSelect?.value === "schedule"
                ? "Confirm Scheduled Booking"
                : "Confirm Book Now";
        confirmBtn.disabled = false;
    }
}

function openPickupConfirmModal() {
    openLocationConfirmModal("pickup");
}

function closePickupConfirmModal() {
    hideModal(elements.pickupConfirmModal);
}

function confirmPickupLocation() {
    confirmActiveLocation();
}

function openLocationConfirmModal(type) {
    const isPickup = type === "pickup";
    const input = isPickup ? elements.pickupInput : elements.dropoffInput;

    activeLocationTarget = type;
    previewAddress = input?.value?.trim() || "Loading location...";

    clearLocationFieldError(input);
    setText(
        "locationConfirmTitle",
        isPickup ? "Select Pickup Location" : "Select Dropoff Location",
    );
    setText(
        "locationConfirmSubtitle",
        isPickup
            ? "Take a quick map ride and stop when the center pin matches your pickup spot."
            : "Take a quick map ride and stop when the center pin matches your dropoff point.",
    );
    setText("pickupPreviewAddress", previewAddress);
    setText(
        "pickupPreviewNotes",
        isPickup
            ? "Use your current location or glide around the map to the exact pickup point."
            : "Glide around the map until the pin sits on the exact dropoff point.",
    );

    showModal(elements.pickupConfirmModal);
    renderLocationPreviewMap(type);
}

async function confirmActiveLocation() {
    if (!previewMap) {
        return;
    }

    const center = previewMap.getCenter();
    const isPickup = activeLocationTarget === "pickup";
    const input = isPickup ? elements.pickupInput : elements.dropoffInput;
    const message = isPickup
        ? "Pickup location confirmed. You can continue with the booking."
        : "Dropoff location confirmed. You can continue with the booking.";

    if (isPickup) {
        await setPickupLocation(center.lat, center.lng);
        pickupConfirmed = true;
        if (elements.pickupConfirmedInput) {
            elements.pickupConfirmedInput.value = "1";
        }
    } else {
        await setDropoffLocation(center.lat, center.lng);
        dropoffConfirmed = true;
        if (elements.dropoffConfirmedInput) {
            elements.dropoffConfirmedInput.value = "1";
        }
    }

    if (input && previewAddress) {
        input.value = previewAddress;
    }

    clearLocationFieldError(input);
    updateLocationStatus(activeLocationTarget, true, message);
    closePickupConfirmModal();
    calculateEstimate();
    toggleBookBtn();
}

function updateLocationStatus(type, confirmed, message) {
    const isPickup = type === "pickup";
    const statusWrap = isPickup
        ? elements.pickupStatusWrap
        : elements.dropoffStatusWrap;
    const statusText = isPickup
        ? elements.pickupStatusText
        : elements.dropoffStatusText;
    const statusBadge = isPickup
        ? elements.pickupStatusBadge
        : elements.dropoffStatusBadge;
    const badgeText = confirmed ? "Confirmed" : message ? "Pinned" : "";

    if (statusText) {
        statusText.innerText = message || "";
    }

    if (statusBadge) {
        statusBadge.innerText = badgeText;
        statusBadge.classList.toggle("confirmed", confirmed);
        statusBadge.classList.toggle(
            "pending",
            !confirmed && Boolean(badgeText),
        );
    }

    if (statusWrap) {
        statusWrap.style.display = badgeText || message ? "flex" : "none";
    }
}

function toggleScheduleFields() {
    if (!elements.scheduleFields) {
        return;
    }

    const isSchedule = elements.serviceTypeSelect?.value === "schedule";
    const displayMode = elements.scheduleFields.classList.contains("row")
        ? "flex"
        : "grid";

    elements.scheduleFields.style.display = isSchedule ? displayMode : "none";

    if (elements.scheduledDateInput) {
        elements.scheduledDateInput.disabled = !isSchedule;
        elements.scheduledDateInput.required = isSchedule;
    }

    if (elements.scheduledTimeInput) {
        elements.scheduledTimeInput.disabled = !isSchedule;
        elements.scheduledTimeInput.required = isSchedule;
    }
}

function updateBookingModeButtonLabel() {
    const isSchedule = elements.serviceTypeSelect?.value === "schedule";
    const button = elements.bookBtn;

    if (!button) {
        return;
    }

    const nextLabel = isSchedule ? "Schedule Booking" : "Book Now";
    const buttonTextTarget = button.querySelector("span") || button;

    buttonTextTarget.textContent = nextLabel;
}

function getScheduleFallbackField() {
    const form = elements.bookingForm;

    if (!form) {
        return null;
    }

    let field = form.querySelector('input[name="schedule_fallback_accepted"]');

    if (!field) {
        field = document.createElement("input");
        field.type = "hidden";
        field.name = "schedule_fallback_accepted";
        field.value = "0";
        form.appendChild(field);
    }

    return field;
}

function clearScheduleFallbackAcceptance() {
    const field = getScheduleFallbackField();

    if (field) {
        field.value = "0";
    }
}

function ensureScheduleRecommendationModal() {
    let modal = document.getElementById("scheduleRecommendationModal");

    if (!document.getElementById("scheduleRecommendationModalStyles")) {
        const styles = document.createElement("style");
        styles.id = "scheduleRecommendationModalStyles";
        styles.textContent = `
            #scheduleRecommendationModal {
                position: fixed;
                inset: 0;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 24px;
                background: rgba(17, 24, 39, 0.58);
                backdrop-filter: blur(10px);
                z-index: 3000;
            }

            #scheduleRecommendationModal.is-open {
                display: flex;
            }

            #scheduleRecommendationModal .schedule-modal-dialog {
                width: min(480px, 96vw);
                background: #ffffff;
                border-radius: 24px;
                box-shadow: 0 30px 80px rgba(17, 24, 39, 0.22);
                padding: 24px;
                border: 1px solid #f3f4f6;
            }

            #scheduleRecommendationModal .schedule-modal-header {
                display: flex;
                align-items: flex-start;
                gap: 14px;
                margin-bottom: 14px;
            }

            #scheduleRecommendationModal .schedule-modal-alert {
                width: 48px;
                height: 48px;
                flex: 0 0 48px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 14px;
                background: #fef3c7;
                color: #111827;
                font-size: 22px;
                font-weight: 800;
            }

            #scheduleRecommendationModal .schedule-modal-copy {
                flex: 1;
            }

            #scheduleRecommendationModal .schedule-modal-badge {
                display: inline-flex;
                align-items: center;
                padding: 5px 10px;
                border-radius: 999px;
                background: #fffbeb;
                color: #b45309;
                font-size: 0.75rem;
                font-weight: 700;
                margin-bottom: 8px;
            }

            #scheduleRecommendationModal h3 {
                margin: 0 0 8px;
                color: #111827;
                font-size: 1.2rem;
                font-weight: 700;
                letter-spacing: -0.02em;
            }

            #scheduleRecommendationModal p {
                margin: 0;
                color: #4b5563;
                line-height: 1.6;
                font-size: 0.96rem;
            }

            #scheduleRecommendationModal .schedule-modal-note {
                margin-top: 14px;
                padding: 12px 14px;
                border-radius: 14px;
                background: #fffdf5;
                border: 1px solid #fde68a;
                color: #3f3f46;
                font-size: 0.9rem;
            }

            #scheduleRecommendationModal .schedule-modal-actions {
                display: flex;
                justify-content: flex-end;
                gap: 10px;
                margin-top: 20px;
                flex-wrap: wrap;
            }

            #scheduleRecommendationModal .schedule-modal-actions .btn-secondary {
                border-radius: 12px;
                border: 1px solid #d1d5db;
                background: #ffffff;
                color: #111827;
            }

            #scheduleRecommendationModal .schedule-modal-actions .btn-primary {
                border-radius: 12px;
                background: #111827;
                color: #ffffff;
                border: 0;
                box-shadow: 0 12px 24px rgba(17, 24, 39, 0.18);
            }
        `;
        document.head.appendChild(styles);
    }

    if (modal) {
        return modal;
    }

    modal = document.createElement("div");
    modal.id = "scheduleRecommendationModal";
    modal.setAttribute("aria-hidden", "true");
    modal.innerHTML = `
        <div class="schedule-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="scheduleRecommendationTitle">
            <div class="schedule-modal-header">
                <div class="schedule-modal-alert">↗</div>
                <div class="schedule-modal-copy">
                    <div class="schedule-modal-badge">Booking update</div>
                    <h3 id="scheduleRecommendationTitle">Switch to Schedule Later?</h3>
                    <p>Immediate dispatch is currently unavailable. You can still proceed with your booking, and we’ll assign your service as soon as possible.</p>
                </div>
            </div>
            <div class="schedule-modal-note">Choosing Yes will reserve the next available 1-hour booking window.</div>
            <div class="schedule-modal-actions">
                <button type="button" class="btn-secondary" data-action="cancel">No, go back</button>
                <button type="button" class="btn-primary" data-action="confirm">Yes, continue</button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);

    return modal;
}

function openScheduleRecommendationModal() {
    const modal = ensureScheduleRecommendationModal();
    const confirmButton = modal.querySelector('[data-action="confirm"]');

    return new Promise((resolve) => {
        const close = (accepted) => {
            modal.classList.remove("is-open");
            modal.setAttribute("aria-hidden", "true");
            modal.removeEventListener("click", handleClick);
            document.removeEventListener("keydown", handleKeydown);
            resolve(accepted);
        };

        const handleClick = (event) => {
            const action = event.target
                .closest("[data-action]")
                ?.getAttribute("data-action");

            if (action === "confirm") {
                close(true);
                return;
            }

            if (action === "cancel" || event.target === modal) {
                close(false);
            }
        };

        const handleKeydown = (event) => {
            if (event.key === "Escape") {
                close(false);
            }
        };

        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        modal.addEventListener("click", handleClick);
        document.addEventListener("keydown", handleKeydown);
        confirmButton?.focus();
    });
}

function prefillRecommendedScheduleWindow() {
    const now = new Date();
    const scheduledAt = new Date(now.getTime() + 60 * 60 * 1000);
    const yyyy = scheduledAt.getFullYear();
    const mm = String(scheduledAt.getMonth() + 1).padStart(2, "0");
    const dd = String(scheduledAt.getDate()).padStart(2, "0");
    const hh = String(scheduledAt.getHours()).padStart(2, "0");
    const min = String(scheduledAt.getMinutes()).padStart(2, "0");

    if (elements.scheduledDateInput && !elements.scheduledDateInput.value) {
        elements.scheduledDateInput.value = `${yyyy}-${mm}-${dd}`;
    }

    if (elements.scheduledTimeInput && !elements.scheduledTimeInput.value) {
        elements.scheduledTimeInput.value = `${hh}:${min}`;
    }
}

async function ensureDispatchAvailabilityForBooking() {
    const isBookNow = elements.serviceTypeSelect?.value !== "schedule";
    const bookNowEnabled = latestAvailability?.book_now_enabled !== false;

    clearScheduleFallbackAcceptance();

    if (!isBookNow || bookNowEnabled) {
        return true;
    }

    const shouldSchedule = await openScheduleRecommendationModal();

    if (!shouldSchedule) {
        setText("availabilityStatus", "Schedule Later recommended");
        setText(
            "availabilityNote",
            "Immediate dispatch is currently unavailable. You can still proceed with your booking, and we’ll assign your service as soon as possible.",
        );
        return false;
    }

    const field = getScheduleFallbackField();

    if (field) {
        field.value = "1";
    }

    if (elements.serviceTypeSelect) {
        elements.serviceTypeSelect.value = "schedule";
    }

    prefillRecommendedScheduleWindow();
    toggleScheduleFields();
    updateBookingModeButtonLabel();

    if (typeof applyBookingActionLabels === "function") {
        applyBookingActionLabels();
    }

    updateAvailabilityMessage({
        ...latestAvailability,
        message:
            "Your booking has been prepared for the next available 1-hour schedule window.",
    });
    prepareBookingData();
    toggleBookBtn();

    return true;
}

function markLocationNeedsConfirmation(type, message) {
    if (type === "pickup") {
        pickupConfirmed = false;

        if (elements.pickupConfirmedInput) {
            elements.pickupConfirmedInput.value = "0";
        }
    } else {
        dropoffConfirmed = false;

        if (elements.dropoffConfirmedInput) {
            elements.dropoffConfirmedInput.value = "0";
        }
    }

    updateLocationStatus(type, false, message);
    toggleBookBtn();
}

function validateLocationState() {
    clearLocationFieldError(elements.pickupInput);
    clearLocationFieldError(elements.dropoffInput);

    if (!pickupCoords) {
        setLocationFieldError(
            elements.pickupInput,
            "Please choose the pickup address from the suggestions or the map.",
        );
    }

    if (!dropCoords) {
        setLocationFieldError(
            elements.dropoffInput,
            "Please choose the dropoff address from the suggestions or the map.",
        );
    }
}

function setLocationFieldError(input, message) {
    if (!input) return;

    if (typeof window.showBookingFieldError === "function") {
        window.showBookingFieldError(input, message);
        return;
    }

    input.classList.add("input-error");
    input.setAttribute("aria-invalid", "true");
    input.setCustomValidity(message);
}

function clearLocationFieldError(input) {
    if (!input) return;

    if (typeof window.clearBookingFieldError === "function") {
        window.clearBookingFieldError(input);
        return;
    }

    input.classList.remove("input-error");
    input.removeAttribute("aria-invalid");
    input.setCustomValidity("");
}

async function updateRoutePreview() {
    if (!pickupCoords || !dropCoords) {
        return;
    }

    try {
        const response = await fetch(geoConfig.routeUrl, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": geoConfig.csrfToken,
                Accept: "application/json",
            },
            body: JSON.stringify({
                pickup_lat: pickupCoords[1],
                pickup_lng: pickupCoords[0],
                drop_lat: dropCoords[1],
                drop_lng: dropCoords[0],
            }),
        });

        if (!response.ok) {
            return;
        }

        const routeData = await response.json();
        drawRoute(routeData || {});
    } catch (error) {
        drawRoute([]);
    }
}

function toggleBookBtn() {
    // Only run on the customer page — landing page manages its own button state
    if (!document.getElementById("bookBtn")) return;

    const pickup = elements.pickupInput?.value.trim();
    const dropoff = elements.dropoffInput?.value.trim();
    const bookBtn = elements.bookBtn;
    const serviceType = elements.serviceTypeSelect?.value;
    const scheduleReady =
        serviceType !== "schedule" ||
        (elements.scheduledDateInput?.value &&
            elements.scheduledTimeInput?.value);

    if (!bookBtn) return;

    const canRequest =
        pickup &&
        dropoff &&
        currentEstimateTotal > 0 &&
        currentDistanceKm > 0 &&
        pickupCoords &&
        dropCoords &&
        elements.vehicleSelect?.value &&
        elements.vehicleCategorySelect?.value &&
        scheduleReady;

    bookBtn.disabled = !canRequest;
    bookBtn.classList.toggle("disabled", !canRequest);
    bookBtn.setAttribute("aria-disabled", String(!canRequest));
}

function fitBothMarkers() {
    if (!pickupCoords || !dropCoords) return;

    const bounds = L.latLngBounds([
        [pickupCoords[1], pickupCoords[0]],
        [dropCoords[1], dropCoords[0]],
    ]);

    map.fitBounds(bounds, { padding: [50, 50] });
}

async function calculateEstimate() {
    console.log("calculateEstimate called", {
        pickupCoords,
        dropCoords,
        vehicleSelect: elements.vehicleSelect?.value,
    });

    if (!elements.vehicleSelect) {
        resetEstimatePreview(Boolean(pickupCoords && dropCoords));
        return;
    }

    const truckTypeId =
        elements.vehicleSelect.value ||
        elements.vehicleSelect.options?.[elements.vehicleSelect.selectedIndex]
            ?.value;

    if (!truckTypeId) {
        console.log("No truck type selected");
        resetEstimatePreview(true);
        return;
    }

    if (!pickupCoords || !dropCoords) {
        console.log("Missing coords, applying base preview");
        applyTruckBasePreview(Boolean(pickupCoords && dropCoords));
        return;
    }

    console.log("Calling pricing API...");

    const payload = {
        truck_type_id: truckTypeId,
        pickup_lat: pickupCoords[1],
        pickup_lng: pickupCoords[0],
        drop_lat: dropCoords[1],
        drop_lng: dropCoords[0],
        customer_type:
            document.querySelector('select[name="customer_type"]')?.value ||
            "regular",
        vehicle_category: elements.vehicleCategorySelect?.value || "",
        service_type: elements.serviceTypeSelect?.value || "book_now",
        discount_code: elements.discountCodeInput?.value?.trim() || "",
    };

    try {
        const response = await fetch(geoConfig.pricingPreviewUrl, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": geoConfig.csrfToken,
                Accept: "application/json",
            },
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));

            if (response.status === 422) {
                applyInvalidRouteState(
                    errorData.message ||
                        "Pickup and dropoff must be different points to continue.",
                );
                return;
            }

            applyLocalEstimateFallback();
            return;
        }

        const data = await response.json();
        const pricing = data.pricing || {};
        latestAvailability = data.availability || { book_now_enabled: true };

        drawRoute(data.route || {});

        currentRate = Number(pricing.per_km_rate || 0);
        currentDistanceKm = Number(pricing.distance_km || 0);
        currentEtaMinutes = Number(data.route?.duration_min || 0);
        currentEstimateTotal = Number(pricing.final_total || 0);

        applyPricingSnapshot(
            createPricingSnapshot({
                baseRateText: currency(pricing.base_rate || 0),
                distanceText: `${currentDistanceKm.toFixed(2)} km`,
                etaText: formatEta(currentEtaMinutes, currentDistanceKm),
                perKmRateText: currency(pricing.per_km_rate || 0),
                distanceFeeText: currency(pricing.distance_fee || 0),
                excessKmText: `${Number(pricing.excess_km || 0).toFixed(2)} km`,
                excessFeeText: currency(pricing.excess_fee || 0),
                additionalFeeText: currency(pricing.additional_fee || 0),
                totalText: currency(pricing.final_total || 0),
                discountAmountText:
                    Number(pricing.discount_amount || 0) > 0
                        ? `- ${currency(pricing.discount_amount || 0)}`
                        : "₱0.00",
                discountPercentage: Number(pricing.discount_percentage || 0),
                discountReason: String(pricing.discount_reason || "").trim(),
            }),
        );

        if (currentDistanceKm <= 0) {
            applyInvalidRouteState(
                "Pickup and dropoff must be different points to continue.",
            );
            return;
        }

        updateAvailabilityMessage(latestAvailability);
        prepareBookingData();

        if (
            elements.confirmModal &&
            !elements.confirmModal.classList.contains("hidden")
        ) {
            populateBookingSummary();
        }

        toggleBookBtn();
    } catch (error) {
        applyLocalEstimateFallback();
    }
}

function drawRoute(routeData = {}) {
    if (!pickupCoords || !dropCoords) {
        return;
    }

    if (routeLayer) {
        map.removeLayer(routeLayer);
        routeLayer = null;
    }

    const routeCoordinates = Array.isArray(routeData)
        ? routeData
        : routeData?.coordinates || [];
    const hasAnyRoute =
        Array.isArray(routeCoordinates) && routeCoordinates.length > 1;
    const isFallback =
        Boolean(routeData?.is_fallback) || routeCoordinates.length <= 2;
    const points = hasAnyRoute
        ? routeCoordinates
        : [
              [pickupCoords[1], pickupCoords[0]],
              [dropCoords[1], dropCoords[0]],
          ];

    routeLayer = L.polyline(points, {
        color: isFallback ? "#16a34a" : "#22c55e",
        weight: 5,
        opacity: isFallback ? 0.78 : 0.95,
        dashArray: isFallback ? "8, 10" : null,
    }).addTo(map);

    map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });
}

function updateAvailabilityMessage(availability) {
    const availabilityStatus = document.getElementById("availabilityStatus");
    const availabilityNote = document.getElementById("availabilityNote");
    const serviceTypeSelect = elements.serviceTypeSelect;

    if (availabilityStatus) {
        availabilityStatus.innerText = availability.book_now_enabled
            ? "Book Now available"
            : "Schedule recommended";
    }

    if (availabilityNote) {
        availabilityNote.innerText = availability.book_now_enabled
            ? availability.message ||
              "Select your pickup stop, dropoff stop, and vehicle to see the live estimate."
            : availability.message ||
              "Immediate dispatch is currently unavailable. You can still proceed with your booking, and we’ll assign your service as soon as possible.";
    }

    if (serviceTypeSelect) {
        updateBookingModeButtonLabel();
    }
}

function resetEstimatePreview(keepRoute = false) {
    if (!keepRoute && routeLayer) {
        map.removeLayer(routeLayer);
        routeLayer = null;
    }

    const rates = getSelectedTruckRates();

    currentRate = rates.perKm;
    currentDistanceKm = 0;
    currentEtaMinutes = 0;
    currentEstimateTotal = rates.base;

    applyPricingSnapshot(
        createPricingSnapshot({
            baseRateText: currency(rates.base),
            distanceText: "0 km",
            etaText: "Pending route",
            perKmRateText: currency(rates.perKm),
            distanceFeeText: "₱0.00",
            excessKmText: "0 km",
            excessFeeText: "₱0.00",
            additionalFeeText: "₱0.00",
            totalText: currency(rates.base),
        }),
    );

    setText(
        "availabilityStatus",
        rates.base > 0 ? "Base rate ready" : "Set your route",
    );
    setText(
        "availabilityNote",
        rates.base > 0
            ? "Truck base rate is ready. Add pickup and dropoff to calculate the full trip total."
            : keepRoute
              ? "Route ready. Pick a vehicle to see the live estimate."
              : "Select your pickup stop, dropoff stop, and vehicle to see the live estimate.",
    );

    prepareBookingData();

    if (
        elements.confirmModal &&
        !elements.confirmModal.classList.contains("hidden")
    ) {
        populateBookingSummary();
    }

    toggleBookBtn();
}

function setText(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.innerText = value;
    }
}

function getSelectedTruckRates() {
    const el = elements.vehicleSelect;
    const selectedOption = el?.options?.[el.selectedIndex];
    const dataset = selectedOption?.dataset || el?.dataset || {};

    return {
        base: Number(dataset.base || 0),
        perKm: Number(dataset.perkm || 0),
    };
}

function estimateDistanceFromCoords() {
    if (!pickupCoords || !dropCoords) {
        return 0;
    }

    const toRadians = (degrees) => (degrees * Math.PI) / 180;
    const earthRadiusKm = 6371;
    const lat1 = Number(pickupCoords[1]);
    const lng1 = Number(pickupCoords[0]);
    const lat2 = Number(dropCoords[1]);
    const lng2 = Number(dropCoords[0]);
    const deltaLat = toRadians(lat2 - lat1);
    const deltaLng = toRadians(lng2 - lng1);

    const a =
        Math.sin(deltaLat / 2) ** 2 +
        Math.cos(toRadians(lat1)) *
            Math.cos(toRadians(lat2)) *
            Math.sin(deltaLng / 2) ** 2;

    return Number(
        (
            earthRadiusKm *
            (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)))
        ).toFixed(2),
    );
}

function applyTruckBasePreview(hasRouteContext = false) {
    const rates = getSelectedTruckRates();

    if (rates.base <= 0 && rates.perKm <= 0) {
        resetEstimatePreview(false);
        return;
    }

    const distanceKm = hasRouteContext ? estimateDistanceFromCoords() : 0;
    const distanceFee = Number((distanceKm * rates.perKm).toFixed(2));
    const total = Number((rates.base + distanceFee).toFixed(2));

    currentRate = rates.perKm;
    currentDistanceKm = distanceKm;
    currentEtaMinutes = estimateEtaFromDistance(distanceKm);
    currentEstimateTotal = total;

    applyPricingSnapshot(
        createPricingSnapshot({
            baseRateText: currency(rates.base),
            distanceText: `${distanceKm.toFixed(2)} km`,
            etaText: formatEta(currentEtaMinutes, distanceKm),
            perKmRateText: currency(rates.perKm),
            distanceFeeText: currency(distanceFee),
            excessKmText: "0 km",
            excessFeeText: "₱0.00",
            additionalFeeText: "₱0.00",
            totalText: currency(total),
        }),
    );

    setText(
        "availabilityStatus",
        hasRouteContext ? "Approximate estimate" : "Base rate ready",
    );
    setText(
        "availabilityNote",
        hasRouteContext
            ? "Showing an estimate from the selected truck while the live route total syncs."
            : "Select pickup and dropoff to calculate the full trip total.",
    );

    prepareBookingData();

    if (
        elements.confirmModal &&
        !elements.confirmModal.classList.contains("hidden")
    ) {
        populateBookingSummary();
    }

    toggleBookBtn();
}

function applyLocalEstimateFallback() {
    applyTruckBasePreview(Boolean(pickupCoords && dropCoords));
}

function applyInvalidRouteState(message) {
    if (routeLayer) {
        map.removeLayer(routeLayer);
        routeLayer = null;
    }

    currentDistanceKm = 0;
    currentEtaMinutes = 0;
    currentEstimateTotal = 0;

    applyPricingSnapshot(
        createPricingSnapshot({
            baseRateText:
                document.getElementById("baseRate")?.innerText || "₱0.00",
            distanceText: "0 km",
            etaText: "Route unavailable",
            perKmRateText:
                document.getElementById("rate")?.innerText || "₱0.00",
            distanceFeeText: "₱0.00",
            excessKmText: "0 km",
            excessFeeText: "₱0.00",
            additionalFeeText: "₱0.00",
            totalText: currency(0),
        }),
    );

    setText("availabilityStatus", "Route needs review");
    setText(
        "availabilityNote",
        message || "Pickup and dropoff must be different points to continue.",
    );

    toggleBookBtn();
}

function estimateEtaFromDistance(distanceKm) {
    if (!Number.isFinite(Number(distanceKm)) || Number(distanceKm) <= 0) {
        return 0;
    }

    return Math.max(Math.round((Number(distanceKm) / 30) * 60), 1);
}

function formatEta(minutes, distanceKm = 0) {
    const safeMinutes = Number(minutes || 0);

    if (safeMinutes > 0) {
        return safeMinutes >= 60
            ? `${Math.floor(safeMinutes / 60)}h ${Math.round(safeMinutes % 60)}m`
            : `${Math.round(safeMinutes)} min`;
    }

    if (Number(distanceKm || 0) > 0) {
        return `${estimateEtaFromDistance(distanceKm)} min`;
    }

    return "Pending route";
}

function buildDiscountMetaText(discountPercentage = 0, discountReason = "") {
    if (Number(discountPercentage) > 0) {
        const percentage = Number(discountPercentage).toFixed(0);
        const reason = discountReason ? ` · ${discountReason}` : "";

        return `${percentage}% validated discount applied${reason}`;
    }

    if (elements.discountCodeInput?.value?.trim()) {
        return "Discount code checked. No matching booking discount was found.";
    }

    return "Optional discounts are validated automatically before submission.";
}

function currency(value) {
    return `₱${Number(value || 0).toFixed(2)}`;
}

function parseCurrencyValue(value) {
    return Number(String(value || "0").replace(/[^\d.-]/g, "")) || 0;
}

function prepareBookingData() {
    if (elements.pickupLatInput && pickupCoords) {
        elements.pickupLatInput.value = pickupCoords[1];
    }
    if (elements.pickupLngInput && pickupCoords) {
        elements.pickupLngInput.value = pickupCoords[0];
    }
    if (elements.dropLatInput && dropCoords) {
        elements.dropLatInput.value = dropCoords[1];
    }
    if (elements.dropLngInput && dropCoords) {
        elements.dropLngInput.value = dropCoords[0];
    }

    const distanceInput = document.getElementById("distance_input");
    const priceInput = document.getElementById("price_input");
    const additionalFeeInput = document.getElementById("additional_fee_input");
    const etaInput = document.getElementById("eta_minutes");

    // ✅ Read from module-level variables — never from DOM text elements
    if (distanceInput) {
        distanceInput.value = currentDistanceKm > 0 ? currentDistanceKm : "";
    }
    if (priceInput) {
        priceInput.value = currentEstimateTotal > 0 ? currentEstimateTotal : "";
    }
    if (additionalFeeInput) {
        const addFee = lastPricingSnapshot
            ? parseCurrencyValue(lastPricingSnapshot.additionalFeeText)
            : 0;
        additionalFeeInput.value = addFee > 0 ? addFee : "0";
    }
    if (etaInput && currentEtaMinutes > 0) {
        etaInput.value = Math.round(currentEtaMinutes);
    }
}

function createMarkerIcon(type) {
    const label = type === "pickup" ? "P" : "D";

    return L.divIcon({
        className: "map-pin-wrapper",
        html: `<div class="map-pin-marker ${type === "pickup" ? "pickup-pin-icon" : "dropoff-pin-icon"}"><span>${label}</span></div>`,
        iconSize: [28, 28],
        iconAnchor: [14, 14],
    });
}

function buildMarker(type, lat, lng) {
    const marker = L.marker([lat, lng], {
        draggable: true,
        icon: createMarkerIcon(type),
    }).addTo(map);

    marker.on("dragend", async (event) => {
        const position = event.target.getLatLng();

        if (type === "pickup") {
            await setPickupLocation(position.lat, position.lng, true);
            markLocationNeedsConfirmation(
                "pickup",
                "Pickup pin moved. Review and confirm the location again.",
            );
            return;
        }

        await setDropoffLocation(position.lat, position.lng, true);
        markLocationNeedsConfirmation(
            "dropoff",
            "Dropoff pin moved. Review and confirm the destination again.",
        );
    });

    return marker;
}

async function setPickupLocation(lat, lng, fromDrag = false) {
    pickupCoords = [lng, lat];

    if (pickupMarker) {
        map.removeLayer(pickupMarker);
    }

    pickupMarker = buildMarker("pickup", lat, lng);

    const address = await getAddressFromCoords(lat, lng);

    if (elements.pickupInput) {
        elements.pickupInput.value = address;
        clearLocationFieldError(elements.pickupInput);
    }

    if (!fromDrag) {
        map.setView([lat, lng], 15);
    }

    pickupConfirmed = true;
    if (elements.pickupConfirmedInput) {
        elements.pickupConfirmedInput.value = "1";
    }
    updateLocationStatus("pickup", true, "");
    prepareBookingData();
    fitBothMarkers();
    await updateRoutePreview();
    calculateEstimate();
    toggleBookBtn();
}

async function setDropoffLocation(lat, lng, fromDrag = false) {
    dropCoords = [lng, lat];

    if (dropMarker) {
        map.removeLayer(dropMarker);
    }

    dropMarker = buildMarker("dropoff", lat, lng);

    const address = await getAddressFromCoords(lat, lng);

    if (elements.dropoffInput) {
        elements.dropoffInput.value = address;
        clearLocationFieldError(elements.dropoffInput);
    }

    if (!fromDrag) {
        map.setView([lat, lng], 15);
    }

    dropoffConfirmed = true;
    if (elements.dropoffConfirmedInput) {
        elements.dropoffConfirmedInput.value = "1";
    }
    updateLocationStatus("dropoff", true, "");

    prepareBookingData();
    fitBothMarkers();
    await updateRoutePreview();
    calculateEstimate();
    toggleBookBtn();
}

async function getSuggestions(query, containerId, type) {
    if (!query) return;

    const url = `${geoConfig.searchUrl}?q=${encodeURIComponent(query)}`;
    const res = await fetch(url, {
        headers: {
            Accept: "application/json",
        },
    });

    const data = await res.json();
    const container = document.getElementById(containerId);

    if (!container) {
        return;
    }

    container.innerHTML = "";

    (data.features || []).forEach((place) => {
        const div = document.createElement("div");
        div.innerText = place.label;

        div.onclick = async () => {
            const coords = place.coordinates || [];

            if (type === "pickup") {
                await setPickupLocation(coords[1], coords[0]);
            } else {
                await setDropoffLocation(coords[1], coords[0]);
            }

            container.innerHTML = "";
        };

        container.appendChild(div);
    });
}

async function resolveTypedAddressIfNeeded(type) {
    const isPickup = type === "pickup";
    const input = isPickup ? elements.pickupInput : elements.dropoffInput;
    const existingCoords = isPickup ? pickupCoords : dropCoords;
    const value = input?.value?.trim();

    if (!value || existingCoords) {
        return Boolean(existingCoords);
    }

    try {
        const url = `${geoConfig.searchUrl}?q=${encodeURIComponent(value)}`;
        const res = await fetch(url, {
            headers: {
                Accept: "application/json",
            },
        });

        if (!res.ok) {
            return false;
        }

        const data = await res.json();
        const firstMatch = (data.features || [])[0];
        const coords = firstMatch?.coordinates || [];

        if (!Array.isArray(coords) || coords.length < 2) {
            return false;
        }

        if (isPickup) {
            await setPickupLocation(coords[1], coords[0]);
        } else {
            await setDropoffLocation(coords[1], coords[0]);
        }

        return true;
    } catch (error) {
        return false;
    }
}

function renderLocationPreviewMap(type = "pickup") {
    const previewElement = document.getElementById("pickupPreviewMap");
    const coords = type === "pickup" ? pickupCoords : dropCoords;
    const fallbackCenter = map?.getCenter?.() || {
        lat: 14.5995,
        lng: 120.9842,
    };
    const lat = coords?.[1] ?? fallbackCenter.lat;
    const lng = coords?.[0] ?? fallbackCenter.lng;

    if (!previewElement) {
        return;
    }

    if (!previewMap) {
        previewMap = L.map(previewElement, {
            zoomControl: true,
        });

        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "&copy; OpenStreetMap",
        }).addTo(previewMap);

        previewMap.on("moveend", () => {
            clearTimeout(previewLookupTimer);
            previewLookupTimer = setTimeout(
                updatePreviewAddressFromCenter,
                180,
            );
        });
    }

    previewMap.setView([lat, lng], coords ? 16 : 15);
    setTimeout(() => {
        previewMap.invalidateSize();
        updatePreviewAddressFromCenter();
    }, 120);
}

async function updatePreviewAddressFromCenter() {
    if (!previewMap) {
        return;
    }

    const center = previewMap.getCenter();
    previewAddress = await getAddressFromCoords(center.lat, center.lng);
    setText("pickupPreviewAddress", previewAddress || "Location unavailable");
    setText(
        "pickupPreviewNotes",
        `Pin ready at ${center.lat.toFixed(5)}, ${center.lng.toFixed(5)}`,
    );
}

function movePickerToCurrentLocation() {
    if (!previewMap || !navigator.geolocation) {
        return;
    }

    setText("pickupPreviewNotes", "Fetching your current location...");

    navigator.geolocation.getCurrentPosition(
        (position) => {
            const { latitude, longitude } = position.coords;
            previewMap.setView([latitude, longitude], 17);
            updatePreviewAddressFromCenter();
        },
        () => {
            setText(
                "pickupPreviewNotes",
                "Current location is unavailable. Move the map manually to continue.",
            );
        },
        { enableHighAccuracy: true, timeout: 10000 },
    );
}

async function getAddressFromCoords(lat, lng) {
    const url = `${geoConfig.reverseUrl}?lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`;
    const res = await fetch(url, {
        headers: {
            Accept: "application/json",
        },
    });

    const data = await res.json();
    return data.address || "Unknown location";
}
