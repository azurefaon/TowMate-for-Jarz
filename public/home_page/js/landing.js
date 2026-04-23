const toggle = document.getElementById("menuToggle");
const nav = document.getElementById("navMenu");

if (toggle) {
    toggle.addEventListener("click", () => {
        toggle.classList.toggle("active");
        nav.classList.toggle("active");
    });
}

if (nav) {
    nav.querySelectorAll("a").forEach((link) => {
        link.addEventListener("click", () => {
            toggle.classList.remove("active");
            nav.classList.remove("active");
        });
    });
}

/* SMOOTH SCROLL */

document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener("click", function (e) {
        e.preventDefault();

        document.querySelector(this.getAttribute("href")).scrollIntoView({
            behavior: "smooth",
        });
    });
});

/* FADE IN ON SCROLL */

const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
        if (entry.isIntersecting) {
            entry.target.classList.add("show");
        }
    });
});

document.querySelectorAll(".fade-in").forEach((el) => {
    observer.observe(el);
});

const links = document.querySelectorAll(".nav-links a:not(.book-btn)");
const indicator = document.querySelector(".nav-indicator");
const disableActiveNavState = document.body.classList.contains("booking-page") ||
    document.querySelector(".booking-page-nav-neutral");

function moveIndicator(el) {
    if (!indicator || disableActiveNavState) return;

    const rect = el.getBoundingClientRect();
    const parentRect = el.parentElement.getBoundingClientRect();

    indicator.style.width = rect.width + "px";
    indicator.style.left = rect.left - parentRect.left + "px";
}

/* INITIAL */

if (indicator && disableActiveNavState) {
    indicator.style.width = "0";
}

if (links.length && !disableActiveNavState) {
    moveIndicator(links[0]);
}

/* HOVER EFFECT */

links.forEach((link) => {
    link.addEventListener("mouseenter", () => {
        if (!disableActiveNavState) {
            moveIndicator(link);
        }
    });
});

const sections = document.querySelectorAll("section");
const navLinks = document.querySelectorAll(".nav-links a:not(.book-btn)");

if (!disableActiveNavState) {
    window.addEventListener("scroll", () => {
        let current = "";

        sections.forEach((section) => {
            const sectionTop = section.offsetTop - 150;
            const sectionHeight = section.clientHeight;

            if (scrollY >= sectionTop) {
                current = section.getAttribute("id");
            }
        });

        navLinks.forEach((link) => {
            link.classList.remove("active");

            if (link.getAttribute("href") === "#" + current) {
                link.classList.add("active");
                moveIndicator(link);
            }
        });
    });
} else {
    navLinks.forEach((link) => link.classList.remove("active"));
}

let lastScrollTop = 0;
let isAtBottom = false;
const floatingBtn = document.getElementById("floatingBookBtn");

window.addEventListener("scroll", () => {
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    const isScrollingDown = scrollTop > lastScrollTop;
    const atBottom =
        window.innerHeight + scrollTop >= document.body.scrollHeight - 10;
    const scrolly = window.scrollY;

    if (floatingBtn) {
        if (scrolly > 400) {
            floatingBtn.classList.add("show");
        } else {
            floatingBtn.classList.remove("show");
        }
    }

    if (atBottom && !isAtBottom) {
        const floatingBookBtn = document.getElementById("floatingBookBtn");
        if (floatingBookBtn) {
            floatingBookBtn.style.display = "block";
        }
        isAtBottom = true;
    } else if (!isScrollingDown && isAtBottom) {
        const floatingBookBtn = document.getElementById("floatingBookBtn");
        if (floatingBookBtn) {
            floatingBookBtn.style.display = "none";
        }
        isAtBottom = false;
    }

    lastScrollTop = scrollTop;
});

// Function to close success message
function closeSuccessMessage() {
    const successMessage = document.getElementById("successMessage");
    if (successMessage) {
        successMessage.style.animation = "slideOutRight 0.3s ease-in forwards";
        setTimeout(() => {
            successMessage.remove();
        }, 300);
    }
}

document.addEventListener("DOMContentLoaded", () => {
    const success = document.getElementById("successMessage");

    if (!success) return;

    // slide in from the right
    setTimeout(() => {
        success.classList.add("show");
    }, 100);

    // auto hide
    setTimeout(() => {
        success.classList.remove("show");

        setTimeout(() => {
            success.remove();
        }, 400);
    }, 4000);
});
