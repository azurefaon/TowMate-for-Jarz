/* ==========================================================================
   Active Jobs — Mock Preview (Phase 1 design-only).
   Everything here is local UI state only. No fetch(), no real endpoints,
   nothing touches admin.jobs.confirm-payment or any live route. Safe to
   click around freely — a page refresh resets all mock state.
   ========================================================================== */
document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") lucide.createIcons();

    const STATUS_LABELS = {
        "on-the-way": "On The Way",
        "in-progress": "In Progress",
        "awaiting-verification": "Awaiting Payment Verification",
        "correction-required": "Correction Required",
    };

    // ---- Tabs -------------------------------------------------------------
    const tabs = document.querySelectorAll("#jobsMockTabs .rb-tab");
    const rows = document.querySelectorAll(".js-open-job-row-mock");

    function applyTabFilter(tab) {
        rows.forEach((row) => {
            const bucket = row.dataset.bucket;
            row.style.display = tab === "all" || bucket === tab ? "" : "none";
        });
    }

    tabs.forEach((btn) => {
        btn.addEventListener("click", function () {
            tabs.forEach((b) => b.classList.remove("is-active"));
            btn.classList.add("is-active");
            applyTabFilter(btn.dataset.tab);
        });
    });

    // ---- Job detail modal ---------------------------------------------------
    const modal = document.getElementById("jobModalMock");
    const overlay = modal?.querySelector(".modal-overlay");
    const closeButtons = modal?.querySelectorAll(".close-modal, .close-modal-btn") ?? [];

    const modalTitle = document.getElementById("modalTitleMock");
    const modalStatusPill = document.getElementById("modalStatusPillMock");
    const unitSectionTitle = document.getElementById("jobm-unit-section-title");
    const historicalNote = document.getElementById("jobm-historical-note");
    const paymentSection = document.getElementById("paymentSectionMock");
    const paymentProofSection = document.getElementById("paymentProofSectionMock");
    const signatureSection = document.getElementById("signatureSectionMock");
    const returnReasonSection = document.getElementById("returnReasonSectionMock");
    const returnBtn = document.getElementById("returnForCorrectionBtnMock");
    const confirmBtn = document.getElementById("confirmPaymentBtnMock");

    let currentRow = null;

    const fillField = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value || "—";
    };

    function openModal(row) {
        if (!modal || !row) return;
        currentRow = row;

        const bucket = row.dataset.bucket;
        const isVerification = bucket === "awaiting-verification" || bucket === "correction-required";

        if (modalTitle) modalTitle.textContent = `Job ${row.dataset.jobId}`;
        if (modalStatusPill) {
            modalStatusPill.textContent = row.dataset.statusLabel;
            modalStatusPill.className = `modal-status-pill status-badge status-${row.dataset.statusSlug}`;
        }

        fillField("jobm-customer", row.dataset.customer);
        fillField("jobm-pickup", row.dataset.pickup);
        fillField("jobm-dropoff", row.dataset.dropoff);
        fillField("jobm-unit", row.dataset.unit);
        fillField("jobm-truckclass", row.dataset.truckClass);
        fillField("jobm-teamleader", row.dataset.teamleader);
        fillField("jobm-time", row.dataset.created);

        // Unit/Team Leader section — historical fact only for verification
        // states, never a live-availability claim (see plan correction).
        if (unitSectionTitle) unitSectionTitle.textContent = isVerification ? "Unit Used at Service" : "Assigned Unit";
        if (historicalNote) historicalNote.style.display = isVerification ? "" : "none";

        // Payment summary
        if (paymentSection) paymentSection.style.display = isVerification ? "" : "none";
        if (isVerification) {
            fillField("jobm-amount-due", row.dataset.amountDue ? "₱" + row.dataset.amountDue : "—");
            fillField("jobm-payment-method", row.dataset.paymentMethod);
            fillField("jobm-amount-submitted", row.dataset.amountSubmitted ? "₱" + row.dataset.amountSubmitted : "—");
            fillField("jobm-payment-submitted-at", row.dataset.paymentSubmittedAt);
            fillField("jobm-service-completed-at", row.dataset.serviceCompletedAt);
        }

        // Payment proof — GCash/Bank Transfer only, never for Cash
        const hasProof = row.dataset.hasProof === "1";
        if (paymentProofSection) paymentProofSection.style.display = isVerification && hasProof ? "" : "none";
        if (hasProof) {
            const label = document.getElementById("jobm-proof-label");
            if (label) label.textContent = `Mock ${row.dataset.paymentMethod} proof image`;
        }

        // Signature — always shown for verification states
        if (signatureSection) signatureSection.style.display = isVerification ? "" : "none";

        // Return reason — only for an already-returned (Correction Required) job
        if (returnReasonSection) returnReasonSection.style.display = bucket === "correction-required" ? "" : "none";
        if (bucket === "correction-required") fillField("jobm-return-reason", row.dataset.returnReason);

        // Footer actions
        if (returnBtn) returnBtn.style.display = bucket === "awaiting-verification" ? "" : "none";
        if (confirmBtn) {
            confirmBtn.style.display = isVerification ? "" : "none";
            confirmBtn.disabled = false;
            confirmBtn.classList.remove("is-confirmed");
            const span = confirmBtn.querySelector("span");
            if (span) span.textContent = "Confirm Payment";
        }

        if (typeof lucide !== "undefined") lucide.createIcons();

        modal.classList.add("active");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.remove("active");
        document.body.style.overflow = "";
    }

    rows.forEach((row) => row.addEventListener("click", () => openModal(row)));
    overlay?.addEventListener("click", closeModal);
    closeButtons.forEach((btn) => btn.addEventListener("click", closeModal));
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
            if (modal?.classList.contains("active")) closeModal();
            closeCorrectionModal();
        }
    });

    // ---- Confirm Payment (mock transition only, no network call) ----------
    confirmBtn?.addEventListener("click", function () {
        if (!currentRow || confirmBtn.disabled) return;
        confirmBtn.disabled = true;
        const span = confirmBtn.querySelector("span");
        if (span) span.textContent = "Confirming…";

        setTimeout(() => {
            if (span) span.textContent = "Payment Verified · Receipt Sent";
            confirmBtn.classList.add("is-confirmed");
            setTimeout(() => {
                markRowVerified(currentRow);
                closeModal();
            }, 900);
        }, 700);
    });

    function markRowVerified(row) {
        row.classList.add("mock-completed-row");
        row.style.opacity = "0.6";
        row.style.pointerEvents = "none";
        const badge = row.querySelector(".status-badge");
        if (badge) {
            badge.textContent = "Completed (mock)";
            badge.className = "job-row-badge status-badge status-completed-preview";
        }
        const dot = row.querySelector(".job-row-dot");
        if (dot) dot.className = "job-row-dot dot-completed-preview";
    }

    // ---- Return for Correction reason modal --------------------------------
    const correctionBackdrop = document.getElementById("correctionModalBackdrop");
    const correctionInput = document.getElementById("correctionReasonInput");
    const correctionError = document.getElementById("correctionReasonError");

    function openCorrectionModal() {
        if (!correctionBackdrop) return;
        if (correctionInput) correctionInput.value = "";
        if (correctionError) correctionError.style.display = "none";
        correctionBackdrop.classList.add("is-open");
    }

    function closeCorrectionModal() {
        correctionBackdrop?.classList.remove("is-open");
    }

    returnBtn?.addEventListener("click", openCorrectionModal);
    document.getElementById("correctionCancelBtn")?.addEventListener("click", closeCorrectionModal);
    correctionBackdrop?.addEventListener("click", function (e) {
        if (e.target === correctionBackdrop) closeCorrectionModal();
    });

    document.getElementById("correctionConfirmBtn")?.addEventListener("click", function () {
        const reason = (correctionInput?.value || "").trim();
        if (!reason) {
            if (correctionError) correctionError.style.display = "";
            correctionInput?.focus();
            return;
        }
        if (!currentRow) return;

        // Flip the row's mock state to Correction Required.
        currentRow.dataset.bucket = "correction-required";
        currentRow.dataset.statusSlug = "correction-required";
        currentRow.dataset.statusLabel = STATUS_LABELS["correction-required"];
        currentRow.dataset.returnReason = reason;

        const dot = currentRow.querySelector(".job-row-dot");
        if (dot) dot.className = "job-row-dot dot-correction-required";
        const badge = currentRow.querySelector(".status-badge");
        if (badge) {
            badge.textContent = STATUS_LABELS["correction-required"];
            badge.className = "job-row-badge status-badge status-correction-required";
        }
        const secondaryText = currentRow.querySelector(".mock-secondary-line span");
        if (secondaryText) secondaryText.textContent = "Service already completed · Submission needs correction";

        closeCorrectionModal();
        closeModal();

        // Re-apply whatever tab is currently active so the row correctly
        // appears/disappears under its new bucket.
        const activeTab = document.querySelector("#jobsMockTabs .rb-tab.is-active");
        if (activeTab) applyTabFilter(activeTab.dataset.tab);
    });
});
