document.addEventListener('DOMContentLoaded', function () {
    const page = document.getElementById('ppPage');
    if (!page) {
        return;
    }

    if (window.lucide) {
        window.lucide.createIcons();
    }

    const indexUrl = page.dataset.indexUrl;
    const storeUrl = page.dataset.storeUrl;
    const filterForm = document.getElementById('ppFilterForm');
    const searchInput = document.getElementById('ppSearchInput');
    const roleFilter = document.getElementById('ppRoleFilter');
    const tableCard = document.getElementById('ppTableCard');
    const tableBody = document.getElementById('ppTableBody');
    const tableFooter = document.getElementById('ppTableFooter');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    const alertEl = document.getElementById('personnelSuccessAlert');
    const sessionAlert = document.getElementById('personnelSessionAlert');
    let alertTimer;

    if (sessionAlert) {
        setTimeout(() => {
            sessionAlert.classList.add('fade-out');
            setTimeout(() => sessionAlert.remove(), 300);
        }, 3500);
    }

    function showAlert(message, isError) {
        if (!alertEl) return;
        alertEl.textContent = message;
        alertEl.classList.remove('type-feedback--success', 'type-feedback--error', 'fade-out');
        alertEl.classList.add(isError ? 'type-feedback--error' : 'type-feedback--success');
        alertEl.style.display = 'block';
        clearTimeout(alertTimer);
        alertTimer = setTimeout(() => {
            alertEl.classList.add('fade-out');
            setTimeout(() => {
                alertEl.style.display = 'none';
                alertEl.classList.remove('fade-out');
            }, 300);
        }, 3500);
    }

    function openModal(modal) {
        modal?.classList.add('is-open');
    }

    function closeModal(modal) {
        modal?.classList.remove('is-open');
    }

    document.querySelectorAll('[data-close-pp-modal]').forEach((btn) => {
        btn.addEventListener('click', () => closeModal(btn.closest('.pp-modal')));
    });

    document.querySelectorAll('.pp-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal(modal);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll('.pp-modal.is-open').forEach(closeModal);
        }
    });

    function buildUrl(extra) {
        const params = new URLSearchParams(new FormData(filterForm));
        Object.entries(extra || {}).forEach(([key, value]) => params.set(key, value));
        return `${indexUrl}?${params.toString()}`;
    }

    function currentUrl() {
        return window.location.search ? `${indexUrl}${window.location.search}` : indexUrl;
    }

    async function refreshTable(url) {
        tableCard.classList.add('is-loading');
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) return;

            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const newBody = doc.getElementById('ppTableBody');
            const newFooter = doc.getElementById('ppTableFooter');

            if (newBody) tableBody.innerHTML = newBody.innerHTML;
            if (newFooter) tableFooter.innerHTML = newFooter.innerHTML;

            window.lucide?.createIcons();
            window.history.replaceState({}, '', url);
        } finally {
            tableCard.classList.remove('is-loading');
        }
    }

    let searchTimer;
    searchInput?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => refreshTable(buildUrl({ page: 1 })), 400);
    });

    roleFilter?.addEventListener('change', () => refreshTable(buildUrl({ page: 1 })));

    filterForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        refreshTable(buildUrl({ page: 1 }));
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('#ppTableFooter a.owner-pagination-btn');
        if (!link) return;
        event.preventDefault();
        refreshTable(link.href);
    });

    const ppModal = document.getElementById('ppModal');
    const ppForm = document.getElementById('ppForm');
    document.getElementById('ppAddBtn')?.addEventListener('click', () => {
        ppForm.reset();
        ppForm.action = storeUrl;
        document.getElementById('ppFormMethod').value = 'POST';
        openModal(ppModal);
    });

    const manageModal = document.getElementById('ppManageModal');
    const pmForm = document.getElementById('pmForm');
    const pmEditableInfo = document.getElementById('pmEditableInfo');
    const pmMiddleNameGroup = document.getElementById('pmMiddleNameGroup');
    const pmRoleGroup = document.getElementById('pmRoleGroup');
    const pmReadonlyInfo = document.getElementById('pmReadonlyInfo');
    const pmToggleBtn = document.getElementById('pmToggleBtn');

    function statusClass(value) {
        return 'status-' + String(value || '').toLowerCase().replace(/\s+/g, '-');
    }

    function openManageModal(btn) {
        const d = btn.dataset;
        const editable = d.editable === '1';

        pmForm.dataset.id = d.id;
        pmForm.dataset.type = d.type;
        pmForm.dataset.updateUrl = d.updateUrl || '';
        pmForm.dataset.homeUnitUrl = d.homeUnitUrl;

        document.getElementById('pmName').textContent = d.fullName;
        document.getElementById('pmRoleLabel').textContent = d.roleLabel;

        const referenceWrap = document.getElementById('pmReferenceWrap');
        document.getElementById('pmReference').textContent = d.reference || '';
        referenceWrap.style.display = d.reference ? '' : 'none';

        const statusBadge = document.getElementById('pmStatusBadge');
        statusBadge.textContent = d.status;
        statusBadge.className = 'status-text ' + statusClass(d.status);

        pmEditableInfo.style.display = editable ? '' : 'none';
        pmMiddleNameGroup.style.display = editable ? '' : 'none';
        pmRoleGroup.style.display = editable ? '' : 'none';
        pmReadonlyInfo.style.display = editable ? 'none' : '';

        if (editable) {
            document.getElementById('pmFirstName').value = d.firstName || '';
            document.getElementById('pmMiddleName').value = d.middleName || '';
            document.getElementById('pmLastName').value = d.lastName || '';
            document.getElementById('pmRole').value = d.roleValue || 'driver';
        } else {
            document.getElementById('pmRoFirst').textContent = d.firstName || '—';
            document.getElementById('pmRoMiddle').textContent = d.middleName || '—';
            document.getElementById('pmRoLast').textContent = d.lastName || '—';
            document.getElementById('pmRoRole').textContent = d.roleLabel || '—';
        }

        document.getElementById('pmHomeUnit').value = d.homeUnitId || '';
        document.getElementById('pmCurrentUnit').textContent = d.currentUnitName || 'Not Assigned';

        const assignmentEl = document.getElementById('pmAssignmentStatus');
        assignmentEl.textContent = d.assignmentStatus;
        assignmentEl.className = 'status-text ' + statusClass(d.assignmentStatus);

        const isArchived = d.status === 'Archived';
        const isActive = d.status === 'Active';
        pmToggleBtn.style.display = isArchived ? 'none' : '';
        pmToggleBtn.textContent = isActive ? 'Deactivate Personnel' : 'Activate Personnel';
        pmToggleBtn.classList.toggle('pp-btn-danger', isActive);
        pmToggleBtn.classList.toggle('pp-btn-positive', !isActive);
        pmToggleBtn.dataset.nextAction = isActive ? 'deactivate' : 'activate';
        pmToggleBtn.dataset.name = d.fullName;
        pmToggleBtn.dataset.toggleUrl = d.toggleUrl;

        openModal(manageModal);
    }

    document.addEventListener('click', (event) => {
        const btn = event.target.closest('.js-pp-manage');
        if (!btn) return;
        openManageModal(btn);
    });

    async function postForm(url, fields) {
        const body = new URLSearchParams();
        body.set('_token', csrfToken);
        Object.entries(fields).forEach(([key, value]) => body.set(key, value ?? ''));

        return fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            body,
        });
    }

    pmForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        const type = pmForm.dataset.type;
        const updateUrl = pmForm.dataset.updateUrl;
        const homeUnitUrl = pmForm.dataset.homeUnitUrl;
        const saveBtn = pmForm.querySelector('.pp-btn-save');
        saveBtn.disabled = true;

        try {
            if (type === 'personnel' && updateUrl) {
                await postForm(updateUrl, {
                    _method: 'PUT',
                    first_name: document.getElementById('pmFirstName').value,
                    middle_name: document.getElementById('pmMiddleName').value,
                    last_name: document.getElementById('pmLastName').value,
                    role: document.getElementById('pmRole').value,
                });
            }

            await postForm(homeUnitUrl, {
                _method: 'PATCH',
                home_unit_id: document.getElementById('pmHomeUnit').value,
            });

            closeModal(manageModal);
            showAlert('Personnel updated.');
            await refreshTable(currentUrl());
        } catch (error) {
            showAlert('Unable to save changes. Please try again.', true);
        } finally {
            saveBtn.disabled = false;
        }
    });

    const confirmModal = document.getElementById('ppConfirmModal');
    const confirmTitle = document.getElementById('ppConfirmTitle');
    const confirmText = document.getElementById('ppConfirmText');
    const confirmProceed = document.getElementById('ppConfirmProceed');
    const confirmCancel = document.getElementById('ppConfirmCancel');

    pmToggleBtn?.addEventListener('click', () => {
        const action = pmToggleBtn.dataset.nextAction;
        const name = pmToggleBtn.dataset.name;

        confirmTitle.textContent = action === 'deactivate' ? 'Deactivate Personnel' : 'Activate Personnel';
        confirmText.textContent = action === 'deactivate'
            ? `Deactivate ${name}? They will no longer be available for new unit assignments.`
            : `Activate ${name}? They will become available for unit assignments again.`;
        confirmProceed.textContent = action === 'deactivate' ? 'Deactivate' : 'Activate';
        confirmProceed.classList.toggle('pp-btn-save--danger', action === 'deactivate');
        confirmProceed.dataset.toggleUrl = pmToggleBtn.dataset.toggleUrl;

        openModal(confirmModal);
    });

    confirmCancel?.addEventListener('click', () => closeModal(confirmModal));

    confirmProceed?.addEventListener('click', async () => {
        const url = confirmProceed.dataset.toggleUrl;
        confirmProceed.disabled = true;

        try {
            await postForm(url, { _method: 'PATCH' });
            closeModal(confirmModal);
            closeModal(manageModal);
            showAlert('Personnel status updated.');
            await refreshTable(currentUrl());
        } catch (error) {
            showAlert('Unable to update status. Please try again.', true);
        } finally {
            confirmProceed.disabled = false;
        }
    });
});
