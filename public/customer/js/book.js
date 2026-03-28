let map;
let marker;
let directionsService;
let directionsRenderer;

window.initMap = function () {
    const defaultLoc = { lat: 14.5995, lng: 120.9842 };

    map = new google.maps.Map(document.getElementById("map"), {
        center: defaultLoc,
        zoom: 14,
    });

    marker = new google.maps.Marker({
        position: defaultLoc,
        map: map,
        draggable: true,
    });

    directionsService = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer({
        suppressMarkers: false,
    });
    directionsRenderer.setMap(map);

    detectUserLocation();
    setupAutocomplete();
    setupMapClick();
    setupInputUX();
};

function detectUserLocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition((position) => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            const loc = { lat, lng };

            map.setCenter(loc);
            marker.setPosition(loc);

            document.getElementById("pickup_lat").value = lat;
            document.getElementById("pickup_lng").value = lng;

            getAddress(lat, lng).then((address) => {
                document.getElementById("pickup").value = address;
            });
        });
    }
}

function setupAutocomplete() {
    const pickupInput = document.getElementById("pickup");
    const dropoffInput = document.getElementById("dropoff");

    if (!pickupInput || !dropoffInput) return;

    const options = {
        componentRestrictions: { country: "ph" },
        fields: ["geometry", "formatted_address", "name"],
    };

    const pickupAuto = new google.maps.places.Autocomplete(
        pickupInput,
        options,
    );
    const dropAuto = new google.maps.places.Autocomplete(dropoffInput, options);

    pickupAuto.addListener("place_changed", () => {
        const place = pickupAuto.getPlace();

        if (!place.geometry) return;

        const lat = place.geometry.location.lat();
        const lng = place.geometry.location.lng();

        document.getElementById("pickup_lat").value = lat;
        document.getElementById("pickup_lng").value = lng;

        pickupInput.value = place.formatted_address;

        marker.setPosition({ lat, lng });
        map.setCenter({ lat, lng });

        autoCalculate();
    });

    dropAuto.addListener("place_changed", () => {
        const place = dropAuto.getPlace();

        if (!place.geometry) return;

        const lat = place.geometry.location.lat();
        const lng = place.geometry.location.lng();

        document.getElementById("drop_lat").value = lat;
        document.getElementById("drop_lng").value = lng;

        dropoffInput.value = place.formatted_address;

        autoCalculate();
    });
}

function setupMapClick() {
    map.addListener("click", function (e) {
        const lat = e.latLng.lat();
        const lng = e.latLng.lng();

        marker.setPosition(e.latLng);

        document.getElementById("pickup_lat").value = lat;
        document.getElementById("pickup_lng").value = lng;

        getAddress(lat, lng).then((address) => {
            document.getElementById("pickup").value = address;
            autoCalculate();
        });
    });
}

function autoCalculate() {
    const pickupLat = document.getElementById("pickup_lat").value;
    const pickupLng = document.getElementById("pickup_lng").value;
    const dropLat = document.getElementById("drop_lat").value;
    const dropLng = document.getElementById("drop_lng").value;

    if (!pickupLat || !dropLat) return;

    const request = {
        origin: { lat: parseFloat(pickupLat), lng: parseFloat(pickupLng) },
        destination: { lat: parseFloat(dropLat), lng: parseFloat(dropLng) },
        travelMode: "DRIVING",
    };

    directionsService.route(request, (result, status) => {
        if (status === "OK") {
            directionsRenderer.setDirections(result);

            const distanceText = result.routes[0].legs[0].distance.text;
            const distanceValue =
                result.routes[0].legs[0].distance.value / 1000;

            document.getElementById("distance").innerText = distanceText;

            const price = Math.round(distanceValue * 50);
            document.getElementById("price").innerText = "₱" + price;
        }
    });
}

function getAddress(lat, lng) {
    return new Promise((resolve) => {
        const geocoder = new google.maps.Geocoder();

        geocoder.geocode({ location: { lat, lng } }, (results, status) => {
            if (status === "OK" && results[0]) {
                resolve(results[0].formatted_address);
            } else {
                resolve("Unknown location");
            }
        });
    });
}

document.getElementById("bookingForm")?.addEventListener("submit", () => {
    document.getElementById("bookingLoading")?.classList.remove("hidden");
});

function setupInputUX() {
    const pickup = document.getElementById("pickup");
    const dropoff = document.getElementById("dropoff");

    if (pickup) {
        pickup.addEventListener("focus", () => pickup.select());
    }

    if (dropoff) {
        dropoff.addEventListener("focus", () => dropoff.select());
    }
}

// OPEN MODAL
window.openConfirmModal = function () {
    const distance = document.getElementById("distance").innerText;

    if (distance === "0 km") {
        alert("Please select pickup and dropoff first");
        return;
    }

    document.getElementById("confirmModal").classList.remove("hidden");
};

window.closeConfirmModal = function () {
    document.getElementById("confirmModal").classList.add("hidden");
};

// SUBMIT BOOKING
window.submitBooking = function () {
    closeConfirmModal();

    const form = document.getElementById("bookingForm");

    const formData = new FormData(form);

    document.getElementById("statusText").innerText = "Sending Request..";

    fetch(form.action, {
        method: "POST",
        headers: {
            "X-CSRF-TOKEN": document.querySelector('input[name="_token"]')
                .value,
        },
        body: formData,
    })
        .then((res) => res.json())
        .then((data) => {
            if (data.success) {
                window.location.href = `/customer/track/${data.booking_id}`;
            } else {
                alert("Booking failed");
            }
        })
        .catch(() => {
            alert("Booking failed");
        });
};
