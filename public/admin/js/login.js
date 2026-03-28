document.addEventListener("DOMContentLoaded", () => {
    const passwordInput = document.getElementById("password");
    const eyeIcon = document.getElementById("togglePassword");

    const loginBtn = document.getElementById("loginBtn");
    const loginForm = document.getElementById("loginForm");

    const emailLogin = document.getElementById("emailLogin");
    const phoneLogin = document.getElementById("phoneLogin");
    const registerOptions = document.getElementById("registerOptions");
    const registerEmail = document.getElementById("registerEmail");

    const signupTab = document.getElementById("signupTab");
    const phoneTab = document.getElementById("phoneTab");
    const emailAlt = document.getElementById("emailAlt");

    const registerEmailBtn = document.getElementById("registerEmailBtn");
    const registerPhoneBtn = document.getElementById("registerPhoneBtn");

    const backBtn = document.getElementById("backToOptions");
    const backToLoginMain = document.getElementById("backToLoginMain");
    const backToRegisterOptions = document.getElementById(
        "backToRegisterOptions",
    );

    const sendOtpBtn = document.getElementById("sendOtpBtn");
    const phoneInput = document.getElementById("phoneInput");
    const otpGroup = document.getElementById("otpGroup");

    if (loginForm && loginBtn) {
        loginForm.addEventListener("submit", () => {
            loginBtn.classList.add("loading");
        });
    }

    function hideAll() {
        if (emailLogin) emailLogin.style.display = "none";
        if (phoneLogin) phoneLogin.style.display = "none";
        if (registerOptions) registerOptions.style.display = "none";
        if (registerEmail) registerEmail.style.display = "none";
    }

    if (signupTab) {
        signupTab.onclick = () => {
            hideAll();
            registerOptions.style.display = "block";
        };
    }

    if (phoneTab) {
        phoneTab.onclick = () => {
            hideAll();
            phoneLogin.style.display = "block";
        };
    }

    if (emailAlt) {
        emailAlt.onclick = () => {
            hideAll();
            emailLogin.style.display = "block";
        };
    }

    if (registerEmailBtn) {
        registerEmailBtn.onclick = () => {
            hideAll();
            registerEmail.style.display = "block";
        };
    }

    if (registerPhoneBtn) {
        registerPhoneBtn.onclick = () => {
            hideAll();
            phoneLogin.style.display = "block";
        };
    }

    if (backBtn) {
        backBtn.onclick = () => {
            hideAll();
            emailLogin.style.display = "block";
        };
    }

    if (backToLoginMain) {
        backToLoginMain.onclick = () => {
            hideAll();
            emailLogin.style.display = "block";
        };
    }

    if (backToRegisterOptions) {
        backToRegisterOptions.onclick = () => {
            hideAll();
            registerOptions.style.display = "block";
        };
    }

    if (eyeIcon && passwordInput) {
        eyeIcon.onclick = () => {
            passwordInput.type =
                passwordInput.type === "password" ? "text" : "password";
        };
    }

    if (sendOtpBtn && phoneInput) {
        sendOtpBtn.disabled = true;

        phoneInput.addEventListener("input", () => {
            sendOtpBtn.disabled = phoneInput.value.length < 11;
        });

        sendOtpBtn.onclick = () => {
            if (phoneInput.value.length < 11) {
                alert("Please enter valid phone number");
                return;
            }

            if (otpGroup) otpGroup.style.display = "block";
        };
    }
});
