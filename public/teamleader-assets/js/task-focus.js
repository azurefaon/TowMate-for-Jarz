document.addEventListener("DOMContentLoaded", function () {
    var panel = document.getElementById("focusedTask");
    if (!panel) return;

    // ── Element refs ──
    var statusBadge     = document.getElementById("focusStatusBadge");
    var statusNote      = document.getElementById("focusStatusNote");
    var feedback        = document.getElementById("focusFeedback");
    var proceedBtn      = document.getElementById("proceedBtn");
    var startTowBtn     = document.getElementById("startTowBtn");
    var completeTaskBtn = document.getElementById("completeTaskBtn");
    var returnTaskBtn   = document.getElementById("returnTaskBtn");
    var backToDashBtn   = document.getElementById("backToDashboardBtn");

    // Waiting verification
    var waitingCard = document.getElementById("waitingVerificationCard");

    // Payment area
    var paymentArea       = document.getElementById("paymentArea");
    var paymentForm       = document.getElementById("paymentForm");
    var paymentSubmitted  = document.getElementById("paymentSubmitted");
    var submitPaymentBtn  = document.getElementById("submitPaymentBtn");
    var paymentProofInput = document.getElementById("paymentProofInput");
    var fileDropZone      = document.getElementById("fileDropZone");
    var fileDropPrompt    = document.getElementById("fileDropPrompt");
    var proofPreview      = document.getElementById("proofPreview");
    var proofError        = document.getElementById("proofError");

    // Return modal
    var returnTaskModal         = document.getElementById("returnTaskModal");
    var returnReasonPreset      = document.getElementById("returnReasonPreset");
    var returnReasonNote        = document.getElementById("returnReasonNote");
    var returnReasonError       = document.getElementById("returnReasonError");
    var returnReasonDescription = document.getElementById("returnReasonDescription");
    var returnReasonNoteReq     = document.getElementById("returnReasonNoteRequired");
    var returnReasonNoteHint    = document.getElementById("returnReasonNoteHint");
    var cancelReturnBtn         = document.getElementById("cancelReturnBtn");
    var confirmReturnBtn        = document.getElementById("confirmReturnBtn");

    var returnReasons       = [];
    var selectedReturnReason = null;
    var selectedPaymentMethod = null;
    var selectedProofFile     = null;
    var pollTimer             = null;

    // Toast container
    var toastWrap = document.createElement("div");
    toastWrap.className = "tl-toast-wrap";
    document.body.appendChild(toastWrap);

    // ── Boot ──
    applyStatus(panel.dataset.currentStatus || "assigned");
    updateFlowSteps(panel.dataset.currentStatus || "assigned");
    loadReturnReasons();
    startPolling();

    // ── Action buttons ──
    proceedBtn && proceedBtn.addEventListener("click", function () {
        submitAction(panel.dataset.proceedEndpoint, {});
    });

    startTowBtn && startTowBtn.addEventListener("click", function () {
        submitAction(panel.dataset.startEndpoint, {});
    });

    completeTaskBtn && completeTaskBtn.addEventListener("click", function () {
        submitAction(panel.dataset.completeEndpoint, {});
    });

    returnTaskBtn && returnTaskBtn.addEventListener("click", openReturnModal);

    // ── Payment method selection ──
    document.querySelectorAll('input[name="payment_method"]').forEach(function (radio) {
        radio.addEventListener("change", function () {
            selectedPaymentMethod = this.value;
            checkPaymentReady();
        });
    });

    // ── File drop zone ──
    if (fileDropZone) {
        fileDropZone.addEventListener("click", function () {
            if (paymentProofInput) paymentProofInput.click();
        });

        fileDropZone.addEventListener("dragover", function (e) {
            e.preventDefault();
            fileDropZone.classList.add("is-hover");
        });

        fileDropZone.addEventListener("dragleave", function () {
            fileDropZone.classList.remove("is-hover");
        });

        fileDropZone.addEventListener("drop", function (e) {
            e.preventDefault();
            fileDropZone.classList.remove("is-hover");
            var file = e.dataTransfer.files[0];
            if (file) handleProofFile(file);
        });
    }

    if (paymentProofInput) {
        paymentProofInput.addEventListener("change", function () {
            if (this.files[0]) handleProofFile(this.files[0]);
        });
    }

    // ── Submit payment ──
    if (submitPaymentBtn) {
        submitPaymentBtn.addEventListener("click", function () {
            if (!selectedPaymentMethod) {
                showProofError("Please select a payment method.");
                return;
            }
            if (!selectedProofFile) {
                showProofError("Please upload proof of payment.");
                return;
            }
            hideProofError();
            submitPayment();
        });
    }

    // ── Return modal ──
    returnReasonPreset && returnReasonPreset.addEventListener("change", function () {
        selectedReturnReason = returnReasons.find(function (r) { return r.value === returnReasonPreset.value; });

        if (selectedReturnReason) {
            if (returnReasonDescription) returnReasonDescription.textContent = selectedReturnReason.description || "";
            var req = selectedReturnReason.requires_note;
            if (returnReasonNoteReq) returnReasonNoteReq.style.display = req ? "inline" : "none";
            if (returnReasonNoteHint) {
                returnReasonNoteHint.textContent = req
                    ? "Required (minimum " + (selectedReturnReason.value === "other" ? 20 : 10) + " characters)"
                    : "Optional";
            }
            if (returnReasonNote) {
                returnReasonNote.placeholder = req
                    ? "Please provide a detailed explanation..."
                    : "Add optional notes for dispatch...";
            }
        } else {
            if (returnReasonDescription) returnReasonDescription.textContent = "";
            if (returnReasonNoteReq)    returnReasonNoteReq.style.display = "none";
            if (returnReasonNoteHint)   returnReasonNoteHint.textContent = "";
        }
    });

    cancelReturnBtn  && cancelReturnBtn.addEventListener("click", closeReturnModal);

    confirmReturnBtn && confirmReturnBtn.addEventListener("click", function () {
        var code = (returnReasonPreset?.value || "").trim();
        var note = (returnReasonNote?.value || "").trim();

        if (!code) { setReturnError("Please select a return reason."); return; }

        var reason = returnReasons.find(function (r) { return r.value === code; });
        if (reason && reason.requires_note && !note) {
            setReturnError("Additional details are required for this return reason."); return;
        }
        if (reason && reason.requires_note) {
            var min = reason.value === "other" ? 20 : 10;
            if (note.length < min) {
                setReturnError("Please provide at least " + min + " characters of explanation."); return;
            }
        }

        setReturnError("");
        closeReturnModal();
        submitAction(panel.dataset.returnEndpoint, { return_reason_code: code, return_reason_note: note }, true);
    });

    // ── Core submit ──
    function submitAction(endpoint, payload, redirectAfter, silent) {
        if (!endpoint) { setFeedback("This action is unavailable right now.", true); return; }

        if (!silent) {
            disableControls(true);
            setFeedback("Processing...", false);
        }

        fetch(endpoint, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": window.TeamLeaderConfig?.csrfToken || "",
            },
            body: JSON.stringify(payload || {}),
        })
        .then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                return { ok: res.ok, data: data };
            });
        })
        .then(function (result) {
            if (!result.ok || !result.data.success) {
                if (result.data && result.data.redirect_url) {
                    stopPolling();
                    window.setTimeout(function () { window.location.replace(result.data.redirect_url); }, 200);
                }
                throw new Error(result.data.message || "Unable to update the task.");
            }
            if (result.data.task) applyTask(result.data.task);
            if (!silent) showToast(result.data.message || "Task updated.", "success");
            setFeedback(result.data.message || "Task updated.", false);
            if (redirectAfter && result.data.redirect_url) {
                window.setTimeout(function () { window.location.href = result.data.redirect_url; }, 350);
            }
        })
        .catch(function (err) {
            if (!silent) showToast(err.message || "Request failed.", "error");
            setFeedback(err.message || "Request failed.", true);
        })
        .finally(function () {
            if (!silent) disableControls(false);
            applyStatus(panel.dataset.currentStatus || "assigned");
        });
    }

    // ── Payment file handler ──
    function handleProofFile(file) {
        var maxBytes = 5 * 1024 * 1024;
        if (file.size > maxBytes) { showProofError("File is too large (max 5 MB)."); return; }
        if (!file.type.startsWith("image/")) { showProofError("Only image files are allowed."); return; }

        hideProofError();
        selectedProofFile = file;

        var reader = new FileReader();
        reader.onload = function (e) {
            if (proofPreview) {
                proofPreview.src = e.target.result;
                proofPreview.classList.remove("hidden");
            }
            if (fileDropPrompt) fileDropPrompt.style.display = "none";
        };
        reader.readAsDataURL(file);
        checkPaymentReady();
    }

    function checkPaymentReady() {
        if (submitPaymentBtn) {
            submitPaymentBtn.disabled = !(selectedPaymentMethod && selectedProofFile);
        }
    }

    function showProofError(msg) {
        if (proofError) { proofError.textContent = msg; proofError.style.display = "block"; }
    }
    function hideProofError() {
        if (proofError) { proofError.textContent = ""; proofError.style.display = "none"; }
    }

    // ── Payment submit (multipart) ──
    function submitPayment() {
        var endpoint = panel.dataset.paymentEndpoint;
        if (!endpoint) { showProofError("Payment endpoint unavailable."); return; }

        if (submitPaymentBtn) { submitPaymentBtn.disabled = true; submitPaymentBtn.textContent = "Uploading..."; }
        setFeedback("Uploading payment proof...", false);

        var form = new FormData();
        form.append("payment_method", selectedPaymentMethod);
        form.append("payment_proof",  selectedProofFile);
        form.append("_token", window.TeamLeaderConfig?.csrfToken || "");

        fetch(endpoint, {
            method: "POST",
            headers: {
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": window.TeamLeaderConfig?.csrfToken || "",
            },
            body: form,
        })
        .then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                return { ok: res.ok, data: data };
            });
        })
        .then(function (result) {
            if (!result.ok || !result.data.success) {
                throw new Error(result.data.message || "Failed to submit payment.");
            }
            showToast(result.data.message || "Payment submitted.", "success");
            setFeedback(result.data.message || "Payment submitted.", false);
            if (result.data.task) applyTask(result.data.task);
        })
        .catch(function (err) {
            showToast(err.message || "Upload failed.", "error");
            showProofError(err.message || "Upload failed. Please try again.");
            if (submitPaymentBtn) {
                submitPaymentBtn.disabled = false;
                submitPaymentBtn.textContent = "Submit to Dispatcher";
            }
        });
    }

    // ── Apply task from API response ──
    function applyTask(task) {
        panel.dataset.currentStatus = task.status || "assigned";

        if (statusBadge) {
            statusBadge.className = "tf-status-pill " + (task.ui_status || "assigned").replace(/_/g, "-");
            statusBadge.textContent = task.status_label || "Active";
        }
        if (statusNote) statusNote.textContent = task.status_note || "";

        applyStatus(task.status || "assigned", task);
        updateFlowSteps(task.status || "assigned");
    }

    // ── Status → UI state ──
    function applyStatus(status, task) {
        var t = task || buildDefaultTask(status);

        hide(proceedBtn,      !t.can_proceed);
        hide(startTowBtn,     !t.can_start);
        hide(completeTaskBtn, !t.can_complete);
        hide(returnTaskBtn,   !t.can_return);
        hide(backToDashBtn,   !t.is_completed);

        // Waiting verification card
        if (waitingCard) hide(waitingCard, status !== "waiting_verification");

        // Payment area
        var isPaymentStep = status === "payment_pending" || status === "payment_submitted";
        if (paymentArea) hide(paymentArea, !isPaymentStep);
        if (paymentForm)      hide(paymentForm,      status !== "payment_pending");
        if (paymentSubmitted) hide(paymentSubmitted,  status !== "payment_submitted");

        if (t.is_waiting) {
            setFeedback("Waiting for customer confirmation. This page updates automatically.", false);
            startPolling();
            return;
        }

        if (status === "payment_pending") {
            setFeedback("Customer confirmed. Select payment method and upload proof.", false);
            startPolling();
            return;
        }

        if (status === "payment_submitted") {
            setFeedback("Payment submitted. Waiting for dispatcher confirmation.", false);
            startPolling();
            return;
        }

        if (t.is_completed) {
            stopPolling();
            setFeedback("Job complete. Redirecting to dashboard...", false);
            window.setTimeout(function () { window.location.href = panel.dataset.dashboardUrl; }, 1500);
            return;
        }

        startPolling();
        setFeedback("Use the buttons above to keep this job moving.", false);
    }

    function buildDefaultTask(status) {
        return {
            can_proceed:  status === "assigned",
            can_start:    status === "on_the_way",
            can_complete: status === "in_progress",
            can_return:   status === "assigned" || status === "on_the_way",
            is_waiting:   status === "waiting_verification",
            is_completed: status === "completed",
        };
    }

    // ── Stepper ──
    var stepOrder = ["claimed", "navigate", "work", "verify", "payment"];

    function updateFlowSteps(status) {
        var current = "claimed";
        if      (status === "on_the_way")           current = "navigate";
        else if (status === "in_progress")           current = "work";
        else if (status === "waiting_verification")  current = "verify";
        else if (status === "payment_pending" || status === "payment_submitted" || status === "completed") current = "payment";

        var currentIdx = stepOrder.indexOf(current);

        document.querySelectorAll("[data-step]").forEach(function (node) {
            var idx = stepOrder.indexOf(node.dataset.step);
            node.classList.toggle("is-complete", idx < currentIdx);
            node.classList.toggle("is-active",   idx === currentIdx);
        });

        document.querySelectorAll("[data-line]").forEach(function (line) {
            var parts = (line.dataset.line || "").split("-");
            var fromStep = parts[0];
            var fromIdx  = stepOrder.indexOf(fromStep);
            line.classList.toggle("is-done", fromIdx < currentIdx);
        });
    }

    // ── Polling ──
    function startPolling() {
        if (pollTimer) return;
        pollTimer = window.setInterval(function () {
            fetch(panel.dataset.statusEndpoint, {
                headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" },
            })
            .then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (d) { return { ok: res.ok, data: d }; });
            })
            .then(function (result) {
                if (!result.ok || result.data.success === false) {
                    if (result.data && result.data.redirect_url) {
                        stopPolling();
                        window.location.replace(result.data.redirect_url);
                    }
                    return;
                }
                if (result.data && result.data.task) applyTask(result.data.task);
            })
            .catch(function () {});
        }, 10000);
    }

    function stopPolling() {
        if (!pollTimer) return;
        window.clearInterval(pollTimer);
        pollTimer = null;
    }

    // ── Return modal ──
    function loadReturnReasons() {
        fetch("/teamleader/return-reasons", {
            headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" },
        })
        .then(function (r) { return r.json(); })
        .then(function (reasons) {
            returnReasons = reasons || [];
            if (!returnReasonPreset || returnReasons.length === 0) return;
            returnReasonPreset.innerHTML = '<option value="">Select a reason</option>';
            var pri = { critical: 0, high: 1, medium: 2 };
            returnReasons.slice().sort(function (a, b) { return (pri[a.priority] || 9) - (pri[b.priority] || 9); })
                .forEach(function (r) {
                    var opt = document.createElement("option");
                    opt.value = r.value;
                    opt.textContent = r.label;
                    returnReasonPreset.appendChild(opt);
                });
        })
        .catch(function () { returnReasons = []; });
    }

    function openReturnModal() {
        if (!returnTaskModal) { setFeedback("Return dialog unavailable.", true); return; }
        if (returnReasonPreset) returnReasonPreset.value = "";
        if (returnReasonNote)   returnReasonNote.value   = "";
        if (returnReasonDescription) returnReasonDescription.textContent = "";
        if (returnReasonNoteReq)     returnReasonNoteReq.style.display   = "none";
        if (returnReasonNoteHint)    returnReasonNoteHint.textContent     = "";
        selectedReturnReason = null;
        setReturnError("");
        returnTaskModal.classList.remove("hidden");
        returnTaskModal.setAttribute("aria-hidden", "false");
        window.setTimeout(function () { if (returnReasonPreset) returnReasonPreset.focus(); }, 30);
    }

    function closeReturnModal() {
        if (!returnTaskModal) return;
        returnTaskModal.classList.add("hidden");
        returnTaskModal.setAttribute("aria-hidden", "true");
        setReturnError("");
    }

    function setReturnError(msg) {
        if (!returnReasonError) return;
        returnReasonError.textContent = msg || "";
        returnReasonError.classList.toggle("hidden", !msg);
    }

    // ── Helpers ──
    function hide(el, shouldHide) {
        if (!el) return;
        el.classList.toggle("hidden", Boolean(shouldHide));
    }

    function disableControls(disabled) {
        [proceedBtn, startTowBtn, completeTaskBtn, returnTaskBtn].forEach(function (btn) {
            if (btn && !btn.classList.contains("hidden")) btn.disabled = disabled;
        });
    }

    function setFeedback(msg, isError) {
        if (!feedback) return;
        feedback.textContent = msg;
        feedback.classList.toggle("is-error", Boolean(isError));
    }

    function showToast(msg, type) {
        var t = document.createElement("div");
        t.className = "tl-toast tl-toast--" + (type || "success");
        t.textContent = msg;
        toastWrap.appendChild(t);
        window.setTimeout(function () { t.remove(); }, 3500);
    }
});
