setInterval(() => {
    console.log("Checking updates...");
}, 10000);

window.cancelTrackBooking = function (id) {
    if (!id) return;

    if (!confirm("Cancel this booking?")) return;

    fetch("/customer/cancel/" + id, {
        method: "POST",
        headers: {
            "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')
                .content,
            Accept: "application/json",
        },
    })
        .then((res) => res.json())
        .then(() => {
            location.reload();
        })
        .catch((err) => {
            console.error("Cancel error:", err);
        });
};

window.callDriver = function (phone) {
    if (!phone) {
        alert("Driver not assigned yet");
        return;
    }

    window.location.href = "tel:" + phone;
};

window.initTrackMap = function () {
    const map = document.getElementById("map");
    if (!map) return;

    map.classList.add("map-loading");
};

document.addEventListener("DOMContentLoaded", () => {
    initTrackMap();
});

document.addEventListener("click", function (e) {
    if (e.target.closest(".cancel-track-btn")) {
        const btn = e.target.closest(".cancel-track-btn");
        const id = btn.dataset.id;

        cancelTrackBooking(id);
    }

    if (e.target.closest(".call-driver-btn")) {
        const btn = e.target.closest(".call-driver-btn");
        const phone = btn.dataset.phone;

        callDriver(phone);
    }
});
