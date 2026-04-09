document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") {
        lucide.createIcons();
    }

    const dateElement = document.getElementById("currentDate");
    const incomingCount = document.getElementById("incomingCount");
    const refreshButton = document.getElementById("refreshDashboardBtn");
    const chartCanvas = document.getElementById("performanceChart");

    const updateDate = () => {
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
    };

    const updateIncomingCount = () => {
        if (!incomingCount) {
            return;
        }

        fetch("/admin-dashboard/pending-bookings-count", {
            headers: { "X-Requested-With": "XMLHttpRequest" },
        })
            .then((response) => response.json())
            .then((payload) => {
                incomingCount.textContent = Number(payload.count) || 0;
            })
            .catch(() => {
                incomingCount.textContent = "—";
            });
    };

    updateDate();
    updateIncomingCount();
    setInterval(updateDate, 60000);
    setInterval(updateIncomingCount, 30000);

    refreshButton?.addEventListener("click", function () {
        window.location.reload();
    });

    if (chartCanvas && typeof Chart !== "undefined") {
        const completed = Number(chartCanvas.dataset.completed || 0);
        const assigned = Number(chartCanvas.dataset.assigned || 0);
        const pending = Number(chartCanvas.dataset.pending || 0);

        new Chart(chartCanvas.getContext("2d"), {
            type: "doughnut",
            data: {
                labels: ["Completed", "Assigned", "Pending"],
                datasets: [
                    {
                        data: [completed, assigned, pending],
                        backgroundColor: ["#111827", "#facc15", "#e5e7eb"],
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

    document.querySelectorAll(".filter-btn").forEach((button) => {
        button.addEventListener("click", function () {
            document
                .querySelector(".filter-btn.active")
                ?.classList.remove("active");
            this.classList.add("active");

            const filter = this.dataset.filter;

            document.querySelectorAll(".activity-item").forEach((item) => {
                item.style.display =
                    filter === "all" || item.dataset.type === filter
                        ? "flex"
                        : "none";
            });
        });
    });
});
