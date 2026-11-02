# PayMongo sandbox pilot runbook (TEST mode only)

Status: local development only. No real PayMongo request has been made. Nothing here is for production,
and no real money can move: only `sk_test_` keys are accepted and live-mode responses/events are refused.

A payment never completes a booking. It is recorded as evidence in `payment_transactions`. The Team Leader
still completes the task (signature + proof) and the dispatcher still confirms payment.

## Current local state (prepared 2026-11-02; nothing has been sent to PayMongo)

- Database `towmate_pilot` exists and is fully migrated (163 migrations). It contains exactly one synthetic fixture:
  customer user id `1` (`pilot.customer@gmail.com`) and booking `0000001` at `arrived_dropoff`, PHP 100.00 (10,000 centavos).
- `.env.pilot` exists (git-ignored) with a fresh pilot-only `APP_KEY`, the test user id, and the tunnel URLs.
  Still EMPTY, to be filled by the owner: `DB_USERNAME`, `DB_PASSWORD`, `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SECRET`.
- `cloudflared` (official release 2026.10.7, checksum + signature verified) is at `%LOCALAPPDATA%\cloudflared\cloudflared.exe`;
  it is not on PATH.
- A quick tunnel gets a NEW hostname every time `cloudflared` starts; when it does, update `APP_URL` and the two
  `PAYMONGO_*_URL` lines in `.env.pilot` and re-create the dashboard webhook (its secret is per endpoint).
- Do not run pilot commands from a shell where `APP_ENV` is already set to something else, and never export the
  testing/dev `.env` values into the pilot shell: real environment variables override `.env.pilot`.

## Entering the two PayMongo secrets privately

Never paste them into chat or put them on a command line. In **your own** PowerShell window, from the repository root:

```
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\pilot\set-pilot-secrets.ps1
```

It asks for each value as hidden input (nothing is echoed or stored in history), accepts only an `sk_test_` key and a
`whsk_` webhook secret, edits only those two lines of `.env.pilot`, and refuses to run unless `.env.pilot` is git-ignored
and untracked. It prints only "configured". Tested with fake values in `scratchpad` (53 checks, including every refusal).

## Exact environment to use

| Item | Value |
|---|---|
| Environment name | `APP_ENV=pilot` (loads `.env.pilot`, **not** `.env`) |
| Database | a throw-away `towmate_pilot` (any name ending `_pilot`, `_testing`, `_sandbox`, `_isolated`) |
| Never use | `towmate_jarz` (shared dev) or any production database. The pilot commands refuse to run on them. |
| Mail / queue / broadcast | `log` / `sync` / `log` (set in `.env.pilot.example`), so nothing reaches real people |

`.env` points at the shared development database. Always set `APP_ENV=pilot` first, in every terminal used for the pilot.

## Required configuration names (in `.env.pilot`, git-ignored; values are yours, never paste them anywhere)

`APP_KEY`, `APP_URL` (the tunnel URL), `DB_DATABASE=towmate_pilot`, `DB_USERNAME`, `DB_PASSWORD`,
`PAYMONGO_SANDBOX_ENABLED=true`, `PAYMONGO_SANDBOX_ENVIRONMENTS=pilot`, `PAYMONGO_SECRET_KEY` (sk_test_),
`PAYMONGO_WEBHOOK_SECRET`, `PAYMONGO_SANDBOX_TEST_USER_IDS`, `PAYMONGO_SUCCESS_URL`, `PAYMONGO_CANCEL_URL`.
Copy `.env.pilot.example` to `.env.pilot` and fill the blanks.

## Endpoints

- Webhook to register: `https://<tunnel-host>/api/webhooks/paymongo` (POST)
- Return page for checkout redirects (confirms nothing): `https://<tunnel-host>/pilot/return`

## Safe HTTPS tunnel approach

Point the tunnel at the **pilot gateway** (`tools/pilot/webhook_gateway.php`), never at the Laravel app.
The gateway forwards only `POST /api/webhooks/paymongo` (body and signature untouched) and serves the static
return page. Every other path, method or variant is a 404 that never reaches Laravel (proven by
`tests/Concurrency/PilotWebhookGatewayTest.php`). If the app is down it answers 502, so PayMongo retries.

Tunnel options (none is installed on this machine; installing one is your call):

1. **cloudflared quick tunnel** (preferred): `cloudflared tunnel --url http://127.0.0.1:8002`. No account, free,
   real HTTPS, a `*.trycloudflare.com` domain name (PayMongo rejects IP addresses and non-https URLs).
2. **ssh reverse tunnel** (ssh is already installed): `ssh -R 80:127.0.0.1:8002 nokey@localhost.run`.
   No install, but a third-party relay; check its current behaviour before relying on it.
3. Avoid `ngrok` free (needs an account and shows an interstitial) and `localtunnel` (interstitial page).

A quick tunnel gets a **new URL every run**, so the webhook (and its secret) must be re-created each session.
Use only synthetic data: the tunnel can see the webhook payloads.

## Pilot checklist (each step needs the previous one)

0. **Approvals needed from the owner:** create the `towmate_pilot` database; migrate it; install a tunnel tool;
   register the webhook in PayMongo TEST mode; run the pilot checkout. Nothing below happens before that.
1. `psql -U postgres -h 127.0.0.1 -c "CREATE DATABASE towmate_pilot"`
2. Copy `.env.pilot.example` to `.env.pilot`; fill `APP_KEY`, `DB_*`, and `PAYMONGO_SECRET_KEY` (sk_test_).
3. Migrate the isolated DB: `$env:APP_ENV='pilot'; php artisan migrate --force`
4. Synthetic fixture: `$env:APP_ENV='pilot'; php artisan online-payments:make-pilot-fixture --amount=100.00`
   Note the printed **booking code** and **user id**; put the id in `PAYMONGO_SANDBOX_TEST_USER_IDS`.
