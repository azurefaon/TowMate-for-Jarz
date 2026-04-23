document.addEventListener('DOMContentLoaded', () => {
    // Only run on the customer booking page
    if (!document.getElementById('bookBtn')) return;

    const bookBtn = document.getElementById('bookBtn');
    const vehicleSelect = document.getElementById('vehicleType');
    const vehicleCategory = document.getElementById('vehicleCategory');
    const pickupInput = document.getElementById('pickup');
    const dropoffInput = document.getElementById('dropoff');
    const btnDebug = document.getElementById('btnDebug');

    function checkValidation() {
        const failedChecks = [
            !pickupInput?.value?.trim() && 'Pickup Text',
            !dropoffInput?.value?.trim() && 'Dropoff Text',
            !window.pickupCoords && 'Pickup Coords',
            !window.dropCoords && 'Dropoff Coords',
            !vehicleSelect?.value && 'Vehicle Selected',
            !vehicleCategory?.value && 'Category Selected',
            !(window.currentDistanceKm > 0) && 'Distance > 0',
            !(window.currentEstimateTotal > 0) && 'Total > 0',
        ].filter(Boolean);

        if (btnDebug) {
            if (failedChecks.length > 0) {
                btnDebug.textContent = `Missing: ${failedChecks.length}`;
                btnDebug.style.display = 'block';
                btnDebug.title = 'Missing: ' + failedChecks.join(', ');
            } else {
                btnDebug.style.display = 'none';
            }
        }
    }

    setInterval(checkValidation, 2000);
    setTimeout(checkValidation, 1000);
});
