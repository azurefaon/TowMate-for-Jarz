document.addEventListener("DOMContentLoaded", () => {
    const page = document.querySelector(".vc-page");

    if (!page) {
        return;
    }

    if (window.lucide) {
        window.lucide.createIcons();
    }

    const baseUrl = page.dataset.baseUrl;

    const refreshCsrf = () =>
        fetch("/superadmin/csrf-token", {
            headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" },
        })
            .then((res) => res.json())
            .then((data) => {
                document
                    .querySelectorAll('input[name="_token"]')
                    .forEach((input) => (input.value = data.token));

                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.setAttribute("content", data.token);
            })
            .catch(() => {});

    document.querySelectorAll(".vc-modal-form").forEach((form) => {
        form.addEventListener("submit", function (event) {
            event.preventDefault();

            refreshCsrf().finally(() => {
                HTMLFormElement.prototype.submit.call(this);
            });
        });
    });

    const showModal = (modal) => modal?.classList.add("is-open");
    const hideModal = (modal) => modal?.classList.remove("is-open");

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

    document.querySelectorAll(".vc-modal").forEach((modal) => {
        modal.addEventListener("click", (event) => {
            if (event.target === modal) {
                hideModal(modal);
            }
        });
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            document.querySelectorAll(".vc-modal.is-open").forEach(hideModal);
        }
    });

    const isClassCompatible = (truckClass, weightKg) => {
        if (weightKg === null || Number.isNaN(weightKg) || !truckClass) {
            return true;
        }

        switch (truckClass) {
            case "light":
                return weightKg <= 4500;
            case "medium":
                return weightKg > 4500 && weightKg <= 7500;
            case "heavy":
                return weightKg > 7500;
            default:
                return true;
        }
    };

    const applyCompatibility = (gridId, weightInputId) => {
        const grid = document.getElementById(gridId);
        const weightInput = document.getElementById(weightInputId);
        if (!grid || !weightInput) return;

        const weightKg = weightInput.value === "" ? null : parseFloat(weightInput.value);

        grid.querySelectorAll(".vc-truck-check").forEach((label) => {
            const checkbox = label.querySelector(".vc-truck-check-input");
            const compatible = isClassCompatible(label.dataset.class, weightKg);

            label.classList.toggle("is-disabled", !compatible);

            if (checkbox) {
                checkbox.disabled = !compatible;
                if (!compatible) {
                    checkbox.checked = false;
                }
            }
        });
    };

    const wireClassAutofill = (gridId, weightInputId) => {
        const grid = document.getElementById(gridId);
        if (!grid) return;

        grid.querySelectorAll(".vc-truck-check-input").forEach((checkbox) => {
            checkbox.addEventListener("change", () => {
                const weightInput = document.getElementById(weightInputId);
                if (!weightInput) return;

                if (checkbox.checked) {
                    const capacity = checkbox.dataset.capacity;
                    if (capacity) {
                        weightInput.value = capacity;
                        applyCompatibility(gridId, weightInputId);
                    }
                    return;
                }

                const stillChecked = grid.querySelector(".vc-truck-check-input:checked");
                if (!stillChecked) {
                    weightInput.value = "";
                    applyCompatibility(gridId, weightInputId);
                }
            });
        });
    };

    document.getElementById("addVcWeight")?.addEventListener("input", () =>
        applyCompatibility("addVcTrucks", "addVcWeight")
    );
    document.getElementById("editVcWeight")?.addEventListener("input", () =>
        applyCompatibility("editVcTrucks", "editVcWeight")
    );

    wireClassAutofill("addVcTrucks", "addVcWeight");
    wireClassAutofill("editVcTrucks", "editVcWeight");

    document.getElementById("vcAddBtn")?.addEventListener("click", () => {
        applyCompatibility("addVcTrucks", "addVcWeight");
        showModal(document.getElementById("addModal"));
    });

    document.addEventListener("click", (e) => {
        const trigger = e.target.closest(".js-vc-edit");
        if (!trigger) return;

        const editForm = document.getElementById("editVcForm");
        if (editForm) {
            editForm.action = `${baseUrl}/${trigger.dataset.id}`;
        }

        document.getElementById("editVcName").value = trigger.dataset.name || "";
        document.getElementById("editVcCategory").value = trigger.dataset.category || "";
        document.getElementById("editVcWeight").value = trigger.dataset.weight || "";
        document.getElementById("editVcDescription").value = trigger.dataset.description || "";

        const truckIds = (trigger.dataset.truckIds || "")
            .split(",")
            .filter(Boolean)
            .map((id) => parseInt(id, 10));

        applyCompatibility("editVcTrucks", "editVcWeight");

        document.querySelectorAll("#editVcTrucks .vc-truck-check-input").forEach((checkbox) => {
            if (!checkbox.disabled) {
                checkbox.checked = truckIds.includes(parseInt(checkbox.value, 10));
            }
        });

        showModal(document.getElementById("editModal"));
    });

    document.addEventListener("click", (e) => {
        const trigger = e.target.closest(".js-vc-delete");
        if (!trigger) return;

        const deleteForm = document.getElementById("deleteVcForm");
        if (deleteForm) {
            deleteForm.action = `${baseUrl}/${trigger.dataset.id}`;
        }

        const textEl = document.getElementById("deleteVcText");
        if (textEl) {
            textEl.textContent = `Delete "${trigger.dataset.name}"? This permanently removes it and cannot be undone.`;
        }

        showModal(document.getElementById("deleteModal"));
    });

    const filterForm = document.getElementById("vcFilterForm");
    const searchInput = document.getElementById("vcSearch");
    let searchTimer = null;

    searchInput?.addEventListener("input", () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => filterForm?.requestSubmit(), 450);
    });

    document.getElementById("vcCategoryFilter")?.addEventListener("change", () => {
        filterForm?.requestSubmit();
    });

    document.getElementById("vcStatusFilter")?.addEventListener("change", () => {
        filterForm?.requestSubmit();
    });

    const closeAllVcMenus = (except) => {
        document.querySelectorAll(".u-menu.is-open").forEach((menu) => {
            if (menu === except) {
                return;
            }

            menu.classList.remove("is-open");
            menu.querySelector(".u-menu-trigger")?.setAttribute("aria-expanded", "false");
        });
    };

    const positionVcMenu = (trigger, dropdown) => {
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

            closeAllVcMenus(willOpen ? menu : null);

            if (willOpen) {
                positionVcMenu(trigger, dropdown);
            }

            menu.classList.toggle("is-open", willOpen);
            trigger.setAttribute("aria-expanded", String(willOpen));
        });
    });

    document.addEventListener("click", () => closeAllVcMenus());

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            closeAllVcMenus();
        }
    });

    document.addEventListener("scroll", () => closeAllVcMenus(), true);
});
