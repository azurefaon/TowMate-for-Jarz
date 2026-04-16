document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") {
        lucide.createIcons();
    }

    const searchInput = document.getElementById("unitSearch");
    const rows = Array.from(
        document.querySelectorAll("#unitsTable tr[data-name]"),
    );
    const addUnitModal = document.getElementById("addUnitModal");
    const openAddUnitModalBtn = document.getElementById("openAddUnitModalBtn");
    const closeAddUnitModalBtn = document.getElementById(
        "closeAddUnitModalBtn",
    );
    const cancelAddUnitModalBtn = document.getElementById(
        "cancelAddUnitModalBtn",
    );
    const unitStatus = document.getElementById("unitStatus");
    const issueNoteGroup = document.getElementById("issueNoteGroup");
    const availabilityToggles = Array.from(
        document.querySelectorAll(".availability-toggle"),
    );

    const filterRows = () => {
        if (!searchInput || rows.length === 0) {
            return;
        }

        const term = searchInput.value.toLowerCase().trim();

        rows.forEach((row) => {
            const haystack = [
                row.dataset.name || "",
                row.dataset.plate || "",
                row.dataset.type || "",
                row.dataset.teamleader || "",
                row.dataset.status || "",
            ].join(" ");

            row.style.display = haystack.includes(term) ? "" : "none";
        });
    };

    const syncIssueNote = () => {
        if (!unitStatus || !issueNoteGroup) {
            return;
        }

        issueNoteGroup.style.display =
            unitStatus.value === "maintenance" ? "block" : "none";
    };

    const openModal = () => {
        if (!addUnitModal) {
            return;
        }

        addUnitModal.classList.add("is-open");
        addUnitModal.setAttribute("aria-hidden", "false");
        document.body.classList.add("modal-open");
        document.getElementById("unitName")?.focus();
    };

    const closeModal = () => {
        if (!addUnitModal) {
            return;
        }

        addUnitModal.classList.remove("is-open");
        addUnitModal.setAttribute("aria-hidden", "true");
        document.body.classList.remove("modal-open");
    };

    searchInput?.addEventListener("input", filterRows);
    openAddUnitModalBtn?.addEventListener("click", openModal);
    closeAddUnitModalBtn?.addEventListener("click", closeModal);
    cancelAddUnitModalBtn?.addEventListener("click", closeModal);
    unitStatus?.addEventListener("change", syncIssueNote);
    availabilityToggles.forEach((toggle) => {
        toggle.addEventListener("change", function () {
            const form = toggle.closest("form");
            const label = form?.querySelector(".availability-switch-text");

            if (label) {
                label.textContent = toggle.checked ? "On" : "Off";
            }

            toggle.disabled = true;
            form?.submit();
        });
    });
    addUnitModal?.addEventListener("click", function (event) {
        if (event.target === addUnitModal) {
            closeModal();
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            if (document.activeElement === searchInput) {
                searchInput.value = "";
                filterRows();
                searchInput.blur();
            }

            closeModal();
        }
    });

    if (addUnitModal?.classList.contains("is-open")) {
        document.body.classList.add("modal-open");
    }

    syncIssueNote();
    filterRows();
});
