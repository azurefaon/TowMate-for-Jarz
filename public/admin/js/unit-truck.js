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

    const closeAllUnitMenus = (except) => {
        document.querySelectorAll(".u-menu.is-open").forEach((menu) => {
            if (menu === except) {
                return;
            }

            menu.classList.remove("is-open");
            menu.querySelector(".u-menu-trigger")?.setAttribute("aria-expanded", "false");
        });
    };

    const positionUnitMenu = (trigger, dropdown) => {
        const rect = trigger.getBoundingClientRect();
        const dropdownWidth = dropdown.offsetWidth || 200;
        const dropdownHeight = dropdown.offsetHeight || 160;

        let left = rect.right - dropdownWidth;
        left = Math.max(8, Math.min(left, window.innerWidth - dropdownWidth - 8));

        let top = rect.bottom + 4;
        if (top + dropdownHeight > window.innerHeight) {
            top = rect.top - dropdownHeight - 4;
        }
        top = Math.max(8, top);

        dropdown.style.left = `${left}px`;
        dropdown.style.top = `${top}px`;
    };

    document.querySelectorAll(".u-menu-trigger").forEach((trigger) => {
        trigger.addEventListener("click", (event) => {
            event.stopPropagation();

            const menu = trigger.closest(".u-menu");
            const dropdown = menu?.querySelector(".u-menu-dropdown");
            if (!menu || !dropdown) {
                return;
            }

            const willOpen = !menu.classList.contains("is-open");

            closeAllUnitMenus(willOpen ? menu : null);

            if (willOpen) {
                positionUnitMenu(trigger, dropdown);
            }

            menu.classList.toggle("is-open", willOpen);
            trigger.setAttribute("aria-expanded", String(willOpen));
        });
    });

    document.addEventListener("click", () => closeAllUnitMenus());

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            closeAllUnitMenus();
        }
    });

    document.addEventListener("scroll", () => closeAllUnitMenus(), true);
});
