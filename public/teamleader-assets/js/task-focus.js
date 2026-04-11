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
        submitAction(panel.dataset.returnEndpoint, {}, true);
    });

    applyStatus(panel.dataset.currentStatus || "assigned");
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
    }

    function applyStatus(status, task) {
        var safeTask = task || {
            can_proceed: status === "assigned",
            can_start: status === "on_the_way",
            can_complete: status === "in_progress",
            can_return:
                status === "assigned" ||
                status === "on_the_way" ||
                status === "in_progress",
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
                    return response.json();
                })
                .then(function (payload) {
                    if (payload && payload.task) {
                        applyTask(payload.task);
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
