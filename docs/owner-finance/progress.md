# Owner Finance Module — Progress

Spec: [spec.md](spec.md).

## Workspace

- Branch `feat/owner-finance`, created from `origin/main` @ `47383fcb` (PR #41 merge). The branch has **no upstream**, so a plain `git push` cannot reach `main`.
- Worktree: `C:\Users\caval\OneDrive\Desktop\TowMate_wt_finance`. The original checkout (`feat/online-banking-test-mode`, its untracked files, and the Phase 0 docs) was left untouched.
- Tests run only against the local disposable database `towmate_jarz_testing`, which the `Tests\TestCase` guard enforces. The dev database `towmate_jarz` and production were not touched. Nothing was pushed or deployed.

## Status log

| Date | Step | Result |
|---|---|---|
| 2026-11-09 | Phase 0: repository inspection and spec | Done |
| 2026-11-09 | Phase 1: database and authorization foundation | Done. See "Phase 1 verification" below |

## Phases

- [x] **Phase 0: Inspection and spec.**
- [x] **Phase 1: Database and authorization foundation.**
  - Nine finance tables with CHECK constraints, partial unique indexes and immutability triggers.
  - The `Money` helper and `MoneyCast`.
  - `FinanceAccess` Gates.
  - Services: `UnitFundService`, `ExpenseService`, `CommissionPolicyService`, `CommissionStatementService` (lifecycle and approval only), `PayoutService`.
  - A finance audit category, and a guard on unit force-delete.
- [ ] **Phase 2: Finance routes and read-only screens.**
  - Owner Finance routes (`/superadmin/finance/*`, `role:1` + `can:`) and the sidebar section.
  - The Overview and Jobs & Collections pages, using a shared collections query that reuses the `ReportMetricsService` predicate and group allocation.
  - A consistency test against the Revenue page.
- [ ] **Phase 3: Unit Funds and Expenses screens.**
  - Owner fund issue, return and void.
  - The Team Leader expense submission path (API or app), the Dispatcher verification queue, the Owner approval queue, and an authorised evidence download route.
  - *Needs the client's final expense category list (config only).*
- [ ] **Phase 4: Job crew capture.**
  - Write `finance_job_personnel` when payment is confirmed (an additive hook).
  - A flagged backfill for past jobs.
  - The Owner review of flagged rows.
  - *The `commission_role` mapping needs C4.*
- [ ] **Phase 5: Weekly Commission computation.**
  - Generate revenue allocations and lines from the confirmed policy.
  - The approve and payout screens.
  - *Blocked by C1–C6.*
- [ ] **Phase 6: Overview and Reports exports.**
- [ ] **Phase 7: Verification and client walkthrough.**

## Phase 1 verification

Run on 2026-11-09/10 against the local disposable `towmate_jarz_testing` database (RefreshDatabase runs `migrate:fresh` there only).

| Check | Result |
|---|---|
| `php -l` on every new and changed PHP file | Clean |
| `pest tests/Unit/Finance tests/Feature/Finance` | **59 passed** (309 assertions) |
| Full suite `pest` (regression check) | 3194 passed, 52 risky (pre-existing), **1 failed**: `Feature/Api/VehicleTypeTruckTypeMappingTest` (ErrorException during the full run only) |
| That failing file run on its own, with and without the finance changes (`git stash -u`) | 19/19 pass both ways. The failure is order-dependent flakiness and is not caused by finance |

The finance tests cover:
- **Exact money:** 0.10 + 0.20 = 0.30; floats refused; half-up rounding; allocations sum exactly; NUMERIC sums.
- **Database rules:** CHECK constraints (amount > 0, rates total 100, Mon–Sat week, net = gross − VAT); no deletes; locked fields; one-way status changes; no actor reassignment; duplicate idempotency keys; supersede history; one approved statement per week; lines locked after approval; payouts only on approved statements; evidence locked after approval.
- **Authorization:** all 7 Owner-only abilities are denied to Dispatcher, Team Leader, Driver, Customer and System Admin, and to inactive or archived Owners; the expense submit and verify roles; each service refuses non-Owners.
- **Services:**
  - fund issuance is idempotent, and a replay with different data is rejected
  - exact unit balance (issued 1000.10 − expense 250.05 − returned 100.00 = 650.05), and returns larger than the balance are refused
  - void instead of delete
  - the Team Leader → Dispatcher → Owner flow, and its refusals
  - evidence on the private disk, with duplicates rejected
  - a policy cannot be activated until confirmed; versioning retires the old policy
  - a statement cannot be approved under a pending policy; approval fails if a total is off by 0.01; a verifiable approval hash is stored
  - payouts equal exact earnings, one per recipient, with an audit reference
  - a statement cannot be voided while it has payouts
  - a unit with finance history cannot be force-deleted

Not done in Phase 1 (by design): no screens or routes, no migration on the dev or production databases, no push or deploy.

## Open client decisions

Only the commission interpretation is still open: C1–C6 in [spec.md §6](spec.md#6-commission-pending-client-confirmation). Unit Funds and Expenses are not blocked. The only outstanding item for them is the client's category list, which goes in `config/finance.php`.

## Known risks and notes

- The crew is stored only on `Unit` as live data. Past jobs need a backfill flagged `needs_review`.
- `ReportMetricsService` sums with floats. Phase 2 adds a consistency test, and that service will only be changed if a real mismatch is found.
- `ActiveBookingsController::updateStatus()` can set `completed` without `completed_at`. Finance requires `completed_at`.
- `cash_received` is the cash tendered, not the amount collected.
- The finance tables use FK `RESTRICT` to bookings and units. `UnitController::forceDelete` now refuses units with finance history. The dev seeding commands (`SeedTlDemo*`, `SeedQuotationTestScenarios`) delete bookings and would be blocked only if those bookings had finance rows, which they never do.
- The migration `down()` drops the finance tables. It is for development rollback only and must never be run against real data.
