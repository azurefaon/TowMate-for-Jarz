const API_KEY =
    "eyJvcmciOiI1YjNjZTM1OTc4NTExMTAwMDFjZjYyNDgiLCJpZCI6ImU1YmM4ZDcyNmJiZTQyOTc5NTA0NTRkZjQxYTY5ODZjIiwiaCI6Im11cm11cjY0In0=";

let trackMap;
let pickupMarker;
let dropMarker;
let routeLine;

function updateETA(distanceKm, durationMin) {
    const etaEl = document.getElementById("eta");
    const distEl = document.getElementById("distance");

    if (etaEl) etaEl.innerText = Math.ceil(durationMin);
    if (distEl) distEl.innerText = distanceKm.toFixed(1) + " km away";
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
    if (!mapEl || !window.bookingData) return;

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

    fetch(
        "https://api.openrouteservice.org/v2/directions/driving-car/geojson",
        {
            method: "POST",
            headers: {
                Authorization: API_KEY,
                "Content-Type": "application/json",
            },
            body: JSON.stringify({
                coordinates: [
                    [pickup[1], pickup[0]],
                    [drop[1], drop[0]],
                ],
            }),
        },
    )
        .then((res) => res.json())
        .then((data) => {
            if (!data.features || !data.features.length) return;

            const route = data.features[0];

            const coords = route.geometry.coordinates.map((c) => [c[1], c[0]]);

            routeLine = L.polyline(coords, {
                color: "#22c55e",
                weight: 5,
            }).addTo(trackMap);

            trackMap.fitBounds(routeLine.getBounds(), {
                padding: [60, 60],
            });

            const distanceKm = route.properties.summary.distance / 1000;
            const durationMin = route.properties.summary.duration / 60;

            updateETA(distanceKm, durationMin);
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
