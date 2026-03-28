const API_KEY =
    "eyJvcmciOiI1YjNjZTM1OTc4NTExMTAwMDFjZjYyNDgiLCJpZCI6ImU1YmM4ZDcyNmJiZTQyOTc5NTA0NTRkZjQxYTY5ODZjIiwiaCI6Im11cm11cjY0In0=";

let map;
let pickupMarker;
let dropMarker;
let pickupCoords = null;
let dropCoords = null;
let debounceTimer;
let routeLayer;

document.addEventListener("DOMContentLoaded", () => {
    map = L.map("map").setView([14.5995, 120.9842], 13);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "&copy; OpenStreetMap",
    }).addTo(map);

    map.on("click", function (e) {
        if (!pickupCoords) {
            pickupCoords = [e.latlng.lng, e.latlng.lat];

            if (pickupMarker) map.removeLayer(pickupMarker);
            pickupMarker = L.marker(e.latlng).addTo(map);

            document.getElementById("pickup").value =
                e.latlng.lat + ", " + e.latlng.lng;
        } else {
            dropCoords = [e.latlng.lng, e.latlng.lat];

            if (dropMarker) map.removeLayer(dropMarker);
            dropMarker = L.marker(e.latlng).addTo(map);

            document.getElementById("dropoff").value =
                e.latlng.lat + ", " + e.latlng.lng;

            calculateEstimate();
        }
    });

    document
        .getElementById("pickup")
        ?.addEventListener("blur", calculateEstimate);
    document
        .getElementById("dropoff")
        ?.addEventListener("blur", calculateEstimate);
    document
        .getElementById("vehicleType")
        ?.addEventListener("change", calculateEstimate);
    document
        .getElementById("serviceType")
        ?.addEventListener("change", calculateEstimate);

    document.getElementById("pickup")?.addEventListener("input", (e) => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            getSuggestions(e.target.value, "pickupSuggestions", "pickup");
        }, 400);
    });

    document.getElementById("dropoff")?.addEventListener("input", (e) => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            getSuggestions(e.target.value, "dropSuggestions", "dropoff");
        }, 400);
    });
});

async function getCoordinates(address) {
    const res = await fetch(
        `https://api.openrouteservice.org/geocode/search?api_key=${API_KEY}&text=${encodeURIComponent(address)}`,
    );

    const data = await res.json();

    if (!data.features.length) return null;

    return data.features[0].geometry.coordinates;
}

async function calculateEstimate() {
    let start = pickupCoords;
    let end = dropCoords;

    if (!start) {
        const pickup = document.getElementById("pickup").value;
        start = await getCoordinates(pickup);
    }

    if (!end) {
        const drop = document.getElementById("dropoff").value;
        end = await getCoordinates(drop);
    }

    if (!start || !end) return;

    const vehicleSelect = document.getElementById("vehicleType");
    const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];

    if (!selectedOption.value) return;

    const baseRate = parseFloat(selectedOption.dataset.base);
    const perKmRate = parseFloat(selectedOption.dataset.perkm);
    const serviceType = document.getElementById("serviceType").value;

    const res = await fetch(
        "https://api.openrouteservice.org/v2/directions/driving-car",
        {
            method: "POST",
            headers: {
                Authorization: API_KEY,
                "Content-Type": "application/json",
            },
            body: JSON.stringify({
                coordinates: [start, end],
            }),
        },
    );

    const data = await res.json();

    if (routeLayer) {
        map.removeLayer(routeLayer);
    }

    const routeCoords = data.routes[0].geometry.coordinates.map((c) => [
        c[1],
        c[0],
    ]);

    routeLayer = L.polyline(routeCoords, {
        color: "#16a34a",
        weight: 5,
    }).addTo(map);

    map.fitBounds(routeLayer.getBounds());

    const distanceKm = data.routes[0].summary.distance / 1000;

    let multiplier = 1;
    if (serviceType === "express") multiplier = 1.5;
    if (serviceType === "scheduled") multiplier = 1.2;

    const total = (baseRate + distanceKm * perKmRate) * multiplier;

    document.getElementById("distance").innerText =
        distanceKm.toFixed(2) + " km";

    document.getElementById("price").innerText = "₱" + total.toFixed(2);
}

window.openConfirmModal = function () {
    const pickup = document.getElementById("pickup").value;
    const drop = document.getElementById("dropoff").value;

    if (!pickup || !drop) return;

    document.getElementById("confirmModal")?.classList.remove("hidden");
};

window.closeConfirmModal = function () {
    document.getElementById("confirmModal")?.classList.add("hidden");
};

window.submitBooking = async function () {
    await calculateEstimate();

    const success = document.getElementById("successModal");
    if (success) success.classList.remove("hidden");

    setTimeout(() => {
        document.getElementById("bookingForm").submit();
    }, 1200);
};

async function getSuggestions(query, containerId, type) {
    if (!query) return;

    const res = await fetch(
        `https://api.openrouteservice.org/geocode/autocomplete?api_key=${API_KEY}&text=${encodeURIComponent(query)}`,
    );

    const data = await res.json();
    const container = document.getElementById(containerId);

    container.innerHTML = "";

    data.features.forEach((place) => {
        const div = document.createElement("div");
        div.innerText = place.properties.label;

        div.onclick = () => {
            document.getElementById(type).value = place.properties.label;

            const coords = place.geometry.coordinates;

            if (type === "pickup") {
                pickupCoords = coords;

                if (pickupMarker) map.removeLayer(pickupMarker);
                pickupMarker = L.marker([coords[1], coords[0]]).addTo(map);
                map.setView([coords[1], coords[0]], 15);
            } else {
                dropCoords = coords;

                if (dropMarker) map.removeLayer(dropMarker);
                dropMarker = L.marker([coords[1], coords[0]]).addTo(map);
            }

            container.innerHTML = "";

            calculateEstimate();
        };

        container.appendChild(div);
    });
}
