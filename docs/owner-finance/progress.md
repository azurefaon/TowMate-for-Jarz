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
| 2026-11-10 | Phase 2: Unit Funds | Done (`42245cb9`). See "Phase 2 verification" below |
| 2026-11-10 | Phase 3: Expenses and receipt review, plus the Phase 2 daily-funds correction | Backend, Owner page and Dispatcher page done (`96842391`). **Unfinished:** Team Leader mobile expense screen; browser visual check |
| 2026-11-10 | Phase 4: Jobs & Collections | Done. See "Phase 4" below |

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
- [ ] **Phase 3: Expenses and receipt review.** Backend and web are done; two items remain open:
  - [x] The Owner Expenses page, the Dispatcher Expense Review queue, and Team Leader API endpoints.
  - [x] Mixed funding; job vs unit/monthly scope; required evidence on private storage; overspend warning; corrections and voids.
  - [x] Integrated with the Unit Funds ledger.
  - [x] Phase 2 correction: daily issuances are allowed.
  - [ ] **UNFINISHED: Team Leader mobile expense screen** (Flutter UI for the existing `/api/v1/team-leader/expenses` endpoints).
  - [ ] **UNFINISHED: browser visual verification** of the Unit Funds, Expenses, Expense Review and Jobs & Collections pages (so far verified only through rendered-HTML tests).
- [x] **Phase 4: Jobs & Collections.**
  - The Owner page `/superadmin/finance/jobs-collections`.
  - A read-only `JobCollectionService` over bookings, invoices and payment transactions.
  - Unit revenue allocation, with an Owner split for multi-unit jobs.
  - A consistency test against the Revenue page.
- [ ] **Phase 4b: Overview** (finance summary cards across modules). Not started.
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

## Phase 3: what was built

| Part | Detail |
|---|---|
| Migration `2026_11_12_000000_expenses_review_and_mixed_funding` | **`finance_expense_fundings`**: one row per (expense, source) with source ∈ {`unit_fund`, `company_direct`, `personal_advance`}; amount > 0; writable only while the expense is `submitted`. **`finance_expenses`** gains `expense_scope` (`job` needs a booking; `unit` needs `coverage_month` = first of month), `unit_fund_shortfall`, and `corrects_expense_id`; `funding_source` is now the summary (`unit_fund` / `company_direct` / `personal_advance` / `mixed`). **Approval trigger:** an expense cannot become approved without at least one evidence row, or if its splits do not sum exactly to the amount. **Phase 2 correction:** the `ffe_one_initial_per_unit` index is dropped |
| `ExpenseService` (rewritten) | Validated submission (category, description, exact amounts, no future date, scope and booking, own unit for Team Leaders). Funding splits must sum exactly. Shortfall snapshot = unit-fund part minus the unit's available cash (balance minus pending unit-fund expenses), recorded as a warning only. Evidence is required for verification and approval. Owner approval needs prior Dispatcher verification unless the Owner entered the expense. Owner corrections close the original (void if approved, reject if pending) and record a linked replacement, with receipts copied to new private files. Evidence is validated by detected MIME type plus extension, max 8 MB, SHA-256 de-duplicated, stored on the private `local` disk. `canAccess()` allows the Owner, Dispatchers, and the submitting or unit Team Leader only |
| `UnitFundLedger` | Spending and pending amounts now come from the **`unit_fund` splits only** (joined to approved or pending expenses), so each approved expense is deducted exactly once, and company or personal-advance parts never reduce issued funds. Adds `available()` |
| `UnitFundService` (correction) | No lifetime "one initial issuance" rule and no "additional only after initial" rule. Any number of issuances per day; the labels are now "Daily operating funds" and "Additional funds" (stored keys unchanged). A same-unit, same-day, same-amount issuance shows a warning but is not blocked. Duplicate protection (idempotency key), carry-forward and history are unchanged |
| Owner page `/superadmin/finance/expenses` | Record expense (unit, job or unit/monthly, booking reference with suggestions, month covered, date, category, description, amount, three funding fields with a live total check and a live overspend hint in integer centavos, receipts). Filters: unit, booking, category, status, type, funding source, date. Six summary cards (awaiting Dispatcher, awaiting Owner, approved, and approved split by unit funds, company and personal). History with flags (missing evidence, shortfall, monthly allocation pending, personal advance pending), evidence links, review trail, Approve (disabled without evidence), Reject, Void, Correct, Upload |
| Dispatcher page `/admin-dashboard/expense-review` | A queue of `submitted` expenses with receipts; Verify (optional note; disabled without receipt) or Reject (reason); a recently verified list. Sidebar: Finance → Expense Review |
| Team Leader API `/api/v1/team-leader/expenses*` | `GET options` (categories, sources, scopes, the TL's unit and available funds), `GET` (own or own-unit list), `POST` (multipart with `receipts[]`; the unit is always the TL's own unit; 201 when new, 200 on idempotent replay; returns a shortfall warning), `POST {id}/evidence`, `GET {id}/evidence/{evidence}`. No new account types; Driver and Crew remain without logins |
| Shared | `ResolvesFinancePeriod` trait (both finance pages), `ExpenseEvidenceStreamer` (private streaming with `nosniff`, `no-store`, sandbox CSP), `_flashes` partial, `public/superadmin/js/finance.js` |

