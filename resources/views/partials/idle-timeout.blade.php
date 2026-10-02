{{-- Idle session timeout (staff dashboards). Warning + logout thresholds come from config/session.php. --}}
<style>
    .idle-modal { position: fixed; inset: 0; z-index: 100000; display: none; align-items: center; justify-content: center; background: rgba(15, 23, 42, .6); padding: 16px; }
    .idle-modal.is-open { display: flex; }
    .idle-card { background: #fff; color: #0f172a; border-radius: 14px; max-width: 420px; width: 100%; padding: 24px; box-shadow: 0 20px 50px rgba(0, 0, 0, .3); font-family: inherit; }
    .idle-card h3 { margin: 0 0 8px; font-size: 20px; }
    .idle-card p { margin: 0 0 12px; font-size: 14px; line-height: 1.5; color: #475569; }
    .idle-countdown { font-size: 15px; font-weight: 700; color: #b91c1c; margin-bottom: 16px; }
    .idle-actions { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }
    .idle-actions button { border: 0; border-radius: 8px; padding: 10px 16px; font-size: 14px; font-weight: 600; cursor: pointer; }
    .idle-actions .idle-secondary { background: #e2e8f0; color: #0f172a; }
    .idle-actions .idle-primary { background: #f59e0b; color: #1f2937; }
</style>

<div class="idle-modal" id="idleModal" aria-hidden="true">
    <div class="idle-card" role="alertdialog" aria-modal="true" aria-labelledby="idleTitle" aria-describedby="idleDesc">
        <h3 id="idleTitle">Are you still there?</h3>
        <p id="idleDesc">For your security, you will be signed out after {{ (int) round(config('session.idle_timeout_seconds') / 60) }} minutes of inactivity.</p>
        <div class="idle-countdown" id="idleCountdown" aria-live="polite"></div>
        <div class="idle-actions">
            <button type="button" class="idle-secondary" id="idleLogoutBtn">Log out</button>
            <button type="button" class="idle-primary" id="idleStayBtn">Yes, I'm still here</button>
        </div>
    </div>
</div>

<script>
    window.IDLE_TIMEOUT_CONFIG = {
        warningSeconds: {{ (int) config('session.idle_warning_seconds') }},
        logoutSeconds: {{ (int) config('session.idle_timeout_seconds') }},
        keepAliveUrl: @json(route('session.keep-alive')),
        logoutUrl: @json(route('logout')),
        loginUrl: @json(route('login')),
    };
</script>
<script src="{{ asset('js/idle-timeout.js') }}?v={{ filemtime(public_path('js/idle-timeout.js')) }}" defer></script>
