document.addEventListener("DOMContentLoaded", function () {
    const toggles = document.querySelectorAll(".availability-toggle input");

    toggles.forEach((toggle) => {
        toggle.addEventListener("change", function () {
            const card = this.closest(".driver-card");
            const driverId = this.dataset.driverId;
            const isAvailable = this.checked;

            updateDriverStatus(card, isAvailable);
            updateDriverAvailability(driverId, isAvailable);
        });
    });

    startLocationUpdates();

    animateCards();
});

function updateDriverStatus(card, isAvailable) {
    const statusEl = card.querySelector(".driver-status");

    if (isAvailable) {
        statusEl.textContent = "Available";
        statusEl.className = "driver-status status-available";
    } else {
        statusEl.textContent = "Busy";
        statusEl.className = "driver-status status-busy";
    }

    statusEl.style.transform = "scale(1.05)";
    setTimeout(() => {
        statusEl.style.transform = "scale(1)";
    }, 200);
}

async function updateDriverAvailability(driverId, isAvailable) {
    if (!driverId) return;

    try {
        const response = await fetch(`/api/drivers/${driverId}/availability`, {
            method: "PATCH",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document
                    .querySelector('meta[name="csrf-token"]')
                    .getAttribute("content"),
            },
            body: JSON.stringify({ available: isAvailable }),
        });

        if (!response.ok) throw new Error();
    } catch {
        location.reload();
    }
}

function startLocationUpdates() {
    setInterval(() => {
        document.querySelectorAll(".driver-location").forEach((loc) => {
            loc.textContent = generateRandomLocation();
        });
    }, 30000);
}

function generateRandomLocation() {
    const locations = [
        "Dispatch Yard",
        "On the way",
        "Customer Location",
        "Highway",
        "Returning to base",
    ];
    return locations[Math.floor(Math.random() * locations.length)];
}

function animateCards() {
    const cards = document.querySelectorAll(".driver-card");

    cards.forEach((card, index) => {
        card.style.opacity = "0";
        card.style.transform = "translateY(20px)";

        setTimeout(() => {
            card.style.opacity = "1";
            card.style.transform = "translateY(0)";
        }, index * 120);
    });
}
