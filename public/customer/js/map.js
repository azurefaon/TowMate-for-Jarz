const API_KEY =
    "eyJvcmciOiI1YjNjZTM1OTc4NTExMTAwMDFjZjYyNDgiLCJpZCI6ImU1YmM4ZDcyNmJiZTQyOTc5NTA0NTRkZjQxYTY5ODZjIiwiaCI6Im11cm11cjY0In0=";

let map;
let pickupMarker;
let dropMarker;
let pickupCoords = null;
let dropCoords = null;
let debounceTimer;
let routeLayer;
let currentRate = 0;

document.addEventListener("DOMContentLoaded", () => {
    map = L.map("map").setView([14.5995, 120.9842], 13);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "&copy; OpenStreetMap",
    }).addTo(map);

    toggleBookBtn();

    map.on("click", async function (e) {
        if (!pickupCoords) {
            pickupCoords = [e.latlng.lng, e.latlng.lat];

            if (pickupMarker) map.removeLayer(pickupMarker);
            pickupMarker = L.marker(e.latlng).addTo(map);

            const address = await getAddressFromCoords(
                e.latlng.lat,
                e.latlng.lng,
            );
            document.getElementById("pickup").value = address;
        } else {
            dropCoords = [e.latlng.lng, e.latlng.lat];

            if (dropMarker) map.removeLayer(dropMarker);
            dropMarker = L.marker(e.latlng).addTo(map);

            const address = await getAddressFromCoords(
                e.latlng.lat,
                e.latlng.lng,
            );
            document.getElementById("dropoff").value = address;

            fitBothMarkers();
            calculateEstimate();
        }

        toggleBookBtn();
    });

    const vehicleSelect = document.getElementById("vehicleType");

    vehicleSelect?.addEventListener("change", () => {
        const selected = vehicleSelect.options[vehicleSelect.selectedIndex];
        const perKm = selected.getAttribute("data-perkm");

        if (perKm) {
            currentRate = parseFloat(perKm);
            document.getElementById("rate").innerText = "₱" + currentRate;
        }

        calculateEstimate();
        toggleBookBtn();
    });

    document.getElementById("pickup")?.addEventListener("input", handleInput);
    document.getElementById("dropoff")?.addEventListener("input", handleInput);

    document
        .getElementById("pickup")
        ?.addEventListener("paste", hideSuggestions);
    document
        .getElementById("dropoff")
        ?.addEventListener("paste", hideSuggestions);

    document.addEventListener("click", (e) => {
        const isInsideInput = e.target.closest(".input-map-wrapper");
        const isSuggestion = e.target.closest(".suggestions");

        if (!isInsideInput && !isSuggestion) {
            hideSuggestions();
        }
    });

    document.getElementById("bookBtn")?.addEventListener("click", () => {
        if (document.getElementById("bookBtn").disabled) return;

        document.getElementById("summaryPickup").innerText =
            document.getElementById("pickup").value;

        document.getElementById("summaryDropoff").innerText =
            document.getElementById("dropoff").value;

        const vehicle = document.getElementById("vehicleType");
        document.getElementById("summaryVehicle").innerText =
            vehicle.options[vehicle.selectedIndex].text;

        const service = document.getElementById("serviceType");
        document.getElementById("summaryService").innerText =
            service.options[service.selectedIndex].text;

        document.getElementById("summaryDistance").innerText =
            document.getElementById("distance").innerText;

        document.getElementById("summaryPrice").innerText =
            document.getElementById("price").innerText;

        document.getElementById("confirmModal").classList.remove("hidden");
    });

    document.getElementById("cancelBtn")?.addEventListener("click", closeModal);

    document.getElementById("confirmBtn")?.addEventListener("click", () => {
        const btn = document.getElementById("confirmBtn");
        btn.innerText = "Processing...";
        btn.disabled = true;

        if (!pickupCoords || !dropCoords) {
            alert("Please select pickup and dropoff on the map");
            btn.innerText = "Confirm";
            btn.disabled = false;
            return;
        }

        prepareBookingData();
        document.getElementById("bookingForm").submit();
    });

    document.getElementById("confirmModal")?.addEventListener("click", (e) => {
        if (e.target.id === "confirmModal") closeModal();
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") closeModal();
    });
});

