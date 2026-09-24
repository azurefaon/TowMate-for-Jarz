document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") lucide.createIcons();

    const csrfToken =
        document.querySelector(".jobs-page")?.dataset.csrf ??
        document.querySelector('meta[name="csrf-token"]')?.content ?? "";

    const tabs = document.querySelectorAll("#jobsTabs .rb-tab");
    const rows = document.querySelectorAll(".js-open-job-row");
    const searchInput = document.getElementById("jobsSearch");

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
    const reassignBtn = document.getElementById("drawerReassignBtn");

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
    const vehiclesSection = document.getElementById("drawer-vehicles-section");
    const vehiclesGrid = document.getElementById("drawer-vehicles-grid");
    const vehiclePhotosSection = document.getElementById("drawer-vehicle-photos-section");
    const vehiclePhotosGrid = document.getElementById("drawer-vehicle-photos-grid");

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

        const distanceKm = row.dataset.distanceKm;
        const hasDistance = distanceKm !== undefined && distanceKm !== "";
        if (distanceWrap) distanceWrap.style.display = hasDistance ? "" : "none";
        if (hasDistance) fillField("drawer-distance", parseFloat(distanceKm).toFixed(1) + " km");

        fillField("drawer-service", row.dataset.service);
        fillField("drawer-unit", row.dataset.unit);
        fillField("drawer-teamleader", row.dataset.teamleader);
        fillField("drawer-driver", row.dataset.driver);

        if (unitTitle) unitTitle.textContent = isAwaiting ? "Unit Used at Service" : "Assigned Unit";

        let groupVehicles = [];
        try {
            groupVehicles = JSON.parse(row.dataset.groupVehicles || "[]");
        } catch (e) {
            groupVehicles = [];
        }
        if (vehiclesSection && vehiclesGrid) {
            vehiclesGrid.textContent = "";
            if (groupVehicles.length > 1) {
                groupVehicles.forEach(function (v) {
                    const item = document.createElement("div");
                    item.className = "jobs-drawer-item full-width";
                    const label = document.createElement("span");
                    label.className = "jobs-drawer-label";
                    label.textContent = v.booking_code;
                    const value = document.createElement("span");
                    value.className = "jobs-drawer-value";
                    value.textContent = v.unit + " · " + v.team_leader + " · " + v.status;
                    item.appendChild(label);
                    item.appendChild(value);
                    vehiclesGrid.appendChild(item);
                });
                vehiclesSection.style.display = "";
            } else {
                vehiclesSection.style.display = "none";
            }
        }

        let vehicleImages = [];
        try {
            vehicleImages = JSON.parse(row.dataset.vehicleImages || "[]");
        } catch (e) {
            vehicleImages = [];
        }
        if (vehiclePhotosSection && vehiclePhotosGrid) {
            vehiclePhotosGrid.textContent = "";
            if (vehicleImages.length > 0) {
                vehicleImages.forEach(function (photo) {
                    const link = document.createElement("a");
                    link.className = "jobs-photo-thumb";
                    link.href = photo.url;
                    link.target = "_blank";
                    link.rel = "noopener noreferrer";
                    const img = document.createElement("img");
                    img.src = photo.url;
                    img.alt = "Vehicle photo — " + (photo.booking_code || "");
                    link.appendChild(img);
                    vehiclePhotosGrid.appendChild(link);
                });
                vehiclePhotosSection.style.display = "";
            } else {
                vehiclePhotosSection.style.display = "none";
            }
        }

        if (completedWrap) completedWrap.style.display = isAwaiting ? "" : "none";
        if (isAwaiting) fillField("drawer-completed-at", row.dataset.serviceCompletedAt);

        const paymentReady = row.dataset.paymentReady === "1";
        const isCash = row.dataset.paymentMethod === "Cash";

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
                    fillField("drawer-amount-submitted-label", isCash ? "Cash Received" : "Amount Submitted");
                    fillField("drawer-amount-submitted", "₱" + row.dataset.amountSubmitted);
                }
                if (diffWrap) {
                    diffWrap.style.display = "";
                    const diff = submittedAmount - dueAmount;
                    if (isCash) {
                        fillField("drawer-difference-label", "Change");
                        fillField("drawer-difference", "₱" + diff.toFixed(2));
                    } else {
                        fillField("drawer-difference-label", "Difference");
                        const sign = diff > 0 ? "+" : "";
                        fillField("drawer-difference", "₱" + sign + diff.toFixed(2));
                    }
                }
                if (paidWrap) paidWrap.style.display = "none";
            }
        }

        const hasProof = !!row.dataset.proofUrl;
        if (proofSection) proofSection.style.display = isAwaiting && paymentReady ? "" : "none";
        if (isAwaiting && paymentReady) {
            if (hasProof) {
                if (proofLink) proofLink.href = row.dataset.proofUrl;
                if (proofImg) proofImg.src = row.dataset.proofUrl;
                if (proofLink) proofLink.style.display = "";
            } else if (proofLink) {
                proofLink.style.display = "none";
            }
            if (cashNote) {
                if (isCash) {
                    cashNote.style.display = "";
                    cashNote.textContent = row.dataset.cashReceived
                        ? "Cash received: ₱" + row.dataset.cashReceived
                        : "Cash received on-site — no proof image required.";
                } else {
                    cashNote.style.display = "none";
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

        // Only offered while the booking's raw status is exactly "assigned" —
        // the "assigned" bucket also covers "accepted", so the bucket alone
        // isn't precise enough to gate this.
        if (reassignBtn) {
            reassignBtn.style.display = row.dataset.status === "assigned" ? "" : "none";
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

    // ---- Reassign Task (dispatcher correction of an accidental assignment) ----
    const jrModal = document.getElementById("jrReassignModal");
    const jrModalBookingCode = document.getElementById("jrModalBookingCode");
    const jrModalCloseBtn = document.getElementById("jrModalCloseBtn");
    const jrCurrentUnit = document.getElementById("jrCurrentUnit");
    const jrCurrentTl = document.getElementById("jrCurrentTl");
    const jrUnitSelect = document.getElementById("jrUnitSelect");
    const jrEmptyState = document.getElementById("jrEmptyState");
    const jrReasonSelect = document.getElementById("jrReasonSelect");
    const jrNotesInput = document.getElementById("jrNotesInput");
    const jrNotesRequiredHint = document.getElementById("jrNotesRequiredHint");
    const jrModalError = document.getElementById("jrModalError");
    const jrModalCancelBtn = document.getElementById("jrModalCancelBtn");
    const jrModalConfirmBtn = document.getElementById("jrModalConfirmBtn");

    function closeReassignModal() {
        if (!jrModal) return;
        jrModal.style.display = "none";
        jrModal.setAttribute("aria-hidden", "true");
    }

    function validateReassignForm() {
        if (!jrModalConfirmBtn) return;
        const hasUnit = !!(jrUnitSelect && jrUnitSelect.value);
        const reason = jrReasonSelect ? jrReasonSelect.value : "";
        const notes = jrNotesInput ? jrNotesInput.value.trim() : "";
        const needsNotes = reason === "Other";

        if (jrNotesRequiredHint) jrNotesRequiredHint.style.display = needsNotes ? "" : "none";

        jrModalConfirmBtn.disabled = !hasUnit || !reason || (needsNotes && !notes);
    }

    async function openReassignModal() {
        if (!jrModal || !currentRow) return;

        const optionsUrl = currentRow.dataset.reassignOptionsUrl;
        if (!optionsUrl) return;

        if (jrModalBookingCode) jrModalBookingCode.textContent = currentRow.dataset.bookingCode || "—";
        if (jrCurrentUnit) jrCurrentUnit.textContent = currentRow.dataset.unit || "—";
        if (jrCurrentTl) jrCurrentTl.textContent = currentRow.dataset.teamleader || "—";
        if (jrReasonSelect) jrReasonSelect.value = "";
        if (jrNotesInput) jrNotesInput.value = "";
        if (jrModalError) jrModalError.textContent = "";
        if (jrUnitSelect) jrUnitSelect.innerHTML = '<option value="">Loading options…</option>';
        if (jrEmptyState) jrEmptyState.style.display = "none";
        if (jrModalConfirmBtn) jrModalConfirmBtn.disabled = true;

        jrModal.style.display = "flex";
        jrModal.setAttribute("aria-hidden", "false");

        try {
            const res = await fetch(optionsUrl, {
                headers: { Accept: "application/json" },
            });
            const data = await res.json();

            if (!data.success) {
                if (jrUnitSelect) jrUnitSelect.innerHTML = '<option value="">—</option>';
                if (jrModalError) jrModalError.textContent = data.message || "This booking can no longer be reassigned from here.";
                return;
            }

            if (jrCurrentUnit && data.current) jrCurrentUnit.textContent = data.current.unit_name || "—";
            if (jrCurrentTl && data.current) jrCurrentTl.textContent = data.current.team_leader_name || "—";

            const options = data.options || [];
            if (jrUnitSelect) {
                jrUnitSelect.innerHTML = "";
                const placeholder = document.createElement("option");
                placeholder.value = "";
                placeholder.textContent = options.length ? "Select a unit / team leader…" : "No eligible units available";
                jrUnitSelect.appendChild(placeholder);
                options.forEach(function (opt) {
                    const el = document.createElement("option");
                    el.value = String(opt.unit_id);
                    el.textContent = opt.label;
                    jrUnitSelect.appendChild(el);
                });
            }
            if (jrEmptyState) jrEmptyState.style.display = options.length ? "none" : "";
        } catch (e) {
            if (jrUnitSelect) jrUnitSelect.innerHTML = '<option value="">—</option>';
            if (jrModalError) jrModalError.textContent = "Could not load reassignment options. Try again.";
        }

        validateReassignForm();
    }

    reassignBtn?.addEventListener("click", openReassignModal);
    jrModalCloseBtn?.addEventListener("click", closeReassignModal);
    jrModalCancelBtn?.addEventListener("click", closeReassignModal);
    jrModal?.addEventListener("click", function (e) {
        if (e.target === jrModal) closeReassignModal();
    });
    jrUnitSelect?.addEventListener("change", validateReassignForm);
    jrReasonSelect?.addEventListener("change", validateReassignForm);
    jrNotesInput?.addEventListener("input", validateReassignForm);

    jrModalConfirmBtn?.addEventListener("click", async function () {
        if (!currentRow || jrModalConfirmBtn.disabled) return;

        const reassignUrl = currentRow.dataset.reassignUrl;
        if (!reassignUrl) return;

        jrModalConfirmBtn.disabled = true;
        jrModalConfirmBtn.textContent = "Reassigning…";
        if (jrModalError) jrModalError.textContent = "";

        try {
            const res = await fetch(reassignUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                    Accept: "application/json",
                },
                body: JSON.stringify({
                    assigned_unit_id: jrUnitSelect ? jrUnitSelect.value : "",
                    reason: jrReasonSelect ? jrReasonSelect.value : "",
                    notes: jrNotesInput ? jrNotesInput.value.trim() : "",
                }),
            });
            const data = await res.json();

            if (!data.success) {
                if (jrModalError) jrModalError.textContent = data.message || "Could not reassign this task.";
                jrModalConfirmBtn.disabled = false;
                jrModalConfirmBtn.textContent = "Confirm Reassignment";
                return;
            }

            // Update the row + drawer in place — no full reload needed since
            // the booking's status/bucket doesn't change, only ownership.
            currentRow.dataset.unit = data.unit_name || "";
            currentRow.dataset.teamleader = data.team_leader_name || "";
            currentRow.dataset.driver = data.driver_name || "";

            const unitCell = currentRow.cells ? currentRow.cells[2] : null;
            if (unitCell) {
                const primary = unitCell.querySelector(".jobs-cell-primary");
                const secondary = unitCell.querySelector(".jobs-cell-secondary");
                if (primary) primary.textContent = data.unit_name || "Unassigned";
                if (secondary) secondary.textContent = data.team_leader_name || "Unassigned";
            }

            fillField("drawer-unit", data.unit_name);
            fillField("drawer-teamleader", data.team_leader_name);
            fillField("drawer-driver", data.driver_name);
            if (jrCurrentUnit) jrCurrentUnit.textContent = data.unit_name || "—";
            if (jrCurrentTl) jrCurrentTl.textContent = data.team_leader_name || "—";

            jrModalConfirmBtn.textContent = "Confirm Reassignment";
            closeReassignModal();
        } catch (e) {
            if (jrModalError) jrModalError.textContent = "Error — retry.";
            jrModalConfirmBtn.disabled = false;
            jrModalConfirmBtn.textContent = "Confirm Reassignment";
        }
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && jrModal && jrModal.style.display !== "none") closeReassignModal();
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
