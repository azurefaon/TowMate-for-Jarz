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

## Pre-flight (before pressing anything)
1. Owner approves the release and confirms the exact commit SHA.
2. Merge path decision (owner): production builds from `main`. Either (a) open a pull request `qa/towmate-retest-20261013` -> `main` and merge it after review, or (b) fast-forward `main`. Do not force-push. **Merging to `main` triggers an automatic production deploy**, so treat the merge itself as the deploy button.
3. Take a **database backup** of the production `Postgres` service (Railway dashboard -> Postgres -> Backups, or `pg_dump` via the Railway connection string) and note the timestamp. The release has no migrations, so this is a safety net only.
4. Record the current production deployment id (Railway dashboard -> TowMate-for-Jarz -> Deployments) so it can be redeployed instantly.
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
1. **Fast (about 2 minutes):** Railway dashboard -> TowMate-for-Jarz -> Deployments -> the deployment recorded in pre-flight (`ccd9f5f`) -> Redeploy. (Config for the service is unchanged, so this restores the previous code exactly.)
2. **Git-level:** `git revert -m 1 <merge-commit>` on `main` and push (never force-push); Railway redeploys the revert.
3. **Data:** nothing to roll back — no migrations. Data written while the new code was live stays valid for the old code: customer risk uses the existing `customers.risk_*` columns; audit rows are additive; password-reset storage columns and hash formats are unchanged, so codes issued before or after remain usable.
4. Keep the DB backup until the release has been stable for a few days.

## Known issues that do NOT block this release
- 67 failing and 40 risky automated tests and a duplicate test-helper fatal predate this work (identical on `ccd9f5f`); `GET /api/v1/bookings` is now 405 instead of 500.
- "Average Revenue per Completed Booking" counts only completed jobs with a payment record (existing behavior).
- One intermittent test (`VehicleTypeTruckTypeMappingTest`) fails only when run together with many other files; passes alone (389/389 in the Api directory) — cause not established.

## Still unverified before go-live
- Real email delivery of reset codes on production SMTP (staging deliberately had email disabled). Test with an internal mailbox right after deploy.
- Mobile app against production after deploy (no app change, but not run by me).
- Formal QA sign-off: all three retests are still "Not Run" in `docs/qa/retest-log-TC004.02-TC015.12-TC028.03.md`.
