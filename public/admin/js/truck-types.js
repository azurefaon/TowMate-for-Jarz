document.addEventListener("DOMContentLoaded", () => {
    const page = document.querySelector(".truck-types-page");

    if (!page) {
        return;
    }

    const baseUrl = page.dataset.baseUrl;
    const addModal = document.getElementById("addModal");
    const editModal = document.getElementById("editModal");
    const disableModal = document.getElementById("disableModal");
    const editForm = document.getElementById("editForm");
    const disableForm = document.getElementById("disableForm");
    const disableText = document.getElementById("disableText");
    const searchInput = document.getElementById("truckTypeSearch");
    const statusFilter = document.getElementById("truckTypeStatusFilter");

    const showModal = (modal) => {
        if (modal) {
            modal.style.display = "flex";
        }
    };

    const hideModal = (modal) => {
        if (modal) {
            modal.style.display = "none";
        }
    };

    if (window.lucide) {
        window.lucide.createIcons();
    }

    document.querySelectorAll("[data-open-modal]").forEach((button) => {
        button.addEventListener("click", () => {
            showModal(document.getElementById(button.dataset.openModal));
        });
    });

    document.querySelectorAll("[data-close-modal]").forEach((button) => {
        button.addEventListener("click", () => {
            hideModal(document.getElementById(button.dataset.closeModal));
        });
    });

    document.querySelectorAll(".js-edit-type").forEach((button) => {
        button.addEventListener("click", () => {
            document.getElementById("editName").value =
                button.dataset.name || "";
            document.getElementById("editBase").value =
                button.dataset.base || "";
            document.getElementById("editKm").value = button.dataset.km || "";
            document.getElementById("editTonnage").value =
                button.dataset.tonnage || "";
            document.getElementById("editDescription").value =
                button.dataset.description || "";

            if (editForm) {
                editForm.action = `${baseUrl}/${button.dataset.id}`;
            }

            showModal(editModal);
        });
    });

    document.querySelectorAll(".js-disable-type").forEach((button) => {
        button.addEventListener("click", () => {
            if (disableText) {
                disableText.textContent = `You are about to disable "${button.dataset.name}". It will no longer be available for new towing unit assignments.`;
            }

            if (disableForm) {
                disableForm.action = `${baseUrl}/${button.dataset.id}/toggle`;
            }

            showModal(disableModal);
        });
    });

    [addModal, editModal, disableModal].forEach((modal) => {
        if (!modal) {
            return;
        }

        modal.addEventListener("click", (event) => {
            if (event.target === modal) {
                hideModal(modal);
            }
        });
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            hideModal(addModal);
            hideModal(editModal);
            hideModal(disableModal);
        }
    });

    const filterRows = () => {
        const search = (searchInput?.value || "").trim().toLowerCase();
        const status = statusFilter?.value || "all";

        document
            .querySelectorAll("#truckTypesTable tr[data-status]")
            .forEach((row) => {
                const matchesSearch = row.textContent
                    .toLowerCase()
                    .includes(search);
                const matchesStatus =
                    status === "all" || row.dataset.status === status;
                row.style.display =
                    matchesSearch && matchesStatus ? "" : "none";
            });
    };

    searchInput?.addEventListener("input", filterRows);
    statusFilter?.addEventListener("change", filterRows);
});
