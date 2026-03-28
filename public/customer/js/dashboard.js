document.addEventListener("DOMContentLoaded", () => {
    const trackBtn = document.getElementById("trackBtn");

    if (trackBtn) {
        trackBtn.addEventListener("click", (e) => {
            // check if disabled
            if (trackBtn.classList.contains("disabled")) {
                e.preventDefault();
                alert("No active booking to track.");
                return;
            }

            // if may booking → go to link
            const url = trackBtn.getAttribute("href");

            if (url && url !== "#") {
                window.location.href = url;
            }
        });
    }

    if (window.lucide) {
        lucide.createIcons();
    }

    const fabMain = document.getElementById("fabMain");
    const fabMenu = document.getElementById("fabMenu");

    if (fabMain && fabMenu) {
        fabMain.addEventListener("click", (e) => {
            fabMain.classList.toggle("rotate");
            fabMenu.classList.toggle("active");
        });

        document.addEventListener("click", (e) => {
            if (!fabMenu.contains(e.target) && !fabMain.contains(e.target)) {
                fabMenu.classList.remove("active");
                fabMain.classList.remove("rotate");
            }
        });
    }

    const modal = document.getElementById("logoutModal");

    if (modal) {
        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && !modal.classList.contains("hidden")) {
                closeLogoutModal();
            }
        });

        modal.addEventListener("click", (e) => {
            if (e.target === modal) {
                closeLogoutModal();
            }
        });
    }
});

/* LOGOUT */

function openLogoutModal() {
    document.getElementById("logoutModal").classList.remove("hidden");
}

function closeLogoutModal() {
    document.getElementById("logoutModal").classList.add("hidden");
}

function submitLogout() {
    document.getElementById("logoutForm").submit();
}

document.querySelectorAll(".primary-btn").forEach((btn) => {
    btn.addEventListener("click", () => {
        document.getElementById("bookingLoading").classList.remove("hidden");
    });
});
