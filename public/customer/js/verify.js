document.addEventListener("DOMContentLoaded", () => {
    const openBtn = document.getElementById("openOtp");
    const modal = document.getElementById("otpModal");
    const successModal = document.getElementById("successModal");
    const inputs = document.querySelectorAll(".otp-inputs input");
    const confirmBtn = document.getElementById("confirmBtn");
    const errorBox = document.getElementById("otpError");
    const resendBtn = document.getElementById("resendBtn");
    const countdown = document.getElementById("countdown");
    const loader = document.getElementById("loader");
    const btnText = document.getElementById("btnText");

    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const baseUrl = window.location.origin;

    let timer;
    let timeLeft = 60;

    function startCountdown() {
        timeLeft = 60;
        resendBtn.disabled = true;
        resendBtn.classList.remove("active");

        timer = setInterval(() => {
            timeLeft--;
            countdown.textContent = `(${timeLeft}s)`;

            if (timeLeft <= 0) {
                clearInterval(timer);
                resendBtn.disabled = false;
                resendBtn.classList.add("active");
                countdown.textContent = "";
            }
        }, 1000);
    }

    async function sendOtp() {
        const res = await fetch(baseUrl + "/send-otp", {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "X-CSRF-TOKEN": csrf,
                Accept: "application/json",
            },
        });

        const data = await res.json();

        if (!res.ok) {
            throw new Error(data.error || "Failed to send OTP");
        }

        return data;
    }

    function getCode() {
        return [...inputs].map((i) => i.value).join("");
    }

    function checkComplete() {
        confirmBtn.disabled = getCode().length !== 4;
    }

    openBtn.addEventListener("click", async () => {
        modal.classList.remove("hidden");

        inputs.forEach((input) => (input.value = ""));
        confirmBtn.disabled = true;

        setTimeout(() => inputs[0].focus(), 200);

        try {
            await sendOtp();
            startCountdown();
            errorBox.textContent = "";
        } catch (err) {
            errorBox.textContent = err.message;
        }
    });

    resendBtn.addEventListener("click", async () => {
        if (resendBtn.disabled) return;

        resendBtn.disabled = true;
        resendBtn.innerText = "Sending...";

        try {
            await sendOtp();
            startCountdown();
            errorBox.textContent = "New OTP sent";
        } catch (err) {
            errorBox.textContent = err.message;
        }

        resendBtn.innerText = "Resend OTP";
    });

    inputs.forEach((input, index) => {
        input.addEventListener("input", () => {
            input.value = input.value.replace(/[^0-9]/g, "");

            if (input.value && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }

            checkComplete();

            if (getCode().length === 4) {
                confirmBtn.click();
            }
        });

        input.addEventListener("keydown", (e) => {
            if (e.key === "Backspace" && !input.value && index > 0) {
                inputs[index - 1].focus();
            }
        });
    });

    inputs[0].addEventListener("paste", (e) => {
        const paste = e.clipboardData.getData("text").trim();

        if (/^\d{4}$/.test(paste)) {
            inputs.forEach((input, i) => (input.value = paste[i]));
            checkComplete();
        }

        e.preventDefault();
    });

    confirmBtn.addEventListener("click", async () => {
        const code = getCode();

        if (code.length !== 4) {
            errorBox.textContent = "Enter complete 4-digit code";
            return;
        }

        errorBox.textContent = "";
        loader.classList.remove("hidden");
        btnText.textContent = "Verifying...";
        confirmBtn.disabled = true;

        let res;

        try {
            res = await fetch(baseUrl + "/verify-otp", {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrf,
                    Accept: "application/json",
                },
                body: JSON.stringify({ code }),
            });
        } catch {
            errorBox.textContent = "Network error";
            confirmBtn.disabled = false;
            loader.classList.add("hidden");
            btnText.textContent = "Confirm email";
            return;
        }

        const data = await res.json();

        loader.classList.add("hidden");
        btnText.textContent = "Confirm email";

        if (data.success) {
            modal.classList.add("hidden");
            successModal.classList.remove("hidden");

            setTimeout(() => window.location.reload(), 1500);
        } else {
            confirmBtn.disabled = false;
            errorBox.textContent = data.error || "Invalid OTP";

            const otpContainer = document.querySelector(".otp-inputs");
            otpContainer.classList.add("shake");

            setTimeout(() => otpContainer.classList.remove("shake"), 300);

            inputs.forEach((input) => (input.value = ""));
            inputs[0].focus();
        }
    });

    checkComplete();
});
