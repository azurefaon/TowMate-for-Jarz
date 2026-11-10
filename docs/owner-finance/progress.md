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
| 2026-11-10 | Phase 4: Jobs & Collections | Done (`f01a4eb9`). See "Phase 4" below |
| 2026-11-10 | Phase 5: Commission calculation (draft, read-only) | Done (`0d425f27`). See "Phase 5" below |
| 2026-11-10 | Phase 6: Job crew recording and weekly commission statements (Draft → For Review → Approved) | Done (`d11007b6`). See "Phase 6" below. Approval on real data still waits for the client's commission decisions |
| 2026-11-10 | Phase 7: Payout records, Paid status and printable statements | Done. See "Phase 7" below. No transfers; no real payouts recorded |

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
- [x] **Phase 5: Commission calculation.**
  - `CommissionCalculator` is the one authoritative, read-only service.
  - `finance_commission_deductions` holds non-expense deductions.
  - The Owner page "Commission (Draft)" shows the breakdown and the blockers.
- [x] **Phase 6: Job crew recording and commission statements.**
  - Owner page "Job Crew": record, correct, remove and confirm who actually worked each job, per unit and performed role.
  - Owner pages "Commission Statements": one statement per unit and Monday–Saturday week, Draft → For Review → Approved, with void and correction (re-issue as a new revision).
  - Approval re-runs `CommissionCalculator`, requires zero blockers and an unchanged calculation, freezes a hashed snapshot and settles each booking × unit once.
  - "Paid" was added in Phase 7.
- [x] **Phase 7: Payout records and printable statements.**
  - Record actual payouts (full payout per person) against Approved statements from the approval snapshot; void and correct with history; Mark Paid only when reconciled exactly.
  - Printable English commission statement (browser Print / Save as PDF, and a Dompdf PDF through the existing document generator).
  - Partial and group payouts are pending client confirmation and not supported.
- [ ] **Phase 8: Overview page.**
- [ ] **Later (not yet scheduled):** reports and exports; client walkthrough.

### Open items carried forward (not complete)
- [ ] **Team Leader mobile expense screen** (from Phase 3): Flutter UI for the `/api/v1/team-leader/expenses` endpoints.
- [ ] **Browser visual verification** (from Phase 3): the Unit Funds, Expenses, Expense Review, Jobs & Collections and Commission (Draft) pages have only been verified through rendered-HTML tests.
- [ ] **Existing Revenue page "Cash Received" duplication** (found in Phase 4, not fixed): group cash is summed once per vehicle row. Fixing it needs approval.
- [x] ~~Job crew capture~~: **resolved in Phase 6 by Owner recording** (Job Crew page). Deliberately not automatic: nothing is captured at payment confirmation and past jobs are not backfilled, because the unit's current crew is not evidence of who worked. Each completed job needs its crew recorded by the Owner before its commission can be approved.
- [ ] **Outstanding client policy confirmations** (C1–C6, week-date basis, Sunday, and the four role rules in Phase 6). No real statement can be approved until an active policy is confirmed by the client; see "Open client decisions".

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

## Phase 5: what was built

**`CommissionCalculator::draft(weekStart, ?unitId, ?policy)`** is the one authoritative calculation. It is read-only: it writes no statement, line or payout.

- **Lines:** for each job in the Monday–Saturday week (by service date) whose collection Jobs & Collections allocated to units, one line per (job, unit).
  - **Sharing base = allocated verified collection − that unit's approved expenses for the job − that unit's approved deductions for the job.**
  - Each expense and deduction belongs to one job and unit, so it is subtracted exactly once. Collections come from the Phase 4 allocation, so a multi-unit job's collection is never duplicated.
  - Unit fund issuances and returns never enter the base.
