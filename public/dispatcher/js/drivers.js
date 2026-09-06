document.addEventListener("DOMContentLoaded", function () {
    const page = document.querySelector(".ul-page");
    if (!page) return;

    const CSRF = page.dataset.csrf;
    const BASE = "/admin-dashboard/drivers";

    function post(url, body) {
        return fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": CSRF,
                Accept: "application/json",
            },
            body: JSON.stringify(body || {}),
        }).then(function (r) {
            return r.json().then(function (data) {
                return { ok: r.ok, data: data };
            });
        });
    }

    function showError(message) {
        window.alert(message || "Something went wrong.");
    }

    // ------------------------------------------------------------------
    // Unit Details drawer — content is server-rendered per unit into a
    // <template>; opening the drawer just clones that template's content
    // into the drawer body. No new read endpoint involved.
    // ------------------------------------------------------------------
    const drawerBackdrop = document.getElementById("ulDrawerBackdrop");
    const drawerBody = document.getElementById("ulDrawerBody");
    let currentDrawerUnitId = null;

    function openDrawerForUnit(unitId) {
        const tpl = document.getElementById("ul-drawer-" + unitId);
        if (!tpl || !drawerBackdrop || !drawerBody) return;

        drawerBody.innerHTML = "";
        drawerBody.appendChild(tpl.content.cloneNode(true));
        currentDrawerUnitId = String(unitId);
        drawerBackdrop.classList.add("is-open");
    }

    function closeDrawer() {
        if (!drawerBackdrop) return;
        drawerBackdrop.classList.remove("is-open");
        currentDrawerUnitId = null;
    }

    document.querySelectorAll(".ul-row[data-unit-id]").forEach(function (row) {
        row.addEventListener("click", function () {
            openDrawerForUnit(row.dataset.unitId);
        });
        row.addEventListener("keydown", function (e) {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                openDrawerForUnit(row.dataset.unitId);
            }
        });
    });

    document.getElementById("ulDrawerClose")?.addEventListener("click", closeDrawer);
    drawerBackdrop?.addEventListener("click", function (e) {
        if (e.target === drawerBackdrop) closeDrawer();
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && drawerBackdrop && drawerBackdrop.classList.contains("is-open")) {
            closeDrawer();
        }
    });

    // A mutation triggered from the drawer reloads the page (server remains
    // authoritative for every field shown), but the drawer should come back
    // open on the same Unit with fresh data rather than closed.
    function reloadKeepingDrawer() {
        try {
            if (currentDrawerUnitId) sessionStorage.setItem("ulReopenUnit", currentDrawerUnitId);
        } catch (e) {
            // sessionStorage unavailable — falls back to a plain reload below.
        }
        window.location.reload();
    }

    (function reopenDrawerAfterReload() {
        try {
            const reopenId = sessionStorage.getItem("ulReopenUnit");
            if (reopenId) {
                sessionStorage.removeItem("ulReopenUnit");
                openDrawerForUnit(reopenId);
            }
        } catch (e) {
            // sessionStorage unavailable — drawer simply stays closed after reload.
        }
    })();

    // ------------------------------------------------------------------
    // Filters (client-side, same pattern as Dispatch Queue/Active Jobs)
    // ------------------------------------------------------------------
    const availabilitySelect = document.getElementById("ulAvailability");
    const presenceSelect = document.getElementById("ulPresence");
    const truckTypeSelect = document.getElementById("ulTruckType");
    const searchInput = document.getElementById("ulSearch");
    const rows = document.querySelectorAll(".ul-row");

    function applyFilters() {
        const availability = availabilitySelect ? availabilitySelect.value : "all";
        const presence = presenceSelect ? presenceSelect.value : "all";
        const truckType = truckTypeSelect ? truckTypeSelect.value : "all";
        const query = (searchInput ? searchInput.value : "").trim().toLowerCase();

        rows.forEach(function (row) {
            const matchesAvailability = availability === "all" || row.dataset.availability === availability;
            const matchesPresence = presence === "all" || row.dataset.presence === presence;
            const matchesTruckType = truckType === "all" || row.dataset.truckType === truckType;
            const matchesQuery = !query || row.dataset.search.indexOf(query) > -1;
            row.style.display = matchesAvailability && matchesPresence && matchesTruckType && matchesQuery ? "" : "none";
        });
    }

    [availabilitySelect, presenceSelect, truckTypeSelect].forEach(function (el) {
        if (el) el.addEventListener("change", applyFilters);
    });
    if (searchInput) searchInput.addEventListener("input", applyFilters);

    // ------------------------------------------------------------------
    // Assign dialog (Team Leader / Driver / Crew) — trigger now lives
    // inside dynamically-injected drawer content, so it's delegated.
    // ------------------------------------------------------------------
    const assignBackdrop = document.getElementById("ulAssignBackdrop");
    const assignTitle = document.getElementById("ulAssignTitle");
    const assignList = document.getElementById("ulAssignList");
    let assignContext = null; // { role, unitId, slot }

    function openAssign(role, unitId, slot) {
        assignContext = { role: role, unitId: unitId, slot: slot };
        assignTitle.textContent =
            "Search " + (role === "team_leader" ? "Team Leader" : role === "driver" ? "Driver" : "Crew Member");
        assignList.innerHTML = '<p class="jobs-cell-secondary">Loading…</p>';
        assignBackdrop.classList.add("is-open");

        fetch(BASE + "/eligible-people?role=" + encodeURIComponent(role) + "&exclude_unit_id=" + encodeURIComponent(unitId))
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                renderAssignList(data.people || []);
            })
            .catch(function () {
                assignList.innerHTML = '<p class="jobs-cell-secondary">Could not load eligible people.</p>';
            });
    }

    function renderAssignList(people) {
        if (!people.length) {
            assignList.innerHTML = '<p class="jobs-cell-secondary">No eligible people found.</p>';
            return;
        }

        assignList.innerHTML = "";
        people.forEach(function (person) {
            const option = document.createElement("div");
            option.className = "ul-person-option";
            option.dataset.eligible = person.eligible ? "true" : "false";

            const left = document.createElement("div");
            const name = document.createElement("div");
            name.className = "ul-person-option-name";
            name.textContent = person.name;
            left.appendChild(name);

            const meta = document.createElement("div");
            meta.className = "ul-person-option-meta";
            const metaParts = [];
            if (person.home_unit || person.source_unit_name) {
                metaParts.push(person.home_unit || person.source_unit_name);
            }
            if (person.duty) metaParts.push("Duty " + capitalize(person.duty));
            if (person.workload) metaParts.push(capitalize(person.workload));
            if (person.presence) metaParts.push(capitalize(person.presence));
            meta.textContent = metaParts.join(" · ");
            left.appendChild(meta);

            option.appendChild(left);

            if (person.eligible) {
                option.addEventListener("click", function () {
                    confirmAssign(person);
                });
            }

            assignList.appendChild(option);
        });
    }

    function capitalize(s) {
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
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
            body = {
                to_slot: assignContext.slot,
                source_unit_id: person.source_unit_id,
                from_slot: person.from_slot,
            };
        }

        post(url, body).then(function (res) {
            if (res.ok && res.data.success) {
                reloadKeepingDrawer();
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

    // ------------------------------------------------------------------
    // Return (Team Leader / Driver / Crew) — delegated (drawer content)
    // ------------------------------------------------------------------
    document.addEventListener("click", function (e) {
        const btn = e.target.closest('[data-action="return-team-leader"]');
        if (!btn) return;
        if (!window.confirm("Return this Team Leader to their home unit?")) return;
        post(BASE + "/units/" + btn.dataset.unitId + "/return-team-leader", {}).then(function (res) {
            if (res.ok && res.data.success) reloadKeepingDrawer();
            else showError(res.data.message || "Could not return.");
        });
    });

    document.addEventListener("click", function (e) {
        const btn = e.target.closest('[data-action="return-slot"]');
        if (!btn) return;
        if (!window.confirm("Return this person to their home unit?")) return;
        post(BASE + "/loans/" + btn.dataset.loanId + "/return", {}).then(function (res) {
            if (res.ok && res.data.success) reloadKeepingDrawer();
            else showError(res.data.message || "Could not return.");
        });
    });

    // ------------------------------------------------------------------
    // Remove (Team Leader / Driver / Crew) — regular, non-borrowed
    // assignments only; delegated (drawer content).
    // ------------------------------------------------------------------
    document.addEventListener("click", function (e) {
        const btn = e.target.closest('[data-action="remove-team-leader"]');
        if (!btn) return;
        if (!window.confirm("Remove this Team Leader from the unit?")) return;
        post(BASE + "/units/" + btn.dataset.unitId + "/remove-team-leader", {}).then(function (res) {
            if (res.ok && res.data.success) reloadKeepingDrawer();
            else showError(res.data.message || "Could not remove.");
        });
    });

    document.addEventListener("click", function (e) {
        const btn = e.target.closest('[data-action="remove-slot"]');
        if (!btn) return;
        if (!window.confirm("Remove this person from the unit?")) return;
        post(BASE + "/units/" + btn.dataset.unitId + "/remove-slot", { slot: btn.dataset.slot }).then(function (res) {
            if (res.ok && res.data.success) reloadKeepingDrawer();
            else showError(res.data.message || "Could not remove.");
        });
    });

    // ------------------------------------------------------------------
    // Duty toggle — delegated (drawer content)
    // ------------------------------------------------------------------
    document.addEventListener("click", function (e) {
        const btn = e.target.closest('[data-action="toggle-tl-duty"]');
        if (!btn) return;
        const next = btn.dataset.current === "available" ? "unavailable" : "available";
        post(BASE + "/team-leaders/" + btn.dataset.tlId + "/duty", { status: next }).then(function (res) {
            if (res.ok && res.data.success) reloadKeepingDrawer();
            else showError(res.data.message || "Could not update duty.");
        });
    });

    // ------------------------------------------------------------------
    // Transfer Team — trigger delegated (drawer content); target list is
    // built from the main table rows, which always stay in the DOM.
    // ------------------------------------------------------------------
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
            if (row.dataset.locked === "1") return; // Reserved/Active-Job units can't receive a team.

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
            if (res.ok && res.data.success) reloadKeepingDrawer();
            else showError(res.data.message || "Could not transfer team.");
        });
    });
});
