document.addEventListener("DOMContentLoaded", () => {
    const page = document.querySelector(".units-page");

    if (!page) {
        return;
    }

    const baseUrl = page.dataset.baseUrl;
    const addModal = document.getElementById("addUnitModal");
    const editModal = document.getElementById("editUnitModal");
    const editForm = document.getElementById("editUnitForm");
    const assignLeaderModal = document.getElementById("assignLeaderModal");
    const assignLeaderForm = document.getElementById("assignLeaderForm");
    const assignLeaderUnitName = document.getElementById("assignLeaderUnitName");
    const assignLeaderSelect = document.getElementById("assignLeaderSelect");
    const searchInput = document.getElementById("unitSearch");
    const statusFilter = document.getElementById("statusFilter");

    // 3 letters + 4 digits, 3 letters + 3 digits, or 2 letters + 5 digits; I and O are excluded (confusable with 1/0).
    const PLATE_NUMBER_REGEX = /^(?:[A-HJ-NP-Z]{3}[0-9]{4}|[A-HJ-NP-Z]{3}[0-9]{3}|[A-HJ-NP-Z]{2}[0-9]{5})$/;
    const PLATE_NUMBER_MESSAGE =
        "Enter 3 letters + 4 digits, 3 letters + 3 digits, or 2 letters + 5 digits. Letters I and O are not allowed.";

    const sanitizePlateInput = (value) =>
        value
            .toUpperCase()
            .replace(/[^A-Z0-9 ]/g, "")
            .replace(/[IO]/g, "")
            .replace(/ {2,}/g, " ");

    const validatePlateInput = (input) => {
        const normalized = input.value.replace(/\s+/g, "");
        input.setCustomValidity(
            normalized && !PLATE_NUMBER_REGEX.test(normalized) ? PLATE_NUMBER_MESSAGE : ""
        );
    };

    ["addPlate", "editPlate"].forEach((id) => {
        const input = document.getElementById(id);
        if (!input) {
            return;
        }

        input.addEventListener("input", () => {
            const cursorFromEnd = input.value.length - input.selectionStart;
            input.value = sanitizePlateInput(input.value);
            const nextPosition = Math.max(input.value.length - cursorFromEnd, 0);
            input.setSelectionRange(nextPosition, nextPosition);
            validatePlateInput(input);
        });
    });

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

    document.addEventListener("click", (e) => {
        const button = e.target.closest(".js-edit-unit");

        if (!button) return;

        const editPlateInput = document.getElementById("editPlate");
        editPlateInput.value = sanitizePlateInput(button.dataset.plate || "");
        validatePlateInput(editPlateInput);
        document.getElementById("editTruckType").value =
            button.dataset.truck || "";

        if (editForm) {
            editForm.action = `${baseUrl}/${button.dataset.id}`;
        }

        showModal(editModal);
    });

    document.addEventListener("click", (e) => {
        const button = e.target.closest(".js-assign-leader");

        if (!button) return;

        if (assignLeaderForm) {
            assignLeaderForm.action = `${baseUrl}/${button.dataset.unitId}/assign-team-leader`;
        }
        if (assignLeaderUnitName) {
            assignLeaderUnitName.textContent = `Unit: ${button.dataset.unitName || ""}`;
        }
        if (assignLeaderSelect) {
            assignLeaderSelect.value = "";
        }
        const assignLeaderPreview = document.getElementById("assignLeaderPreview");
        if (assignLeaderPreview) {
            assignLeaderPreview.textContent = "";
        }

        showModal(assignLeaderModal);
    });

    [addModal, editModal, assignLeaderModal].forEach((modal) => {
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
            hideModal(assignLeaderModal);
        }
    });

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