- **Shares:** every percentage applies to the same base (policy rates: Company 60 / Driver 12 / Team Leader 10 / Rider-Substitute 10 / Crew Mate 8). They are calculated with `Money::allocateByPercent` (half-up to the centavo, remainder to Company), so Company + crew + unallocated = base, exactly.
- **People:** only `finance_job_personnel` rows for that job and unit. A role nobody held keeps its share **unallocated** (never redistributed). Two people in one role are shown split equally to the centavo, flagged pending C2. Unconfirmed role mappings use a provisional slot mapping (`driver_2` → Rider, `crew_member_*` → Crew Mate) and are flagged.
- **Output:** the breakdown per line (collection, each expense, each deduction, base, Company, each role with recipients, unallocated, a reconciliation check), totals by role and by person, Sunday notices, jobs left out and why, and **blockers**. `can_approve` is true only with zero blockers and at least one line.
- **Blockers:**

  | Code | Meaning |
  |---|---|
  | `policy_for_client_review` | Policy not active and confirmed: Draft / For Client Review |
  | `invalid_percentages` | Rates outside 0–100 or not totalling exactly 100 |
  | `job_unfinished` | The job is not finished |
  | `payment_not_verified`, `allocation_pending`, `not_allocated` | Payment incomplete or unverified; unit allocation unresolved |
  | `expenses_pending` | Job expenses awaiting review |
  | `monthly_allocation_pending` | A unit/monthly expense for the unit touches the week |
  | `personal_advance_pending` | A personal advance in a job or unit expense |
  | `no_personnel`, `missing_role`, `role_mapping_unresolved`, `shared_role_rule_pending`, `personnel_needs_review` | Personnel and role mapping |
  | `non_positive_base` | Zero or negative sharing base |
  | `deduction_pending`, `refund_unresolved`, `payment_fee_unresolved` | Unresolved deductions, refunds and fees; a live online payment needs an approved payment-fee deduction |

**Deductions** (`finance_commission_deductions` + `CommissionDeductionService`, Owner-only ability `finance.deductions.manage`):
- Types: payment fee, refund, damage, other. Recorded as pending review; only approved ones reduce the base.
- Duplicates are refused by an idempotency key, by the service (same job, unit and type, plus the same reference, or the same amount when there is no reference), and by a partial unique index on reference.
- Never deleted (trigger); void with a reason.

**Owner page "Commission (Draft)"** (`/superadmin/finance/commission`): week and unit pickers, the Draft / For Client Review banner with the rates, six totals, the blocker list, a breakdown per job and unit, draft shares by person, the deductions list (approve or void), and the record-deduction form. It has no approve or pay controls (Phases 6 and 7).

## Phase 5 verification

| Check | Result |
|---|---|
| `pest tests/Feature/Finance/CommissionCalculationTest.php` | **21 passed** (126 assertions) |
| `pest tests/Unit/Finance tests/Feature/Finance` | **168 passed** (971 assertions) |
| Full suite `pest` | **3304 passed, 0 failed**, 52 risky (pre-existing); 1,350 s |

The tests cover:
- **Client baseline (exact):** collection 15,000.00; funds issued 3,000.00; diesel 1,700 + food 1,000 + toll/parking 250 = **2,950.00** expenses; **50.00** returned (unit balance 0.00); base **12,050.00**; Company **7,230.00**, Driver **1,446.00**, Team Leader **1,205.00**, Rider **1,205.00**, Crew Mate **964.00**; crew total **4,820.00**; reconciles; no blockers under a confirmed policy. The base and Company share are identical before and after the 50.00 return.
- **Missing people:** under the seeded draft policy the result is "Draft / For Client Review" with the only blocker `policy_for_client_review`. An absent rider leaves 1,205.00 **unallocated**, nobody else gets more, and the line still reconciles to 12,050.00. No personnel recorded means no invented people (even with a booking TL and driver), all crew shares unallocated, and blocked. An unmapped role is shown provisionally and blocked.
- **Blockers:** a partial payment is left out of the calculation and blocked. A pending expense blocks and is not deducted. A monthly maintenance expense blocks and stays out of the base. A personal advance blocks and is counted once. A zero base (and a −100.00 base) blocks with no shares. Invalid percentages (sum 99, or a negative rate) block with no shares.
- **Two units:** a group trip of 10,000 + 5,000 with expenses of 1,000 and 500 gives two independent lines (bases 9,000 / 4,500; Company 5,400 / 2,700). Collections total 15,000.00 once, expenses 1,500.00 once; the unit filter works.
- **Rounding:** 1,000.01, 1,234.57, 9,999.99 − 0.02, and 0.03 all reconcile to the centavo. A shared crew-mate role splits exactly.
- **Deductions:** replay is idempotent; duplicate by reference and duplicate by amount are refused; the database index rejects duplicates too; pending blocks and is not deducted; approved deducts once (base 14,500.00). A live online payment blocks until an approved payment-fee deduction exists (base 9,750.00).
- **Page and access:** the Owner page shows base 12,050 / Company 7,230 / crew 4,820 and the Draft blocker, and writes no statement, line or payout. Five non-Owner roles get 403 on the page and on deductions; the service refuses them too.

