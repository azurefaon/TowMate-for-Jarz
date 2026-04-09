document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") {
        lucide.createIcons();
    }

    const searchInput = document.getElementById("unitSearch");
    const rows = Array.from(
        document.querySelectorAll("#unitsTable tr[data-name]"),
    );

    if (!searchInput || rows.length === 0) {
        return;
    }

    const filterRows = () => {
        const term = searchInput.value.toLowerCase().trim();

        rows.forEach((row) => {
            const haystack = [
                row.dataset.name || "",
                row.dataset.plate || "",
                row.dataset.type || "",
                row.dataset.teamleader || "",
            ].join(" ");

            row.style.display = haystack.includes(term) ? "" : "none";
        });
    };

    searchInput.addEventListener("input", filterRows);

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && document.activeElement === searchInput) {
            searchInput.value = "";
            filterRows();
            searchInput.blur();
        }
    });

    filterRows();
});