function handleInput(e) {
    clearTimeout(debounceTimer);

    const value = e.target.value.trim();
    const id = e.target.id;
    const containerId =
        id === "pickup" ? "pickupSuggestions" : "dropSuggestions";

    if (!value) {
        document.getElementById(containerId).innerHTML = "";

        if (id === "pickup") {
            pickupCoords = null;
            if (pickupMarker) map.removeLayer(pickupMarker);
            pickupMarker = null;
        } else {
            dropCoords = null;
            if (dropMarker) map.removeLayer(dropMarker);
            dropMarker = null;
        }

        if (routeLayer) {
            map.removeLayer(routeLayer);
            routeLayer = null;
        }

        document.getElementById("distance").innerText = "0 km";
        document.getElementById("price").innerText = "₱0.00";

        toggleBookBtn();
        return;
    }

    debounceTimer = setTimeout(() => {
        getSuggestions(value, containerId, id);
    }, 400);

    toggleBookBtn();
}

function hideSuggestions() {
    const pickup = document.getElementById("pickupSuggestions");
    const drop = document.getElementById("dropSuggestions");

    if (pickup) pickup.innerHTML = "";
    if (drop) drop.innerHTML = "";
}

function closeModal() {
    const modal = document.getElementById("confirmModal");
    const confirmBtn = document.getElementById("confirmBtn");

    modal.classList.add("hidden");
    confirmBtn.innerText = "Confirm";
    confirmBtn.disabled = false;
}

function toggleBookBtn() {
    const pickup = document.getElementById("pickup")?.value.trim();
    const dropoff = document.getElementById("dropoff")?.value.trim();
    const bookBtn = document.getElementById("bookBtn");

    if (!bookBtn) return;

    if (pickup && dropoff && currentRate > 0) {
        bookBtn.disabled = false;
        bookBtn.classList.remove("disabled");
    } else {
        bookBtn.disabled = true;
        bookBtn.classList.add("disabled");
    }
}

function fitBothMarkers() {
    if (!pickupCoords || !dropCoords) return;

    const bounds = L.latLngBounds([
        [pickupCoords[1], pickupCoords[0]],
        [dropCoords[1], dropCoords[0]],
    ]);

    map.fitBounds(bounds, { padding: [50, 50] });
}

async function calculateEstimate() {
    if (!pickupCoords || !dropCoords) return;

    const vehicleSelect = document.getElementById("vehicleType");
    const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];

    if (!selectedOption.value) return;

    const baseRate = parseFloat(selectedOption.dataset.base);
    const perKmRate = parseFloat(selectedOption.dataset.perkm);
    const serviceType = document.getElementById("serviceType").value;

    const res = await fetch(
        "https://api.openrouteservice.org/v2/directions/driving-car/geojson",
        {
            method: "POST",
            headers: {
                Authorization: API_KEY,
                "Content-Type": "application/json",
            },
            body: JSON.stringify({
                coordinates: [pickupCoords, dropCoords],
            }),
        },
    );

    const data = await res.json();
    if (!data.features || !data.features.length) return;

    if (routeLayer) map.removeLayer(routeLayer);

    const routeCoords = data.features[0].geometry.coordinates.map((c) => [
        c[1],
        c[0],
    ]);

    routeLayer = L.polyline(routeCoords, {
        color: "#22c55e",
        weight: 5,
    }).addTo(map);

    map.fitBounds(routeLayer.getBounds());

    const distanceKm = data.features[0].properties.summary.distance / 1000;

    let multiplier = 1;
    if (serviceType === "express") multiplier = 1.5;
    if (serviceType === "scheduled") multiplier = 1.2;

    const total = (baseRate + distanceKm * perKmRate) * multiplier;

    document.getElementById("distance").innerText =
        distanceKm.toFixed(2) + " km";
    document.getElementById("price").innerText = "₱" + total.toFixed(2);
}

function prepareBookingData() {
    document.getElementById("pickup_lat").value = pickupCoords[1];
    document.getElementById("pickup_lng").value = pickupCoords[0];
    document.getElementById("drop_lat").value = dropCoords[1];
    document.getElementById("drop_lng").value = dropCoords[0];
    document.getElementById("distance_input").value = document
        .getElementById("distance")
        .innerText.replace(" km", "");
    document.getElementById("price_input").value = document
        .getElementById("price")
        .innerText.replace("₱", "");
}

async function getSuggestions(query, containerId, type) {
    if (!query) return;

    const res = await fetch(
        `https://api.openrouteservice.org/geocode/autocomplete?api_key=${API_KEY}&text=${encodeURIComponent(query)}&boundary.country=PH&size=5`,
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

            fitBothMarkers();
            calculateEstimate();
            toggleBookBtn();
        };

        container.appendChild(div);
    });
}

async function getAddressFromCoords(lat, lng) {
    const res = await fetch(
        `https://api.openrouteservice.org/geocode/reverse?api_key=${API_KEY}&point.lat=${lat}&point.lon=${lng}`,
    );

    const data = await res.json();
    return data.features?.[0]?.properties?.label || "Unknown location";
}
