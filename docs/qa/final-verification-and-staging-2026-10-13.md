# Final Verification, Deployment Readiness and QA Checklist — 2026-10-13

Branch `qa/towmate-retest-20261013` (code tip before this doc: `08eee95`). **Not deployed anywhere. No production deployment is authorized.**
Remote `main` is untouched (`ccd9f5f`).

## 1. Final test verification (developer-run; NOT QA execution)

Environment: Windows 11, PHP 8.2.12, PostgreSQL (local), database `towmate_jarz_testing`.

**Isolation.** A separately named database was tried and rejected by the project's own safety guard in `tests/TestCase.php` (`ALLOWED_TEST_DATABASE` is hard-coded to `towmate_jarz_testing`; the guard was deliberately not weakened). Isolation was instead established by checking that no other PHP/pest/node test process and no other connection to any `towmate*` database existed during the run (earlier runs had overlapped with another checkout, `TowMate-UI-Clone`, against the same database). The temporary database was dropped.

Per-directory results at the branch tip (`php artisan test <paths>`):

| Group | Result |
|---|---|
| tests/Unit | 2 failed, 7 passed |
| tests/Feature/Admin (all files except GroupedInvoiceVoidReplaceTest) | 26 failed, 29 risky, 603 passed |
| tests/Feature/Admin/GroupedInvoiceVoidReplaceTest | 14 passed |
| tests/Feature/Api | 386 passed |
| tests/Feature/Auth | 12 failed, 108 passed |
| tests/Feature/ControlCenter | 4 failed |
| tests/Feature/Customer | 3 failed, 18 passed |
| tests/Feature/Public | 14 passed |
| tests/Feature/Push | 1 risky, 19 passed |
| tests/Feature/Security | 15 failed, 193 passed |
| tests/Feature/SuperAdmin | 4 failed, 509 passed |
| tests/Feature/SystemAdmin | 136 passed |
| tests/Feature/TeamLeader | 10 risky, 141 passed |
| top-level Feature files (4) | 1 failed, 16 passed |
| **Total** | **67 failed, 40 risky, 2,164 passed** |
| `node tests/js/*.cjs` (11 files) | 9 pass, 2 fail (`booking-drawer-price-history`, `booking-drawer-unpriced-vehicles`) |

**The entire suite does not pass.** I am not claiming it does.

### Blocker in test infrastructure
`php artisan test` (whole suite in one process) aborts with `Cannot redeclare givrDispatcher()` — declared in both `tests/Feature/Admin/GroupInvoiceVatRateConsistencyTest.php` and `tests/Feature/Admin/GroupedInvoiceVoidReplaceTest.php`. Pre-existing, unchanged by this work. Suites must be run per directory until one helper is renamed. Related: `GroupFinancialCompletionTest` calls `gauDispatcher()`, defined in `GroupedAssignmentUpdateTest.php`, so it cannot run as a single file.

### The 67 failures vs the original baseline (commit `ccd9f5f`)
The 17 test files containing the failures were run on a separate checkout of `ccd9f5f`: **67 failed** there as well. Compared by test name: **0 new failures, 0 fixed** (65 distinct truncated names; two pairs collapse under name truncation). Causes (unchanged from the baseline):
- `UniqueConstraintViolationException` on `roles_pkey` (DispatchBookingActionsTest, DispatcherDashboardTest, ControlCenterTest: 27 tests) — tests insert fixed role IDs that the schema already seeds.
- `Route [landing.book] / [teamleader.dashboard] / [teamleader.task.accept] not defined` (LandingBookingCooldownTest, BookingSecurityEnhancementsTest, BookingRealtimeFlowTest, AuthenticationTest, RouteRegressionTest) — tests reference routes removed in the landing/staff-login redesign (`5810464`).
- Login-page copy/style assertions (LoginLockoutAndRecoveryTest 9, AuthenticationTest 2, AndroidAppReleaseTest 2) — assert the pre-redesign page.
- Others (BookingDrawerPriceHistoryUiTest runs the two failing JS checks; Owner settings / role-boundary; Phase5SecurityCategoryTest; VehicleFirstBackendSecurityTest; a purge command that no longer exists) — outdated assertions or removed features; not individually root-caused.
None of these touch code changed by this work. They were not edited.

### The two failing JS checks
Both pattern-match `public/dispatcher/js/booking-drawer.js` markup (`Price history (N adjustment)</span>`, `function previewVehicleTotal`). That file is not part of this change set, so they fail identically on the baseline.

