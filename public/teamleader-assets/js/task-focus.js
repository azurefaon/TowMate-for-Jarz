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

    // PayMongo payment area
    var paymongoArea       = document.getElementById("paymongoArea");
    var paymongoAmountDisp = document.getElementById("paymongoAmountDisplay");
    var paymongoWaiting    = document.getElementById("paymongoWaiting");
    var paymongoOpenLink   = document.getElementById("paymongoOpenLink");

    // Payment method tabs + sections
    var pmMethodTabs   = document.getElementById("pmMethodTabs");
    var pmTabCard      = document.getElementById("pmTabCard");
    var pmTabGcash     = document.getElementById("pmTabGcash");
    var pmCardSection  = document.getElementById("pmCardSection");
    var pmGcashSection = document.getElementById("pmGcashSection");
    var pmCardNumber   = document.getElementById("pmCardNumber");
    var pmCardExpiry   = document.getElementById("pmCardExpiry");
    var pmCardCvc      = document.getElementById("pmCardCvc");
    var pmCardName     = document.getElementById("pmCardName");
    var pmCardPayBtn   = document.getElementById("pmCardPayBtn");
    var pmGcashPayBtn  = document.getElementById("pmGcashPayBtn");
    var pmCardError    = document.getElementById("pmCardError");

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
    var paymentPollTimer     = null;
    var paymentUiOpened      = false; // true once TL clicks Collect Payment
    var stepOrder = ["claimed", "navigate", "work", "dropoff"];

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
        openMapsWithPickup();
        submitAction(panel.dataset.proceedEndpoint, {});
    });

    navigateMapsBtn && navigateMapsBtn.addEventListener("click", function () {
        openMapsWithPickup();
    });

    startTowBtn && startTowBtn.addEventListener("click", function () {
        submitAction(panel.dataset.startEndpoint, {});
    });

    completeTaskBtn && completeTaskBtn.addEventListener("click", function () {
        paymentUiOpened = true;
        hide(completeTaskBtn, true);
        if (paymongoArea) paymongoArea.classList.remove("hidden");
        setFeedback("Choose a payment method below.", false);
    });

    // ── Payment method tab switching ──
    pmTabCard && pmTabCard.addEventListener("click", function () { switchPmTab("card"); });
    pmTabGcash && pmTabGcash.addEventListener("click", function () { switchPmTab("gcash"); });

    function switchPmTab(method) {
        if (method === "card") {
            pmTabCard && pmTabCard.classList.add("tf-pm-tab--active");
            pmTabGcash && pmTabGcash.classList.remove("tf-pm-tab--active");
            hide(pmCardSection, false);
            hide(pmGcashSection, true);
        } else {
            pmTabGcash && pmTabGcash.classList.add("tf-pm-tab--active");
            pmTabCard && pmTabCard.classList.remove("tf-pm-tab--active");
            hide(pmGcashSection, false);
            hide(pmCardSection, true);
        }
    }

    // ── Card number / expiry auto-format ──
    pmCardNumber && pmCardNumber.addEventListener("input", function () {
        var v = this.value.replace(/\D/g, "").slice(0, 16);
        this.value = v.replace(/(.{4})/g, "$1 ").trim();
    });
    pmCardExpiry && pmCardExpiry.addEventListener("input", function () {
        var v = this.value.replace(/\D/g, "").slice(0, 4);
        if (v.length >= 3) v = v.slice(0, 2) + "/" + v.slice(2);
        this.value = v;
    });
    pmCardCvc && pmCardCvc.addEventListener("input", function () {
        this.value = this.value.replace(/\D/g, "").slice(0, 4);
    });

    // ── Card "Pay Now" ──
    pmCardPayBtn && pmCardPayBtn.addEventListener("click", function () {
        var cardNum = (pmCardNumber ? pmCardNumber.value : "").replace(/\s/g, "");
        var expiry  = (pmCardExpiry ? pmCardExpiry.value : "").trim();
        var cvc     = (pmCardCvc   ? pmCardCvc.value   : "").trim();
        var name    = (pmCardName  ? pmCardName.value  : "").trim();

        if (cardNum.length < 13) { setPmCardError("Enter a valid card number."); return; }
        if (!/^\d{2}\/\d{2}$/.test(expiry)) { setPmCardError("Enter expiry as MM/YY."); return; }
        if (cvc.length < 3) { setPmCardError("Enter a valid CVC."); return; }
        if (!name) { setPmCardError("Enter the cardholder name."); return; }
        setPmCardError("");

        var parts    = expiry.split("/");
        var expMonth = parseInt(parts[0], 10);
        var expYear  = parseInt("20" + parts[1], 10);
        var publicKey = panel.dataset.paymongoPublicKey || "";

        disablePmCardForm(true);
        setFeedback("Processing card payment…", false);

        // Step 1: create Payment Intent on backend
        backendPost(panel.dataset.completeEndpoint, { payment_method: "card" })
            .then(function (data) {
                if (!data.success) throw new Error(data.message || "Failed to create payment intent.");
                var intentId  = data.intent_id;
                var clientKey = data.client_key;
                if (data.task) applyTask(data.task);

                // Step 2: create Payment Method via PayMongo API (public key)
                return fetch("https://api.paymongo.com/v1/payment_methods", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "Accept": "application/json",
                        "Authorization": "Basic " + btoa(publicKey + ":"),
                    },
                    body: JSON.stringify({ data: { attributes: {
                        type: "card",
                        details: { card_number: cardNum, exp_month: expMonth, exp_year: expYear, cvc: cvc },
                        billing: { name: name },
                    }}}),
                })
                .then(function (r) { return r.json(); })
                .then(function (pm) {
                    if (!pm.data || !pm.data.id) {
                        var errMsg = (pm.errors && pm.errors[0] && pm.errors[0].detail) || "Invalid card details.";
                        throw new Error(errMsg);
                    }
                    // Step 3: attach Payment Method to Intent
                    return fetch("https://api.paymongo.com/v1/payment_intents/" + intentId + "/attach", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "Accept": "application/json",
                            "Authorization": "Basic " + btoa(publicKey + ":"),
                        },
                        body: JSON.stringify({ data: { attributes: {
                            payment_method: pm.data.id,
                            client_key: clientKey,
                            return_url: window.location.href,
                        }}}),
                    }).then(function (r) { return r.json(); });
                })
                .then(function (att) {
                    var status = att.data && att.data.attributes && att.data.attributes.status;
                    if (status === "succeeded") {
                        showPaymentWaiting();
                        showToast("Card payment successful!", "success");
                        startPaymentPolling();
                    } else if (status === "awaiting_next_action") {
                        var redirectUrl = att.data.attributes.next_action &&
                                          att.data.attributes.next_action.redirect &&
                                          att.data.attributes.next_action.redirect.url;
                        if (redirectUrl) {
                            showPaymentWaiting();
                            setFeedback("Complete 3D Secure verification in the opened tab.", false);
                            window.open(redirectUrl, "_blank", "noopener,noreferrer");
                            startPaymentPolling();
                        } else {
                            throw new Error("3D Secure required but no redirect URL found.");
                        }
                    } else {
                        var failMsg = (att.errors && att.errors[0] && att.errors[0].detail) || "Card payment failed.";
                        throw new Error(failMsg);
                    }
                });
            })
            .catch(function (err) {
                setPmCardError(err.message || "Payment failed. Please try again.");
                setFeedback(err.message || "Payment failed.", true);
                disablePmCardForm(false);
            });
    });

    // ── GCash "Open GCash Payment" ──
    pmGcashPayBtn && pmGcashPayBtn.addEventListener("click", function () {
        pmGcashPayBtn.disabled = true;
        setFeedback("Creating GCash payment link…", false);

        backendPost(panel.dataset.completeEndpoint, { payment_method: "gcash" })
            .then(function (data) {
                if (!data.success) throw new Error(data.message || "Failed to create GCash link.");
                if (data.task) applyTask(data.task);
                var url = data.checkout_url || "";
                if (paymongoOpenLink) { paymongoOpenLink.href = url; paymongoOpenLink.classList.remove("hidden"); }
                if (url) window.open(url, "_blank", "noopener,noreferrer");
                showPaymentWaiting();
                showToast("GCash page opened.", "success");
                startPaymentPolling();
            })
            .catch(function (err) {
                showToast(err.message || "Request failed.", "error");
                setFeedback(err.message || "Failed.", true);
                pmGcashPayBtn.disabled = false;
            });
    });

    function showPaymentWaiting() {
        hide(pmMethodTabs, true);
        hide(pmCardSection, true);
        hide(pmGcashSection, true);
        if (paymongoWaiting) paymongoWaiting.classList.remove("hidden");
        setFeedback("Waiting for payment to complete. This page updates automatically.", false);
    }

    function setPmCardError(msg) {
        if (pmCardError) { pmCardError.textContent = msg; }
    }

    function disablePmCardForm(disabled) {
        [pmCardNumber, pmCardExpiry, pmCardCvc, pmCardName].forEach(function (el) {
            if (el) el.disabled = disabled;
        });
        if (pmCardPayBtn) pmCardPayBtn.disabled = disabled;
    }

    function backendPost(endpoint, payload) {
        return fetch(endpoint, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": window.TeamLeaderConfig && window.TeamLeaderConfig.csrfToken || "",
            },
            body: JSON.stringify(payload || {}),
        })
        .then(function (res) {
            return res.json().catch(function () { return { success: false, message: "Server error." }; });
        });
    }

    returnTaskBtn && returnTaskBtn.addEventListener("click", openReturnModal);

    // ── Return modal events ──
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

    // ── Payment status polling (PayMongo) ──
    function startPaymentPolling() {
        if (paymentPollTimer) return;
        var endpoint = panel.dataset.paymentStatusEndpoint;
        if (!endpoint) return;

        paymentPollTimer = window.setInterval(function () {
            fetch(endpoint, {
                headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" },
            })
            .then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (d) { return { ok: res.ok, data: d }; });
            })
            .then(function (result) {
                if (!result.ok || !result.data.success) return;
                if (result.data.paid) {
                    stopPaymentPolling();
                    stopPolling();
                    showToast(result.data.message || "Payment received! Job complete.", "success");
                    setFeedback("Payment confirmed! Redirecting…", false);
                    if (result.data.task) applyTask(result.data.task);
                    window.setTimeout(function () {
                        window.location.href = result.data.redirect_url || panel.dataset.tasksUrl;
                    }, 1500);
                }
            })
            .catch(function () {});
        }, 5000);
    }

    function stopPaymentPolling() {
        if (!paymentPollTimer) return;
        window.clearInterval(paymentPollTimer);
        paymentPollTimer = null;
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

    // ── Apply task from API response ──
    function applyTask(task) {
        panel.dataset.currentStatus = task.status || "assigned";

        if (task.paymongo_checkout_url) {
            panel.dataset.paymongoCheckoutUrl = task.paymongo_checkout_url;
        }
        if (task.payment_method_type) {
            panel.dataset.paymentMethodType = task.payment_method_type;
        }

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
            paymentUiOpened = true;
            if (paymongoArea) paymongoArea.classList.remove("hidden");
            hide(pmMethodTabs, true);
            hide(pmCardSection, true);
            hide(pmGcashSection, true);
            if (paymongoWaiting) paymongoWaiting.classList.remove("hidden");

            var methodType = (t && t.payment_method_type) || panel.dataset.paymentMethodType || "";
            if (methodType === "gcash") {
                var gcashUrl = (t && t.paymongo_checkout_url) || panel.dataset.paymongoCheckoutUrl || "";
                if (paymongoOpenLink && gcashUrl) {
                    paymongoOpenLink.href = gcashUrl;
                    paymongoOpenLink.classList.remove("hidden");
                }
                if (pmGcashSection) pmGcashSection.classList.remove("hidden");
            }

            setFeedback("Waiting for payment to complete. This page updates automatically.", false);
            startPaymentPolling();
            return;
        }

        // Hide payment area only if the TL hasn't opened it yet
        if (!paymentUiOpened) hide(paymongoArea, true);

        if (t.is_completed) {
            stopPolling();
            stopPaymentPolling();
            hide(paymongoArea, true);
            setFeedback("Job complete. Redirecting to dashboard…", false);
            window.setTimeout(function () { window.location.href = panel.dataset.tasksUrl || panel.dataset.dashboardUrl; }, 1500);
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
        else if (status === "payment_pending" || status === "payment_submitted" || status === "waiting_verification" || status === "completed") current = "dropoff";

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

    // ── General polling (for status changes from other actors) ──
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
