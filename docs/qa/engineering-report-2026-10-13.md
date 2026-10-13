# Engineering Report — TC004.02, TC015.12, TC028.03 (2026-10-13)

Branch `main`. Commits (local, **not pushed, not deployed**):
`1cf915a` TC004.02 · `a3d2c03` TC015.12 · `a0f734c` TC028.03 · `83765f2` fix-up (badge CSS, Google test message).

## Root causes and fixes

| Test | Root cause | Fix |
|---|---|---|
| TC004.02 | Recovery messages differed from the agreed wording; reset code verification/consumption was not atomic (concurrent requests could both pass); API `verify-otp` returned a distinct "expired" message that reveals whether a reset was requested for an account; no tests for blank/malformed email or concurrency. (Unknown emails already received a generic reply and the same navigation on the web flow.) | Both flows use the agreed confirmation; all invalid/expired/consumed/unknown codes return one message; verify and reset run in transactions with row locks (staff: otp row, then user row; API: user row) and compare-and-swap consumption. Rate limits, hashing, session/token revocation unchanged. |
| TC015.12 | Feature already existed (customer-wide, `markCustomerRisk`) but was only reachable from a returned-booking card, had no clear action and no booking badge; `Customer::risk_status_label` showed "Clear" for low/medium/high. | Added `clear-risk` (reason required, audited), row-locked updates, badge on dispatcher views (history labelled "Current:"), controls in the booking detail modal, risk fields hidden from model serialization. No schema change. |
| TC028.03 | No XLSX export existed; only PDF (and an unlinked CSV). | `phpoffice/phpspreadsheet` 5.10.0; owner-only `superadmin.reports.export-xlsx`; sheets Summary / Booking Report / Booking Details / Vehicle Report / Financial Report built from `buildSummary` (same data as page and PDF); formula-injection escaping; Export Excel buttons. |

### Staff OTP test F ("OTP collision") — investigated
The generator is random (`random_int`, CSPRNG), not time-based or deterministic. The test slept 61 s, but the staff resend cooldown is 300 s (`RESEND_COOLDOWN_SECONDS`), so the resend was rejected, only one code was ever issued, and both captured values were the same code. Invalid test assumption, fixed by waiting 301 s. Not a flake.

### Existing tests changed (reasons)
- Message assertions updated to the approved wording (StaffPasswordReset*, CustomerPasswordResetOtp, CustomerGoogleAuth).
- `verifiedResetSession` helpers now also create the verified code row that a real verify step leaves behind (the old helpers faked only session state, which the hardened reset correctly refuses). Assertions were not weakened.

## Migrations / dependencies
- Migrations: **none**.
- Composer: `phpoffice/phpspreadsheet ^5.10` (+ `maennchen/zipstream-php`, `markbaker/complex`, `markbaker/matrix`, `composer/pcre`). Needs ext-zip/xml/gd (present locally; verify on Railway).
- `composer audit` reports 2 advisories on `league/commonmark` — pre-existing, unrelated to this change.
- Note: the first `composer require` hung for ~20 min in its post-install step and was killed; autoload was regenerated with `composer dump-autoload --no-scripts`. Run a normal `composer install` on the deploy target.

## Test results (developer-run, PHP 8.2.12, Windows, local Postgres `towmate_jarz_testing`)

New tests: `PasswordRecoveryHardeningTest` (17), `CustomerRiskFlagTest` (12), `ReportXlsxExportTest` (16), `booking-detail-modal-risk-controls.test.cjs` (8 checks).

| Command | Result |
|---|---|
| `php artisan test tests/Feature/Auth/PasswordRecoveryHardeningTest.php tests/Feature/Auth/StaffPasswordResetTest.php tests/Feature/Auth/StaffPasswordResetPhase2Test.php tests/Feature/Api/CustomerPasswordResetOtpTest.php` | 65 passed |
| `php artisan test tests/Feature/Admin/CustomerRiskFlagTest.php tests/Feature/Admin/CustomerRiskAuditTest.php tests/Feature/Admin/BookingHistoryPageTest.php tests/Feature/Api/CustomerGoogleAuthTest.php` | 66 passed |
| `php artisan test tests/Feature/SuperAdmin/ReportXlsxExportTest.php tests/Feature/SuperAdmin/OwnerReportsPageTest.php` | 26 passed |
| `node tests/js/booking-detail-modal-*.test.cjs` | pass |
| Regression after `83765f2`: Admin (minus GroupedInvoiceVoidReplaceTest) + SuperAdmin + Auth + Api | 1605 passed, 43 failed, 29 risky |

