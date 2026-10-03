document.addEventListener("DOMContentLoaded", function () {
    const page = document.querySelector(".ul-page");
    if (!page) return;

    const BASE = "/admin-dashboard/drivers";

    function csrfToken() {
        return page.dataset.csrf || "";
    }

    function post(url, body) {
        return fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": csrfToken(),
                Accept: "application/json",
            },
            body: JSON.stringify(body || {}),
        }).then(function (r) {
            if (r.status === 419) {
                try {
                    if (expandedUnitId) {
                        sessionStorage.setItem("ulReopenUnit", expandedUnitId);
                        if (isEditing()) sessionStorage.setItem("ulReopenEdit", "1");
                    }
                    sessionStorage.setItem(
                        "ulPendingFeedback",
                        JSON.stringify({ message: "Your session refreshed. Please try that action again.", type: "error" })
                    );
                } catch (e) {}
                window.location.reload();
                return { ok: false, data: { success: false }, handled: true };
            }

            return r.json().then(function (data) {
                return { ok: r.ok, data: data };
            });
        });
    }

    function showFeedback(message, type) {
        let el = document.querySelector(".ul-feedback");
        if (!el) {
            el = document.createElement("div");
            const header = document.querySelector(".ul-header");
            if (header) header.insertAdjacentElement("afterend", el);
            else page.prepend(el);
        }
        el.className = "ul-feedback ul-feedback--" + (type === "error" ? "error" : "success");
        el.textContent = message;

        clearTimeout(showFeedback._timer);
        showFeedback._timer = setTimeout(function () {
            el.remove();
        }, 6000);
    }

    function showError(message) {
        showFeedback(message || "Something went wrong.", "error");
    }

    // ------------------------------------------------------------------
    // Expandable rows (replaces the old right-side drawer).
    // One unit open at a time; its detail row sits directly under it.
    // ------------------------------------------------------------------
    let expandedUnitId = null;

    function rowFor(unitId) {
        return document.getElementById("ul-row-" + unitId);
    }

    function detailFor(unitId) {
        return document.getElementById("ul-detail-" + unitId);
    }

    function isEditing() {
        if (!expandedUnitId) return false;
        const edit = detailFor(expandedUnitId)?.querySelector(".ul-detail-edit");
        return !!edit && !edit.hidden;
    }

    function setEditMode(unitId, on) {
        const detail = detailFor(unitId);
        if (!detail) return;
        const view = detail.querySelector(".ul-detail-view");
        const edit = detail.querySelector(".ul-detail-edit");
        if (!edit) return; // locked units have no edit table at all
        edit.hidden = !on;
        if (view) view.hidden = on;
        const focusTarget = on
            ? edit.querySelector("button")
            : detail.querySelector('[data-action="ul-edit"]');
        if (focusTarget) focusTarget.focus();
    }

    function collapseUnit() {
        if (!expandedUnitId) return;
        const row = rowFor(expandedUnitId);
        const detail = detailFor(expandedUnitId);
        if (detail) {
            setEditMode(expandedUnitId, false);
            detail.hidden = true;
        }
        if (row) {
            row.classList.remove("is-open");
            const btn = row.querySelector(".ul-expand");
            if (btn) {
                btn.setAttribute("aria-expanded", "false");
                btn.setAttribute("aria-label", "Show team for " + (row.dataset.unitName || "this unit"));
            }
        }
        expandedUnitId = null;
    }

    function expandUnit(unitId) {
        const id = String(unitId);
        const row = rowFor(id);
        const detail = detailFor(id);
        if (!row || !detail || row.hidden) return;
        if (expandedUnitId && expandedUnitId !== id) collapseUnit();

        detail.hidden = false;
        row.classList.add("is-open");
        const btn = row.querySelector(".ul-expand");
        if (btn) {
            btn.setAttribute("aria-expanded", "true");
            btn.setAttribute("aria-label", "Hide team for " + (row.dataset.unitName || "this unit"));
        }
        expandedUnitId = id;
    }

    function toggleUnit(unitId) {
        if (expandedUnitId === String(unitId)) collapseUnit();
        else expandUnit(unitId);
    }

    // Whole-row click for mouse users; keyboard users use the real
    // expand button in the last cell (aria-expanded / aria-controls).
    document.querySelectorAll(".ul-row[data-unit-id]").forEach(function (row) {
        row.addEventListener("click", function (e) {
            if (e.target.closest("a, input, select, textarea")) return;
            toggleUnit(row.dataset.unitId);
        });
    });

    document.addEventListener("click", function (e) {
        const editBtn = e.target.closest('[data-action="ul-edit"]');
        if (editBtn) {
            setEditMode(editBtn.dataset.unitId, true);
            return;
        }
        const doneBtn = e.target.closest('[data-action="ul-edit-done"]');
        if (doneBtn) setEditMode(doneBtn.dataset.unitId, false);
    });

    function anyModalOpen() {
        return !!document.querySelector(".ul-modal-backdrop.is-open");
    }

    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape" || !expandedUnitId || anyModalOpen()) return;
        const btn = rowFor(expandedUnitId)?.querySelector(".ul-expand");
        collapseUnit();
        if (btn) btn.focus();
    });

    function reloadKeepingExpanded(message) {
        try {
            if (expandedUnitId) {
                sessionStorage.setItem("ulReopenUnit", expandedUnitId);
                if (isEditing()) sessionStorage.setItem("ulReopenEdit", "1");
            }
            if (message) sessionStorage.setItem("ulPendingFeedback", JSON.stringify({ message: message, type: "success" }));
        } catch (e) {}
        window.location.reload();
    }

    (function reopenExpandedAfterReload() {
        try {
            const reopenId = sessionStorage.getItem("ulReopenUnit");
            const reopenEdit = sessionStorage.getItem("ulReopenEdit") === "1";
            sessionStorage.removeItem("ulReopenUnit");
            sessionStorage.removeItem("ulReopenEdit");
            if (reopenId) {
                expandUnit(reopenId);
                if (reopenEdit && expandedUnitId === reopenId) setEditMode(reopenId, true);
            }
        } catch (e) {}
    })();

    (function showPendingFeedbackAfterReload() {
        try {
            const raw = sessionStorage.getItem("ulPendingFeedback");
            if (raw) {
                sessionStorage.removeItem("ulPendingFeedback");
                const parsed = JSON.parse(raw);
                showFeedback(parsed.message, parsed.type);
            }
        } catch (e) {}
    })();

    // ------------------------------------------------------------------
    // Filters (same values as before). A filtered-out unit hides BOTH its
    // row and its detail row; the open unit collapses if it is hidden.
    // ------------------------------------------------------------------
    const availabilitySelect = document.getElementById("ulAvailability");
    const presenceSelect = document.getElementById("ulPresence");
    const truckTypeSelect = document.getElementById("ulTruckType");
    const searchInput = document.getElementById("ulSearch");
    const clearBtn = document.getElementById("ulClearFilters");
    const shownCountEl = document.getElementById("ulShownCount");
    const filterEmptyRow = document.getElementById("ulFilterEmpty");
    const rows = document.querySelectorAll(".ul-row");

    function applyFilters() {
        const availability = availabilitySelect ? availabilitySelect.value : "all";
        const presence = presenceSelect ? presenceSelect.value : "all";
        const truckType = truckTypeSelect ? truckTypeSelect.value : "all";
        const query = (searchInput ? searchInput.value : "").trim().toLowerCase();
        let shown = 0;

        rows.forEach(function (row) {
            const matchesAvailability = availability === "all" || row.dataset.availability === availability;
            const matchesPresence = presence === "all" || row.dataset.presence === presence;
            const matchesTruckType = truckType === "all" || row.dataset.truckType === truckType;
            const matchesQuery = !query || row.dataset.search.indexOf(query) > -1;
            const visible = matchesAvailability && matchesPresence && matchesTruckType && matchesQuery;

            if (!visible && expandedUnitId === row.dataset.unitId) collapseUnit();
            row.hidden = !visible;
            const detail = detailFor(row.dataset.unitId);
            if (detail && !visible) detail.hidden = true;
            if (visible) shown++;
        });

        if (shownCountEl) shownCountEl.textContent = String(shown);
        if (filterEmptyRow) filterEmptyRow.hidden = !(rows.length > 0 && shown === 0);
        if (clearBtn) {
            clearBtn.hidden = availability === "all" && presence === "all" && truckType === "all" && query === "";
        }
    }

    [availabilitySelect, presenceSelect, truckTypeSelect].forEach(function (el) {
        if (el) el.addEventListener("change", applyFilters);
    });
    if (searchInput) searchInput.addEventListener("input", applyFilters);

    clearBtn?.addEventListener("click", function () {
        if (availabilitySelect) availabilitySelect.value = "all";
        if (presenceSelect) presenceSelect.value = "all";
        if (truckTypeSelect) truckTypeSelect.value = "all";
        if (searchInput) searchInput.value = "";
        applyFilters();
        if (searchInput) searchInput.focus();
    });

    const confirmBackdrop = document.getElementById("ulConfirmBackdrop");
    const confirmTitleEl = document.getElementById("ulConfirmTitle");
    const confirmBodyEl = document.getElementById("ulConfirmBody");
    const confirmOkBtn = document.getElementById("ulConfirmOk");
    let confirmHandler = null;

    function openConfirm(title, body, actionLabel, onConfirm) {
        if (!confirmBackdrop) return;
        confirmTitleEl.textContent = title;
        confirmBodyEl.textContent = body;
        confirmOkBtn.textContent = actionLabel;
        confirmHandler = onConfirm;
        confirmBackdrop.classList.add("is-open");
    }

    function closeConfirm() {
        if (!confirmBackdrop) return;
        confirmBackdrop.classList.remove("is-open");
        confirmHandler = null;
    }

    document.getElementById("ulConfirmClose")?.addEventListener("click", closeConfirm);
    document.getElementById("ulConfirmCancel")?.addEventListener("click", closeConfirm);
    confirmBackdrop?.addEventListener("click", function (e) {
        if (e.target === confirmBackdrop) closeConfirm();
    });
    confirmOkBtn?.addEventListener("click", function () {
        const handler = confirmHandler;
        closeConfirm();
        if (handler) handler();
    });

    function slotRoleLabel(slot) {
        if (slot === "driver_1") return "Driver";
        if (slot === "crew_member_1" || slot === "crew_member_2") return "Crew";
        return "Person";
    }

    const assignBackdrop = document.getElementById("ulAssignBackdrop");
    const assignTitle = document.getElementById("ulAssignTitle");
    const assignList = document.getElementById("ulAssignList");
    let assignContext = null;

    function openAssign(role, unitId, slot) {
        assignContext = { role: role, unitId: unitId, slot: slot };
        const unitName = rowFor(unitId)?.dataset.unitName;
        const roleLabel = role === "team_leader" ? "Team Leader" : role === "driver" ? "Driver" : "Crew Member";
        assignTitle.textContent = "Assign " + roleLabel + (unitName ? " to " + unitName : "");
        assignList.innerHTML = '<p class="ul-modal-text">Loading…</p>';
        assignBackdrop.classList.add("is-open");

        fetch(BASE + "/eligible-people?role=" + encodeURIComponent(role) + "&exclude_unit_id=" + encodeURIComponent(unitId))
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                renderAssignList(data.people || []);
            })
            .catch(function () {
                assignList.innerHTML = '<p class="ul-modal-text">Could not load eligible people.</p>';
            });
    }

    function assignReason(person) {
        if (assignContext && assignContext.role === "team_leader") {
            if (person.duty && person.duty !== "available") return "Off duty";
            if (person.workload === "busy") return "Busy";
            return "Not available";
        }
        // Driver/Crew: the API only says the source unit has an active job
        // or reservation — it gives no booking code, so none is shown.
        return "Unit busy or reserved";
    }

    function renderAssignList(people) {
        if (!people.length) {
            assignList.innerHTML = '<p class="ul-modal-text">No eligible people found.</p>';
            return;
        }

        assignList.innerHTML = "";
        const table = document.createElement("table");
        table.className = "ul-assign-table";
        table.innerHTML =
            '<thead><tr><th scope="col">Name</th><th scope="col">Currently on</th>' +
            '<th scope="col" class="ul-assign-action"><span class="sr-only">Action</span></th></tr></thead>';
        const tbody = document.createElement("tbody");

        people.forEach(function (person) {
            const tr = document.createElement("tr");
            tr.dataset.eligible = person.eligible ? "true" : "false";

            const nameTd = document.createElement("td");
            nameTd.className = "ul-assign-name";
            nameTd.textContent = person.name;
            tr.appendChild(nameTd);

            const unitTd = document.createElement("td");
            unitTd.className = "ul-assign-unit";
            unitTd.textContent = person.home_unit || person.source_unit_name || "—";
            tr.appendChild(unitTd);

            const actionTd = document.createElement("td");
            actionTd.className = "ul-assign-action";
            if (person.eligible) {
                const btn = document.createElement("button");
                btn.type = "button";
                btn.className = "ul-btn ul-btn--sm";
                btn.textContent = "Assign";
                btn.addEventListener("click", function () {
                    confirmAssign(person);
                });
                actionTd.appendChild(btn);
            } else {
                const reason = document.createElement("span");
                reason.className = "ul-assign-reason";
                reason.textContent = assignReason(person);
                actionTd.appendChild(reason);
            }
            tr.appendChild(actionTd);

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        assignList.appendChild(table);
    }

    function confirmAssign(person) {
        if (!assignContext) return;

        let url;
        let body;

        if (assignContext.role === "team_leader") {
            url = BASE + "/units/" + assignContext.unitId + "/assign-team-leader";
            body = { team_leader_id: person.id };
        } else {
            url = BASE + "/units/" + assignContext.unitId + "/assign-slot";
            body = person.source_unit_id
                ? {
                    to_slot: assignContext.slot,
                    source_unit_id: person.source_unit_id,
                    from_slot: person.from_slot,
                }
                : {
                    to_slot: assignContext.slot,
                    personnel_id: person.personnel_id,
                };
        }

        post(url, body).then(function (res) {
            if (res.handled) return;
            if (res.ok && res.data.success) {
                reloadKeepingExpanded(res.data.message);
            } else {
                showError(res.data.message || "Could not assign.");
            }
        });
    }

    document.addEventListener("click", function (e) {
        const btn = e.target.closest('[data-action="open-assign"]');
        if (!btn) return;
        openAssign(btn.dataset.role, btn.dataset.unitId, btn.dataset.slot);
    });

    document.getElementById("ulAssignClose")?.addEventListener("click", function () {
        assignBackdrop.classList.remove("is-open");
    });
    assignBackdrop?.addEventListener("click", function (e) {
        if (e.target === assignBackdrop) assignBackdrop.classList.remove("is-open");
    });

    document.addEventListener("click", function (e) {
        const btn = e.target.closest('[data-action="return-team-leader"]');
        if (!btn) return;
        const person = btn.dataset.personName || "This Team Leader";
        const homeUnit = btn.dataset.homeUnitName || "their home unit";
        openConfirm("Return Team Leader", "Return " + person + " to " + homeUnit + "?", "Return", function () {
            post(BASE + "/units/" + btn.dataset.unitId + "/return-team-leader", {}).then(function (res) {
                if (res.handled) return;
                if (res.ok && res.data.success) reloadKeepingExpanded(res.data.message);
                else showError(res.data.message || "Could not return.");
            });
        });
    });

    document.addEventListener("click", function (e) {
        const btn = e.target.closest('[data-action="return-slot"]');
        if (!btn) return;
        const role = slotRoleLabel(btn.dataset.slot);
        const person = btn.dataset.personName || "This person";
        const homeUnit = btn.dataset.homeUnitName || "their home unit";
        openConfirm("Return " + role, "Return " + person + " to " + homeUnit + "?", "Return", function () {
            post(BASE + "/loans/" + btn.dataset.loanId + "/return", {}).then(function (res) {
                if (res.handled) return;
                if (res.ok && res.data.success) reloadKeepingExpanded(res.data.message);
                else showError(res.data.message || "Could not return.");
            });
        });
    });

    document.addEventListener("click", function (e) {
        const btn = e.target.closest('[data-action="remove-team-leader"]');
        if (!btn) return;
        const person = btn.dataset.personName || "This Team Leader";
        const unitName = btn.dataset.unitName || "this unit";
        openConfirm("Remove Team Leader", "Remove " + person + " from " + unitName + "?", "Remove", function () {
            post(BASE + "/units/" + btn.dataset.unitId + "/remove-team-leader", {}).then(function (res) {
                if (res.handled) return;
                if (res.ok && res.data.success) reloadKeepingExpanded(res.data.message);
                else showError(res.data.message || "Could not remove.");
            });
        });
    });

    document.addEventListener("click", function (e) {
        const btn = e.target.closest('[data-action="remove-slot"]');
        if (!btn) return;
        const role = slotRoleLabel(btn.dataset.slot);
        const person = btn.dataset.personName || "This person";
        const unitName = btn.dataset.unitName || "this unit";
        openConfirm("Remove " + role, "Remove " + person + " from " + unitName + "?", "Remove", function () {
            post(BASE + "/units/" + btn.dataset.unitId + "/remove-slot", { slot: btn.dataset.slot }).then(function (res) {
                if (res.handled) return;
                if (res.ok && res.data.success) reloadKeepingExpanded(res.data.message);
                else showError(res.data.message || "Could not remove.");
            });
        });
    });

    document.addEventListener("click", function (e) {
        const btn = e.target.closest('[data-action="toggle-tl-duty"]');
        if (!btn) return;
        const next = btn.dataset.current === "available" ? "unavailable" : "available";
        post(BASE + "/team-leaders/" + btn.dataset.tlId + "/duty", { status: next }).then(function (res) {
            if (res.handled) return;
            if (res.ok && res.data.success) reloadKeepingExpanded(res.data.message);
            else showError(res.data.message || "Could not update duty.");
        });
    });

    const transferBackdrop = document.getElementById("ulTransferBackdrop");
    const transferTargetSelect = document.getElementById("ulTransferTarget");
    let transferSourceUnitId = null;

    document.addEventListener("click", function (e) {
        const btn = e.target.closest('[data-action="open-transfer"]');
        if (!btn) return;

        transferSourceUnitId = btn.dataset.unitId;
        transferTargetSelect.innerHTML = "";

        document.querySelectorAll(".ul-row[data-unit-id]").forEach(function (row) {
            const unitId = row.dataset.unitId;
            if (!unitId || unitId === transferSourceUnitId) return;
            if (row.dataset.locked === "1") return;

            const opt = document.createElement("option");
            opt.value = unitId;
            opt.textContent = row.dataset.unitName || ("Unit #" + unitId);
            transferTargetSelect.appendChild(opt);
        });

        transferBackdrop.classList.add("is-open");
    });

    document.getElementById("ulTransferClose")?.addEventListener("click", function () {
        transferBackdrop.classList.remove("is-open");
    });
    transferBackdrop?.addEventListener("click", function (e) {
        if (e.target === transferBackdrop) transferBackdrop.classList.remove("is-open");
    });

    document.getElementById("ulTransferConfirm")?.addEventListener("click", function () {
        if (!transferSourceUnitId || !transferTargetSelect.value) return;

        post(BASE + "/units/" + transferSourceUnitId + "/transfer-team", {
            target_unit_id: transferTargetSelect.value,
        }).then(function (res) {
            if (res.handled) return;
            if (res.ok && res.data.success) reloadKeepingExpanded(res.data.message);
            else showError(res.data.message || "Could not transfer team.");
        });
    });
});
