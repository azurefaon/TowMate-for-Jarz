document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") lucide.createIcons();

    const modal             = document.getElementById("jobModal");
    const overlay           = modal?.querySelector(".modal-overlay");
    const closeButtons      = modal?.querySelectorAll(".close-modal, .close-modal-btn") ?? [];
    const confirmPaymentBtn    = document.getElementById("confirmPaymentBtn");
    const paymentSection       = document.getElementById("paymentSection");
    const paymentProofArea     = document.getElementById("paymentProofArea");
    const modalStatusPill      = document.getElementById("modalStatusPill");
    const totalSection         = document.getElementById("totalSection");
    const vehicleImagesSection = document.getElementById("vehicleImagesSection");
    const vehicleImagesContainer = document.getElementById("vehicleImagesContainer");
    const jobCashRow        = document.getElementById("jobCashRow");
    const jobCashReceivedEl = document.getElementById("jobCashReceivedDisplay");

    const csrfToken =
        document.querySelector(".jobs-page")?.dataset.csrf ??
        document.querySelector('meta[name="csrf-token"]')?.content ?? "";

    let currentConfirmUrl = null;

    const fillField = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value || "—";
    };

    const formatStatus = (raw) =>
        raw
            ? raw.split("_").map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(" ")
            : "—";

    const openModal = (row) => {
        if (!modal || !row) return;

        const status        = row.dataset.status ?? "";
        const statusSlug    = status.replace(/_/g, "-");
        const isPaymentSent = status === "payment_submitted";
        const isPaymentPend = status === "payment_pending";
        const hasPayment    = isPaymentSent || isPaymentPend;

        // Header
        fillField("modalTitle", row.dataset.jobId ? `Job ${row.dataset.jobId}` : "Job Details");

        if (modalStatusPill) {
            modalStatusPill.textContent  = formatStatus(status);
            modalStatusPill.className    = `modal-status-pill status-badge status-${statusSlug}`;
        }

        // Fields
        fillField("job-customer",   row.dataset.customer);
        fillField("job-phone",      row.dataset.phone);
        fillField("job-email",      row.dataset.email);
        fillField("job-service",    row.dataset.service);
        fillField("job-unit",       row.dataset.unit);
        fillField("job-teamleader", row.dataset.teamleader);
        fillField("job-driver",     row.dataset.driver);
        fillField("job-pickup",     row.dataset.pickup);
        fillField("job-dropoff",    row.dataset.dropoff);
        fillField("job-time",       row.dataset.created);

        // Total price
        const total = row.dataset.total;
        if (totalSection) totalSection.style.display = total ? "" : "none";
        if (total) fillField("job-total", "₱" + total);

        // Vehicle images from customer
        let vehicleUrls = [];
        try { vehicleUrls = JSON.parse(row.dataset.vehicleImages || "[]"); } catch { vehicleUrls = []; }
        if (vehicleImagesSection) vehicleImagesSection.style.display = vehicleUrls.length > 0 ? "" : "none";
        if (vehicleImagesContainer) {
            vehicleImagesContainer.innerHTML = "";
            vehicleUrls.forEach((url) => {
                const a   = document.createElement("a");
                a.href    = url;
                a.target  = "_blank";
                a.rel     = "noopener noreferrer";
                a.style.cssText = "flex:1 1 calc(50% - 4px);min-width:100px;max-width:200px;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;display:block;";
                const img = document.createElement("img");
                img.src   = url;
                img.alt   = "Vehicle";
                img.style.cssText = "width:100%;height:130px;object-fit:cover;display:block;";
                a.appendChild(img);
                vehicleImagesContainer.appendChild(a);
            });
        }

        // Payment section
        if (paymentSection) paymentSection.style.display = hasPayment ? "" : "none";

        if (hasPayment) {
            fillField("job-payment-method",
                row.dataset.paymentMethod ? row.dataset.paymentMethod.toUpperCase() : "—");
            fillField("job-payment-submitted-at", row.dataset.paymentSubmittedAt || "—");

            let proofUrls = [];
            try { proofUrls = JSON.parse(row.dataset.paymentProof || "[]"); } catch { proofUrls = []; }
            if (!Array.isArray(proofUrls)) proofUrls = proofUrls ? [proofUrls] : [];

            const proofContainer = document.getElementById("job-payment-proof-container");
            if (paymentProofArea) paymentProofArea.style.display = proofUrls.length > 0 ? "" : "none";
            if (proofContainer) {
                proofContainer.innerHTML = "";
                proofUrls.forEach((url) => {
                    const a   = document.createElement("a");
                    a.href    = url;
                    a.target  = "_blank";
                    a.rel     = "noopener noreferrer";
                    a.style.cssText = "flex:1 1 calc(50% - 4px);min-width:80px;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;display:block;";
                    const img = document.createElement("img");
                    img.src   = url;
                    img.alt   = "Payment proof";
                    img.className  = "payment-proof-img";
                    img.style.cssText = "width:100%;max-height:160px;object-fit:contain;display:block;";
                    a.appendChild(img);
                    proofContainer.appendChild(a);
                });
            }
        }

        // Confirm Payment button — only for payment_submitted
        currentConfirmUrl = isPaymentSent ? (row.dataset.confirmUrl ?? null) : null;
        const isCash = !row.dataset.paymentMethod || row.dataset.paymentMethod === "cash";
        if (jobCashReceivedEl) {
            jobCashReceivedEl.textContent = row.dataset.cashReceived ? "₱" + row.dataset.cashReceived : "—";
        }
        if (jobCashRow) jobCashRow.style.display = isPaymentSent && isCash ? "" : "none";
        if (confirmPaymentBtn) {
            confirmPaymentBtn.style.display = isPaymentSent ? "" : "none";
            confirmPaymentBtn.disabled      = false;
            confirmPaymentBtn.classList.remove("is-confirmed");
            const span = confirmPaymentBtn.querySelector("span");
            if (span) span.textContent = "Confirm Payment";
        }

        if (typeof lucide !== "undefined") lucide.createIcons();

        modal.classList.add("active");
        document.body.style.overflow = "hidden";
    };

    const closeModal = () => {
        if (!modal) return;
        modal.classList.remove("active");
        document.body.style.overflow = "";
        currentConfirmUrl = null;
    };

    // Each list row is clickable
    document.querySelectorAll(".js-open-job-row").forEach((row) => {
        row.addEventListener("click", function () {
            openModal(this);
        });
    });

    overlay?.addEventListener("click", closeModal);
    closeButtons.forEach((btn) => btn.addEventListener("click", closeModal));

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && modal?.classList.contains("active")) closeModal();
    });

    confirmPaymentBtn?.addEventListener("click", async function () {
        if (!currentConfirmUrl || confirmPaymentBtn.disabled) return;

        confirmPaymentBtn.disabled = true;
        const span = confirmPaymentBtn.querySelector("span");
        if (span) span.textContent = "Confirming…";

        try {
            const res  = await fetch(currentConfirmUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                    Accept: "application/json",
                },
                body: JSON.stringify({}),
            });
            const data = await res.json();

            if (data.success) {
                if (span) span.textContent = "Payment Confirmed!";
                confirmPaymentBtn.classList.add("is-confirmed");
                setTimeout(() => { closeModal(); window.location.reload(); }, 1400);
            } else {
                if (span) span.textContent = data.message || "Failed";
                confirmPaymentBtn.disabled = false;
            }
        } catch {
            if (span) span.textContent = "Error — retry";
            confirmPaymentBtn.disabled = false;
        }
    });
});
