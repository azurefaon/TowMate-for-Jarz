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
| 2026-11-09 | Phase 1: database and authorization foundation | Done (`77ac792f`). See "Phase 1 verification" below |
| 2026-11-10 | Phase 2: Unit Funds | Done. See "Phase 2 verification" below |

## Phases

- [x] **Phase 0: Inspection and spec.**
- [x] **Phase 1: Database and authorization foundation.**
  - Nine finance tables with CHECK constraints, partial unique indexes and immutability triggers.
  - The `Money` helper and `MoneyCast`.
  - `FinanceAccess` Gates.
  - Services: `UnitFundService`, `ExpenseService`, `CommissionPolicyService`, `CommissionStatementService` (lifecycle and approval only), `PayoutService`.
  - A finance audit category, and a guard on unit force-delete.
- [x] **Phase 2: Unit Funds (Owner).**
  - The Owner page `/superadmin/finance/unit-funds`, with a Finance sidebar group.
  - Issue initial or additional funds and record returned funds, each with date, amount, unit, receiving or returning person, reference and remarks.
  - The backend ledger: Opening + Issued − Advance-funded spending − Returned = Remaining.
  - Carry-forward between periods, filters by unit and date, movement history, balance summary, and a column for expenses awaiting review.
  - Corrections (void plus linked replacement) and voids with a reason.
- [ ] **Phase 3: Expenses.**
  - The Team Leader submission path (API or app) and the Dispatcher verification queue.
  - The Owner approval queue and an authorised evidence download route.
  - Unit-fund spending then flows automatically into the Unit Funds ledger, which already reads approved `unit_fund` expenses.
  - The confirmed categories are in `config/finance.php`.
- [ ] **Phase 4: Jobs & Collections, and Overview.**
  - A read-only collections query that reuses the `ReportMetricsService` predicate and group allocation, plus a consistency test against the Revenue page.
- [ ] **Phase 5: Job crew capture.**
  - Write `finance_job_personnel` when payment is confirmed (an additive hook).
  - A flagged backfill for past jobs.
  - The Owner review of flagged rows.
  - *The `commission_role` mapping needs C4.*
- [ ] **Phase 6: Weekly Commission computation.**
  - Generate revenue allocations and lines from the confirmed policy.
  - The approve and payout screens.
  - *Blocked by C1–C6.*
- [ ] **Phase 7: Reports exports.**
- [ ] **Phase 8: Verification and client walkthrough.**

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

## Phase 2: what was built

| Part | Detail |
|---|---|
| Migration `2026_11_11_000000_add_unit_fund_details_to_finance_fund_entries` | Adds `issue_kind` (`initial` or `additional`; CHECK: issuances only), `external_reference`, and `corrects_entry_id`. Partial unique indexes allow one recorded initial issuance per unit and one active correction per entry. The new columns are added to the immutability trigger |
| `UnitFundLedger` | Backend balances from NUMERIC aggregates: **Opening + Issued − Advance-funded spending − Returned = Remaining**. Opening = everything dated before the period, so balances carry forward with no period closing. Spending = APPROVED expenses with `funding_source = unit_fund`. Pending review (submitted or verified) is reported separately and not deducted. Collections and payouts are never included |
| `UnitFundService` (extended) | Initial issuance only once per unit; additional only after initial; no issuing to archived units. Returns are refused when they exceed the balance today, the balance on the return date, or today's balance minus pending unit-fund expenses. No future dates. Amounts must be exact two-place strings. `correct()` voids the original (`Corrected: <reason>`) and records a linked replacement in one transaction, and refuses a correction that would make the balance negative. Voiding initial funds is blocked once additional funds exist (correct it instead) |
| `UnitFundController` + `UnitFundEntryRequest` | `GET /superadmin/finance/unit-funds` (`can:finance.view`) and POST issue / return / `{entry}/correct` / `{entry}/void` (`can:finance.funds.manage`, throttled), all inside the `role:1` group. Validation keeps amounts as strings (commas and ₱ are stripped; at most two decimals). Business errors come back as a form error without saving |
| UI `superadmin/finance/unit-funds/index.blade.php` + `public/superadmin/css/finance.css` | Built from the existing `ob-`/`oa-` Owner components. "Issue funds" and "Record returned funds" panels; unit and Mon–Sat period filters (defaults to this week); six summary cards; a per-unit balance table with totals and an "Awaiting review" column; paginated movement history showing voided entries struck through with reason, voider and replacement, plus inline Correct or void. Double-click protection on the page and a one-time idempotency key per form on the server |
| Config | Expense categories replaced with the client's confirmed list (9 categories); `issue_kinds` added |

