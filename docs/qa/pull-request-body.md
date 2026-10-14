# Title
Fix TC004.02 / TC015.12 / TC028.03: password-recovery hardening, customer risk flag, Excel export

# Description

## What this changes
- **Password recovery (TC004.02):** staff web and customer API show the same confirmation for any valid email (no account enumeration); one generic error for invalid/expired/used codes; codes are verified and consumed atomically (transaction + row locks), so a code cannot be redeemed twice.
- **Customer risk flag (TC015.12):** keeps the customer-wide, informational model on the existing `customers.risk_*` columns; adds a clear action (reason required, audited), badges on dispatcher views ("Current:" in history), Mark/Update/Clear controls in the booking drawer (and a "Customer & Risk" button on Returned cards). Risk data is hidden from customer-facing serialization.
- **Excel export (TC028.03):** owner-only `.xlsx` export for booking, fleet and financial reports built from the same data as the page/PDF; typed numbers/dates; spreadsheet-formula injection neutralised; detail rows capped at 5,000 (memory). PDF and CSV exports unchanged.
- Removes dead `apiResource('bookings')` routes that returned 500 (the mobile app never used them).
- Declares `ext-gd` and `ext-zip` (needed by `phpoffice/phpspreadsheet` 5.10.0).

## Not changed
No database migrations, no config/env changes, no mobile app / APK change.

## Release notes for the deploy
- Merging to `main` triggers the production deploy. Expect ~5–10 min (slow composer autoload step).
- Production already has PHP 8.2.27 with `gd` and `zip`, a running queue worker, a valid Brevo key and an active sender (checked read-only; nothing sent).
- Database backup taken before merge (SHA-256 recorded in `docs/qa/production-deployment-and-rollback-plan.md`).
- Rollback (rehearsed on staging, ~3 min): deploy `git archive ccd9f5f` with `railway up`, then `git revert` the merge.

## Testing
- New tests: `PasswordRecoveryHardeningTest`, `CustomerRiskFlagTest`, `ReportXlsxExportTest`, `CustomerBookingRoutesTest`, JS checks for the modal and drawer.
- Staging retests run in real Chrome + HTTP + Microsoft Excel on 2026-10-14: see `docs/qa/retest-log-TC004.02-TC015.12-TC028.03.md` and `docs/qa/evidence/`. **Executed by an AI assistant, not human QA; formal QA sign-off still pending.**
- The existing suite is not green: 67 failing and 40 risky tests and a duplicate test-helper fatal predate this PR (identical on `ccd9f5f`); this PR introduces none (compared by test name and cause). GitHub Actions will likely show red for the same reason.

## Known limitations
- Mobile app UI not exercised; real email delivery not tested before deploy (staging used a log sink).
- "Average Revenue per Completed Booking" counts only jobs with a payment record (existing rule).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
