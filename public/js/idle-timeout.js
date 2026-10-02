(function () {
    'use strict';

    var cfg = window.IDLE_TIMEOUT_CONFIG;
    var modal = document.getElementById('idleModal');
    if (!cfg || !modal) return;

    var WARN_MS = cfg.warningSeconds * 1000;
    var LOGOUT_MS = cfg.logoutSeconds * 1000;
    var KEEPALIVE_THROTTLE_MS = 2 * 60 * 1000;
    var ACTIVITY_THROTTLE_MS = 1000;
    var KEY_ACTIVITY = 'towmate:idle:lastActivity';
    var KEY_KEEPALIVE = 'towmate:idle:lastKeepAlive';
    var KEY_LOGOUT = 'towmate:idle:logout';

    var countdownEl = document.getElementById('idleCountdown');
    var stayBtn = document.getElementById('idleStayBtn');
    var logoutBtn = document.getElementById('idleLogoutBtn');
    var lastActivity = Date.now();
    var lastKeepAlive = 0;
    var serverDirty = false;
    var keepAliveInFlight = false;
    var warningOpen = false;
    var loggingOut = false;
    var previousFocus = null;

    function readShared() {
        try { return parseInt(localStorage.getItem(KEY_ACTIVITY), 10) || 0; } catch (e) { return 0; }
    }

    function writeShared(ts) {
        try { localStorage.setItem(KEY_ACTIVITY, String(ts)); } catch (e) { /* storage unavailable */ }
    }

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }

    function redirectToLogin() {
        loggingOut = true;
        window.location.href = cfg.loginUrl;
    }

    function readSharedKeepAlive() {
        try { return parseInt(localStorage.getItem(KEY_KEEPALIVE), 10) || 0; } catch (e) { return 0; }
    }

    // Tells the server the user is genuinely active. Only ever called from user-driven
    // paths (activity handler / "still here" click) or the flush in tick() below, which
    // itself only runs when a real interaction made the server clock stale.
    function keepAlive(force) {
        if (keepAliveInFlight && !force) return;
        var sentAt = Date.now();
        keepAliveInFlight = true;
        serverDirty = false;
        lastKeepAlive = sentAt;
        try { localStorage.setItem(KEY_KEEPALIVE, String(sentAt)); } catch (e) { /* ignore */ }
        fetch(cfg.keepAliveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        }).then(function (res) {
            if (res.status === 401 || res.status === 419) redirectToLogin();
            else if (!res.ok) serverDirty = true;
        }).catch(function () {
            serverDirty = true; // network blip: retry on a later tick, server still enforces the timeout
        }).then(function () { keepAliveInFlight = false; });
    }

    // Trailing flush: activity that happened inside the throttle window still reaches the
    // server, so the server clock never lags the browser clock by more than the throttle.
    function flushKeepAlive() {
        if (!serverDirty || warningOpen || loggingOut || keepAliveInFlight) return;
        var sharedLast = Math.max(lastKeepAlive, readSharedKeepAlive());
        if (sharedLast >= lastActivity) { serverDirty = false; return; } // another tab already synced
        if (Date.now() - sharedLast < KEEPALIVE_THROTTLE_MS) return;
        keepAlive(false);
    }

    function markActive(ts) {
        lastActivity = ts;
        writeShared(ts);
    }

    // Genuine user interaction only. Network requests and timers never call this.
    function onUserActivity(e) {
        if (e && e.isTrusted === false) return;
        if (warningOpen || loggingOut) return; // once warned, require explicit confirmation
        var now = Date.now();
        if (now - lastActivity < ACTIVITY_THROTTLE_MS) return;
        markActive(now);
        serverDirty = true;
        flushKeepAlive();
    }

    ['mousedown', 'keydown', 'touchstart', 'wheel', 'scroll'].forEach(function (evt) {
        window.addEventListener(evt, onUserActivity, { passive: true, capture: true });
    });
    window.addEventListener('mousemove', onUserActivity, { passive: true });

    function openWarning() {
        if (warningOpen) return;
        warningOpen = true;
        previousFocus = document.activeElement;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        stayBtn.focus();
    }

    function closeWarning() {
        warningOpen = false;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        if (previousFocus && previousFocus.focus) {
            try { previousFocus.focus(); } catch (e) { /* element gone */ }
        }
    }

    function format(ms) {
        var s = Math.max(0, Math.ceil(ms / 1000));
        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }

    function doLogout() {
        if (loggingOut) return;
        loggingOut = true;
        try { localStorage.setItem(KEY_LOGOUT, String(Date.now())); } catch (e) { /* ignore */ }
        var body = new URLSearchParams({ _token: csrf() });
        fetch(cfg.logoutUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        }).catch(function () { /* fall through to redirect */ })
            .then(function () { window.location.href = cfg.loginUrl; });
    }

    function tick() {
        if (loggingOut) return;
        flushKeepAlive();
        // Pick up activity from other tabs; timestamps (not counters) also survive sleeping tabs.
        var shared = readShared();
        if (shared > lastActivity) lastActivity = shared;

        var idle = Date.now() - lastActivity;
        if (idle >= LOGOUT_MS) {
            doLogout();
        } else if (idle >= WARN_MS) {
            openWarning();
            countdownEl.textContent = 'Signing out in ' + format(LOGOUT_MS - idle);
        } else if (warningOpen) {
            closeWarning(); // another tab confirmed "still here"
        }
    }

    stayBtn.addEventListener('click', function () {
        markActive(Date.now());
        closeWarning();
        keepAlive(true); // explicit confirmation always reaches the server immediately
    });

    logoutBtn.addEventListener('click', doLogout);

    // Keep focus inside the dialog; no backdrop dismissal and Escape does nothing.
    modal.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') return;
        if (e.shiftKey && document.activeElement === logoutBtn) { e.preventDefault(); stayBtn.focus(); }
        else if (!e.shiftKey && document.activeElement === stayBtn) { e.preventDefault(); logoutBtn.focus(); }
    });

    window.addEventListener('storage', function (e) {
        if (e.key === KEY_LOGOUT && e.newValue) redirectToLogin();
    });

    // A page load is a user navigation, which the server also counts as activity.
    markActive(Date.now());
    document.addEventListener('visibilitychange', tick);
    setInterval(tick, 1000);
    tick();
})();