5. Terminal A (app): `$env:APP_ENV='pilot'; php -S 127.0.0.1:8001 -t public server.php`
6. Terminal B (gateway): `$env:PILOT_TARGET='http://127.0.0.1:8001'; php -S 127.0.0.1:8002 tools/pilot/webhook_gateway.php`
7. Terminal C (tunnel): `cloudflared tunnel --url http://127.0.0.1:8002` -> note the `https://….trycloudflare.com` host.
8. Set `APP_URL`, `PAYMONGO_SUCCESS_URL`, `PAYMONGO_CANCEL_URL` in `.env.pilot` to that host; restart terminal A.
9. Check the tunnel is the narrow door: `https://<host>/` must answer `{"error":"not_found"}`; `https://<host>/pilot/return` answers the static text.
10. **PayMongo dashboard (TEST mode switch ON) -> Developers -> Webhooks -> Add endpoint:**
    URL `https://<host>/api/webhooks/paymongo`; events `checkout_session.payment.paid` (and optionally `payment.failed`).
    Copy the endpoint secret **immediately** (shown once) into `PAYMONGO_WEBHOOK_SECRET`; restart terminal A.
11. `$env:APP_ENV='pilot'; php artisan online-payments:check` must say **Ready for a controlled sandbox pilot**.
12. **Webhook-only checks (report separately from a real checkout):**
    - Dashboard "send test event" (if offered) -> gateway log `POST /api/webhooks/paymongo -> 200`; the app stores the event with outcome `unknown_session`; no transaction becomes paid.
    - A request with a wrong signature (e.g. `curl -X POST -H "Paymongo-Signature: t=1,te=abc,li=" -d "{}" https://<host>/api/webhooks/paymongo`) -> 401.
13. **Pilot (needs explicit owner approval):**
    `$env:APP_ENV='pilot'; php artisan online-payments:pilot-checkout <BOOKING_CODE> --user=<USER_ID> --confirm-sandbox`
14. Open the printed URL, choose GCash, then **Authorize** (success) on PayMongo's test page. No real GCash account is used.

## Expected outcomes

| Step | Success looks like | Failure looks like |
|---|---|---|
| Pilot command | `Test checkout created`, PHP 100.00, an `https://…paymongo.com/…` link | `Not started: … (gateway_error)` plus `Recorded failure: provider_rejected:<identifier>` / `provider_auth` / `provider_unexpected_response` |
| After Authorize | webhook arrives; gateway log shows `200`; transaction `paid`, no flag, `provider_payment_id` `pay_…`; event outcome `paid` | transaction `review` with a flag (`reference_mismatch`, `amount_mismatch`, `currency_mismatch`), or stays `pending` |
| After Fail | transaction stays `pending`; session is retryable until it expires (30 min) | – |
| Booking | still `arrived_dropoff`, `payment_method` empty, `payment_submitted_at` empty | – (anything else is a defect) |

## How to verify the payment really came through the signed webhook

1. Gateway terminal: one `POST /api/webhooks/paymongo -> 200`.
2. `payment_webhook_events`: a row with the `evt_…` id and outcome `paid` (the table stores a hash of the body, not the body).
3. `payment_transactions`: `status=paid`, `provider_payment_id` set, `amount_centavos=10000`, `flag` empty.
4. Resend the same event from the dashboard -> outcome `replay`, still exactly one paid transaction.
5. The booking row is unchanged. PayMongo dashboard (TEST mode) -> Payments shows the PHP 100.00 test payment and the Checkout Session.

A paid transaction is **not** a verified customer payment until the dispatcher confirms it through the existing flow.

## Why an unknown session is acknowledged with 200

A correctly signed event for a session we cannot match (dashboard test events, another environment) can never become
matchable, so a non-2xx would only make PayMongo retry it up to 12 times for nothing. It is therefore stored (audit
trail) and answered 200, and it is never recorded as paid. Non-2xx is kept for: bad/missing signature (401), malformed
envelope (400), our own transient failure such as the database being down (5xx, so PayMongo does retry), and the
gateway's 502 when the app is down.

Known gap: if the process dies between PayMongo creating a session and us saving its id, a later payment for that
session would also be acknowledged as `unknown_session`. The event row and the `reference_number` we sent still let a
human find it. Binding such an event by reference to a `review` row is a possible future hardening.

## Cleanup

1. Stop the tunnel (Ctrl+C), then the gateway, then the app.
2. PayMongo dashboard -> Webhooks: **delete** the pilot endpoint (this also retires its secret).
3. Open test sessions: after the 30-minute TTL run `$env:APP_ENV='pilot'; php artisan online-payments:reconcile`
   (it asks PayMongo before expiring, and records a payment it finds). Or expire the session in the dashboard.
4. Drop the throw-away data: `psql -U postgres -h 127.0.0.1 -c "DROP DATABASE towmate_pilot"` and delete `.env.pilot`.
5. Test keys are unchanged by all this; rotate the test key in the dashboard if it was ever exposed.

## Things the PayMongo docs do not settle (verify during the pilot, do not assume)

- The exact nesting of the v2 create response (both documented shapes are read; anything else is rejected).
- Whether `GET /v1/checkout_sessions/{id}` and `POST /v1/checkout_sessions/{id}/expire` work for v2 sessions.
- The real test checkout host (links outside `paymongo.com` are refused; widen `PAYMONGO_CHECKOUT_URL_HOSTS` only after seeing it).
- Whether GCash is enabled on the test account, and the error PayMongo returns if it is not.
- Whether `payment.failed` events carry our reference (failures may arrive unattributed; the session simply stays open).
- Whether success/cancel URLs are mandatory (they are set to the static return page to be safe).
