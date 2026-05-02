document.addEventListener("DOMContentLoaded", () => {
    const page = document.querySelector(".units-page");

    if (!page) {
        return;
    }

    const baseUrl = page.dataset.baseUrl;
    const addModal = document.getElementById("addUnitModal");
    const editModal = document.getElementById("editUnitModal");
    const editForm = document.getElementById("editUnitForm");
    const searchInput = document.getElementById("unitSearch");
    const statusFilter = document.getElementById("statusFilter");
    const addStatus = document.getElementById("addUnitStatus");
    const editStatus = document.getElementById("editStatus");
    const addIssueWrapper = document.getElementById("addIssueWrapper");
    const editIssueWrapper = document.getElementById("editIssueWrapper");

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

    const syncIssueVisibility = (selectElement, wrapperElement) => {
        if (!selectElement || !wrapperElement) {
            return;
        }

        wrapperElement.style.display =
            selectElement.value === "maintenance" ? "block" : "none";
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

    document.addEventListener("click", (e) => {
        const button = e.target.closest(".js-edit-unit");

        if (!button) return;

        document.getElementById("editName").value = button.dataset.name || "";
        document.getElementById("editPlate").value = button.dataset.plate || "";
        document.getElementById("editStatus").value =
            button.dataset.status || "available";
        document.getElementById("editIssue").value = button.dataset.issue || "";
        document.getElementById("editTruckType").value =
            button.dataset.truck || "";

        document.getElementById("editLeaderId").value =
            button.dataset.leaderId || "";
        document.getElementById("editDriverId").value =
            button.dataset.driverId || "";

        if (editForm) {
            editForm.action = `${baseUrl}/${button.dataset.id}`;
        }

        syncIssueVisibility(editStatus, editIssueWrapper);
        showModal(editModal);
    });

    [addModal, editModal].forEach((modal) => {
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
        }
    });

    addStatus?.addEventListener("change", () =>
        syncIssueVisibility(addStatus, addIssueWrapper),
    );
    editStatus?.addEventListener("change", () =>
        syncIssueVisibility(editStatus, editIssueWrapper),
    );

    syncIssueVisibility(addStatus, addIssueWrapper);
    syncIssueVisibility(editStatus, editIssueWrapper);

    const filterUnits = () => {
        const search = (searchInput?.value || "").trim().toLowerCase();
        const status = statusFilter?.value || "all";

        document
            .querySelectorAll("#unitsTable tr[data-status]")
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

    searchInput?.addEventListener("input", filterUnits);
    statusFilter?.addEventListener("change", filterUnits);
});