### The 40 risky tests
Classification: PHPUnit "Test code or tested code closed output buffers other than its own". Cause: production code does `while (ob_get_level() > 0) { ob_end_flush(); }` when streaming a file response (`app/Http/Controllers/Admin/JobsController.php:253`, `app/Http/Controllers/Api/TeamLeader/TLTaskController.php:970`), which also closes PHPUnit's own buffer.
Classes: Admin `GroupFinancialCompletionTest` (21), `CompletionWorkflowTest` (6), `GroupPaymentExcludesCancelledVehicleTest` (1), `GroupInvoiceVatRateConsistencyTest` (1); Push `BookingStatusPushTest` (1); TeamLeader `GroupCompletionTotalConsistencyTest`, `TlDeferredInvoiceIssuanceTest`, `TlPostConfirmationLifecycleTest` (10).
Verified pre-existing: on `ccd9f5f` the same classes give identical counts (30 risky for the Admin+Push set, 10 for TeamLeader). The tests themselves pass. Not caused by this work; not fixed here.

### Intermittent `VehicleTypeTruckTypeMappingTest`
Failed once (in a regression run at the time another checkout was using the same test database); passed at the previous run, in the final isolated run (19/19), and in **12/12 consecutive isolated reruns**. The test uses `fake()->unique()->word()` suffixes but nothing time- or order-dependent that I could find. Cause **not proven**; most likely cross-process interference on the shared database. Status: not reproducible under isolation; watch in CI.

### Tests for this change set (all pass at branch tip)
`PasswordRecoveryHardeningTest` 17 · `StaffPasswordResetTest`/`Phase2Test` · `CustomerPasswordResetOtpTest` · `CustomerRiskFlagTest` 12 · `CustomerRiskAuditTest` · `ReportXlsxExportTest` 16 · `OwnerReportsPageTest` · `booking-detail-modal-risk-controls.test.cjs` 8 checks.

## 2. Deployment readiness (assessment)

| Check | Result |
|---|---|
| PHP version | PhpSpreadsheet 5.10.0 requires `php ^8.2`; project requires `^8.2`; local PHP 8.2.12. OK. |
| Extensions PhpSpreadsheet needs | ctype, dom, fileinfo, filter, gd, iconv, libxml, mbstring, simplexml, xml, xmlreader, xmlwriter, zip, zlib — all present locally. **Not verified on the deploy target.** The root `composer.json` declares no `ext-*` requirements, so a Nixpacks/Railway build may not install `zip`/`gd` automatically. Composer fails loudly if one is missing (no silent breakage), but this must be confirmed before deploy. |
| Clean `composer install` | Fresh clone of the pushed branch: **succeeds** (exit 0). Package install fast; **autoload generation took ~7 min** here (45,704 classes; earlier "hangs" were this). Production-safe. Non-fatal PSR-4 warnings for `app/_archive/*` are pre-existing. |
| Boot / caches in the clean clone | `package:discover`, `view:cache` (all Blade incl. new partial), `config:cache`, `route:cache` all succeed. (Run with a placeholder `.env`; `migrate:status` could not run without a database.) |
| Migrations | **None added** (no files under `database/` in the diff). Railway `releaseCommand` runs `migrate --force` plus two seeders — existing behaviour. |
| Config | No config or env changes. No secrets or stray files in the diff (reviewed: only app/resources/routes/tests/public-css-js/docs/composer files). |
| APK | **No rebuild required.** App version 1.0.5+6; mobile app navigates on `success` and only shows server text on errors. `public/downloads/towmate.apk` remains modified in the working tree from before this work and was not pushed. |
| `composer audit` | 2 advisories on `league/commonmark` (pre-existing, not introduced by this work). |

### Staging target: NOT identified
Nothing in the repo defines a staging environment. `railway.toml` is a single deploy config and `.env.example` sets `APP_URL=https://jarztowing.up.railway.app`, which appears to be the **live production** app. The Railway project/environment, whether it auto-deploys from branches, its database, and its mail/payment credentials are unknown to me. **Deployment has therefore not been attempted.** Required from the owner: the staging Railway project/environment (or other host) name and confirmation that it uses a non-production database and non-live mail/payment settings; confirmation that pushing `qa/towmate-retest-20261013` does not trigger an automatic production deploy.

