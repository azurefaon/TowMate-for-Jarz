document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") {
        lucide.createIcons();
    }

    const modal = document.getElementById("jobModal");
    const overlay = modal?.querySelector(".modal-overlay");
    const closeButtons =
        modal?.querySelectorAll(".close-modal, .close-modal-btn") ?? [];

    const fillField = (id, value) => {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value || "—";
        }
    };

    const openModal = (card) => {
        if (!modal || !card) {
            return;
        }

        fillField("modalTitle", `Job #${card.dataset.jobId}`);
        fillField("job-customer", card.dataset.customer);
        fillField("job-service", card.dataset.service);
        fillField("job-status", card.dataset.status);
        fillField("job-unit", card.dataset.unit);
        fillField("job-teamleader", card.dataset.teamleader);
        fillField("job-driver", card.dataset.driver);
        fillField("job-pickup", card.dataset.pickup);
        fillField("job-dropoff", card.dataset.dropoff);
        fillField("job-time", card.dataset.created);

        modal.classList.add("active");
        document.body.style.overflow = "hidden";
    };

    const closeModal = () => {
        if (!modal) {
            return;
        }

        modal.classList.remove("active");
        document.body.style.overflow = "";
    };

    document.querySelectorAll(".js-open-job-modal").forEach((button) => {
        button.addEventListener("click", function () {
            openModal(this.closest(".job-card"));
        });
    });

    overlay?.addEventListener("click", closeModal);
    closeButtons.forEach((button) =>
        button.addEventListener("click", closeModal),
    );

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modal?.classList.contains("active")) {
            closeModal();
        }
    });
});