## Phase 6: what was built

**Migration `2026_11_15_000000_commission_statements_and_crew_recording`** (run only on `towmate_jarz_testing` by the test suite):
- `finance_job_personnel`: `is_substitute`, `notes`, `change_reason`, `confirmed_by/at`, `superseded_by_user_id`, `supersede_reason`. Extra positions `extra_1..3`. One current position per booking × **unit** (a group job can have a Team Leader per unit). One current row per person × role per job and unit. CHECKs: a superseded row needs a reason; a confirmed row cannot need review. Triggers freeze the new fields, the reason, and confirmation.
- `finance_commission_policies.confirmed_rules` (jsonb, frozen with the rates once the policy leaves draft).
- `finance_commission_statements`: `unit_id`, collection / expense / deduction / unallocated totals, `job_count`, `included_jobs`, `calculation` + `calculation_hash`, submission, return and correction fields. Status `for_review` added. Transitions allowed by trigger: draft → for_review, for_review → draft, for_review → approved, approved → voided. Content is frozen while For Review; everything including the snapshot is frozen once approved or voided. One open (draft or For Review) statement per week × unit; revisions unique per week × unit. An approved statement can never hold unallocated amounts (CHECK).
- `finance_commission_lines`: `unit_id`; `revenue_allocation_id` is now optional (automatic allocations are calculated, not stored); unique per statement × job × unit × role × recipient. Unallocated shares are stored as `recipient_key = 'unallocated'` and are never payable.
- New `finance_commission_settlements`: one row per member booking × unit settled by an approved statement. **Partial unique index: one active settlement per booking × unit.** Insert only for an approved statement and release only after it is voided (trigger); never deleted.

