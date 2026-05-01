document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") {
        lucide.createIcons();
    }

    const modal = document.getElementById("jobModal");
    const overlay = modal?.querySelector(".modal-overlay");
    const closeButtons =
        modal?.querySelectorAll(".close-modal, .close-modal-btn") ?? [];
    const confirmPaymentBtn = document.getElementById("confirmPaymentBtn");
    const paymentSection = document.getElementById("paymentSection");
    const paymentProofArea = document.getElementById("paymentProofArea");

    const csrfToken =
        document.querySelector(".jobs-page")?.dataset.csrf ??
        document.querySelector('meta[name="csrf-token"]')?.content ??
        "";

    let currentConfirmUrl = null;

    const fillField = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value || "—";
    };

    const formatStatus = (raw) =>
        raw
            ? raw
                  .split("_")
                  .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
                  .join(" ")
            : "—";

    const openModal = (card) => {
        if (!modal || !card) return;

        const status = card.dataset.status ?? "";
        const isPaymentSubmitted = status === "payment_submitted";
        const isPaymentPending = status === "payment_pending";
        const hasPayment = isPaymentSubmitted || isPaymentPending;

        fillField("modalTitle", `Job #${card.dataset.jobId}`);
        fillField("job-customer", card.dataset.customer);
        fillField("job-service", card.dataset.service);
        fillField("job-status", formatStatus(status));
        fillField("job-unit", card.dataset.unit);
        fillField("job-teamleader", card.dataset.teamleader);
        fillField("job-driver", card.dataset.driver);
        fillField("job-pickup", card.dataset.pickup);
        fillField("job-dropoff", card.dataset.dropoff);
        fillField("job-time", card.dataset.created);

        // Payment section
        if (paymentSection) {
            paymentSection.style.display = hasPayment ? "" : "none";
        }
        if (hasPayment) {
            fillField(
                "job-payment-method",
                card.dataset.paymentMethod
                    ? card.dataset.paymentMethod.toUpperCase()
                    : "—",
            );
            fillField(
                "job-payment-submitted-at",
                card.dataset.paymentSubmittedAt || "—",
            );

            var proofUrls = [];
            try {
                proofUrls = JSON.parse(card.dataset.paymentProof || "[]");
            } catch (e) {
                proofUrls = [];
            }
            if (!Array.isArray(proofUrls))
                proofUrls = proofUrls ? [proofUrls] : [];
            var proofContainer = document.getElementById(
                "job-payment-proof-container",
            );
            if (paymentProofArea) {
                paymentProofArea.style.display =
                    proofUrls.length > 0 ? "" : "none";
            }
            if (proofContainer) {
                proofContainer.innerHTML = "";
                proofUrls.forEach(function (url) {
                    var a = document.createElement("a");
                    a.href = url;
                    a.target = "_blank";
                    a.rel = "noopener noreferrer";
                    a.style.cssText =
                        "flex:1 1 calc(50% - 4px);min-width:80px;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;display:block;";
                    var img = document.createElement("img");
                    img.src = url;
                    img.alt = "Payment proof";
                    img.className = "payment-proof-img";
                    img.style.cssText =
                        "width:100%;max-height:160px;object-fit:contain;display:block;";
                    a.appendChild(img);
                    proofContainer.appendChild(a);
                });
            }
        }

        // Confirm Payment button — only for payment_submitted
        currentConfirmUrl = isPaymentSubmitted
            ? (card.dataset.confirmUrl ?? null)
            : null;
        if (confirmPaymentBtn) {
            confirmPaymentBtn.style.display = isPaymentSubmitted ? "" : "none";
            confirmPaymentBtn.disabled = false;
            confirmPaymentBtn.classList.remove("is-confirmed");
            const span = confirmPaymentBtn.querySelector("span");
            if (span) span.textContent = "Confirm Payment";
        }

        if (typeof lucide !== "undefined") {
            lucide.createIcons();
        }

        modal.classList.add("active");
        document.body.style.overflow = "hidden";
    };

    const closeModal = () => {
        if (!modal) return;
        modal.classList.remove("active");
        document.body.style.overflow = "";
        currentConfirmUrl = null;
    };

    document.querySelectorAll(".js-open-job-modal").forEach((button) => {
        button.addEventListener("click", function () {
            openModal(this.closest(".job-card"));
        });
    });

    overlay?.addEventListener("click", closeModal);
    closeButtons.forEach((btn) => btn.addEventListener("click", closeModal));

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && modal?.classList.contains("active")) {
            closeModal();
        }
    });

    confirmPaymentBtn?.addEventListener("click", async function () {
        if (!currentConfirmUrl || confirmPaymentBtn.disabled) return;

        confirmPaymentBtn.disabled = true;
        const span = confirmPaymentBtn.querySelector("span");
        if (span) span.textContent = "Confirming…";

        try {
            const response = await fetch(currentConfirmUrl, {
                method: "POST",
                headers: {
                    "X-CSRF-TOKEN": csrfToken,
                    Accept: "application/json",
                },
            });

            const data = await response.json();

            if (data.success) {
                if (span) span.textContent = "Payment Confirmed!";
                confirmPaymentBtn.classList.add("is-confirmed");
                setTimeout(() => {
                    closeModal();
                    window.location.reload();
                }, 1400);
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