## Phase 3 verification

| Check | Result |
|---|---|
| `pest tests/Unit/Finance tests/Feature/Finance` | **126 passed** (712 assertions), including 34 new tests in `ExpensesTest.php` |
| Full suite `pest` | 3261 passed, 52 risky (pre-existing), **1 failed**: `Admin/UnitsAndLeadersTest` #67 (`UniqueConstraintViolationException`, full run only) |
| That file run on its own, with and without the Phase 3 changes (`git stash -u`) | **98/98 pass both ways.** The failure is order-dependent flakiness (like the Phase 1 run) and is not caused by finance |

`ExpensesTest.php` covers:
- **Creation and validation:** creation with scope, funding, evidence and review trail; job expenses linked by booking code, with a missing or unknown booking refused; 9 invalid inputs rejected with nothing saved.
- **Receipts:** txt, disguised PHP and over-8 MB files are rejected; PDF accepted. Evidence can be viewed by the Owner, a Dispatcher and the submitting Team Leader; another Team Leader, Driver, Customer and System Admin are refused; evidence from another expense returns 404.
- **Evidence required:** verify and approve are refused without evidence in the service, over HTTP and by the database trigger; approval succeeds once a receipt is attached.
- **The client's example (end to end):** 3,000 issued; the TL submits 2,950 through the API, the Dispatcher verifies, the Owner approves; it was pending and not deducted until approval; re-approval is refused; 50 returned; balance **0.00**.
- **Mixed funding:** 1,000 = 600 unit + 300 company + 100 personal; one expense; unit balance −600 only; the summary cards split exactly; splits are locked after approval.
- **Overspend:** 800 against 500 available is recorded with a 300.00 shortfall warning; balance −300; no debt or adjustment entry; returns are blocked; the TL API returns the shortfall.
- **Personal advance and company-paid:** unit balance unchanged; reimbursement-pending flag shown.
- **Monthly / unit expenses:** allocation-pending flag; the month covered is stored; the type filter works.
- **Duplicates:** an Owner double-submit gives 1 expense, 1 split, 1 receipt and 1 audit entry; a changed replay is refused; a TL app double-submit gives 1 expense.
- **Authorization:** 5 non-Owner roles get 403 on every Owner expense action; only Dispatchers can verify; only Team Leaders can use the TL API; a TL's unit cannot be overridden; a TL without a unit gets 422; guests are redirected or get 401.
- **Dispatcher queue:** verify with a note, reject with a reason (required), and the item shows as awaiting the Owner.
- **History:** correcting an approved expense voids the original, links a replacement and copies its receipt; the voided original is no longer deducted; the replacement is deducted only after approval. A voided expense stops deducting.
- **Commission and filters:** returns do not touch commission data; filters work by unit, booking, category, status and date; sidebar links are present.

`UnitFundsTest.php` was updated for the correction: daily funds on three consecutive days plus a same-day top-up (carry-forward 200.00 → 600.00 → 3,600.00, in date order); "additional" funds without a prior daily issuance are allowed; an identical same-day issuance shows a warning but is not blocked; a void that would leave negative cash is still blocked.

Not done in Phase 3: no Flutter screens for the Team Leader expense endpoints (backend only); no browser screenshot review; no migration on the dev or production databases; no push or deploy.

## Phase 4: what was built

