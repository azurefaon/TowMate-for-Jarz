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
    const disableTitle = document.getElementById("disableTitle");
    const disableText = document.getElementById("disableText");
    const disableSubmitBtn = document.getElementById("disableSubmitBtn");
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

            const editClassEl = document.getElementById('editClass');
            if (editClassEl) editClassEl.value = button.dataset.class || '';

            if (editForm) {
                editForm.action = `${baseUrl}/${button.dataset.id}`;
            }

            showModal(editModal);
        });
    });

    document.querySelectorAll(".js-disable-type").forEach((button) => {
        button.addEventListener("click", () => {
            const isBusy = button.dataset.busy === "1";
            const units = Number(button.dataset.unitCount || 0);
            const bookings = Number(button.dataset.bookingCount || 0);

            if (disableTitle) {
                disableTitle.textContent = isBusy
                    ? "Tow Truck Type Busy"
                    : "Disable Tow Truck Type?";
            }

            if (disableText) {
                disableText.textContent = isBusy
                    ? `"${button.dataset.name}" is currently busy and cannot be set to inactive. It is linked to ${units} unit(s) and ${bookings} active booking(s).`
                    : `You are about to disable "${button.dataset.name}". It will no longer be available for new towing unit assignments.`;
            }

            if (disableForm) {
                disableForm.action = `${baseUrl}/${button.dataset.id}/toggle`;
            }

            if (disableSubmitBtn) {
                disableSubmitBtn.hidden = isBusy;
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

        document.querySelectorAll("[data-card][data-status]").forEach((card) => {
            const searchable = (card.dataset.search || card.textContent).toLowerCase();
            const matchesSearch = searchable.includes(search);
            const matchesStatus = status === "all" || card.dataset.status === status;
            card.style.display = matchesSearch && matchesStatus ? "" : "none";
        });
    };

    searchInput?.addEventListener("input", filterRows);
    statusFilter?.addEventListener("change", filterRows);

    // Auto-hide alerts after 3 seconds
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    if (successAlert) {
        setTimeout(() => {
            successAlert.classList.add('fade-out');
            setTimeout(() => successAlert.remove(), 300);
        }, 3000);
    }
    
    if (errorAlert) {
        setTimeout(() => {
            errorAlert.classList.add('fade-out');
            setTimeout(() => errorAlert.remove(), 300);
        }, 3000);
    }

    // Delete truck type functionality
    document.querySelectorAll('.js-delete-type').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const name = this.dataset.name;
            const units = parseInt(this.dataset.units || 0);
            const bookings = parseInt(this.dataset.bookings || 0);
            
            const deleteModal = document.getElementById('deleteModal');
            const deleteTitle = document.getElementById('deleteTitle');
            const deleteText = document.getElementById('deleteText');
            const deleteForm = document.getElementById('deleteForm');
            const deleteSubmitBtn = document.getElementById('deleteSubmitBtn');
            
            // Check if truck type can be deleted
            if (units > 0 || bookings > 0) {
                deleteTitle.textContent = 'Cannot Delete Truck Type';
                deleteText.textContent = `"${name}" cannot be deleted because it has ${units} unit(s) and ${bookings} booking(s) associated with it. Please reassign or remove them first.`;
                deleteSubmitBtn.disabled = true;
                deleteSubmitBtn.style.opacity = '0.5';
                deleteSubmitBtn.style.cursor = 'not-allowed';
            } else {
                deleteTitle.textContent = 'Delete Truck Type?';
                deleteText.textContent = `Are you sure you want to delete "${name}"? This action cannot be undone.`;
                deleteSubmitBtn.disabled = false;
                deleteSubmitBtn.style.opacity = '1';
                deleteSubmitBtn.style.cursor = 'pointer';
            }
            
            deleteForm.action = `${baseUrl}/${id}`;
            showModal(deleteModal);
        });
    });

    // Close delete modal
    document.querySelectorAll('[data-close-modal="deleteModal"]').forEach(btn => {
        btn.addEventListener('click', function() {
            hideModal(document.getElementById('deleteModal'));
        });
    });

    // Close delete modal on backdrop click
    const deleteModal = document.getElementById('deleteModal');
    if (deleteModal) {
        deleteModal.addEventListener('click', function(e) {
            if (e.target === this) {
                hideModal(this);
            }
        });
    }
});
