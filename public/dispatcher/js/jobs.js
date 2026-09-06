document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") lucide.createIcons();

    const csrfToken =
        document.querySelector(".jobs-page")?.dataset.csrf ??
        document.querySelector('meta[name="csrf-token"]')?.content ?? "";

    const tabs = document.querySelectorAll("#jobsTabs .rb-tab");
    const rows = document.querySelectorAll(".js-open-job-row");
    const searchInput = document.getElementById("jobsSearch");

    // Both the active tab and the search box filter the same rendered page
    // of rows (12/page — see JobsController::index()). Tab counts themselves
    // come from server-side stats, not from counting visible rows, since
    // pagination means not every matching job is in the DOM at once.
    function applyFilters() {
        const activeTabBtn = document.querySelector("#jobsTabs .rb-tab.is-active");
        const tab = activeTabBtn ? activeTabBtn.dataset.tab : "all";
        const query = (searchInput ? searchInput.value : "").trim().toLowerCase();

        rows.forEach((row) => {
            const matchesTab = tab === "all" || row.dataset.bucket === tab;
            const text = (row.textContent || "").toLowerCase();
            const matchesQuery = !query || text.indexOf(query) > -1;
            row.style.display = matchesTab && matchesQuery ? "" : "none";
        });
    }

    tabs.forEach((btn) => {
        btn.addEventListener("click", function () {
            tabs.forEach((b) => b.classList.remove("is-active"));
            btn.classList.add("is-active");
            applyFilters();
        });
    });

    if (searchInput) searchInput.addEventListener("input", applyFilters);

    const drawer = document.getElementById("jobsDrawer");
    const backdrop = document.getElementById("jobsDrawerBackdrop");
    const closeBtn = document.getElementById("jobsDrawerClose");
    const confirmBtn = document.getElementById("drawerConfirmPaymentBtn");

    const paymentSection = document.getElementById("drawer-payment-section");
    const proofSection = document.getElementById("drawer-proof-section");
    const signatureSection = document.getElementById("drawer-signature-section");
    const proofLink = document.getElementById("drawer-proof-link");
    const proofImg = document.getElementById("drawer-proof-img");
    const cashNote = document.getElementById("drawer-cash-note");
    const signatureImg = document.getElementById("drawer-signature-img");
    const signatureCaption = document.getElementById("drawer-signature-caption");
    const completedWrap = document.getElementById("drawer-completed-wrap");
    const distanceWrap = document.getElementById("drawer-distance-wrap");
    const unitTitle = document.getElementById("drawer-unit-title");

    let currentRow = null;

    const fillField = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value || "—";
    };

    function openDrawer(row) {
        if (!drawer || !row) return;
        currentRow = row;

        const bucket = row.dataset.bucket;
        const isAwaiting = bucket === "awaiting-verification";

        fillField("drawerBookingCode", row.dataset.bookingCode);
        const statusEl = document.getElementById("drawerStatus");
        if (statusEl) {
            statusEl.textContent = row.dataset.statusLabel || "";
            statusEl.className = "jobs-drawer-status jobs-status-" + row.dataset.bucketClass;
        }

        fillField("drawer-customer", row.dataset.customer);
        fillField("drawer-phone", row.dataset.phone);
        fillField("drawer-email", row.dataset.email);
        fillField("drawer-pickup", row.dataset.pickup);
        fillField("drawer-dropoff", row.dataset.dropoff);

        // distance_km is the existing authoritative booking field (see
        // jobs.blade.php's data-distance-km) — not recalculated here.
        const distanceKm = row.dataset.distanceKm;
        const hasDistance = distanceKm !== undefined && distanceKm !== "";
        if (distanceWrap) distanceWrap.style.display = hasDistance ? "" : "none";
        if (hasDistance) fillField("drawer-distance", parseFloat(distanceKm).toFixed(1) + " km");

        fillField("drawer-service", row.dataset.service);
        fillField("drawer-unit", row.dataset.unit);
        fillField("drawer-teamleader", row.dataset.teamleader);
        fillField("drawer-driver", row.dataset.driver);

        if (unitTitle) unitTitle.textContent = isAwaiting ? "Unit Used at Service" : "Assigned Unit";

        if (completedWrap) completedWrap.style.display = isAwaiting ? "" : "none";
        if (isAwaiting) fillField("drawer-completed-at", row.dataset.serviceCompletedAt);

        const paymentReady = row.dataset.paymentReady === "1";

        if (paymentSection) paymentSection.style.display = isAwaiting ? "" : "none";
        if (isAwaiting) {
            fillField("drawer-payment-method", paymentReady ? row.dataset.paymentMethod : "Not yet submitted");
            fillField("drawer-submitted-at", paymentReady ? row.dataset.paymentSubmittedAt : "—");

            const dueWrap = document.getElementById("drawer-amount-due-wrap");
            const submittedWrap = document.getElementById("drawer-amount-submitted-wrap");
            const diffWrap = document.getElementById("drawer-difference-wrap");
            const paidWrap = document.getElementById("drawer-amount-paid-wrap");

            const toNumber = (v) => {
                const n = parseFloat((v || "").replace(/,/g, ""));
                return isNaN(n) ? null : n;
            };
            const dueAmount = toNumber(row.dataset.total);
            const submittedAmount = paymentReady ? toNumber(row.dataset.amountSubmitted) : null;
            const isExactMatch =
                dueAmount !== null && submittedAmount !== null && Math.abs(dueAmount - submittedAmount) < 0.005;

            if (!paymentReady) {
                if (dueWrap) dueWrap.style.display = "";
                fillField("drawer-amount-due", dueAmount !== null ? "₱" + row.dataset.total : "—");
                if (submittedWrap) submittedWrap.style.display = "none";
                if (diffWrap) diffWrap.style.display = "none";
                if (paidWrap) paidWrap.style.display = "none";
            } else if (isExactMatch) {
                if (dueWrap) dueWrap.style.display = "none";
                if (submittedWrap) submittedWrap.style.display = "none";
                if (diffWrap) diffWrap.style.display = "none";
                if (paidWrap) {
                    paidWrap.style.display = "";
                    fillField("drawer-amount-paid", "₱" + row.dataset.amountSubmitted);
                }
            } else {
                if (dueWrap) {
                    dueWrap.style.display = "";
                    fillField("drawer-amount-due", "₱" + row.dataset.total);
                }
                if (submittedWrap) {
                    submittedWrap.style.display = "";
                    fillField("drawer-amount-submitted", "₱" + row.dataset.amountSubmitted);
                }
                if (diffWrap) {
                    diffWrap.style.display = "";
                    const diff = submittedAmount - dueAmount;
                    const sign = diff > 0 ? "+" : "";
                    fillField("drawer-difference", "₱" + sign + diff.toFixed(2));
                }
                if (paidWrap) paidWrap.style.display = "none";
            }
        }

        const hasProof = !!row.dataset.proofUrl;
        const isCash = row.dataset.paymentMethod === "Cash";
        if (proofSection) proofSection.style.display = isAwaiting && paymentReady ? "" : "none";
        if (isAwaiting && paymentReady) {
            if (hasProof && !isCash) {
                if (proofLink) proofLink.href = row.dataset.proofUrl;
                if (proofImg) proofImg.src = row.dataset.proofUrl;
                if (proofLink) proofLink.style.display = "";
                if (cashNote) cashNote.style.display = "none";
            } else {
                if (proofLink) proofLink.style.display = "none";
                if (cashNote) {
                    cashNote.style.display = "";
                    cashNote.textContent = row.dataset.cashReceived
                        ? "Cash received: ₱" + row.dataset.cashReceived
                        : "Cash received on-site — no proof image required.";
                }
            }
        }

        const hasSignature = !!row.dataset.signatureUrl;
        if (signatureSection) signatureSection.style.display = isAwaiting && hasSignature ? "" : "none";
        if (hasSignature) {
            if (signatureImg) signatureImg.src = row.dataset.signatureUrl;
            if (signatureCaption) signatureCaption.textContent = "Signed " + (row.dataset.paymentSubmittedAt || "");
        }

        if (confirmBtn) {
            confirmBtn.style.display = isAwaiting && paymentReady ? "" : "none";
            confirmBtn.disabled = false;
            confirmBtn.classList.remove("is-confirmed");
            const span = confirmBtn.querySelector("span");
            if (span) span.textContent = "Confirm Payment";
        }

        if (typeof lucide !== "undefined") lucide.createIcons();

        drawer.classList.add("is-open");
        backdrop?.classList.add("is-open");
        document.body.style.overflow = "hidden";
    }

    function closeDrawer() {
        if (!drawer) return;
        drawer.classList.remove("is-open");
        backdrop?.classList.remove("is-open");
        document.body.style.overflow = "";
        currentRow = null;
    }

    rows.forEach((row) => {
        row.addEventListener("click", () => openDrawer(row));
        row.addEventListener("keydown", (e) => {
            if (e.key !== "Enter" && e.key !== " ") return;
            e.preventDefault();
            openDrawer(row);
        });
    });
    backdrop?.addEventListener("click", closeDrawer);
    closeBtn?.addEventListener("click", closeDrawer);
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && drawer?.classList.contains("is-open")) closeDrawer();
    });

    confirmBtn?.addEventListener("click", async function () {
        if (!currentRow || confirmBtn.disabled) return;

        const confirmUrl = currentRow.dataset.confirmUrl;
        if (!confirmUrl) return;

        confirmBtn.disabled = true;
        const span = confirmBtn.querySelector("span");
        if (span) span.textContent = "Confirming…";

        try {
            const res = await fetch(confirmUrl, {
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
                if (span) span.textContent = "Payment Verified · Receipt Sent";
                confirmBtn.classList.add("is-confirmed");
                setTimeout(() => { closeDrawer(); window.location.reload(); }, 1200);
            } else {
                if (span) span.textContent = data.message || "Failed";
                confirmBtn.disabled = false;
            }
        } catch {
            if (span) span.textContent = "Error — retry";
            confirmBtn.disabled = false;
        }
    });

    const params = new URLSearchParams(window.location.search);
    const highlightCode = params.get("booking");

    if (highlightCode) {
        const target = document.querySelector(
            '.js-open-job-row[data-booking-code="' + CSS.escape(highlightCode) + '"]'
        );
        if (target) {
            target.scrollIntoView({ behavior: "smooth", block: "center" });
            target.classList.add("jobs-row--highlight");
            setTimeout(() => target.classList.remove("jobs-row--highlight"), 2600);
        }
        window.history.replaceState({}, document.title, window.location.pathname);
    }
});