**Crew recording (`JobCrewService`, Owner-only ability `finance.crew.manage`):**
- Record a participant on a completed job: towing unit (one of the job's units), person (an active personnel record or a Team Leader account), **performed role chosen explicitly**, optional substitute flag, borrowed-from unit and notes. Recording by the Owner is the confirmation.
- Correct (supersede plus replacement) and remove (supersede only) both require a reason; the old row stays with who changed it, when and why. Confirm clears a captured or backfilled row flagged for review.
- Refused: no role, a unit that did not work the job, the same person twice in the same role, non-Team-Leader accounts, unfinished jobs. Job rows are locked while editing.
- Supporting information is displayed and never recorded: the booking's assigned Team Leader and driver name, crew loans active on the service date, and the unit's **current** roster labelled "not evidence of who worked".

**Role rules (client decisions, enforced by `CommissionCalculator`):** each blocks approval unless an active, client-confirmed policy records the accepted answer in `confirmed_rules`:

| Situation | Blocker | Rule key and accepted answer |
|---|---|---|
| Someone recorded as Rider / Substitute | `rider_role_pending` | `rider_role = confirmed` |
| Substitute flag or borrowed from another unit | `substitute_rule_pending` | `substitutes = paid_in_recorded_role` |
| Two or more people in one role | `shared_role_rule_pending` | `shared_role = split_equally` |
| One person in two roles on one job | `combined_role_rule_pending` | `combined_roles = paid_per_role` |
| A row without a chosen role | `role_mapping_unresolved` | none: the Owner must choose the role |

The equal split and the provisional slot mapping are still **shown** for review, but they can never be approved without the rule. No screen sets `confirmed_rules`; it is recorded through `CommissionPolicyService::confirmInterpretation` only when the client answers.

**Statements (`CommissionStatementService`):**
- **Prepare:** the Owner picks the week and unit and selects jobs (default: every unsettled job, including blocked ones, so issues are visible). Jobs already settled by an approved statement cannot be selected. The calculator output for exactly those jobs is stored with its sha256, and lines are written from it. Recalculating a draft replaces its lines.
- **Submit for review:** allowed with open issues (they are what the review is for) but refused if the source records changed since calculation.
- **Return to draft:** with a reason.
- **Approve:** Owner-only. Locks the row and a week × unit advisory lock; requires status For Review, an active client-confirmed policy, **zero calculator blockers**, no job settled elsewhere, an **identical recalculation** (otherwise `calculation_changed`), and exact line totals. It then writes the snapshot (statement totals, included jobs, unit, the full calculation with job references, collections, source expense and deduction ids, personnel ids and roles, policy version, rates and confirmed rules, every line, approver, approval time, and the statement's audit log ids), its hash, and the settlements, all in one transaction. A lost race on the settlement index is reported as a conflict, not an error.
- **Void / Correct:** Owner-only, reason required. Correct voids the approved statement (snapshot kept and still verifiable), releases its settlements, and creates a new draft revision linked by `supersedes_statement_id` with the correction reason.
- `verifyIntegrity()` checks the snapshot hash and that the stored statement, lines and calculation still match it.

**Pages** (Finance sidebar: "Job Crew", "Commission (Draft)", "Commission Statements"):
- `/superadmin/finance/job-crew` lists the week's jobs with crew status per unit. `/job-crew/{booking}` has the per-unit crew, record/correct/remove/confirm forms, supporting information, and the corrected/removed history.
- `/superadmin/finance/commission-statements` (list and filters), `/new` (job selection), `/{id}` (detail):
  - Open statements show period and unit, policy, live eligibility with every blocker, totals (collections, expenses, deductions, base, Company, personnel, unallocated), included jobs with full breakdown, personnel commission, history, and Recalculate / Submit / Return / Approve controls.
  - Approved and voided statements show only the frozen snapshot, the approver and time, the hash and an integrity result, plus Correct and Void controls.

**Audit actions:** `finance_crew_recorded/corrected/removed/confirmed`, `finance_statement_prepared/recalculated/submitted/returned/approved/voided`.

## Phase 6 verification

| Check | Result |
|---|---|
| `pest tests/Feature/Finance/CommissionStatementsTest.php` | **20 passed** |
| `pest tests/Unit/Finance tests/Feature/Finance` | **188 passed** (1,248 assertions) |
| Full suite `pest` | **3324 passed, 0 failed**, 52 risky (pre-existing); 2,774 s |

The new tests cover:
- **Crew recording:** actual crew linked to booking, unit and role, with audit rows. Booking, roster and loan information is shown but creates no records, and the job stays blocked with `no_personnel`. Historical attribution survives the unit's roster change and the person going inactive. Correct and remove require a reason, keep the superseded history (immutable), and refuse re-correction. Invalid records are refused (no role, other unit, duplicate, non-Team-Leader account, unfinished job). A captured row needing review blocks until the Owner confirms it.
- **Role rules:** under a confirmed policy without the answers, a Rider, a substitute, a shared role and a combined role each block. With every answer explicitly recorded, the blockers clear, and the shared split is exact (120.00 → 60.00 / 60.00).
- **Workflow:** missing crew blocks approval (unallocated 400.00, no settlement). Draft → For Review → Approved, with return to draft (reason required), skipped steps refused, lines locked while For Review, and the full audit sequence.
- **Policy:** an unconfirmed policy shows Draft / For Client Review, the policy blocker, and refuses approval.
- **Valid approval (TEST-confirmed policy fixture, test database only):** 15,000 collected, 2,950 expenses gives base 12,050.00, Company 7,230.00, personnel 4,820.00, unallocated 0.00. The snapshot has the unit, job keys, rates, interpretation, source expense ids, personnel and Team Leader ids, amounts, approver, approval time and audit references. Integrity is verified, one settlement exists, and the audit row carries the hash.
- **Duplicates and concurrency:** a booking × unit settled by another approval meanwhile is refused (status stays For Review); a second approval is refused. A settled job cannot be selected or re-generated; a supplementary statement settles only the new job (revision 2). The database rejects a duplicate active settlement, deleting or releasing a settlement without a void, and a second open statement per week × unit.
- **Immutability after approval:** after a crew correction, a new policy (50/20/10/10/10) and a new approved expense, the hash, total and lines are identical, the snapshot still names the original driver, and integrity passes; the page shows the snapshot. The live preview shows the new base 900.00 and the new driver.
- **Payments and sources:** an unverified job and a partly paid job are blocked (`payment_not_verified`, `allocation_pending`). A pending expense blocks. An expense approved after submission makes the statement stale (`calculation_changed`); approval is refused until it is returned, recalculated and resubmitted (base 4,500.00).
- **Corrections:** reason required. The original is voided with its hash and integrity intact, its settlement released, and a linked revision 2 draft created with the same jobs; re-approval moves the active settlement to it.
- **Pages and access:** Owner end-to-end through the pages (record crew, select jobs, submit, approve). Five non-Owner roles get 403 on every crew and statement page and action, and the services refuse them too.
- **Regression updates:** the Phase 1 statement tests now use the real flow (the old manual-line fixture cannot be approved any more, by design). The Phase 5 unconfirmed-policy test now also expects `rider_role_pending`. The Phase 1 personnel test now supplies a supersede reason.

## Phase 7: what was built

**No money moves.** TowMate only records a payment the Owner has already made outside it (cash, GCash or bank transfer). Choosing a method never starts a transfer and never marks a statement Paid.

**Migration `2026_11_16_000000_payout_records_and_paid_statements`** (run only on `towmate_jarz_testing` by the test suite):
- `finance_payouts`: `recipient_roles`, `recorded_by_name`, optional evidence (`evidence_path` on the private disk, original name, MIME type, sha256), `notes`, `corrects_payout_id`. CHECKs: a transaction reference **or** evidence is required; `recipient_key` can never be `unallocated` (or `company`, from Phase 1). All payout content is frozen by trigger. A payout of a Paid statement cannot be voided (trigger).
- `finance_commission_statements`: status `paid` with `paid_by`, `paid_by_name`, `paid_at`. Transition approved → paid only, and a trigger refuses it unless recorded payouts equal `personnel_amount` exactly. Paid statements are frozen like approved ones (including `paid_at`), cannot be voided, and cannot hold unallocated amounts.

**`PayoutService`** (Owner-only ability `finance.payouts.record`):
- **Payable amounts come from the approval snapshot** (`approval_snapshot.lines`), grouped by recipient. Company and unallocated lines are excluded. The amount is never typed and never recalculated from current crew or rates. Recording also re-checks the snapshot's integrity.
- **Record:** recipient, roles (from the snapshot), exact approved amount, actual payout date (not in the future, not before the statement period), method (Cash, GCash, Bank Transfer), transaction reference and/or evidence file, notes, and the recording Owner. Only for **Approved** statements (not draft, for review, voided or paid). One recorded payout per recipient per statement: idempotency key, row lock, service check, and the database unique index.
- **Supported workflow:** one **full** payout per person. Partial disbursements and one combined (group) payout for several people are pending client confirmation and are not supported.
- **Void / Correct:** reason required. Correct voids the wrong payout and records a linked replacement (`corrects_payout_id`) in one transaction. Originals stay with their reference, reason and audit rows. Not allowed once the statement is Paid.
- **Reconciliation:** per person approved amount vs recorded payout; totals for sharing base, company share (not a payout), crew payable, recorded and remaining; issues list.
- **Mark Paid:** explicit Owner action, only when every payable person has exactly their approved amount recorded and the total equals the personnel amount. Audited (`finance_statement_paid`).

**Pages:**
- The statement detail page has a **Payouts** section (Approved and Paid statements): base / company / crew payable / recorded-remaining figures, a row per person with approved amount and payout status, record and correct forms (read-only amount, date, method, reference, evidence, notes), void, the voided-payout history, and **Mark statement Paid** (enabled only when reconciled). Evidence is served only to the Owner through `/superadmin/finance/payouts/{id}/evidence`.
- **Printable statement** `/superadmin/finance/commission-statements/{id}/print` (browser Print / Save as PDF) and `/pdf` (rendered by the existing `DocumentGenerationService::renderReportPdf`, Dompdf, with the company letterhead settings). It shows statement number and revision, period, unit, policy version, status (Draft / For Review / Approved / Paid / Voided, with a "must not be paid" warning before approval), summary (collections, expenses, deductions, base, company share, personnel payable, unallocated), included bookings, approved expenses and deductions with references, personnel names, roles, rates, jobs and amounts, unallocated rows, approval details and hash, recorded payouts (date, method, reference, recorder) with totals, voided payouts, and signature lines. Approved, Paid and Voided statements print from the frozen snapshot.

**Phase 6 review (Job Crew):** the Owner can record Driver, Rider / Substitute and Crew Mate participants from existing **personnel records**; no login account is needed. Only *user accounts* are limited to Team Leaders (so a Driver or Customer account cannot be chosen by mistake). The restriction was correct and was not changed; a regression test records all three roles from personnel records through the page and confirms no user account is created or linked.

**Audit actions:** `finance_payout_recorded/voided/corrected`, `finance_statement_paid`.

## Phase 7 verification

| Check | Result |
|---|---|
| `pest tests/Feature/Finance/PayoutsTest.php` | **15 passed** |
| `pest tests/Unit/Finance tests/Feature/Finance` | **203 passed** (1,460 assertions) |
| Full suite `pest` | **3339 passed, 0 failed**, 52 risky (pre-existing); 2,259 s |

The tests cover:
- **Baseline:** base 12,050.00, company 7,230.00 (refused as a payee), crew payable **4,820.00** = 1,446 + 1,205 + 1,205 + 964. After all four payouts the statement is still Approved until the Owner marks it Paid; then it is Paid, the base is unchanged, and payouts total 4,820.00.
- **Payout record fields:** statement, recipient, role, amount, date, GCash label, reference, recording Owner, audit row.
- **Restrictions:** refused for For Review, Voided and Paid statements. Reference or evidence required; invalid method, future date and pre-period date refused. Evidence is stored privately with sha256, hidden from serialization, served to the Owner only, and frozen.
- **Duplicates and concurrency:** idempotent replay, same key with different details refused, second payout for the same person refused, and the database unique index blocks a payout that bypasses the service.
- **Reconciliation:** partial recording (remaining 2,169.00) cannot be marked Paid; the database refuses a direct status change; recording never marks Paid; double mark-Paid refused.
- **Unallocated and company:** neither is payable (service, CHECK); an approved statement cannot be given an unallocated amount.
- **Snapshot authority:** after a crew correction and a new 40/30/10/10/10 policy, the original driver is still paid 1,446.00 and the new driver has no earnings.
- **Immutability:** payout amount, evidence and deletion are refused; voiding a Paid statement's payout is refused (service and trigger); a Paid statement's amounts, status and paid time cannot change; it cannot be voided; snapshot hash and integrity unchanged.
- **Corrections:** reason required; the original is voided and kept (reference intact), the replacement is linked and exact, the history appears on the page.
- **Printable statement:** reference, unit, period, booking, collections 15,000.00, expenses 2,950.00, base 12,050.00, company 7,230.00, personnel 4,820.00, unallocated 0.00, names, roles, 12% rate, amounts, Approved status, approver, payout reference; the PDF route returns a real PDF. An unapproved statement prints as "FOR REVIEW — NOT APPROVED … must not be paid" with no payout section.
- **Pages and access:** Owner end-to-end (record four payouts, Mark Paid enabled only when reconciled, Paid shown). Five non-Owner roles get 403 on record, void, mark Paid, print, PDF and evidence, and the services refuse them.
- **Phase 6 regression:** Driver, Rider and Crew Mate recorded from personnel records without login accounts.
- **Updated earlier tests:** the Phase 1 payout tests now supply a transaction reference (now required).

## Open client decisions

- Commission interpretation C1–C6 ([spec.md §6](spec.md#6-commission-pending-client-confirmation)).
- **Monthly maintenance allocation:** unit/monthly expenses are recorded, approved and deducted from unit funds when paid from them, but they are **not allocated** to jobs or commission. Each one is flagged "Monthly allocation rule pending client confirmation".
- **Personal advance reimbursement:** recorded as a funding split and flagged. No reimbursement, payable or Team Leader debt is created.
- **Overspending:** recorded as entered with a shortfall snapshot. Whether a negative unit balance should trigger anything (for example, top-up or deduction) is the client's decision; nothing automatic happens.
- **Evidence exemptions:** every expense currently needs evidence before verification and approval (enforced by a DB trigger). If the client wants exemptions (for example, a toll with no receipt), that needs a rule decision and a migration.
- **Sunday activity (minor):** reporting weeks are Monday–Saturday. Sunday-dated entries count in balances and carry into the next week; the default view includes today when today is Sunday. Jobs & Collections flags Sunday service and late payments. Which week Sunday belongs to in reports still needs confirming.
- **Multi-unit revenue (new):** when another unit helps on a one-booking job, or a job is only partly paid, the allocation stays pending until the Owner records a split. Whether a standard rule should apply to an approved scenario (for example, a fixed share for assistance) is the client's decision. Splitting a group trip by hand is not supported yet (group vehicles are allocated to their own units).
- **Refunds and provider fees:** TowMate does not record refunds or PayMongo fees automatically. Since Phase 5 they can be entered as commission deductions (pending review, then approved). Whether fees and refunds should reduce the sharing base at all, and who bears them, is part of C1.
- **Commission (Phase 5), all Draft / For Client Review:**
  - *C1 sharing base:* implemented as allocated verified collection − approved job expenses − approved deductions, with **all expense categories and funding sources** (company-paid and personal advances included) treated as job costs. To confirm: whether VAT is excluded first, and whether any category is excluded.
  - *C2 shared roles:* two people in one role are shown splitting one share equally. Since Phase 6 this blocks approval until the policy records `shared_role = split_equally`. To confirm: pool vs per person.
  - *C3 empty roles:* the share stays unallocated (as instructed). To confirm: whether unallocated shares ever go to Company.
  - *C4 role mapping:* since Phase 6 the Owner records each person's performed role explicitly; the provisional slot mapping only applies to rows without a role, which block. Paying a Rider, a substitute, or one person in two roles each blocks until the policy records the client's answer (`rider_role`, `substitutes`, `combined_roles`). To confirm, including how a substitute who replaces the main driver is paid.
  - *C5 group trips:* each vehicle's allocated collection is the base for its unit. To confirm.
  - *Payout distribution (Phase 7):* only one full payout per person per statement is supported. To confirm: whether partial disbursements (advances, instalments) or one combined payout for a group (for example to the Team Leader for the whole crew) are allowed, and how they are evidenced.
  - *C6 corrections after approval:* implemented in Phase 6 as void (with reason, original and snapshot kept) plus a re-issued draft revision for the same jobs. To confirm: whether corrections should instead be adjustments in a later week (relevant once payouts exist in Phase 7).
  - *Which date puts a job in a week:* implemented as the **service date**. A late payment makes the job wait, blocked, until verified. To confirm, including Sunday jobs (currently outside every week and reported as a notice).

## Known risks and notes

- The crew is stored only on `Unit` as live data, so per-job crew exists only where the Owner records it on the Job Crew page (Phase 6). Past jobs are not backfilled. Jobs & Collections never uses the current crew.
- Recording a person as borrowed from another unit makes the job's revenue allocation "pending review" (Phase 4 rule: another unit appears to have helped), so a split must be recorded before that job can be approved.
- A statement's figures are tied to the moment of calculation. Any later change to payments, expenses, deductions, crew or policy makes an open statement stale, and approval refuses it until it is recalculated. Approved statements never change; corrections go through void and re-issue.
- A Paid statement is final: its payouts cannot be voided and it cannot be voided or corrected. A payout mistake must be corrected before marking Paid. Whether a reopening procedure is needed after Paid is a client decision.
- Payout evidence files live on the private `local` disk under `finance/payouts/{statement}`; like expense evidence they are not in the database backups unless the storage folder is backed up.
- The Phase 1 statement fixture `finDraftStatement` (manual lines) is still used by schema tests for draft-only behaviour; such a statement can no longer be approved because approval recalculates.
- `ReportMetricsService` sums with floats. The Phase 4 consistency test shows its confirmed-revenue total equals the exact Jobs & Collections total for the tested cases. It was not changed.
- **Existing Revenue page defect (found, not changed):** the "Cash Received" card sums `bookings.cash_received` over every vehicle row, but a group payment stores the same group cash on every vehicle row, so group cash is counted once per vehicle. Jobs & Collections reads it once per job. Fixing the Revenue page is outside this module and needs approval.
- Booking counts differ by design: Jobs & Collections counts a group trip as one booking with N vehicles; the existing Revenue page's "Completed Jobs" counts vehicle rows.
- Expenses with `funding_source = unit_fund` count as spending on their `expense_date` once approved. If an approval lands after the period has been reviewed, that period's figures change. This is intended (it reflects actual spending dates), but the Owner should know.
- `ActiveBookingsController::updateStatus()` can set `completed` without `completed_at`. Finance requires `completed_at`.
- `cash_received` is the cash tendered, not the amount collected.
- The finance tables use FK `RESTRICT` to bookings and units. `UnitController::forceDelete` now refuses units with finance history. The dev seeding commands (`SeedTlDemo*`, `SeedQuotationTestScenarios`) delete bookings and would be blocked only if those bookings had finance rows, which they never do.
- The migration `down()` drops the finance tables. It is for development rollback only and must never be run against real data.
