# Production deployment and rollback plan — TowMate QA fixes

**Status: PREPARED ONLY. Nothing has been deployed to production. Requires the project owner's explicit approval.**

Release candidate: branch `qa/towmate-retest-20261013` at the commit approved for release (currently `2101273`; re-confirm the SHA before deploying).
Production today: Railway project `humorous-fulfillment`, environment `production`, service `TowMate-for-Jarz` (GitHub `azurefaon/TowMate-for-Jarz`, branch `main`, currently `ccd9f5f`), database service `Postgres`. Domains: jarztowing.up.railway.app, jarztowing.com, www.jarztowing.com.

## What the release changes
- Password recovery (web + customer API): uniform confirmation text, one generic invalid-code message, atomic single-use codes. Mobile app needs no rebuild.
- Customer risk flag: clear action, badges on dispatcher views, controls in the booking drawer / detail modal.
- Excel export for booking, fleet and financial reports (new dependency `phpoffice/phpspreadsheet` 5.10.0).
- Removal of the dead `/api/v1/bookings` resource routes (they returned 500; the mobile app never used them).
- `composer.json` now requires `ext-gd` and `ext-zip`.
- **No database migrations. No config changes. No APK change.**

## Verified so far (on staging, not production)
Staging was built from this branch with `composer.json` alone (no extension variable): `install-php-extensions` list included `gd zip`; PHP 8.2.34. Smoke-tested over HTTP and in real Chrome; the three exported workbooks were opened in real Microsoft Excel (no errors, numbers/dates typed correctly, formula text stored as text). Targeted PHP tests pass; remaining failures are the pre-existing baseline set (none new).

## Verified on production (read-only) — 2026-10-14
- **Backup taken and verified:** `pg_dump -Fc` run inside the production Postgres container (PostgreSQL 18.6, DB `railway`, 13 MB). Archive fully read back with `pg_restore` (OK), 40 tables with data, 338,525 bytes, SHA-256 `cf1896426681b88a984a1e680049c4c22090b06e32e152affc3dc52e4784ad7c`, copied to `C:\Users\caval\TowMate_backups\towmate_prod_20261014T030123Z.dump` (+ `.sha256` and exact row counts: users 24, customers 31, bookings 116, quotations 101, invoices 12, audit_logs 2250, migrations 158). **Contains customer data — move it to secure storage; it is deliberately outside the OneDrive-synced folders and the repo.** A test *restore* was not performed (copying production data into staging was declined as a privacy risk); restoring uses `pg_restore -d <db> --clean --if-exists <file>`.
- **Migrations:** production has 158 applied, same as the branch → no pending migration.
- **Mail:** `MAIL_MAILER=brevo`; Brevo API key accepted (HTTP 200 on the account endpoint); sender `MAIL_FROM_ADDRESS` is a registered, active Brevo sender; plan is the **free tier, 300 emails/day**. No email was sent by these checks.
- **Queue:** `QUEUE_CONNECTION=database`; a `queue:work` process is running (since Oct 3); 0 pending jobs; 32 old failed jobs, latest 2026-08-21 (pre-existing).
- **PHP runtime:** 8.2.27 with `gd` and `zip` already loaded (so the PhpSpreadsheet requirement is already satisfied at runtime), `memory_limit=128M`.
- **Backup location:** the dump, its `.sha256` and the row counts are on the owner's PC at `C:\Users\caval\TowMate_backups\` — outside Railway, but a **single copy**; the SHA-256 was re-verified on 2026-10-14 (matches). Keep a second copy in secure storage until the release is stable.
- **Export truncation is not silent:** with 5,001 source bookings the "Booking Details" sheet lists 5,000, its total row reads "Total (5000 bookings listed)", a note reads "Only the first 5,000 bookings are listed. Narrow the date range or filters to export the rest.", and the "Booking Report" sheet still shows the full 5,001 (verified 2026-10-14). The fleet and financial workbooks are not affected by the cap.
- **Export memory:** measured peak ~102 MB at 5,000 booking rows and ~134 MB at 10,000, so the workbook's detail-row cap was lowered to 5,000 (commit after `fb1c8a2`). Production holds 116 bookings in total, so normal ranges are tiny.

