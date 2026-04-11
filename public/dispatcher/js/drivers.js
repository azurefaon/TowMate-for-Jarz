document.addEventListener("DOMContentLoaded", () => {
    const cards = Array.from(document.querySelectorAll(".driver-card"));
    const filterButtons = Array.from(document.querySelectorAll(".filter-btn"));
    const filtersWrap = document.querySelector(".drivers-filters");
    const emptyState = document.getElementById("driversEmptyFiltered");
    const defaultFilter = filtersWrap?.dataset.defaultFilter || "all";

    if (window.lucide) {
        window.lucide.createIcons();
    }

    const setActiveButton = (filter) => {
        filterButtons.forEach((btn) => {
            btn.classList.toggle(
                "is-active",
                (btn.dataset.filter || "all") === filter,
            );
        });
    };

    const applyFilter = (requestedFilter) => {
        let filter = requestedFilter;

        if (
            filter === "online" &&
            !cards.some(
                (card) => (card.dataset.presence || "offline") === "online",
            )
        ) {
            filter = "all";
        }

        let visibleCount = 0;

        cards.forEach((card) => {
            const presence = card.dataset.presence || "offline";
            const matches = filter === "all" || presence === filter;

            card.classList.toggle("is-hidden", !matches);

            if (matches) {
                visibleCount += 1;
            }
        });

        setActiveButton(filter);

        if (emptyState) {
            emptyState.hidden = visibleCount !== 0;
        }
    };

    filterButtons.forEach((button) => {
        button.addEventListener("click", () => {
            applyFilter(button.dataset.filter || "all");
        });
    });

    animateCards(cards);
    applyFilter(defaultFilter);
});

function animateCards(cards) {
    cards.forEach((card, index) => {
        card.style.opacity = "0";
        card.style.transform = "translateY(18px)";

        window.setTimeout(() => {
            card.style.opacity = "1";
            card.style.transform = "translateY(0)";
        }, index * 90);
    });
}
