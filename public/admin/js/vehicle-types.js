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

    document.getElementById("vcAddBtn")?.addEventListener("click", () => {
        showModal(document.getElementById("addModal"));
    });

    const wireAccordionExclusivity = (root) => {
        (root || document).querySelectorAll("details[data-accordion-group]").forEach((details) => {
            if (details.dataset.accordionWired) return;
            details.dataset.accordionWired = "1";

            details.addEventListener("toggle", () => {
                if (!details.open) return;
                const group = details.dataset.accordionGroup;
                document.querySelectorAll(`details[data-accordion-group="${group}"]`).forEach((other) => {
                    if (other !== details) other.open = false;
                });
            });
        });
    };

    wireAccordionExclusivity(document.getElementById("vcGroups"));

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
        document.getElementById("editVcRequiredTruckType").value = trigger.dataset.requiredTruckTypeId || "";

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

    const categoriesUrl = page.dataset.categoriesUrl;
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const arrangeCategoryList = document.getElementById("arrangeCategoryList");

    document.getElementById("vcCategoriesBtn")?.addEventListener("click", () => {
        const feedback = document.getElementById("arrangeCategoryFeedback");
        if (feedback) feedback.textContent = "";

        showModal(document.getElementById("categoriesModal"));
    });

    const vcGroups = document.getElementById("vcGroups");
    const orderBar = document.getElementById("vcOrderBar");
    const editOrderBtn = document.getElementById("vcEditOrderBtn");
    const orderActions = document.getElementById("vcOrderActions");
    const filterControls = ["vcSearch", "vcCategoryFilter", "vcStatusFilter"].map((id) => document.getElementById(id));
    const toolbarButtons = ["vcCategoriesBtn", "vcAddBtn"].map((id) => document.getElementById(id));

    const wireVehicleDragHandlers = () => {
        document.querySelectorAll(".vc-vehicle-list").forEach((list) => {
            list.querySelectorAll('.vc-vehicle-row[data-status="active"]').forEach((row) => {
                if (row.dataset.dragWired) return;
                row.dataset.dragWired = "1";
                row.draggable = true;

                row.addEventListener("dragstart", () => {
                    row.classList.add("is-dragging");
                });

                row.addEventListener("dragend", () => {
                    row.classList.remove("is-dragging");
                });
            });

            if (list.dataset.dragoverWired) return;
            list.dataset.dragoverWired = "1";

            list.addEventListener("dragover", (event) => {
                event.preventDefault();
                const dragging = list.querySelector(".is-dragging");
                if (!dragging || dragging.parentElement !== list) return;

                const afterElement = [...list.querySelectorAll('.vc-vehicle-row[data-status="active"]:not(.is-dragging)')].find((sibling) => {
                    const rect = sibling.getBoundingClientRect();
                    return event.clientY < rect.top + rect.height / 2;
                });

                if (afterElement) {
                    list.insertBefore(dragging, afterElement);
                } else {
                    const inactiveRows = list.querySelectorAll('.vc-vehicle-row:not([data-status="active"])');
                    if (inactiveRows.length) {
                        list.insertBefore(dragging, inactiveRows[0]);
                    } else {
                        list.appendChild(dragging);
                    }
                }
            });
        });
    };

    const setEditOrderMode = (active) => {
        vcGroups?.classList.toggle("vc-editing-order", active);
        if (orderBar) orderBar.classList.toggle("is-open", active);
        if (editOrderBtn) editOrderBtn.style.display = active ? "none" : "";
        if (orderActions) orderActions.classList.toggle("is-open", active);
        filterControls.forEach((control) => {
            if (control) control.disabled = active;
        });
        toolbarButtons.forEach((button) => {
            if (button) button.disabled = active;
        });
    };

    const enterEditOrderMode = () => {
        wireVehicleDragHandlers();
        const feedback = document.getElementById("vcOrderFeedback");
        if (feedback) feedback.textContent = "";
        setEditOrderMode(true);
    };

    editOrderBtn?.addEventListener("click", () => {
        const params = new URLSearchParams(window.location.search);
        if (params.has("search") || params.has("category") || params.has("status")) {
            window.location.href = `${baseUrl}?edit_order=1`;
            return;
        }
        enterEditOrderMode();
    });

    document.getElementById("vcOrderCancelBtn")?.addEventListener("click", () => {
        window.location.reload();
    });

    document.getElementById("vcOrderSaveBtn")?.addEventListener("click", () => {
        const groups = [...document.querySelectorAll(".vc-vehicle-list")]
            .map((list) => ({
                required_truck_type_id: parseInt(list.dataset.truckTypeId, 10),
                category: list.dataset.category,
                vehicle_ids: [...list.querySelectorAll('.vc-vehicle-row[data-status="active"]')].map((row) => parseInt(row.dataset.id, 10)),
            }))
            .filter((group) => group.vehicle_ids.length > 0);

        const feedback = document.getElementById("vcOrderFeedback");
        if (feedback) feedback.textContent = "Saving...";

        fetch(`${baseUrl}/reorder`, {
            method: "PATCH",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": csrfToken(),
            },
            body: JSON.stringify({ groups }),
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    const message = data?.errors?.groups?.[0] || data?.message || "Could not save the new order.";
                    if (feedback) feedback.textContent = message;
                    return;
                }
                window.location.reload();
            })
            .catch(() => {
                if (feedback) feedback.textContent = "Could not save the new order.";
            });
    });

    if (new URLSearchParams(window.location.search).get("edit_order") === "1") {
        enterEditOrderMode();
        window.history.replaceState(null, "", baseUrl);
    }

    document.getElementById("arrangeAddCategoryBtn")?.addEventListener("click", () => {
        const input = document.getElementById("arrangeNewCategoryName");
        const feedback = document.getElementById("arrangeCategoryFeedback");
        const name = input?.value.trim();

        if (!name) {
            if (feedback) feedback.textContent = "Enter a category name.";
            return;
        }

        fetch(categoriesUrl, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": csrfToken(),
            },
            body: JSON.stringify({ name }),
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    const message = data?.errors?.name?.[0] || data?.message || "Could not add the category.";
                    if (feedback) feedback.textContent = message;
                    return;
                }

                const category = data.category;
                if (feedback) feedback.textContent = `Added "${category.name}".`;
                if (input) input.value = "";

                const row = document.createElement("div");
                row.className = "vc-arrange-category-row";
                row.dataset.categoryId = category.id;

                const nameInput = document.createElement("input");
                nameInput.type = "text";
                nameInput.className = "vc-arrange-category-input";
                nameInput.value = category.name;

                const saveButton = document.createElement("button");
                saveButton.type = "button";
                saveButton.className = "vc-arrange-category-save";
                saveButton.dataset.id = category.id;
                saveButton.textContent = "Save";

                row.appendChild(nameInput);
                row.appendChild(saveButton);
                arrangeCategoryList?.appendChild(row);
                wireCategorySave(saveButton);

                ["addVcCategory", "editVcCategory", "vcCategoryFilter"].forEach((selectId) => {
                    const select = document.getElementById(selectId);
                    if (!select) return;
                    const option = document.createElement("option");
                    option.value = category.slug;
                    option.textContent = category.name;
                    select.appendChild(option);
                });
            })
            .catch(() => {
                if (feedback) feedback.textContent = "Could not add the category.";
            });
    });

    function wireCategorySave(button) {
        button?.addEventListener("click", () => {
            const row = button.closest(".vc-arrange-category-row");
            const input = row?.querySelector(".vc-arrange-category-input");
            const feedback = document.getElementById("arrangeCategoryFeedback");
            const name = input?.value.trim();
            const id = button.dataset.id;

            if (!name) {
                if (feedback) feedback.textContent = "Enter a category name.";
                return;
            }

            fetch(`${categoriesUrl}/${id}`, {
                method: "PUT",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": csrfToken(),
                },
                body: JSON.stringify({ name }),
            })
                .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        const message = data?.errors?.name?.[0] || data?.message || "Could not rename the category.";
                        if (feedback) feedback.textContent = message;
                        return;
                    }

                    const category = data.category;
                    if (feedback) feedback.textContent = `Renamed to "${category.name}".`;

                    document.querySelectorAll(`option[value="${category.slug}"]`).forEach((option) => {
                        option.textContent = category.name;
                    });

                    document.querySelectorAll(".vc-group-category > summary").forEach((summary) => {
                        if (summary.dataset.slug === category.slug) {
                            const nameSpan = summary.querySelector("span:first-child");
                            if (nameSpan) nameSpan.textContent = category.name;
                        }
                    });
                })
                .catch(() => {
                    if (feedback) feedback.textContent = "Could not rename the category.";
                });
        });
    }

    document.querySelectorAll(".vc-arrange-category-save").forEach(wireCategorySave);

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