## Phase 2 verification

| Check | Result |
|---|---|
| `pest tests/Unit/Finance tests/Feature/Finance` | **91 passed** (440 assertions), including 32 new Unit Funds tests |
| `pest tests/Feature/SuperAdmin tests/Feature/Security` (Owner sidebar, role boundary and security regression) | **1008 passed** |
| Full suite `pest` | **3227 passed, 0 failed**, 52 risky (pre-existing); 1,370 s |

The Unit Funds tests (`tests/Feature/Finance/UnitFundsTest.php`) cover:
- **The client's scenario:** issue 3,000.00, spend 2,950.00 (spending fixture until Phase 3), return 50.00, leaving exactly 0.00. The page shows the same figures, and a further 0.01 return is refused.
- **Carry-forward:** last week 3,000.00 − 1,000.25 = 1,999.75; this week's opening is 1,999.75, then +500.00 = 2,499.75.
- **Duplicates:** the same form posted twice gives 1 row and 1 audit entry; the same key with a different amount is refused; a second initial issuance is refused; additional funds before initial are refused.
- **Authorization:** Dispatcher, Team Leader, Driver, Customer and System Admin get 403 on view, issue, return, void and correct; guests are redirected to login; nothing is written.
- **Invalid input:** 0, 0.00, negative, 12.345, text, 1e3 and empty are rejected; "3,000.50" is stored exactly; future dates and a missing person are rejected.
- **Unsupported returns:** over the balance; a unit with no funds; dated before the issuance; over the cash left after pending reviews are set aside.
- **Corrections:** the linked replacement is recorded, the original stays in history unchanged, and the history page shows both links; a correction that would go negative is refused; voids require a reason and stay visible; voiding initial funds is blocked once additional funds exist.
- **Separation, filters and exactness:** customer collections are excluded; filters work by unit and date; the default is the current week; the sidebar links to the page; 0.10 + 0.20 = 0.30.

Not done in Phase 2: no browser screenshot review (the page was verified through rendered-HTML tests only), no migration on the dev or production databases, no push or deploy.

## Open client decisions

- Commission interpretation C1–C6 ([spec.md §6](spec.md#6-commission-pending-client-confirmation)).
- **Sunday activity (new, minor):** reporting weeks are Monday–Saturday. Fund movements dated on a Sunday are still counted in balances (they carry into the next week's opening), and the default view includes today when today is Sunday. The client should confirm whether Sunday movements belong to the preceding week or the following week in reports.

Unit Funds and Expenses are not blocked. The expense categories are confirmed and configured.

## Known risks and notes

- The crew is stored only on `Unit` as live data. Past jobs need a backfill flagged `needs_review`.
- `ReportMetricsService` sums with floats. Phase 4 (Jobs & Collections) adds a consistency test, and that service will only be changed if a real mismatch is found.
- Expenses with `funding_source = unit_fund` count as spending on their `expense_date` once approved. If an approval lands after the period has been reviewed, that period's figures change. This is intended (it reflects actual spending dates), but the Owner should know.
- `ActiveBookingsController::updateStatus()` can set `completed` without `completed_at`. Finance requires `completed_at`.
- `cash_received` is the cash tendered, not the amount collected.
- The finance tables use FK `RESTRICT` to bookings and units. `UnitController::forceDelete` now refuses units with finance history. The dev seeding commands (`SeedTlDemo*`, `SeedQuotationTestScenarios`) delete bookings and would be blocked only if those bookings had finance rows, which they never do.
- The migration `down()` drops the finance tables. It is for development rollback only and must never be run against real data.