## Pre-flight (before pressing anything)
1. Owner approves the release and confirms the exact commit SHA.
2. Merge path decision (owner): production builds from `main`. Either (a) open a pull request `qa/towmate-retest-20261013` -> `main` and merge it after review, or (b) fast-forward `main`. Do not force-push. **Merging to `main` triggers an automatic production deploy**, so treat the merge itself as the deploy button.
3. **Database backup: done** (see "Verified on production"). If the merge is delayed by more than a day, take a fresh one: `railway ssh --service Postgres --environment production -- sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc -f /tmp/<name>.dump'` and copy it out. The release has no migrations, so this is a safety net only.
4. The current production deployment is `007d44f0-1efc-45e5-9c77-09c3aaff0722` (`ccd9f5f`, SUCCESS) — recorded for reference only; rollback uses the clean-export method below.
5. Choose a low-traffic window; allow ~10 minutes (Railway build 3-5 min; `composer install` autoload generation is slow).
6. Confirm production has the `SUPERADMIN_*`/mail variables it already uses; **do not change any variables**. (Do not copy staging variables: staging uses `MAIL_MAILER=log`, which would stop real emails.)

## Deploy
1. Merge to `main` (or fast-forward) as decided above. Railway builds and runs the release command (`migrate --force` — a no-op here — and the two idempotent seeders).
2. Watch: Railway dashboard -> Deployments, or `railway service status --service TowMate-for-Jarz --environment production` (read-only).
3. Build-failure signature to expect only if extensions are missing: `phpoffice/phpspreadsheet requires ext-gd ... missing`. This was resolved on staging by the `composer.json` requirement; if production still fails, the old deployment stays live (Railway keeps serving the last healthy deployment) — then add the service variable `RAILPACK_PHP_EXTENSIONS=gd,zip` and redeploy.

## Production smoke test (about 10 minutes, use real but non-customer-impacting actions)
- `GET /login` 200; Owner login works.
- Owner -> Reports -> Export Financial Excel: file downloads; open it; Export PDF still works.
- Dispatcher -> Dispatch queue: open a booking row; the Customer risk section is present; **do not flag a real customer** (or flag a test customer and clear it with a reason).
- Staff "Forgot password" with an unregistered address: shows the generic confirmation (no email is sent). Do not trigger resets for real users.
- Mobile app: sign in, view booking history (`/api/v1/bookings/history` returns 200).
- Watch Railway logs for new 500s for 15–30 minutes.

## Rollback
Trigger if: build ok but login broken, repeated 500s, reports/dispatch pages broken, or password recovery failing for real users.

**Important (verified 2026-10-14):** Railway marks superseded deployments `REMOVED` (all deployments before the current `ccd9f5f` one already are), so the dashboard "Redeploy" button on the old deployment should **not** be relied on. The rollback below was **rehearsed on staging**: deploying the previous code took ~3 minutes and staging then served the old code (new route 404).

1. **Fast (about 3 minutes) — deploy the previous code from a clean export:**
   ```
   mkdir C:\tmq_rollback ; git archive ccd9f5f | tar -x -C C:\tmq_rollback
   cd C:\tmq_rollback
   railway up --project 81ffd7ff-741f-4d0a-8a44-55e3697cecf9 --environment production --service TowMate-for-Jarz --detach
   ```
   (use the exact `--environment production` flag deliberately; this is the only command in this plan that targets production by CLI).
2. **Then make it stick (same day):** revert on `main` and push (never force-push): `git revert -m 1 <merge-commit>` if the PR was merged with a merge commit, or plain `git revert <commit>` if it was squash- or rebase-merged (`-m 1` only applies to merge commits). Until `main` is reverted, the next push to `main` would redeploy the new code.
3. If the rollback upload also fails, the database is untouched and no data is at risk; fix forward or contact the owner.
3. **Data:** nothing to roll back — no migrations. Data written while the new code was live stays valid for the old code: customer risk uses the existing `customers.risk_*` columns; audit rows are additive; password-reset storage columns and hash formats are unchanged, so codes issued before or after remain usable.
4. Keep the DB backup until the release has been stable for a few days.

## Known issues that do NOT block this release
- 67 failing and 40 risky automated tests and a duplicate test-helper fatal predate this work (identical on `ccd9f5f`); `GET /api/v1/bookings` is now 405 instead of 500.
- "Average Revenue per Completed Booking" counts only completed jobs with a payment record (existing behavior).
- One intermittent test (`VehicleTypeTruckTypeMappingTest`) fails only when run together with many other files; passes alone (389/389 in the Api directory) — cause not established.

## Still unverified before go-live
- Real email delivery of reset codes on production SMTP (staging deliberately had email disabled). Test with an internal mailbox right after deploy.
- Mobile app against production after deploy (no app change, but not run by me).
- **Formal human QA sign-off is PENDING.** The three retests were executed on staging on 2026-10-14 by an AI assistant (Chrome automation, HTTP, Microsoft Excel COM) and recorded as Passed in `docs/qa/retest-log-TC004.02-TC015.12-TC028.03.md` with evidence, but a human QA tester has not yet executed or signed them. The historical Failed results are preserved unchanged; the sign-off table in that log is blank until QA completes it.
