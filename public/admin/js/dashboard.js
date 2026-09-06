document.addEventListener("DOMContentLoaded", function () {
    const dashboard = document.getElementById("dispatcherDashboard");
    const dateElement = document.getElementById("currentDate");
    const refreshButton = document.getElementById("refreshDashboardBtn");
    const chartCanvas = document.getElementById("performanceChart");
    const overviewUrl = dashboard?.dataset.liveOverviewUrl || "";

    let chartInstance = null;

    function updateDate() {
        if (!dateElement) {
            return;
        }

        const now = new Date();
        dateElement.textContent = now.toLocaleDateString("en-US", {
            weekday: "long",
            year: "numeric",
            month: "long",
            day: "numeric",
            hour: "2-digit",
            minute: "2-digit",
        });
    }

    function setText(id, value) {
        const node = document.getElementById(id);
        if (node) {
            node.textContent = value;
        }
    }

    function escapeHtml(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function renderIncomingRequests(items) {
        const list = document.getElementById("incomingRequestList");
        if (!list) {
            return;
        }

        if (!Array.isArray(items) || !items.length) {
            list.innerHTML = `<p class="empty-note">No pending requests right now.</p>`;
            return;
        }

        list.innerHTML = items
            .map((item) => {
                return `
                    <div class="simple-row">
                        <div class="simple-row-top">
                            <strong>${escapeHtml(item.customer_name)}</strong>
                            <span class="badge-pending">Pending</span>
                        </div>
                        <div class="simple-row-sub">${escapeHtml(item.truck_type)} &middot; ${escapeHtml(item.booking_code)}</div>
                        <div class="simple-row-time">${escapeHtml(item.created_at_human)}</div>
                    </div>`;
            })
            .join("");
    }

    function renderCurrentActivity(items) {
        const list = document.getElementById("currentActivityList");
        if (!list) {
            return;
        }

        if (!Array.isArray(items) || !items.length) {
            list.innerHTML = `<tr><td colspan="5" class="empty-note">No jobs are active right now.</td></tr>`;
            return;
        }

        list.innerHTML = items
            .map((item) => {
                return `
                    <tr>
                        <td>${escapeHtml(item.booking_code)}</td>
                        <td>${escapeHtml(item.status)}</td>
                        <td>${escapeHtml(item.unit_name)} &middot; ${escapeHtml(item.unit_plate)}</td>
                        <td>${escapeHtml(item.customer_name)}</td>
                        <td>${escapeHtml(item.updated_at_human)}</td>
                    </tr>`;
            })
            .join("");
    }

    function renderScheduleOverview(items) {
        const list = document.getElementById("scheduleOverviewList");
        if (!list) {
            return;
        }

        if (!Array.isArray(items) || !items.length) {
            list.innerHTML = `
                <div class="no-activity">
                    <i data-lucide="calendar-clock"></i>
                    <p>No scheduled bookings are waiting right now.</p>
                </div>`;
            return;
        }

        list.innerHTML = items
            .map((item) => {
                return `
                    <div class="activity-item" data-type="schedule">
                        <div class="activity-icon request-icon">
                            <i data-lucide="calendar-clock"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-line">
                                <strong>${escapeHtml(item.booking_code)}</strong>
                                <span>${escapeHtml(item.truck_type)}</span>
                            </div>
                            <div class="activity-meta">
                                <span>${escapeHtml(item.customer_name)}</span>
                                <span>${escapeHtml(item.schedule_window_label)}</span>
                                <span>${escapeHtml(item.pickup_address)}</span>
                                <span>${escapeHtml(item.dropoff_address)}</span>
                            </div>
                        </div>
                        <div class="schedule-status ${escapeHtml(item.tone || "upcoming")}">${escapeHtml(item.status)}</div>
                    </div>`;
            })
            .join("");
    }

    function renderTeamLeaderStatuses(items) {
        const list = document.getElementById("teamLeaderStatusList");
        if (!list) {
            return;
        }

        if (!Array.isArray(items) || !items.length) {
            list.innerHTML = `
                <div class="no-activity">
                    <i data-lucide="wifi-off"></i>
                    <p>No team leaders are registered yet.</p>
                </div>`;
            return;
        }

        list.innerHTML = items
            .map((item) => {
                const statusClass =
                    item.presence === "online"
                        ? item.workload === "busy"
                            ? "busy"
                            : "available"
                        : "offline";

                return `
                    <div class="activity-item" data-type="request">
                        <div class="activity-icon request-icon">
                            <i data-lucide="${item.presence === "online" ? "wifi" : "wifi-off"}"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-line">
                                <strong>${escapeHtml(item.name)}</strong>
                                <span>${escapeHtml(item.unit_name)}</span>
                            </div>
                            <div class="activity-meta">
                                <span>${escapeHtml(item.phone)}</span>
                                <span>${escapeHtml(item.driver_name)}</span>
                                <span>${escapeHtml(item.last_seen_label)}</span>
                            </div>
                        </div>
                        <div class="activity-status ${statusClass}">${escapeHtml(item.status_summary)}</div>
                    </div>`;
            })
            .join("");
    }

    function initialiseChart() {
        if (!chartCanvas || typeof Chart === "undefined" || chartInstance) {
            return;
        }

        chartInstance = new Chart(chartCanvas.getContext("2d"), {
            type: "doughnut",
            data: {
                labels: ["Completed", "Assigned", "Pending"],
                datasets: [
                    {
                        data: [
                            Number(chartCanvas.dataset.completed || 0),
                            Number(chartCanvas.dataset.assigned || 0),
                            Number(chartCanvas.dataset.pending || 0),
                        ],
                        backgroundColor: ["#111827", "#facc15", "#d1d5db"],
                        borderWidth: 0,
                        cutout: "70%",
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                },
            },
        });
    }

    function updateChart(chartData) {
        initialiseChart();

        if (!chartInstance || !chartData) {
            return;
        }

        const completed = Number(chartData.completed || 0);
        const assigned = Number(chartData.assigned || 0);
        const pending = Number(chartData.pending || 0);

        chartInstance.data.datasets[0].data = [completed, assigned, pending];
        chartInstance.update();

        setText("totalJobsCount", completed + assigned + pending);
        setText("legendAssigned", assigned);
        setText("legendCompleted", completed);
        setText("legendPending", pending);
    }

    function refreshOverview() {
        if (!overviewUrl) {
            return;
        }

        fetch(overviewUrl, {
            headers: { "X-Requested-With": "XMLHttpRequest" },
        })
            .then((response) => response.json())
            .then((payload) => {
                setText("incomingCount", Number(payload.pendingRequests || 0));
                setText("activeJobsCount", Number(payload.activeJobs || 0));
                setText(
                    "availableLeadersCount",
                    Number(payload.available || 0),
                );
                setText(
                    "scheduledQueueCount",
                    Number(payload.scheduledTodayCount || 0) +
                        Number(payload.upcomingScheduledCount || 0),
                );
                setText(
                    "dueNowScheduledCount",
                    Number(payload.dueNowScheduledCount || 0),
                );
                setText(
                    "scheduledTodayCount",
                    Number(payload.scheduledTodayCount || 0),
                );
                setText(
                    "upcomingScheduledCount",
                    Number(payload.upcomingScheduledCount || 0),
                );
                setText(
                    "busyLeadersCount",
                    Number(payload.busyTeamLeadersCount || 0),
                );
                setText("delayedJobsCount", Number(payload.delayed || 0));
                setText(
                    "onlineLeadersCount",
                    Number(payload.onlineTeamLeadersCount || 0),
                );
                setText(
                    "offlineLeadersNote",
                    `${Number(payload.offlineTeamLeadersCount || 0)} offline`,
                );

                renderIncomingRequests(payload.incomingRequests || []);
                renderScheduleOverview(payload.scheduleOverview || []);
                renderCurrentActivity(payload.currentActivities || []);
                renderTeamLeaderStatuses(payload.teamLeaderStatuses || []);
                updateChart(payload.chartData || {});

                if (typeof lucide !== "undefined") {
                    lucide.createIcons();
                }
            })
            .catch(() => null);
    }

    function bindRealtime() {
        if (
            typeof Pusher === "undefined" ||
            !window.PusherConfig ||
            !window.PusherConfig.key
        ) {
            return;
        }

        try {
            const pusher = new Pusher(window.PusherConfig.key, {
                cluster: window.PusherConfig.cluster,
                encrypted: true,
            });

            const channel = pusher.subscribe("dispatch");
            channel.bind("booking.created", refreshOverview);
            channel.bind("booking.updated", refreshOverview);
        } catch (error) {
            return;
        }
    }

    updateDate();
    initialiseChart();
    refreshOverview();
    bindRealtime();

    setInterval(updateDate, 60000);
    setInterval(refreshOverview, 12000);

    refreshButton?.addEventListener("click", refreshOverview);

    if (typeof lucide !== "undefined") {
        lucide.createIcons();
    }
});
