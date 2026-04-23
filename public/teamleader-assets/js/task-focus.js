document.addEventListener("DOMContentLoaded", function () {
    var panel = document.getElementById("focusedTask");

    if (!panel) {
        return;
    }

    var statusBadge = document.getElementById("focusStatusBadge");
    var statusNote = document.getElementById("focusStatusNote");
    var feedback = document.getElementById("focusFeedback");
    var driverNameInput = document.getElementById("driverNameInput");
    var completionNoteInput = document.getElementById("completionNoteInput");
    var saveDriverBtn = document.getElementById("saveDriverBtn");
    var changeDriverBtn = document.getElementById("changeDriverBtn");
    var proceedBtn = document.getElementById("proceedBtn");
    var startTowBtn = document.getElementById("startTowBtn");
    var completeTaskBtn = document.getElementById("completeTaskBtn");
    var returnTaskBtn = document.getElementById("returnTaskBtn");
    var backToDashboardBtn = document.getElementById("backToDashboardBtn");
    var returnTaskModal = document.getElementById("returnTaskModal");
    var returnReasonPreset = document.getElementById("returnReasonPreset");
    var returnReasonNote = document.getElementById("returnReasonNote");
    var returnReasonError = document.getElementById("returnReasonError");
    var returnReasonDescription = document.getElementById("returnReasonDescription");
    var returnReasonNoteRequired = document.getElementById("returnReasonNoteRequired");
    var returnReasonNoteHint = document.getElementById("returnReasonNoteHint");
    var cancelReturnBtn = document.getElementById("cancelReturnBtn");
    var confirmReturnBtn = document.getElementById("confirmReturnBtn");

    var returnReasons = [];
    var selectedReturnReason = null;

    var toastWrap = document.createElement("div");
    toastWrap.className = "tl-toast-wrap";
    document.body.appendChild(toastWrap);

    var pollTimer = null;
    var noteSaveTimer = null;
    var driverSaveTimer = null;

    saveDriverBtn?.addEventListener("click", function () {
        var driverName = (driverNameInput?.value || "").trim();

        if (!driverName) {
            setFeedback("Please enter the driver name first.", true);
            return;
        }

        submitAction(panel.dataset.driverEndpoint, { driver_name: driverName });
    });

    changeDriverBtn?.addEventListener("click", function () {
        if (!driverNameInput || panel.dataset.currentStatus !== "assigned") {
            return;
        }

        driverNameInput.disabled = false;
        driverNameInput.focus();
        driverNameInput.select();

        if (saveDriverBtn) {
            saveDriverBtn.disabled = false;
            saveDriverBtn.textContent = (driverNameInput.value || "").trim()
                ? "Update Driver"
                : "Save Driver";
        }

        toggleHidden(changeDriverBtn, true);
        setFeedback(
            "Update the driver name, then save it again before proceeding.",
            false,
        );
    });

    driverNameInput?.addEventListener("input", function () {
        if (driverNameInput.disabled) {
            return;
        }

        window.clearTimeout(driverSaveTimer);
        driverSaveTimer = window.setTimeout(function () {
            var driverName = (driverNameInput.value || "").trim();
            if (driverName.length < 2) {
                return;
            }

            submitAction(
                panel.dataset.driverEndpoint,
                { driver_name: driverName },
                false,
                true,
            );
        }, 900);
    });

    completionNoteInput?.addEventListener("input", function () {
        if (completionNoteInput.disabled || !panel.dataset.noteEndpoint) {
            return;
        }

        window.clearTimeout(noteSaveTimer);
        noteSaveTimer = window.setTimeout(function () {
            submitAction(
                panel.dataset.noteEndpoint,
                { completion_note: (completionNoteInput.value || "").trim() },
                false,
                true,
            );
        }, 900);
    });

    proceedBtn?.addEventListener("click", function () {
        if (!(driverNameInput?.value || "").trim()) {
            setFeedback("Enter the driver name before proceeding.", true);
            return;
        }

        submitAction(panel.dataset.proceedEndpoint, {});
    });

    startTowBtn?.addEventListener("click", function () {
        submitAction(panel.dataset.startEndpoint, {});
    });

    completeTaskBtn?.addEventListener("click", function () {
        submitAction(panel.dataset.completeEndpoint, {
            completion_note: (completionNoteInput?.value || "").trim(),
        });
    });

    returnTaskBtn?.addEventListener("click", function () {
        openReturnModal();
    });

    returnReasonPreset?.addEventListener("change", function () {
        var reasonValue = returnReasonPreset.value;
        selectedReturnReason = returnReasons.find(function (r) {
            return r.value === reasonValue;
        });

        if (selectedReturnReason) {
            if (returnReasonDescription) {
                returnReasonDescription.textContent = selectedReturnReason.description || "";
            }

            var requiresNote = selectedReturnReason.requires_note;
            if (returnReasonNoteRequired) {
                returnReasonNoteRequired.style.display = requiresNote ? "inline" : "none";
            }

            if (returnReasonNoteHint) {
                if (requiresNote) {
                    var minLength = selectedReturnReason.value === "other" ? 20 : 10;
                    returnReasonNoteHint.textContent = "Required (minimum " + minLength + " characters)";
                } else {
                    returnReasonNoteHint.textContent = "Optional";
                }
            }

            if (returnReasonNote) {
                returnReasonNote.placeholder = requiresNote
                    ? "Please provide detailed explanation..."
                    : "Add optional notes for dispatch...";
            }
        } else {
            if (returnReasonDescription) {
                returnReasonDescription.textContent = "";
            }
            if (returnReasonNoteRequired) {
                returnReasonNoteRequired.style.display = "none";
            }
            if (returnReasonNoteHint) {
                returnReasonNoteHint.textContent = "";
            }
        }
    });

    cancelReturnBtn?.addEventListener("click", function () {
        closeReturnModal();
    });

    confirmReturnBtn?.addEventListener("click", function () {
        var reasonCode = (returnReasonPreset?.value || "").trim();
        var reasonNote = (returnReasonNote?.value || "").trim();

        if (!reasonCode) {
            setReturnError("Please select a return reason.");
            return;
        }

        var reason = returnReasons.find(function (r) {
            return r.value === reasonCode;
        });

        if (reason && reason.requires_note && !reasonNote) {
            setReturnError("Additional details are required for this return reason.");
            return;
        }

        if (reason && reason.requires_note) {
            var minLength = reason.value === "other" ? 20 : 10;
            if (reasonNote.length < minLength) {
                setReturnError("Please provide at least " + minLength + " characters of explanation.");
                return;
            }
        }

        setReturnError("");
        closeReturnModal();
        submitAction(
            panel.dataset.returnEndpoint,
            {
                return_reason_code: reasonCode,
                return_reason_note: reasonNote,
            },
            true,
        );
    });

    applyStatus(panel.dataset.currentStatus || "assigned");
    updateFlowSteps(panel.dataset.currentStatus || "assigned");
    loadReturnReasons();
    startPolling();

    function submitAction(endpoint, payload, redirectAfter, silent) {
        if (!endpoint) {
            setFeedback("This task action is unavailable right now.", true);
            return;
        }

        if (!silent) {
            disableControls(true);
            setFeedback("Processing request...", false);
        }

        fetch(endpoint, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": window.TeamLeaderConfig?.csrfToken || "",
            },
            body: JSON.stringify(payload || {}),
        })
            .then(function (response) {
                return response
                    .json()
                    .catch(function () {
                        return {};
                    })
                    .then(function (data) {
                        return {
                            ok: response.ok,
                            data: data,
                        };
                    });
            })
            .then(function (result) {
                if (!result.ok || !result.data.success) {
                    if (result.data && result.data.redirect_url) {
                        stopPolling();
                        window.setTimeout(function () {
                            window.location.replace(result.data.redirect_url);
                        }, 200);
                    }

                    throw new Error(
                        result.data.message || "Unable to update the task.",
                    );
                }

                if (result.data.task) {
                    applyTask(result.data.task);
                }

                if (!silent) {
                    showToast(
                        result.data.message || "Task updated successfully.",
                        "success",
                    );
                }

                setFeedback(
                    result.data.message || "Task updated successfully.",
                    false,
                );

                if (redirectAfter && result.data.redirect_url) {
                    window.setTimeout(function () {
                        window.location.href = result.data.redirect_url;
                    }, 350);
                }
            })
            .catch(function (error) {
                if (!silent) {
                    showToast(error.message || "Request failed.", "error");
                }
                setFeedback(error.message || "Request failed.", true);
            })
            .finally(function () {
                if (!silent) {
                    disableControls(false);
                }
                applyStatus(panel.dataset.currentStatus || "assigned");
            });
    }

    function applyTask(task) {
        panel.dataset.currentStatus = task.status || "assigned";

        if (driverNameInput && typeof task.driver_name !== "undefined") {
            driverNameInput.value = task.driver_name || "";
        }

        if (
            completionNoteInput &&
            typeof task.completion_note !== "undefined"
        ) {
            completionNoteInput.value =
                task.completion_note || completionNoteInput.value;
        }

        if (statusBadge) {
            statusBadge.className =
                "tl-status-badge " +
                (task.ui_status || "assigned").replace(/_/g, "-");
            statusBadge.textContent = task.status_label || "Assigned";
        }

        if (statusNote) {
            statusNote.textContent = task.status_note || "Task updated.";
        }

        applyStatus(task.status || "assigned", task);
        updateFlowSteps(task.status || "assigned");
    }

    function updateFlowSteps(status) {
        var order = ["claimed", "navigate", "work", "verify", "done"];
        var current = "claimed";

        if (status === "on_the_way") {
            current = "navigate";
        } else if (status === "in_progress") {
            current = "work";
        } else if (status === "waiting_verification") {
            current = "verify";
        } else if (status === "completed") {
            current = "done";
        }

        var currentIndex = order.indexOf(current);

        document.querySelectorAll("[data-step]").forEach(function (node) {
            var stepIndex = order.indexOf(node.dataset.step);
            node.classList.toggle("is-complete", stepIndex < currentIndex);
            node.classList.toggle("is-active", stepIndex === currentIndex);
        });
    }

    function applyStatus(status, task) {
        var safeTask = task || {
            can_proceed: status === "assigned",
            can_start: status === "on_the_way",
            can_complete: status === "in_progress",
            can_return: status === "assigned" || status === "on_the_way",
            driver_locked:
                status === "assigned" &&
                Boolean((driverNameInput?.value || "").trim()),
            can_edit_driver:
                status === "assigned" &&
                Boolean((driverNameInput?.value || "").trim()),
            completion_note_locked: status !== "in_progress",
            is_waiting: status === "waiting_verification",
            is_completed: status === "completed",
        };

        toggleHidden(proceedBtn, !safeTask.can_proceed);
        toggleHidden(startTowBtn, !safeTask.can_start);
        toggleHidden(completeTaskBtn, !safeTask.can_complete);
        toggleHidden(returnTaskBtn, !safeTask.can_return);
        toggleHidden(backToDashboardBtn, !safeTask.is_completed);

        if (driverNameInput) {
            driverNameInput.disabled = Boolean(safeTask.driver_locked);
            driverNameInput.placeholder = safeTask.driver_locked
                ? "Click Change Driver to update the saved driver"
                : "Enter driver name";
        }

        if (saveDriverBtn) {
            saveDriverBtn.disabled = Boolean(safeTask.driver_locked);
            saveDriverBtn.textContent = safeTask.driver_locked
                ? "Driver Saved"
                : Boolean((driverNameInput?.value || "").trim())
                  ? "Update Driver"
                  : "Save Driver";
        }

        toggleHidden(changeDriverBtn, !safeTask.can_edit_driver);

        if (completionNoteInput) {
            completionNoteInput.disabled = Boolean(
                safeTask.completion_note_locked || safeTask.is_completed,
            );
            completionNoteInput.placeholder = safeTask.can_complete
                ? "Add a short note before sending customer verification..."
                : "Completion note becomes available during the final step";
        }

        if (safeTask.is_waiting) {
            setFeedback(
                "Waiting for customer confirmation. This page will refresh automatically once verified.",
                false,
            );
            startPolling();
            return;
        }

        if (safeTask.is_completed) {
            stopPolling();
            setFeedback(
                "Customer confirmed the service. Redirecting back to the dashboard...",
                false,
            );
            window.setTimeout(function () {
                window.location.href = panel.dataset.dashboardUrl;
            }, 1200);
            return;
        }

        startPolling();
        setFeedback(
            "Use the actions below to keep this job moving smoothly.",
            false,
        );
    }

    function loadReturnReasons() {
        fetch("/teamleader/return-reasons", {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (reasons) {
                returnReasons = reasons || [];
                populateReturnReasonDropdown();
            })
            .catch(function () {
                returnReasons = [];
            });
    }

    function populateReturnReasonDropdown() {
        if (!returnReasonPreset || returnReasons.length === 0) {
            return;
        }

        returnReasonPreset.innerHTML = '<option value="">Select a reason</option>';

        var priorityOrder = { critical: 0, high: 1, medium: 2 };
        var sortedReasons = returnReasons.slice().sort(function (a, b) {
            return priorityOrder[a.priority] - priorityOrder[b.priority];
        });

        sortedReasons.forEach(function (reason) {
            var option = document.createElement("option");
            option.value = reason.value;
            option.textContent = reason.label;
            returnReasonPreset.appendChild(option);
        });
    }

    function openReturnModal() {
        if (!returnTaskModal) {
            setFeedback("Return dialog is unavailable right now.", true);
            return;
        }

        if (returnReasonPreset) {
            returnReasonPreset.value = "";
        }

        if (returnReasonNote) {
            returnReasonNote.value = "";
        }

        if (returnReasonDescription) {
            returnReasonDescription.textContent = "";
        }
        if (returnReasonNoteRequired) {
            returnReasonNoteRequired.style.display = "none";
        }
        if (returnReasonNoteHint) {
            returnReasonNoteHint.textContent = "";
        }

        selectedReturnReason = null;
        setReturnError("");
        returnTaskModal.classList.remove("hidden");
        returnTaskModal.setAttribute("aria-hidden", "false");

        window.setTimeout(function () {
            if (returnReasonPreset) {
                returnReasonPreset.focus();
            }
        }, 30);
    }

    function closeReturnModal() {
        if (!returnTaskModal) {
            return;
        }

        returnTaskModal.classList.add("hidden");
        returnTaskModal.setAttribute("aria-hidden", "true");
        setReturnError("");
    }

    function setReturnError(message) {
        if (!returnReasonError) {
            return;
        }

        returnReasonError.textContent = message || "";
        toggleHidden(returnReasonError, !message);
    }

    function startPolling() {
        if (pollTimer) {
            return;
        }

        pollTimer = window.setInterval(function () {
            fetch(panel.dataset.statusEndpoint, {
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
            })
                .then(function (response) {
                    return response
                        .json()
                        .catch(function () {
                            return {};
                        })
                        .then(function (data) {
                            return {
                                ok: response.ok,
                                data: data,
                            };
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

                    if (result.data && result.data.task) {
                        applyTask(result.data.task);
                    }
                })
                .catch(function () {
                    return null;
                });
        }, 10000);
    }

    function stopPolling() {
        if (!pollTimer) {
            return;
        }

        window.clearInterval(pollTimer);
        pollTimer = null;
    }

    function disableControls(disabled) {
        [
            saveDriverBtn,
            changeDriverBtn,
            proceedBtn,
            startTowBtn,
            completeTaskBtn,
            returnTaskBtn,
        ].forEach(function (button) {
            if (button && !button.classList.contains("hidden")) {
                button.disabled = disabled;
            }
        });
    }

    function toggleHidden(node, hidden) {
        if (!node) {
            return;
        }

        node.classList.toggle("hidden", Boolean(hidden));
    }

    function setFeedback(message, isError) {
        if (!feedback) {
            return;
        }

        feedback.textContent = message;
        feedback.classList.toggle("is-error", Boolean(isError));
    }

    function showToast(message, type) {
        var toast = document.createElement("div");
        toast.className = "tl-toast tl-toast--" + (type || "success");
        toast.textContent = message;
        toastWrap.appendChild(toast);

        window.setTimeout(function () {
            toast.remove();
        }, 3000);
    }
});