**The authoritative verified-payment source** (identified before any collected amount is calculated):
1. **Dispatcher confirmation.** `Admin\JobsController::confirmPayment()` sets `status=completed` and `completed_at` only after the Team Leader submitted payment, and the TL flow requires cash ≥ the total. This verifies the **full agreed charge** (see `ReportMetricsService::PAYMENT_INVARIANT`).
2. **Live PayMongo payments.** `payment_transactions.status=paid` with `livemode=true` on a non-demo booking is provider-verified money received. It counts before confirmation (which is how a partial payment becomes visible) and is never added on top of a Dispatcher confirmation: collected = max(agreed, live paid) once confirmed.
3. **Never counted:** the customer's preferred method; a TL submission awaiting verification; `cash_received` (cash tendered, which includes change); PayMongo **TEST** payments; pending, failed or expired transactions; review or duplicate transactions (flagged for refund review); demo bookings; and a booking manually set to `completed` without `completed_at` (shown as "Completed, payment not verified").

| Part | Detail |
|---|---|
| `JobCollectionService` | One row per **job**: a single booking, or a normalized group trip (`Invoice::sameTripMembers`, one booking per vehicle, counted once). **Agreed charge:** current non-voided invoice total, else group formula (active vehicles + quotation fee − discount, minimum 0), else `final_total`. **Vehicles:** group = vehicle bookings; single booking = 1 + legacy `extra_vehicles`. **Collection status:** paid, partial, awaiting verification, completed but unverified, unpaid, over-collected. Outstanding = agreed − collected, exact. Group cash (repeated on every vehicle row) is read once. **Dates** are Philippine time; by service date (`completion_requested_at` → … → `created_at`) or by payment date; flags for Sunday service and payment in a later reporting week. **Personnel:** captured `finance_job_personnel`, else the booking's own `assigned_team_leader_id` / `driver_name`; never the unit's current crew. **Expenses** for the job are shown separately (approved and pending) |
| Unit allocation | **Automatic** only when fully collected and every vehicle has its own assigned unit: each vehicle's collection goes to its unit, and a group fee or discount is shared in proportion to each vehicle's own charge. Rounding centavos go to the largest charge, so the sum equals the allocatable collection exactly. Over-collection allocates the agreed charge and flags the excess. **Pending review** (nothing allocated) for partial payments, a vehicle with no unit, an "Assistance from Another Unit" expense, or borrowed or other-unit crew. **No 50:50 default.** The **Owner split** (`recordSplit`, single-booking jobs) requires amounts that sum exactly to the allocatable collection, stores `manual_split` rows with `allocatable_total`, and supersedes earlier splits (kept in history). If the collection later changes, the split goes back to pending review |
| Migration `2026_11_13_000000_jobs_collections_allocations` | Adds `allocatable_total` and `notes` to `finance_revenue_allocations`; method `manual_split`; both columns added to the immutability trigger |
| `JobCollectionController` + view `superadmin/finance/collections/index.blade.php` | `GET /superadmin/finance/jobs-collections` (`can:finance.view`) and `POST /{booking}/split` (`can:finance.revenue.allocate`, a new Owner-only ability, throttled). Filters: unit, booking or group code, collection status (including "has outstanding balance"), payment method, allocation status, date basis, period (default this Mon–Sat week). Cards: bookings (and how many have service completed), vehicles towed, agreed, verified collections, outstanding (plus over-collected), allocations pending review. A revenue-by-unit table. Job rows with details: charge source, Dispatcher confirmation, preferred method (labelled as not a payment), cash tendered and change, online transactions (TEST labelled), vehicles and units, personnel at the time, job expenses, allocation lines, and the split form. Sidebar: Finance → Jobs & Collections |

No booking confirmation, payment verification or dispatch code was changed. No customer payment record is written.

## Phase 4 verification

| Check | Result |
|---|---|
| `pest tests/Unit/Finance tests/Feature/Finance` | **147 passed** (837 assertions), including 21 new tests in `JobsCollectionsTest.php` |
| Full suite `pest` (includes the existing booking, payment, Team Leader and dispatch tests) | **3283 passed, 0 failed**, 52 risky (pre-existing); 1,418 s |