Other directories (Unit, Customer, ControlCenter, Public, Push, Security, SystemAdmin, TeamLeader, top-level files) were run once before the fix-up commit `83765f2`; their failures were all present on the baseline commit (see below) except the three fixed regressions. They were not re-run after the fix-up.

**A single whole-suite run is impossible and was already so before this work:** `php artisan test` aborts with `Cannot redeclare givrDispatcher()` (declared in both `GroupInvoiceVatRateConsistencyTest.php` and `GroupedInvoiceVoidReplaceTest.php`). Suites were therefore run per directory; `GroupedInvoiceVoidReplaceTest` separately (14 passed).

### Pre-existing failures (baseline commit `ccd9f5f`, same test files, separate worktree)
The 17 failing test files fail identically at `ccd9f5f` (67 failed / 175 passed there). 62 of the 65 distinct failing tests seen in my first per-directory run fail at baseline too; the other 3 were mine (below). Causes:
- `roles_pkey` unique violations (DispatchBookingActionsTest, DispatcherDashboardTest, ControlCenterTest): tests insert fixed role ids that migrations already seed.
- `Route [landing.book] / [teamleader.dashboard] / [teamleader.task.accept] not defined` (LandingBookingCooldownTest, BookingSecurityEnhancementsTest, BookingRealtimeFlowTest, AuthenticationTest, RouteRegressionTest): tests reference routes absent after the landing-page / staff-login redesign (`5810464`).
- Login-page copy/style assertions (LoginLockoutAndRecoveryTest, AuthenticationTest, AndroidAppReleaseTest): assert the pre-redesign login page ("Welcome back", background image, etc.).
- Others (BookingDrawerPriceHistoryUiTest node run, Owner settings/role-boundary, Phase5SecurityCategoryTest, VehicleFirstBackendSecurityTest, a purge command that no longer exists): not analysed in depth.
These were **not** edited (no production behaviour changed to satisfy outdated tests; no tests weakened).

### Regressions I introduced and fixed
Three tests failed only after my changes: 2x BookingHistoryPageTest (the badge partial emitted a `<style>` inside table cells) and 1x CustomerGoogleAuthTest (old message). Fixed in `83765f2`; all pass.

### Intermittent, cause not established
`VehicleTypeTruckTypeMappingTest > it derives the extra vehicle truck type…` failed once in the final regression run, passed in the earlier run and in 2/2 isolated reruns. Other `php artisan test` processes from a different checkout (`TowMate-UI-Clone`) were running against the same `towmate_jarz_testing` database during my runs, which can cause cross-test interference; I did not confirm this is the cause.

## Limitations / risks
- Concurrency is verified by simulating a competing request between read and write (`DB::beforeExecuting`) plus the lock/CAS design; no true multi-process race test was run.
- Unknown-vs-known email timing difference (bcrypt only runs for known accounts) is not equalised.
- Audit wording for risk is unchanged (medium/high are logged as "Watchlist"); the badge shows the specific level. Cosmetic inconsistency.
- Control Center "flagged customers" still counts only watchlist/blacklisted, not low/medium/high.
- The Booking Details sheet is capped at 10,000 rows (noted in the workbook).
- XLSX was not opened in Microsoft Excel; it was validated by re-reading with PhpSpreadsheet only.
- Login lockout recovery (`LoginUnlockController`) is a separate flow and was not changed.
- Mobile app unchanged: it navigates on `success` and shows server error text; no APK rebuild required.
- The UI controls in the booking detail modal were checked by source-level JS tests and server-side tests only; no browser run.

## QA build
No mobile artifact is affected (app `1.0.5+6`; the existing `public/downloads/towmate.apk` was already modified in the working tree before this work and was not touched). This is a **backend/web** release: deploy `main` at the latest commit to a staging environment with `composer install`. The Railway `releaseCommand` runs `php artisan migrate --force`; there are no new migrations. No build timestamp, checksum or downloadable artifact exists, because no build was produced.
