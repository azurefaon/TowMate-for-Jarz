let currentBooking = null;

window.openDetailsModal = function (el) {
    try {
        const booking = JSON.parse(el.getAttribute("data-booking"));
        const modal = document.getElementById("detailsModal");
        currentBooking = booking;

        document.getElementById("dPickup").innerText =
            booking.pickup_address || "-";

        document.getElementById("dDrop").innerText =
            booking.dropoff_address || "-";

        document.getElementById("dVehicle").innerText =
            booking.truckType?.name || "-";

        document.getElementById("dDriver").innerText =
            booking.unit?.driver?.name || "Not Assigned";

        document.getElementById("dStatus").innerText = (
            booking.status || "-"
        ).toUpperCase();

        document.getElementById("dDate").innerText = booking.created_at || "-";

        document.getElementById("dTotal").innerText =
            "₱" + parseFloat(booking.final_total || 0).toFixed(2);

        modal.classList.remove("hidden");
        modal.classList.add("active");
    } catch (err) {
        console.error("Modal Error:", err);
    }
};

window.closeDetailsModal = function () {
    const modal = document.getElementById("detailsModal");
    modal.classList.add("hidden");
    modal.classList.remove("active");
};

window.downloadReceipt = function () {
    if (!currentBooking) return;
    window.location.href = "/customer/receipt/" + currentBooking.id;
};

document.addEventListener("click", function (e) {
    const modal = document.getElementById("detailsModal");

    if (!modal || modal.classList.contains("hidden")) return;

    if (e.target === modal) {
        closeDetailsModal();
    }
});

document.addEventListener("DOMContentLoaded", () => {
    const filter = document.getElementById("statusFilter");
    const searchInput = document.querySelector('input[name="search"]');

    let typingTimer;
    const delay = 500;

    function updateURL(params) {
        const url = new URL(window.location.href);

        Object.keys(params).forEach((key) => {
            if (params[key] === "" || params[key] === "all") {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, params[key]);
            }
        });

        url.searchParams.delete("page");
        window.location.href = url.toString();
    }

    if (filter) {
        filter.addEventListener("change", () => {
            updateURL({
                status: filter.value,
                search: searchInput ? searchInput.value : "",
            });
        });
    }

    if (searchInput) {
        searchInput.addEventListener("input", () => {
            clearTimeout(typingTimer);

            typingTimer = setTimeout(() => {
                updateURL({
                    search: searchInput.value,
                    status: filter ? filter.value : "",
                });
            }, delay);
        });
    }

    const gridBtn = document.getElementById("gridViewBtn");
    const tableBtn = document.getElementById("tableViewBtn");

    const gridView = document.getElementById("gridView");
    const tableView = document.getElementById("tableView");

    if (gridBtn && tableBtn && gridView && tableView) {
        gridBtn.addEventListener("click", () => {
            gridView.style.display = "flex";
            tableView.style.display = "none";

            gridBtn.classList.add("active");
            tableBtn.classList.remove("active");

            localStorage.setItem("viewMode", "grid");
        });

        tableBtn.addEventListener("click", () => {
            gridView.style.display = "none";
            tableView.style.display = "block";

            tableBtn.classList.add("active");
            gridBtn.classList.remove("active");

            localStorage.setItem("viewMode", "table");
        });

        const saved = localStorage.getItem("viewMode");

        if (saved === "table") {
            tableBtn.click();
        }
    }
});

function openTracking(id) {
    console.log("Tracking booking ID:", id);

    const modal = document.getElementById("trackingModal");
    if (modal) modal.style.display = "flex";
}

function closeTracking() {
    const modal = document.getElementById("trackingModal");
    if (modal) modal.style.display = "none";
}

function openLogoutModal() {
    document.getElementById("logoutModal").classList.remove("hidden");
}

function closeLogoutModal() {
    document.getElementById("logoutModal").classList.add("hidden");
}

window.cancelBooking = function () {
    const modal = document.getElementById("confirmCancelModal");
    modal.classList.remove("hidden");
    if (modal) modal.classList.add("active");
};

window.closeCancelModal = function () {
    const modal = document.getElementById("confirmCancelModal");
    modal.classList.add("hidden");
    if (modal) modal.classList.remove("active");
};

window.confirmCancel = function () {
    if (!currentBooking) return;

    const btn = document.querySelector(".cancel-btn.danger");

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = "Cancelling...";
    }

    fetch("/customer/cancel/" + currentBooking.id, {
        method: "POST",
        headers: {
            "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')
                .content,
        },
    })
        .then(() => {
            location.reload();
        })
        .catch(() => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = "Cancel Booking";
            }
        });
};
