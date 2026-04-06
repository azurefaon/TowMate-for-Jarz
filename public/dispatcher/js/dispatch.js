document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".btn-assign").forEach((btn) => {
        btn.addEventListener("click", function () {
            const bookingId = this.dataset.id;

            console.log("Assign booking:", bookingId);

            // open assign modal
        });
    });
});
