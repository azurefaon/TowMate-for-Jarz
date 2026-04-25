document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") {
        lucide.createIcons();
    }

    const searchInput = document.getElementById("unitSearch");
    const cards = Array.from(document.querySelectorAll(".unit-card"));

    const filterCards = () => {
        if (!searchInput) return;
        const term = searchInput.value.toLowerCase().trim();
        cards.forEach((card) => {
            const haystack = [
                card.dataset.name || "",
                card.dataset.plate || "",
                card.dataset.type || "",
                card.dataset.status || "",
                card.dataset.tl || "",
                card.dataset.driver || "",
            ].join(" ");
            card.style.display = haystack.includes(term) ? "" : "none";
        });
    };

    searchInput?.addEventListener("input", filterCards);

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
            if (document.activeElement === searchInput) {
                searchInput.value = "";
                filterCards();
                searchInput.blur();
            } else {
                closeModal();
            }
        }
    });

    const modal = document.getElementById("unitDetailModal");
    const udTitle = document.getElementById("udTitle");
    const udSubtitle = document.getElementById("udSubtitle");
    const udPlate = document.getElementById("udPlate");
    const udType = document.getElementById("udType");
    const udStatusBadge = document.getElementById("udStatusBadge");
    const udTlCard = document.getElementById("udTlCard");
    const udTlName = document.getElementById("udTlName");
    const udTlRole = document.getElementById("udTlRole");
    const udTlEmail = document.getElementById("udTlEmail");
    const udTlPhone = document.getElementById("udTlPhone");
    const udDriverCard = document.getElementById("udDriverCard");
    const udDriverName = document.getElementById("udDriverName");
    const udDriverRole = document.getElementById("udDriverRole");
    const udDriverEmail = document.getElementById("udDriverEmail");
    const udDriverPhone = document.getElementById("udDriverPhone");
    const udOverrideSection = document.getElementById("udOverrideSection");
    const udOverrideReason = document.getElementById("udOverrideReason");
    const udSaveBtn = document.getElementById("udSaveBtn");
    const udCloseBtn = document.getElementById("udCloseBtn");
    const udClose2 = document.getElementById("udClose2");

    let currentUnitId = null;

    const openModal = (card) => {
        if (!modal || !card) return;

        currentUnitId = card.dataset.modalId;
        const status = card.dataset.modalEffStatus || "";

        if (udTitle) udTitle.textContent = card.dataset.modalName || "";
        if (udSubtitle)
            udSubtitle.textContent = card.dataset.modalType || "Asset";
        if (udPlate) udPlate.textContent = card.dataset.modalPlate || "—";
        if (udType) udType.textContent = card.dataset.modalType || "—";

        let statusClass = "maintenance";
        if (status === "available") statusClass = "available";
        else if (status === "on_job") statusClass = "on-job";
        else if (status === "not_avail") statusClass = "maintenance";

        if (udStatusBadge) {
            udStatusBadge.className = "ud-status-pill " + statusClass;
            udStatusBadge.textContent =
                card.dataset.modalStatusLabel || status || "Unknown";
        }

        const tlName = card.dataset.modalTlName || "Unassigned";
        if (udTlName) udTlName.textContent = tlName;
        if (udTlRole) udTlRole.textContent = card.dataset.modalTlRole || "";
        if (udTlEmail) udTlEmail.textContent = card.dataset.modalTlEmail || "—";
        if (udTlPhone) udTlPhone.textContent = card.dataset.modalTlPhone || "—";

        const drvName = card.dataset.modalDriverName || "No driver assigned";
        if (udDriverName) udDriverName.textContent = drvName;
        if (udDriverRole)
            udDriverRole.textContent = card.dataset.modalDriverRole || "";
        if (udDriverEmail)
            udDriverEmail.textContent = card.dataset.modalDriverEmail || "—";
        if (udDriverPhone)
            udDriverPhone.textContent = card.dataset.modalDriverPhone || "—";

        if (udOverrideSection) {
            udOverrideSection.style.display =
                status === "available" ? "" : "none";
        }

        if (udOverrideReason) udOverrideReason.value = "";

        resetSaveBtn?.();

        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        document.body.classList.add("modal-open");
    };

    const closeModal = () => {
        if (!modal) return;
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        document.body.classList.remove("modal-open");
        currentUnitId = null;
    };

    const resetSaveBtn = () => {
        if (!udSaveBtn) return;
        udSaveBtn.disabled = false;
        udSaveBtn.innerHTML = '<i data-lucide="save"></i> Save Override';
        if (typeof lucide !== "undefined") lucide.createIcons();
    };

    cards.forEach((card) =>
        card.addEventListener("click", () => openModal(card)),
    );
    udCloseBtn?.addEventListener("click", closeModal);
    udClose2?.addEventListener("click", closeModal);
    modal?.addEventListener("click", (e) => {
        if (e.target === modal) closeModal();
    });

    udSaveBtn?.addEventListener("click", async () => {
        if (!currentUnitId) return;

        udSaveBtn.disabled = true;
        udSaveBtn.textContent = "Saving...";

        const reason =
            udOverrideReason?.value.trim() || "Status overridden by dispatcher";
        const csrf =
            document.querySelector('meta[name="csrf-token"]')?.content || "";

        try {
            const res = await fetch(
                `/admin-dashboard/units/${currentUnitId}/maintenance`,
                {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrf,
                        Accept: "application/json",
                    },
                    body: JSON.stringify({ reason }),
                },
            );

            const data = await res.json();

            if (data.success) {
                const card = document.querySelector(
                    `.unit-fleet-card[data-modal-id="${currentUnitId}"]`,
                );
                if (card) {
                    card.dataset.status = "not available";
                    card.dataset.modalStatus = "maintenance";
                    card.dataset.modalStatusLabel = "Not Available";

                    const badge = card.querySelector(".unit-status-badge");
                    if (badge) {
                        badge.className = "unit-status-badge maintenance";
                        badge.textContent = "Not Available";
                    }
                    const tag = card.querySelector(".unit-tag");
                    if (tag) {
                        tag.className = "unit-tag maintenance";
                    }
                }
                closeModal();
            } else {
                alert(data.message || "Override failed. Please try again.");
                resetSaveBtn();
            }
        } catch {
            alert("Network error. Please try again.");
            resetSaveBtn();
        }
    });
});
