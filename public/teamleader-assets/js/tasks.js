document.addEventListener("DOMContentLoaded", function () {
    var board = document.getElementById("taskBoard");
    var taskGrid = document.getElementById("taskGrid");

    if (!board || !taskGrid) {
        return;
    }

    var toastWrap = document.createElement("div");
    toastWrap.className = "tl-toast-wrap";
    document.body.appendChild(toastWrap);

    board.addEventListener("click", function (event) {
        var button = event.target.closest('[data-booking-action="accept"]');

        if (!button || button.disabled) {
            return;
        }

        event.preventDefault();
        acceptTask(button);
    });

    function acceptTask(button) {
        var endpoint = button.getAttribute("data-endpoint");
        var originalText = button.textContent;

        toggleButtons(true);
        button.textContent = "Accepting...";

        fetch(endpoint, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": window.TeamLeaderConfig?.csrfToken || "",
            },
            body: JSON.stringify({}),
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
                    showToast(
                        result.data.message || "Unable to accept this task.",
                        "error",
                    );

                    if (result.data.redirect_url) {
                        window.setTimeout(function () {
                            window.location.href = result.data.redirect_url;
                        }, 500);
                    }

                    throw new Error("Task acceptance failed.");
                }

                showToast(
                    result.data.message || "Task accepted successfully.",
                    "success",
                );

                if (result.data.redirect_url) {
                    window.setTimeout(function () {
                        window.location.href = result.data.redirect_url;
                    }, 250);
                }
            })
            .catch(function () {
                button.textContent = originalText;
                toggleButtons(false);
            });
    }

    function refreshQueue() {
        var endpoint = window.TeamLeaderConfig?.tasksUrl;

        if (!endpoint) {
            return;
        }

        fetch(endpoint, {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                if (payload.redirect_url && payload.success === false) {
                    window.location.href = payload.redirect_url;
                    return;
                }

                updateStats(payload.stats || {});
                renderTasks(payload.tasks || []);
            })
            .catch(function () {
                return null;
            });
    }

    function updateStats(stats) {
        Object.keys(stats || {}).forEach(function (key) {
            var node = board.querySelector('[data-stat="' + key + '"]');
            if (node) {
                node.textContent = stats[key];
            }
        });
    }

    function renderTasks(tasks) {
        if (!Array.isArray(tasks) || !tasks.length) {
            taskGrid.innerHTML = `
                <div class="tl-empty-state" id="emptyTaskState">
                    <h3>No open tasks right now</h3>
                    <p>New dispatcher handoffs will appear here when they are ready for a Team Leader to accept.</p>
                </div>`;
            return;
        }

        taskGrid.innerHTML = tasks
            .map(function (task) {
                return `
                    <article class="tl-task-card" data-booking-id="${escapeHtml(task.booking_code)}" data-status="${escapeHtml(task.status)}">
                        <div class="tl-task-card__header">
                            <div>
                                <p class="tl-task-card__eyebrow">Task ${escapeHtml(task.booking_code)}</p>
                                <h3>${escapeHtml(task.pickup_address)} → ${escapeHtml(task.dropoff_address)}</h3>
                            </div>
                            <span class="tl-status-badge assigned">Ready</span>
                        </div>

                        <div class="tl-task-card__meta">
                            <div>
                                <small>Customer</small>
                                <p>${escapeHtml(task.customer_name)}</p>
                                <span>${escapeHtml(task.customer_phone)}</span>
                            </div>
                            <div>
                                <small>Truck Type</small>
                                <p>${escapeHtml(task.truck_type)}</p>
                                <span>Quotation: ${escapeHtml(task.quotation_number)}</span>
                            </div>
                            <div>
                                <small>Assigned Truck</small>
                                <p>${escapeHtml(task.unit_name)}</p>
                                <span>${escapeHtml(task.unit_plate || "Plate pending")} · Updated ${escapeHtml(task.updated_at_human)}</span>
                            </div>
                        </div>

                        <div class="tl-task-card__note">
                            Your assigned truck is linked automatically when you accept this job, so your crew can move right away.
                        </div>

                        <div class="tl-task-card__actions">
                            <button type="button" class="tl-btn tl-btn--primary tl-btn--full" data-booking-action="accept" data-endpoint="${escapeHtml(task.accept_url)}">
                                Accept Task
                            </button>
                        </div>
                    </article>`;
            })
            .join("");
    }

    function toggleButtons(disabled) {
        board.querySelectorAll("button").forEach(function (button) {
            button.disabled = disabled;
        });
    }

    function showToast(message, type) {
        var toast = document.createElement("div");
        toast.className = "tl-toast tl-toast--" + (type || "success");
        toast.textContent = message;
        toastWrap.appendChild(toast);

        window.setTimeout(function () {
            toast.remove();
        }, 2800);
    }

    function escapeHtml(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    refreshQueue();
    window.setInterval(refreshQueue, 12000);
});
