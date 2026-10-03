document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") lucide.createIcons();

    const csrfToken =
        document.querySelector(".jobs-page")?.dataset.csrf ??
        document.querySelector('meta[name="csrf-token"]')?.content ?? "";

    const tabs = document.querySelectorAll("#jobsTabs .rb-tab");
    const rows = document.querySelectorAll(".js-open-job-row");
    const searchInput = document.getElementById("jobsSearch");

    // The single inline-detail template; cloned into the open row's sibling
    // .jobs-detail-row so only one detail exists (and its ids are unique).
    const detailTemplate = document.getElementById("jobsDetailTemplate");

    // The currently expanded job row (also the row the reassign / confirm
    // actions operate on).
    let currentRow = null;

    // Elements inside the open detail; rebound every time a detail is opened.
    let paymentSection = null;
    let proofSection = null;
    let signatureSection = null;
    let proofLink = null;
    let proofImg = null;
    let cashNote = null;
    let signatureImg = null;
    let signatureCaption = null;
    let completedWrap = null;
    let distanceWrap = null;
    let unitTitle = null;
    let vehiclesSection = null;
    let vehiclesGrid = null;
    let vehiclePhotosSection = null;
    let vehiclePhotosGrid = null;
    let confirmBtn = null;
    let reassignBtn = null;
    let cancelBtn = null;
    // Mirrors JobsController::DISPATCHER_CANCELLABLE_STATUSES (display only -
    // the server re-validates the status after locking the booking).
    const CANCELLABLE_STATUSES = ["assigned", "accepted", "on_the_way", "arrived_pickup", "in_progress"];

    const fillField = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value || "—";
    };

    function detailRowFor(row) {
        const next = row ? row.nextElementSibling : null;
        return next && next.classList.contains("jobs-detail-row") ? next : null;
    }

    function applyFilters() {
        const activeTabBtn = document.querySelector("#jobsTabs .rb-tab.is-active");
        const tab = activeTabBtn ? activeTabBtn.dataset.tab : "all";
        const query = (searchInput ? searchInput.value : "").trim().toLowerCase();

        rows.forEach((row) => {
            const matchesTab = tab === "all" || row.dataset.bucket === tab;
            const text = (row.textContent || "").toLowerCase();
            const matchesQuery = !query || text.indexOf(query) > -1;
            const visible = matchesTab && matchesQuery;
            row.style.display = visible ? "" : "none";

            const detailRow = detailRowFor(row);
            if (detailRow) detailRow.style.display = visible ? "" : "none";
            if (!visible && currentRow === row) closeDetail();
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

    function bindDetailElements() {
        paymentSection = document.getElementById("job-detail-payment-section");
        proofSection = document.getElementById("job-detail-proof-section");
        signatureSection = document.getElementById("job-detail-signature-section");
        proofLink = document.getElementById("job-detail-proof-link");
        proofImg = document.getElementById("job-detail-proof-img");
        cashNote = document.getElementById("job-detail-cash-note");
        signatureImg = document.getElementById("job-detail-signature-img");
        signatureCaption = document.getElementById("job-detail-signature-caption");
        completedWrap = document.getElementById("job-detail-completed-wrap");
        distanceWrap = document.getElementById("job-detail-distance-wrap");
        unitTitle = document.getElementById("job-detail-unit-title");
        vehiclesSection = document.getElementById("job-detail-vehicles-section");
        vehiclesGrid = document.getElementById("job-detail-vehicles-grid");
        vehiclePhotosSection = document.getElementById("job-detail-vehicle-photos-section");
        vehiclePhotosGrid = document.getElementById("job-detail-vehicle-photos-grid");
        confirmBtn = document.getElementById("job-detail-confirm-btn");
        reassignBtn = document.getElementById("job-detail-reassign-btn");
        cancelBtn = document.getElementById("job-detail-cancel-btn");
    }

    function renderDetail(row) {
        const bucket = row.dataset.bucket;
        const isAwaiting = bucket === "awaiting-verification";

        fillField("job-detail-phone", row.dataset.phone);
        fillField("job-detail-email", row.dataset.email);
        fillField("job-detail-pickup", row.dataset.pickup);
        fillField("job-detail-dropoff", row.dataset.dropoff);

        const distanceKm = row.dataset.distanceKm;
        const hasDistance = distanceKm !== undefined && distanceKm !== "";
        if (distanceWrap) distanceWrap.style.display = hasDistance ? "" : "none";
        if (hasDistance) fillField("job-detail-distance", parseFloat(distanceKm).toFixed(1) + " km");

        fillField("job-detail-service", row.dataset.service);
        fillField("job-detail-unit", row.dataset.unit);
        fillField("job-detail-teamleader", row.dataset.teamleader);
        fillField("job-detail-driver", row.dataset.driver);

        if (unitTitle) unitTitle.textContent = isAwaiting ? "Unit Used at Service" : "Team / Service";

        // The agreed amount is shown exactly once: here for jobs that have no
        // payment section, otherwise as part of the payment section below.
        const agreedWrap = document.getElementById("job-detail-agreed-wrap");
        const hasTotal = !!row.dataset.total;
        if (agreedWrap) agreedWrap.style.display = !isAwaiting && hasTotal ? "" : "none";
        if (!isAwaiting && hasTotal) fillField("job-detail-agreed", "₱" + row.dataset.total);

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
                    const tr = document.createElement("tr");
                    [v.booking_code, v.unit, v.team_leader, v.status].forEach(function (text) {
                        const td = document.createElement("td");
                        td.textContent = text || "—";
                        tr.appendChild(td);
                    });
                    vehiclesGrid.appendChild(tr);
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
        if (isAwaiting) fillField("job-detail-completed-at", row.dataset.serviceCompletedAt);

        const paymentReady = row.dataset.paymentReady === "1";
        const isCash = row.dataset.paymentMethod === "Cash";

        if (paymentSection) paymentSection.style.display = isAwaiting ? "" : "none";
        if (isAwaiting) {
            fillField("job-detail-payment-method", paymentReady ? row.dataset.paymentMethod : "Not yet submitted");
            fillField("job-detail-submitted-at", paymentReady ? row.dataset.paymentSubmittedAt : "—");

            const dueWrap = document.getElementById("job-detail-amount-due-wrap");
            const submittedWrap = document.getElementById("job-detail-amount-submitted-wrap");
            const diffWrap = document.getElementById("job-detail-difference-wrap");
            const paidWrap = document.getElementById("job-detail-amount-paid-wrap");

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
                fillField("job-detail-amount-due", dueAmount !== null ? "₱" + row.dataset.total : "—");
                if (submittedWrap) submittedWrap.style.display = "none";
                if (diffWrap) diffWrap.style.display = "none";
                if (paidWrap) paidWrap.style.display = "none";
            } else if (isExactMatch) {
                if (dueWrap) dueWrap.style.display = "none";
                if (submittedWrap) submittedWrap.style.display = "none";
                if (diffWrap) diffWrap.style.display = "none";
                if (paidWrap) {
                    paidWrap.style.display = "";
                    fillField("job-detail-amount-paid", "₱" + row.dataset.amountSubmitted);
                }
            } else {
                if (dueWrap) {
                    dueWrap.style.display = "";
                    fillField("job-detail-amount-due", "₱" + row.dataset.total);
                }
                if (submittedWrap) {
                    submittedWrap.style.display = "";
                    fillField("job-detail-amount-submitted-label", isCash ? "Cash Received" : "Amount Submitted");
                    fillField("job-detail-amount-submitted", "₱" + row.dataset.amountSubmitted);
                }
                if (diffWrap) {
                    diffWrap.style.display = "";
                    const diff = submittedAmount - dueAmount;
                    if (isCash) {
                        fillField("job-detail-difference-label", "Change");
                        fillField("job-detail-difference", "₱" + diff.toFixed(2));
                    } else {
                        fillField("job-detail-difference-label", "Difference");
                        const sign = diff > 0 ? "+" : "";
                        fillField("job-detail-difference", "₱" + sign + diff.toFixed(2));
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
            confirmBtn.addEventListener("click", onConfirmClick);
        }

        // Only offered while the booking's raw status is exactly "assigned" —
        // the "assigned" bucket also covers "accepted", so the bucket alone
        // isn't precise enough to gate this.
        if (reassignBtn) {
            reassignBtn.style.display = row.dataset.status === "assigned" ? "" : "none";
            reassignBtn.addEventListener("click", openReassignModal);
        }

        if (cancelBtn) {
            cancelBtn.style.display = CANCELLABLE_STATUSES.indexOf(row.dataset.status) !== -1 ? "" : "none";
            cancelBtn.addEventListener("click", openCancelModal);
        }
    }

    function openDetail(row) {
        if (!detailTemplate || !row) return;
        const detailRow = detailRowFor(row);
        if (!detailRow || !detailRow.cells[0]) return;

        if (currentRow && currentRow !== row) closeDetail();

        const cell = detailRow.cells[0];
        cell.textContent = "";
        cell.appendChild(detailTemplate.content.cloneNode(true));
        detailRow.hidden = false;

        row.setAttribute("aria-expanded", "true");
        row.classList.add("is-open");
        currentRow = row;

        bindDetailElements();
        renderDetail(row);
    }

    function closeDetail() {
        if (!currentRow) return;
        const detailRow = detailRowFor(currentRow);
        if (detailRow) {
            detailRow.hidden = true;
            if (detailRow.cells[0]) detailRow.cells[0].textContent = "";
        }
        currentRow.setAttribute("aria-expanded", "false");
        currentRow.classList.remove("is-open");
        currentRow = null;
    }

    function toggleDetail(row) {
        if (currentRow === row) {
            closeDetail();
        } else {
            openDetail(row);
        }
    }

    rows.forEach((row) => {
        row.addEventListener("click", () => toggleDetail(row));
        row.addEventListener("keydown", (e) => {
            if (e.key !== "Enter" && e.key !== " ") return;
            e.preventDefault();
            toggleDetail(row);
        });
    });

    async function onConfirmClick() {
        if (!currentRow || !confirmBtn || confirmBtn.disabled) return;

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
                setTimeout(() => { closeDetail(); window.location.reload(); }, 1200);
            } else {
                if (span) span.textContent = data.message || "Failed";
                confirmBtn.disabled = false;
            }
        } catch {
            if (span) span.textContent = "Error — retry";
            confirmBtn.disabled = false;
        }
    }

    // ---- Cancel booking (dispatcher override once the customer cannot self-cancel) ----
    const jcModal = document.getElementById("jcCancelModal");
    const jcBookingCode = document.getElementById("jcCancelBookingCode");
    const jcCloseBtn = document.getElementById("jcCancelCloseBtn");
    const jcReason = document.getElementById("jcCancelReason");
    const jcError = document.getElementById("jcCancelError");
    const jcDismissBtn = document.getElementById("jcCancelDismissBtn");
    const jcConfirmBtn = document.getElementById("jcCancelConfirmBtn");

    function closeCancelModal() {
        if (!jcModal) return;
        jcModal.style.display = "none";
        jcModal.setAttribute("aria-hidden", "true");
    }

    function validateCancelForm() {
        if (jcConfirmBtn) jcConfirmBtn.disabled = !(jcReason && jcReason.value.trim());
    }

    function openCancelModal() {
        if (!currentRow || !jcModal) return;
        if (jcBookingCode) jcBookingCode.textContent = currentRow.dataset.bookingCode || "—";
        if (jcReason) jcReason.value = "";
        if (jcError) jcError.textContent = "";
        if (jcConfirmBtn) jcConfirmBtn.textContent = "Confirm cancellation";
        validateCancelForm();
        jcModal.style.display = "";
        jcModal.setAttribute("aria-hidden", "false");
        if (jcReason) jcReason.focus();
    }

    jcCloseBtn?.addEventListener("click", closeCancelModal);
    jcDismissBtn?.addEventListener("click", closeCancelModal);
    jcModal?.addEventListener("click", function (e) {
        if (e.target === jcModal) closeCancelModal();
    });
    jcReason?.addEventListener("input", validateCancelForm);

    jcConfirmBtn?.addEventListener("click", async function () {
        if (!currentRow || jcConfirmBtn.disabled) return;
        const cancelUrl = currentRow.dataset.cancelUrl;
        if (!cancelUrl) return;

        jcConfirmBtn.disabled = true;
        jcConfirmBtn.textContent = "Cancelling…";
        if (jcError) jcError.textContent = "";

        try {
            const res = await fetch(cancelUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                    Accept: "application/json",
                },
                body: JSON.stringify({ reason: jcReason ? jcReason.value.trim() : "" }),
            });
            const data = await res.json().catch(function () { return {}; });

            if (!res.ok || !data.success) {
                if (jcError) jcError.textContent = data.message || "Could not cancel this booking.";
                jcConfirmBtn.disabled = false;
                jcConfirmBtn.textContent = "Confirm cancellation";
                return;
            }

            closeCancelModal();
            window.location.reload();
        } catch (e) {
            if (jcError) jcError.textContent = "Error — retry.";
            jcConfirmBtn.disabled = false;
            jcConfirmBtn.textContent = "Confirm cancellation";
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

            // Update the row + inline detail in place — no full reload needed
            // since the booking's status/bucket doesn't change, only ownership.
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

            fillField("job-detail-unit", data.unit_name);
            fillField("job-detail-teamleader", data.team_leader_name);
            fillField("job-detail-driver", data.driver_name);
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

    // Escape closes the reassign modal first; otherwise it collapses the open job.
    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        if (jcModal && jcModal.style.display !== "none") {
            closeCancelModal();
            return;
        }
        if (jrModal && jrModal.style.display !== "none") {
            closeReassignModal();
            return;
        }
        closeDetail();
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
            openDetail(target);
        }
        window.history.replaceState({}, document.title, window.location.pathname);
    }
});
