let trackMap;
let pickupMarker;
let dropMarker;
let routeLine;

function updateETA(distanceKm, durationMin) {
    const etaEl = document.getElementById("eta");
    const distEl = document.getElementById("distance");
    const numericDistance = Number(distanceKm || 0);
    const fallbackDuration =
        numericDistance > 0
            ? Math.max(Math.ceil((numericDistance / 30) * 60), 1)
            : 0;
    const safeDuration =
        Number(durationMin || 0) > 0
            ? Math.ceil(durationMin || 0)
            : fallbackDuration;

    if (etaEl) etaEl.innerText = safeDuration > 0 ? safeDuration : "--";
    if (distEl)
        distEl.innerText =
            numericDistance > 0
                ? numericDistance.toFixed(1) + " km away"
                : "Route is being prepared";
}

setInterval(() => {
    initTrackMap();
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
        .catch(() => {
            alert("Something went wrong");
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
    const mapEl = document.getElementById("map");
    if (!mapEl || !window.bookingData || typeof L === "undefined") return;

    if (trackMap) {
        trackMap.remove();
    }

    trackMap = L.map("map", {
        zoomControl: false,
    }).setView([14.5995, 120.9842], 13);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "&copy; OpenStreetMap",
    }).addTo(trackMap);

    const pickup = [
        window.bookingData.pickup_lat,
        window.bookingData.pickup_lng,
    ];

    const drop = [window.bookingData.drop_lat, window.bookingData.drop_lng];

    pickupMarker = L.marker(pickup).addTo(trackMap);
    dropMarker = L.marker(drop).addTo(trackMap);

    fetch("/geo/route", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')
                ?.content,
            Accept: "application/json",
        },
        body: JSON.stringify({
            pickup_lat: pickup[0],
            pickup_lng: pickup[1],
            drop_lat: drop[0],
            drop_lng: drop[1],
        }),
    })
        .then((res) => res.json())
        .then((data) => {
            const coords =
                Array.isArray(data.coordinates) && data.coordinates.length > 1
                    ? data.coordinates
                    : [pickup, drop];

            routeLine = L.polyline(coords, {
                color: "#22c55e",
                weight: 5,
                dashArray: coords.length > 2 ? null : "5, 10",
            }).addTo(trackMap);

            trackMap.fitBounds(routeLine.getBounds(), {
                padding: [60, 60],
            });

            updateETA(data.distance_km || 0, data.duration_min || 0);
        })
        .catch(() => {
            const fallback = L.polyline([pickup, drop], {
                color: "#22c55e",
                weight: 5,
                dashArray: "5, 10",
            }).addTo(trackMap);

            trackMap.fitBounds(fallback.getBounds(), {
                padding: [60, 60],
            });
        });
};

document.addEventListener("DOMContentLoaded", () => {
    initTrackMap();
});

document.addEventListener("click", function (e) {
    const cancelBtn = e.target.closest(".cancel-track-btn");
    if (cancelBtn) {
        cancelTrackBooking(cancelBtn.dataset.id);
    }

    const callBtn = e.target.closest(".call-driver-btn");
    if (callBtn) {
        callDriver(callBtn.dataset.phone);
    }
});
