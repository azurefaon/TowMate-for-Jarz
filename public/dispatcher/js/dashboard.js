function openLogoutModal() {
    document.getElementById("logoutModal").style.display = "flex";
}

function closeLogoutModal() {
    document.getElementById("logoutModal").style.display = "none";
}

function submitLogout() {
    document.getElementById("logoutForm").submit();
}

document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".toggle-btn").forEach((btn) => {
        btn.addEventListener("click", () => {
            document
                .querySelectorAll(".toggle-btn")
                .forEach((b) => b.classList.remove("active"));
            btn.classList.add("active");
            btn.style.transform = "scale(0.97)";
            setTimeout(() => {
                btn.style.transform = "scale(1)";
            }, 100);
        });
    });
});
