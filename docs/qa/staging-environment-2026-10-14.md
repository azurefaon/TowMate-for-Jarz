# Staging environment — TowMate QA (created 2026-10-14)

**URL:** https://towmate-staging-staging.up.railway.app
**Code deployed:** QA branch `qa/towmate-retest-20261013`, commit `8200a81` (= approved `383f90f` plus one bug-fix commit, see below). Docs commits after it do not change the running code.
**Production:** not touched. Still `main` @ `ccd9f5f`, domains `jarztowing.up.railway.app` / `jarztowing.com`.

Credentials are **not** stored in the repo; they were handed to the project owner in the session.

## How it is isolated
| Concern | Staging | Production |
|---|---|---|
| Railway environment | `staging` (new, created empty — NOT duplicated from production) | `production` |
| Web service | `towmate-staging` (empty service, code uploaded with `railway up`; **no GitHub link, no auto-deploy**) | `TowMate-for-Jarz` (GitHub, branch `main`) |
| Database | `Postgres-Hufb` — a brand-new Postgres inside `staging` (host `postgres-hufb.railway.internal`) | `Postgres` |
| Secrets | new `APP_KEY`, new owner password; no production variables copied | unchanged |
| Email | `MAIL_MAILER=log` — nothing is sent anywhere; no SMTP/Brevo key exists in staging. Mail bodies are written to the app log (`LOG_LEVEL=debug`). | real SMTP |
| Third-party keys | none set (Google, payments, push, maps all absent) | configured |
| Seeded data | owner + test accounts + 4 QA bookings only | real data |

## Reading a password-reset code on staging
Reset emails are not delivered; they appear in the staging service **Deploy Logs** (Railway dashboard → project `humorous-fulfillment` → environment `staging` → `towmate-staging` → Logs). Search for `Subject: TowMate Password Verification Code`; the 6-digit code is in the HTML body inside the OTP block.

## Build requirement discovered (affects production later)
Railway's PHP builder installs only a fixed extension list (`ctype curl dom fileinfo filter hash mbstring openssl pcre pdo session tokenizer xml pdo_pgsql`). `phpoffice/phpspreadsheet` needs `ext-gd` and `ext-zip`, so the first staging build **failed** at `composer install` ("requires ext-gd … missing"). Staging fixes this with the service variable `RAILPACK_PHP_EXTENSIONS=gd,zip`.
**Production would fail the same way** when this code is released. Before any production deploy either set the same variable on the production service or (preferred, permanent) add `ext-gd` and `ext-zip` to `composer.json` `require` — a code change that has NOT been made.

## Redeploying staging
Export a clean copy of the commit and upload it (never from the working tree, which contains unrelated changes):
```
mkdir C:\tmq_stage3 ; git archive <commit> | tar -x -C C:\tmq_stage3
cd C:\tmq_stage3
railway up --project 81ffd7ff-741f-4d0a-8a44-55e3697cecf9 --environment staging --service towmate-staging --detach
```
Always pass `--environment staging`. Build + release (migrations, seeders) takes ~3–4 min.

## Teardown
`railway environment delete staging` (removes `towmate-staging`, `Postgres-Hufb` and its data). Two empty service shells named `Postgres--L2v` and `Postgres-QxD9` were created by retries and removed from the environment; they may still appear in the project's service list and can be deleted in the dashboard.

## Bug found by the staging smoke test
The Customer Risk controls added to the booking detail modal built their URLs from the numeric booking `id`, but `Booking` routes by `booking_code`, so the browser buttons would have returned 404 (the PHP tests used `route()` and could not see it). Fixed in `8200a81` (URLs now use `booking_code`) with a JS regression check, and re-verified on staging.

## Smoke test results (HTTP-level, run against the staging URL on 2026-10-14)
All passed unless noted. These are developer smoke tests, **not QA execution**; the retest log stays "Not Run".
- **Password recovery (web):** blank and malformed email rejected; registered and unregistered email → same status, same next page, identical confirmation text; wrong code → generic message; correct code → reset page → password changed; code cannot be reused; new password logs in, old one does not.
- **Password recovery (customer API):** blank/malformed → 422; registered vs unregistered → identical responses; exactly one email logged for the registered account, none for the unknown address; wrong code vs unknown account → identical response; reset works once, token and code cannot be reused; new password logs in, old one is rejected.
- **Customer risk flag:** reason required to mark and to clear; High risk badge appears on booking history ("Current: High risk") and dispatch page; persists after a new login; a different customer is unaffected; Owner → 403 and anonymous → 419 when trying to change it; customer-facing APIs (login, profile, history, current, detail) contain no risk data; clearing removes the badge.
- **Excel export:** booking / vehicle / financial `.xlsx` download as real workbooks; contents match the seeded data (4 bookings, 2 completed totalling 5,136.50, per-unit split); PDF export still works; invalid section rejected; Dispatcher → 403.

## Known, not caused by this change
- `GET /api/v1/bookings` returns 500: the legacy route points to `BookingController::index()`, which does not exist (`routes/api.php` is unchanged by this work).
- "Average Revenue per Completed Booking" shows 0 on the page, PDF and Excel for the seeded data because the metric only counts completed jobs with a payment record.

## Not verified
Microsoft Excel opening the files; the booking-detail modal in a real browser (the HTTP calls it makes were tested, the clicks were not); delivery of real email (intentionally disabled).