### Proposed staging procedure (once a target is confirmed)
1. Create/choose a staging environment with its own database; set env vars from `.env.example`. **Use a test SMTP/mail sink** — password-recovery tests send real email if live Brevo credentials are configured.
2. Point it at branch `qa/towmate-retest-20261013` (commit = branch tip).
3. Ensure PHP 8.2 with the extensions above (consider adding `ext-zip`, `ext-gd` to `composer.json` `require` — a small change I have **not** made without approval).
4. Deploy; the `releaseCommand` runs migrations (none new) and seeders. Allow several minutes for `composer install` autoload generation.
5. Smoke test: log in as Owner, open Reports, export one XLSX; request a password reset; open a booking as Dispatcher.
6. Record build version (branch tip SHA), environment and tester in the retest log.

### Rollback plan
No schema changes, so rollback is code-only: redeploy `main` (`ccd9f5f`) in the staging environment (or revert the branch). `composer.lock` gains only additive packages and there is no data migration to undo. Password-reset storage (columns and hash format) is unchanged, so codes issued before a rollback keep working.

## 3. Manual QA retest checklists (all **Not Run**)

Environment prerequisites: staging build at the branch tip; test Owner, Dispatcher, Team Leader, Customer (mobile) accounts; test mailbox that QA can read; a few bookings (completed, cancelled, multi-vehicle) dated inside and outside the test range.

### TC004.02 — Password recovery
1. Web `/forgot-password` with blank, `abc`, `a@b`, a registered staff email, an unregistered email. Expect: required error; format error; the same exact confirmation and the same next page for both valid emails; mail arrives only for the registered one.
2. Mobile app "Forgot password" with a registered and an unregistered customer email: same confirmation text, same navigation.
3. Enter a wrong code, an expired code (wait > 5 min), a code from another account, then reuse an already-consumed code: each shows the same generic "invalid or expired" message.
4. Enter the correct code, set a new password; old password no longer works, new one does; other sessions/tokens are signed out.
5. Request repeatedly (> 3 times in 15 min) and enter many wrong codes: rate-limited without revealing account existence.
6. Try opening `/reset-password` directly and submitting without verification: refused.
7. Regression: normal sign-up, login and logout still work.

### TC015.12 — Customer risk flag
1. As Dispatcher open a booking > Details: mark a customer Medium with a reason; try without a reason (rejected); update to Blacklist; clear with a reason (reason required).
2. Badge appears on dispatch cards, Active Jobs, Active Bookings and Booking History (history shows "Current: …"); refresh and log out/in: still there; a new booking for the same customer shows it; other customers do not.
3. Booking status, price and assignment are unchanged by flagging; a flagged customer can still book.
4. As Owner / Team Leader / Customer: no way to change the flag (direct URL attempts denied); Owner activity log shows the entries with actor and time.
5. In the customer mobile app, confirm no risk information anywhere.
6. **Browser-based modal interaction has not been verified by me** — QA must exercise the Details modal controls in a real browser.

### TC028.03 — Excel export
1. As Owner open Reports; choose a custom range; press Export Booking / Fleet / Financial Excel.
2. **Open each downloaded file in Microsoft Excel** (not yet verified by me — only re-read with PhpSpreadsheet): sheets present, headings, numbers are numeric (sum a column), dates are dates, currency format.
3. Compare each figure with the on-screen report and the PDF for the same range; check a record created exactly at 00:00 on the first day and 23:59 on the last day is included, and one outside is not.
4. Empty range exports a valid file. A customer named `=1+1` appears as text prefixed with `'`, never calculated.
5. Dispatcher/Team Leader cannot download (403). PDF exports and the CSV link still work.

## 4. Remaining release blockers
1. **Staging target unidentified / production-looking URL** — owner must name and confirm a non-production environment before any deploy.
2. **Deploy-target PHP extensions** (`zip`, `gd` etc.) unverified.
3. **Manual QA not executed** for all three tests; Microsoft Excel and browser-modal behaviour unverified.
4. **Additional QA sign-off** of the revised TC004.02 expected result (owner decision recorded; QA approval per the project's process still pending).
5. **Test-suite health** (not caused by this work): 67 failing and 40 risky tests plus the duplicate-helper fatal prevent a green whole-suite run; recommend a separate clean-up task.
6. Intermittent `VehicleTypeTruckTypeMappingTest`: unproven cause; monitor.