`JobsCollectionsTest.php` covers:
- **Verified vs unverified:** a Dispatcher-confirmed job counts in full. Submitted-awaiting, preferred-method-only, TEST, pending, failed and expired payments count 0. Demo bookings are excluded. A live online payment counts once, even after Dispatcher confirmation.
- **Partial:** 15,000.00 agreed, 5,000.50 live paid → partial, outstanding 9,999.50, allocation pending.
- **Cash with change:** 2,000 tendered on 1,800 → collected 1,800.00, change 200.00 flagged.
- **Completed but unpaid:** "completed, payment not verified", 0 collected, nothing allocated.
- **Groups and duplicates:** a 3-vehicle trip + ₱300 fee = one job, 3 vehicles, 15,300.00 collected once despite an online payment plus a duplicate transaction (flagged for refund review). The invoice total overrides the booking total.
- **Allocation:** a two-unit group 10,000 + 5,000 is automatic and totals exactly **15,000.00**. A group discount is shared and totals exactly 9,900.00. A one-booking job with another unit's assistance expense stays pending (no 50:50); a split of 9,000.00 + 5,999.99 is refused; the Owner split 9,000.50 + 5,999.50 = 15,000.00 is recorded; a re-split supersedes with history kept; a split goes back to pending when the collection basis changes; a split with nothing collected is refused.
- **Vehicles:** one booking with three vehicles counts as 1 booking and 3 vehicles.
- **Historical personnel:** the booking's TL and driver are shown after the unit's crew changed; today's crew is never shown; captured crew take precedence.
- **Filters and dates:** service vs payment basis; late-payment and Sunday flags; unit, booking, status, outstanding and method filters.
- **Page and authorization:** the Owner page shows exact totals (2 bookings, 3 vehicles, 17,500 agreed, 15,000 collected, 2,500 outstanding); 5 non-Owner roles get 403 on view and split; guests are redirected.
- **No writes and consistency:** viewing writes no payment, invoice, receipt or booking record. Dispatcher-confirmed collections equal the existing Revenue page total (12,100.00). Bookings are counted per job (2) where the Revenue page counts vehicle rows (3).

## Open client decisions

- Commission interpretation C1–C6 ([spec.md §6](spec.md#6-commission-pending-client-confirmation)).
- **Monthly maintenance allocation:** unit/monthly expenses are recorded, approved and deducted from unit funds when paid from them, but they are **not allocated** to jobs or commission. Each one is flagged "Monthly allocation rule pending client confirmation".
- **Personal advance reimbursement:** recorded as a funding split and flagged. No reimbursement, payable or Team Leader debt is created.
- **Overspending:** recorded as entered with a shortfall snapshot. Whether a negative unit balance should trigger anything (for example, top-up or deduction) is the client's decision; nothing automatic happens.
- **Evidence exemptions:** every expense currently needs evidence before verification and approval (enforced by a DB trigger). If the client wants exemptions (for example, a toll with no receipt), that needs a rule decision and a migration.
- **Sunday activity (minor):** reporting weeks are Monday–Saturday. Sunday-dated entries count in balances and carry into the next week; the default view includes today when today is Sunday. Jobs & Collections flags Sunday service and late payments. Which week Sunday belongs to in reports still needs confirming.
- **Multi-unit revenue (new):** when another unit helps on a one-booking job, or a job is only partly paid, the allocation stays pending until the Owner records a split. Whether a standard rule should apply to an approved scenario (for example, a fixed share for assistance) is the client's decision. Splitting a group trip by hand is not supported yet (group vehicles are allocated to their own units).
- **Refunds and provider fees (new):** TowMate does not record refunds or PayMongo fees. Collections are shown gross, and transactions flagged for refund review are marked. How refunds and fees should be recorded is a client and accounting decision.

## Known risks and notes

- The crew is stored only on `Unit` as live data. Past jobs need a backfill flagged `needs_review`. Jobs & Collections never uses the current crew; it shows the booking's own TL and driver until crew capture exists.
- `ReportMetricsService` sums with floats. The Phase 4 consistency test shows its confirmed-revenue total equals the exact Jobs & Collections total for the tested cases. It was not changed.
- **Existing Revenue page defect (found, not changed):** the "Cash Received" card sums `bookings.cash_received` over every vehicle row, but a group payment stores the same group cash on every vehicle row, so group cash is counted once per vehicle. Jobs & Collections reads it once per job. Fixing the Revenue page is outside this module and needs approval.
- Booking counts differ by design: Jobs & Collections counts a group trip as one booking with N vehicles; the existing Revenue page's "Completed Jobs" counts vehicle rows.
- Expenses with `funding_source = unit_fund` count as spending on their `expense_date` once approved. If an approval lands after the period has been reviewed, that period's figures change. This is intended (it reflects actual spending dates), but the Owner should know.
- `ActiveBookingsController::updateStatus()` can set `completed` without `completed_at`. Finance requires `completed_at`.
- `cash_received` is the cash tendered, not the amount collected.
- The finance tables use FK `RESTRICT` to bookings and units. `UnitController::forceDelete` now refuses units with finance history. The dev seeding commands (`SeedTlDemo*`, `SeedQuotationTestScenarios`) delete bookings and would be blocked only if those bookings had finance rows, which they never do.
- The migration `down()` drops the finance tables. It is for development rollback only and must never be run against real data.
