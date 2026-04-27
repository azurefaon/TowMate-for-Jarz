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
    var navigateMapsBtn = document.getElementById("navigateMapsBtn");
    var backToDashBtn   = document.getElementById("backToDashboardBtn");

    // Payment area
    var paymongoArea          = document.getElementById("paymongoArea");
    var pmMethodArea          = document.getElementById("pmMethodArea");
    var paymentSubmittedCard  = document.getElementById("paymentSubmittedCard");

    // Payment method tabs
    var pmTabGcash   = document.getElementById("pmTabGcash");
    var pmTabBank    = document.getElementById("pmTabBank");
    var pmTabCash    = document.getElementById("pmTabCash");
    var pmTabCheque  = document.getElementById("pmTabCheque");

    // Payment sections
    var pmGcashSection  = document.getElementById("pmGcashSection");
    var pmBankSection   = document.getElementById("pmBankSection");
    var pmCashSection   = document.getElementById("pmCashSection");
    var pmChequeSection = document.getElementById("pmChequeSection");

    // GCash refs
    var gcashProofInput       = document.getElementById("gcashProofInput");
    var gcashProofPreview     = document.getElementById("gcashProofPreview");
    var gcashProofPlaceholder = document.getElementById("gcashProofPlaceholder");
    var gcashProofError       = document.getElementById("gcashProofError");
    var gcashSubmitBtn        = document.getElementById("gcashSubmitBtn");

    // Bank Transfer refs
    var bankProofInput        = document.getElementById("bankProofInput");
    var bankProofPreview      = document.getElementById("bankProofPreview");
    var bankProofPlaceholder  = document.getElementById("bankProofPlaceholder");
    var bankProofError        = document.getElementById("bankProofError");
    var bankSubmitBtn         = document.getElementById("bankSubmitBtn");

    // Cash refs
    var cashProofInput        = document.getElementById("cashProofInput");
    var cashProofPreview      = document.getElementById("cashProofPreview");
    var cashProofPlaceholder  = document.getElementById("cashProofPlaceholder");
    var cashConfirmBtn        = document.getElementById("cashConfirmBtn");

    // Cheque refs
    var chequeProofInput       = document.getElementById("chequeProofInput");
    var chequeProofPreview     = document.getElementById("chequeProofPreview");
    var chequeProofPlaceholder = document.getElementById("chequeProofPlaceholder");
    var chequeProofError       = document.getElementById("chequeProofError");
    var chequeSubmitBtn        = document.getElementById("chequeSubmitBtn");

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

    var returnReasons        = [];
    var selectedReturnReason = null;
    var pollTimer            = null;
    var stepOrder = ["claimed", "navigate", "work", "dropoff", "payment", "complete"];

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

    navigateMapsBtn && navigateMapsBtn.addEventListener("click", function () {
        openMapsWithPickup();
    });

    startTowBtn && startTowBtn.addEventListener("click", function () {
        submitAction(panel.dataset.startEndpoint, {});
    });

    completeTaskBtn && completeTaskBtn.addEventListener("click", function () {
        submitAction(panel.dataset.completeEndpoint, {});
    });

    returnTaskBtn && returnTaskBtn.addEventListener("click", openReturnModal);

    // ── Payment method tab switching ──
    pmTabGcash  && pmTabGcash.addEventListener("click",  function () { switchPmTab("gcash"); });
    pmTabBank   && pmTabBank.addEventListener("click",   function () { switchPmTab("bank"); });
    pmTabCash   && pmTabCash.addEventListener("click",   function () { switchPmTab("cash"); });
    pmTabCheque && pmTabCheque.addEventListener("click", function () { switchPmTab("cheque"); });

    function switchPmTab(method) {
        var tabs = {
            gcash: pmTabGcash, bank: pmTabBank,
            cash: pmTabCash,   cheque: pmTabCheque,
        };
        var sections = {
            gcash: pmGcashSection, bank: pmBankSection,
            cash: pmCashSection,   cheque: pmChequeSection,
        };
        Object.keys(tabs).forEach(function (key) {
            if (tabs[key]) tabs[key].classList.toggle("tf-pm-tab--active", key === method);
            hide(sections[key], key !== method);
        });
    }

    // ── Generic file-change handler ──
    function wireProofInput(input, preview, placeholder) {
        if (!input) return;
        input.addEventListener("change", function () {
            var file = this.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) {
                showToast("File too large. Maximum 5 MB.", "error");
                this.value = "";
                return;
            }
            var reader = new FileReader();
            reader.onload = function (e) {
                if (preview)     { preview.src = e.target.result; preview.style.display = "block"; }
                if (placeholder) { placeholder.style.display = "none"; }
            };
            reader.readAsDataURL(file);
        });
    }

    wireProofInput(gcashProofInput,   gcashProofPreview,   gcashProofPlaceholder);
    wireProofInput(bankProofInput,    bankProofPreview,    bankProofPlaceholder);
    wireProofInput(cashProofInput,    cashProofPreview,    cashProofPlaceholder);
    wireProofInput(chequeProofInput,  chequeProofPreview,  chequeProofPlaceholder);

    // ── Generic proof submit ──
    function submitProof(method, fileInput, errorEl, submitBtn) {
        var file = fileInput && fileInput.files[0];
        var requiresProof = (method !== "cash");

        if (requiresProof && !file) {
            if (errorEl) errorEl.textContent = "Please take or choose a photo first.";
            return;
        }
        if (errorEl) errorEl.textContent = "";

        var endpoint = panel.dataset.proofEndpoint;
        if (!endpoint) return;

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.dataset.defaultText = submitBtn.dataset.defaultText || submitBtn.textContent;
            submitBtn.textContent = "Submitting…";
        }

        var formData = new FormData();
        formData.append("payment_method", method);
        if (file) formData.append("payment_proof", file);
        formData.append("_token", (window.TeamLeaderConfig && window.TeamLeaderConfig.csrfToken) || "");

        fetch(endpoint, {
            method: "POST",
            headers: {
                "Accept": "application/json",
                "X-CSRF-TOKEN": (window.TeamLeaderConfig && window.TeamLeaderConfig.csrfToken) || "",
            },
            body: formData,
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.success) throw new Error(data.message || "Submission failed.");
            showToast(data.message || "Payment submitted!", "success");
            if (data.task) applyTask(data.task);
            setFeedback("Proof submitted. Waiting for dispatcher to confirm.", false);
        })
        .catch(function (err) {
            if (errorEl) errorEl.textContent = err.message || "Submission failed. Try again.";
            showToast(err.message || "Submission failed.", "error");
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = submitBtn.dataset.defaultText || "Submit";
            }
        });
    }

    // ── Wire proof submit buttons ──
    gcashSubmitBtn  && gcashSubmitBtn.addEventListener("click", function () {
        submitProof("gcash",  gcashProofInput,  gcashProofError,  gcashSubmitBtn);
    });
    bankSubmitBtn   && bankSubmitBtn.addEventListener("click", function () {
        submitProof("bank",   bankProofInput,   bankProofError,   bankSubmitBtn);
    });
    cashConfirmBtn  && cashConfirmBtn.addEventListener("click", function () {
        submitProof("cash",   cashProofInput,   null,             cashConfirmBtn);
    });
    chequeSubmitBtn && chequeSubmitBtn.addEventListener("click", function () {
        submitProof("cheque", chequeProofInput, chequeProofError, chequeSubmitBtn);
    });

    // ── Return modal events ──
    returnReasonPreset && returnReasonPreset.addEventListener("change", function () {
        selectedReturnReason = returnReasons.find(function (r) { return r.value === returnReasonPreset.value; });

        if (selectedReturnReason) {
            if (returnReasonDescription) returnReasonDescription.textContent = selectedReturnReason.description || "";
            var req = selectedReturnReason.requires_note;
            if (returnReasonNoteReq)  returnReasonNoteReq.style.display = req ? "inline" : "none";
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
            if (returnReasonNoteReq)     returnReasonNoteReq.style.display = "none";
            if (returnReasonNoteHint)    returnReasonNoteHint.textContent = "";
        }
    });

    cancelReturnBtn && cancelReturnBtn.addEventListener("click", closeReturnModal);

    confirmReturnBtn && confirmReturnBtn.addEventListener("click", function () {
        var code = (returnReasonPreset ? returnReasonPreset.value : "").trim();
        var note = (returnReasonNote   ? returnReasonNote.value   : "").trim();

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

    // ── Maps navigation ──
    function openMapsWithPickup() {
        var addr = (panel.dataset.pickupAddress || "").trim();
        if (!addr) return;
        window.open(
            "https://www.google.com/maps/search/?api=1&query=" + encodeURIComponent(addr),
            "_blank",
            "noopener,noreferrer"
        );
    }

    // ── Core submit (for non-payment actions) ──
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
                "X-CSRF-TOKEN": (window.TeamLeaderConfig && window.TeamLeaderConfig.csrfToken) || "",
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
        hide(navigateMapsBtn, status !== "on_the_way");
        hide(startTowBtn,     !t.can_start);
        hide(completeTaskBtn, !t.can_complete);
        hide(returnTaskBtn,   !t.can_return);
        hide(backToDashBtn,   !t.is_completed);

        if (status === "payment_pending") {
            if (paymongoArea)        paymongoArea.classList.remove("hidden");
            hide(pmMethodArea,       false);
            hide(paymentSubmittedCard, true);
            setFeedback("Collect payment from the customer using one of the methods below.", false);
            startPolling();
            return;
        }

        if (status === "payment_submitted") {
            if (paymongoArea)        paymongoArea.classList.remove("hidden");
            hide(pmMethodArea,       true);
            hide(paymentSubmittedCard, false);
            setFeedback("Payment proof submitted. Waiting for dispatcher to confirm.", false);
            startPolling();
            return;
        }

        hide(paymongoArea, true);

        if (t.is_completed) {
            stopPolling();
            setFeedback("Job complete. Redirecting to dashboard…", false);
            window.setTimeout(function () {
                window.location.href = panel.dataset.tasksUrl || panel.dataset.dashboardUrl;
            }, 1500);
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
            is_completed: status === "completed",
        };
    }

    // ── Stepper ──
    function updateFlowSteps(status) {
        var current = "claimed";
        if      (status === "on_the_way")           current = "navigate";
        else if (status === "in_progress")           current = "work";
        else if (status === "payment_pending" || status === "payment_submitted" || status === "waiting_verification") current = "payment";
        else if (status === "completed")             current = "complete";

        var currentIdx = stepOrder.indexOf(current);

        document.querySelectorAll("[data-step]").forEach(function (node) {
            var idx = stepOrder.indexOf(node.dataset.step);
            node.classList.toggle("is-complete", idx < currentIdx);
            node.classList.toggle("is-active",   idx === currentIdx);
        });

        document.querySelectorAll("[data-line]").forEach(function (line) {
            var parts   = (line.dataset.line || "").split("-");
            var fromIdx = stepOrder.indexOf(parts[0]);
            line.classList.toggle("is-done", fromIdx < currentIdx);
        });
    }

    // ── General polling (detects status changes from dispatcher) ──
    function startPolling() {
        if (pollTimer) return;
        pollTimer = window.setInterval(function () {
            fetch(panel.dataset.statusEndpoint, {
                headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" },
            })
            .then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (d) {
                    return { ok: res.ok, data: d };
                });
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
