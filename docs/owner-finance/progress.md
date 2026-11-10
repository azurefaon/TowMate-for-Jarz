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
| 2026-11-10 | Phase 7: Payout records, Paid status and printable statements | Done (`166bd010`). See "Phase 7" below. No transfers; no real payouts recorded |
| 2026-11-10 | Phase 8: Finance Overview, UI completion, Team Leader expense screen, Revenue cash fix | Done (`f6d3e273`). See "Phase 8" below. Live click-through and on-phone checks remain on the manual checklist |
| 2026-11-10 | Phase 9: Finance Reports and exports (page, print/PDF, Excel) | Done (`4f25a175`). See "Phase 9" below |
| 2026-11-10 | Phase 10: Demo environment and Client Review | Done (`5effc13b`). See "Phase 10" below |
| 2026-11-10 | Phase 11: Final integration and QA | Done (`f569c045`). See "Phase 11" (acceptance matrix) below |
| 2026-11-10 | Phase 12: Staging preparation and handoff | Done. See [handoff.md](handoff.md) and "Phase 12" below. Ready for staging review; real approvals blocked by pending client decisions |

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
- [x] **Phase 3: Expenses and receipt review.** Completed in Phase 8 (the two open items below):
  - [x] The Owner Expenses page, the Dispatcher Expense Review queue, and Team Leader API endpoints.
  - [x] Mixed funding; job vs unit/monthly scope; required evidence on private storage; overspend warning; corrections and voids.
  - [x] Integrated with the Unit Funds ledger.
  - [x] Phase 2 correction: daily issuances are allowed.
  - [x] **Team Leader mobile expense screen**: built in Phase 8 (Flutter "Expenses" tab). Not yet tried on a real phone (manual checklist).
  - [x] **Browser visual verification**: real headless-Chrome renders at desktop and 390 px reviewed in Phase 8; defects fixed. Live click-through remains on the manual checklist.
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
- [x] **Phase 8: Overview and UI completion.**
  - Finance Overview (actual collections, expenses, unit funds, draft commission, approved commission and payouts, needs attention), unit and period filters.
  - UI review with fixes from real browser renders; Team Leader expense screen (Flutter); Revenue "Cash Received" group duplication fixed.
- [x] **Phase 9: Finance Reports and exports.** Report page with period, unit, personnel, payment, expense and statement filters; printable report / PDF and Excel from one data set.
- [x] **Phase 11: Final integration and QA.** Acceptance matrix below; 4 defects fixed and re-tested.
- [x] **Phase 12: Staging preparation and handoff.** [handoff.md](handoff.md): ERD, routes and permissions, calculation rules, demo setup, Owner guide, client demo checklist, decisions, deployment and rollback.
- [x] **Phase 10: Demo environment and Client Review.** Isolated `*_demo` database with six scenarios built through the real services, a guarded reset, simulated payouts, and a Client Review register with a downloadable summary.

### Open items carried forward (not complete)
- [x] **Team Leader mobile expense screen**: done in Phase 8 (widget-tested; **not yet tested on a real phone**, and the APK has not been rebuilt).
- [ ] **Manual browser interaction checks (still open):** click-through on a running server, real print dialogs, and the Phase 9 report exports opened in Excel and a PDF viewer. See the Phase 8 checklist; add: Finance Reports → change each filter → Print / Save as PDF and Download Excel → totals match the screen.
- [ ] **Physical-device Flutter verification (still open):** the Team Leader expense screen has only widget tests; it has not been run on a phone, and the APK has not been rebuilt (not part of Phase 9).
- [~] **Browser visual verification**: real rendered pages reviewed with headless Chrome in Phase 8 (desktop and 390 px) and the defects found were fixed. **Still manual:** interactive click-through on a running server and printing from a real browser dialog (see the Phase 8 checklist).
- [x] **Existing Revenue page "Cash Received" duplication**: fixed in Phase 8 with a regression test (group cash counted once per trip).
- [ ] **Paid statement corrections**: a Paid statement and its payouts are final. No reopening or adjustment procedure exists, and none was invented; it is a client decision.
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

## Phase 8: what was built

**Finance Overview** (`/superadmin/finance`, first item in the Finance sidebar, labelled "Finance Overview"; `FinanceOverviewService` + `OverviewController`, Owner-only):
- Unit and reporting-period filters (the shared date-range control; default this Monday–Saturday week).
- Five separate sections, never summed together:
  1. **Customer collections (actual)** from `JobCollectionService` (service-date basis, as on Jobs & Collections): jobs and vehicles, agreed charges, verified collections, outstanding balances, over-collection.
  2. **Expenses** from `ExpenseService::summary` (moved out of `ExpenseController` so both pages share it): approved (by funding source), awaiting Dispatcher, awaiting Owner, rejected.
  3. **Unit funds** from `UnitFundLedger`: opening, issued, spent, returned, remaining, awaiting review.
  4. **Commission — draft estimate** from `CommissionCalculator`, week by week for every Monday–Saturday week starting in the period: sharing base, company share, crew commission, unallocated, and allocated / unallocated per role, labelled "not approved and may change".
  5. **Approved commission and recorded payouts** from approved and Paid statements only (frozen figures) and recorded payouts: counts, base, company share, crew payable, payouts recorded and remaining.
- **Needs attention:** expenses awaiting each review step and without evidence, pending unit allocations, open and For Review statements, and every commission approval blocker with a count, each linking to the page that resolves it.

**Revenue page "Cash Received" defect (fixed):** reproduced first. A ₱15,000 two-vehicle group trip showed ₱30,000, because the group cash is stored on every vehicle row and `ReportMetricsService::revenueSummary` summed the column. Minimal fix: `cashReceivedOncePerCollection()` counts the cash once per normalized group trip (same group code, pickup and drop-off, the `Invoice::sameTripMembers` definition); single bookings are unchanged. No other report metric was changed: total revenue, completed-job counts (still vehicle rows) and the verified-payment rules are untouched. It feeds the Revenue page, the summary PDF and the XLSX export, which already shared this method.

**Team Leader expense screen (Flutter):** a new "Expenses" tab in the Team Leader bottom navigation (`/tl-expenses`), on the existing `/api/v1/team-leader/expenses` endpoints and the existing login token. No new account type or expense service.
- **List:** each expense shows category, peso amount, reference, date, job or month, description, review status (Awaiting Dispatcher, Verified, Approved, Rejected with reason, Voided), receipt count and funding. Pull to refresh, empty and retry states.
- **New expense form:**
  - The unit is the Team Leader's own, shown with available unit funds; the server always decides the unit.
  - Job expense (booking reference) or unit/monthly (month covered), category, amount (exact decimal, at most two places), paid from (issued unit funds, direct company payment or personal advance), date paid (no future dates), description, and 1–5 receipt photos (camera or gallery; size checked).
  - Inline validation; server validation messages are shown as returned.
  - The submit button is disabled with a spinner while sending.
  - One idempotency key per form, so a retry after an error cannot create a duplicate.
- Splitting one expense across funding sources stays an Owner correction on the web, and the form says so.

**UI review and fixes (all Finance pages):**
- Overview added to the sidebar. Every Finance page renders for the Owner and returns 403 to the other five roles.
- Defects found in real browser renders and fixed in `finance.css`:
  - Native filter selects rendered unstyled on every Finance page (Unit filter on Unit Funds, Expenses, Collections and others); they now match the 44px TowMate control.
  - Disabled buttons (for example "Mark statement Paid" before reconciliation, "Approve" with blockers) looked enabled; they now look disabled.
  - An amount column ran into the next header ("Approved amountPayout").
  - Action cells were squeezed on phones; they keep a usable width and the table scrolls.
- Frozen statements listed rates and roles in jsonb key order ("Rider, Driver, Company…"). `CommissionCalculator::inRoleOrder()` restores policy order on the statement page and the printable statement.
- The printable statement PDF spilled a one-line footer onto a blank second page; tightened to one page.

## Phase 8 verification

| Check | Result |
|---|---|
| `pest tests/Feature/Finance/OverviewTest.php` | 5 passed |
| `pest tests/Feature/Finance/TeamLeaderExpenseAppTest.php` | 3 passed |
| `pest tests/Feature/SuperAdmin/RevenueGroupCashTest.php` | 4 passed (all 4 **failed before the fix**: 30,000 / 62,500 instead of 15,000 / 32,500) |
| Existing reporting tests (`ReportsFinancialConsistencyTest`, `ReportXlsxExportTest`, `OwnerAnalyticsUiTest`) with the fix | 39 passed |
| `pest tests/Unit/Finance tests/Feature/Finance` + Revenue regression | **215 passed** (1,576 assertions) |
| Full suite `pest` | 3350 passed, **1 failed** (`OwnerSidebarNavigationTest`: it forbids a generic ">Overview<" sidebar label), 52 risky (pre-existing); 1,411 s. Fixed by labelling the item "Finance Overview"; the sidebar test plus all Finance tests were then re-run: **220 passed**. The full suite was not re-run after that label-only change. |
| `flutter test test/screens/team_leader/tl_expenses_screen_test.dart test/widgets/tl_bottom_nav_test.dart` | 26 passed |
| Full `flutter test --no-pub` | **1,560 passed** |
| `flutter analyze --no-pub` | 0 errors, 0 warnings; 27 infos, all pre-existing (none in the new files) |

The tests cover:
- **Overview:** baseline figures from each service (collected 15,000; approved expenses 2,950; funds 3,000 / 2,950 / 50 / 0; draft base 12,050, company 7,230, crew 4,820; driver 1,446). After approval and one payout, approved 12,050 / 7,230 / 4,820 and payouts 1,446 recorded, 3,374 remaining, shown separately from the draft. Unit filter (17,000 all / 15,000 / 2,000); pending reviews and blockers listed. Owner-only (403 for five roles, guests redirected). Every Finance page is linked from the sidebar and renders with no placeholder text.
- **Team Leader app contract:** options for the Team Leader's own unit; the exact app payload (job scope, `booking_ref`, `fundings[company_direct]`, `receipts[]`) creates a submitted expense; a retry with the same key creates nothing new; the Dispatcher's verification shows in the app list; validation messages match what the app displays.
- **Flutter:** peso formatting; UUID v4 keys; 422 message parsing; list with statuses, amounts and rejection reason; empty and retry states; field validation blocks sending (including the missing receipt); a full submission sends the right fields and receipt, shows a server error, and the retry reuses the same idempotency key; no unit assigned shows a clear message; the bottom navigation has the new Expenses tab.
- **Revenue regression:** a group trip counts once; groups and single bookings add correctly; unit filter; Revenue cash equals the Jobs & Collections collected amount (15,000.00) and the Revenue page shows ₱15,000.00, not ₱30,000.00.

**Browser verification (what was actually done):**
- Headless Google Chrome rendered the real pages from test-database fixtures: the Owner Overview, Jobs & Collections, Unit Funds, Expenses, Job Crew (list and job), Weekly Commission, Commission Statements (list and approved statement with payouts), the printable statement, and the Dispatcher Expense Review.
- Screenshots were reviewed at 1366 px desktop and a true 390 px phone viewport (an iframe, because headless Chrome will not lay a window out narrower than 500 px). The four defects above were found this way and re-checked after the fix.
- The generated PDF was opened and read: one page, correct figures, and roles in policy order.

**Not verified in a live browser session:** clicking through forms in a running server (date-range popover, file upload, print dialog) and the Flutter screens on a real phone. Form behaviour is covered by HTTP and widget tests. The manual checklist is below.

### Manual verification checklist (to do on a running local server and a phone)
1. Owner: open Finance → Overview, change the unit and the date range, and check that every section link opens the matching page with the same filter.
2. Owner: on each Finance page, open the Unit filter and the date range on desktop and a phone-width window; confirm nothing overflows except tables, which scroll sideways.
3. Team Leader (phone, APK built from this branch): Expenses tab → New expense → take a receipt photo, submit; it appears as "Awaiting Dispatcher". Try a future date and a three-decimal amount and check the messages.
4. Dispatcher: Expense Review shows that expense with its receipt; verify it. Team Leader pull-to-refresh shows the new status. Owner approves under Expenses.
5. Owner: Commission Statements → open an approved statement → Printable statement → the browser's Print → Save as PDF; also Download PDF. Both should be one A4 page for a one-job statement.
6. Owner: record a payout with an evidence file, open "View evidence", then try "Mark statement Paid" before and after all payouts are recorded.
7. Revenue page: with a real group trip in the period, "Cash Received" equals the group's cash once.

## Phase 9: what was built

**Finance Reports** (`/superadmin/finance/reports`, sidebar "Finance Reports"; Owner-only `finance.view`):
- `FinanceReportService::build()` produces ONE data set used by the page, the printable report, the PDF and the Excel workbook, so all four show identical totals. It calculates nothing of its own:
  - jobs, collections, outstanding balances and unit allocations: `JobCollectionService`
  - expense summary and the new `ExpenseService::breakdown()` (by category, by unit and by status; NUMERIC aggregates): `ExpenseService`
  - unit funds with the opening balance carried forward: `UnitFundLedger`
  - draft commission: `CommissionCalculator`, through `FinanceOverviewService::draftCommission()`, which was extracted from the Overview and is now shared by both
  - approved commission: approved and Paid statements and their frozen approval snapshots
  - payouts: recorded payouts only (voided excluded)
- **Sections:**
  1. Bookings and collections: completed bookings and vehicles towed as separate counts, final agreed charges, verified collections, outstanding, over-collected, allocated to units with a per-unit table, and one row per booking.
  2. Kept visible for review, with no rule applied: Sunday jobs outside the Monday–Saturday week, collections in the period for earlier service (late), unsettled payments or allocations, and commission approval blockers.
  3. Expenses: approved (split into unit funds, company and personal advance), awaiting review (not deducted), rejected or voided; by category and by unit.
  4. Unit funds per unit and in total: opening (carried forward), issued, spent, returned, remaining.
  5. Draft commission ("Draft / Pending Client Confirmation — not approved" while the policy is unconfirmed): base, company, crew, unallocated, per role and per person.
  6. Commission statements (frozen snapshots): count, base, company, crew payable, per statement and per person.
  7. Recorded payouts with method and reference, and the total paid.
- **Filters:**
  - period / custom range (Asia/Manila business dates; default this Monday–Saturday week)
  - towing unit
  - historical personnel: everyone ever recorded on a job, including corrected records
  - payment status (or "with an outstanding balance")
  - expense status
  - statement status (Approved and Paid by default; Approved only, Paid, Voided, or Draft and For Review marked NOT APPROVED)
- **Personnel filter behaviour:**
  - Jobs where the person is on the *current* crew record.
  - Their draft amount.
  - Their approved amount from frozen snapshots, so a later crew correction does not move approved money.
  - Their recorded payouts.
  - It does not apply to expenses or unit funds, which are not tied to a person.
- **Exports** (same filters, carried in the query string):
  - `/reports/print`: printable HTML with Print / Save as PDF (A4 landscape).
  - `/reports/pdf`: rendered by the existing `DocumentGenerationService::renderReportPdf` (Dompdf, company letterhead).
  - `/reports/excel`: `FinanceReportWorkbook` (PhpSpreadsheet, as the existing report export uses) with sheets Summary, Jobs, For Review, Expenses, Unit Funds, Draft Commission, Approved Commission and Payouts.
    - Every text cell passes through the existing `ReportWorkbookBuilder::safeText` formula-injection guard and is stored as text.
    - Amounts are numeric cells in `"₱"#,##0.00`; counts use `#,##0`.
    - Each sheet header repeats the period, filters and the policy status (DRAFT / PENDING CLIENT CONFIRMATION when unconfirmed).
- The page and the printable report share one Blade body partial (`documents/partials/finance-report-body.blade.php`).
- Downloads are Owner-only and rate-limited (20 per minute), sent with `Cache-Control: private, no-store`.
- No sample data: everything comes from recorded records. Test fixtures live only in the test database.

## Phase 9 verification

| Check | Result |
|---|---|
| `pest tests/Feature/Finance/FinanceReportsTest.php` | **12 passed** (138 assertions) |
| `pest tests/Unit/Finance tests/Feature/Finance` | **223 passed** (1,703 assertions) |
| Clean full suite `pest` (also confirms the Phase 8 sidebar label fix) | **3363 passed, 0 failed**, 52 risky (pre-existing); 1,402 s. `OwnerSidebarNavigationTest` passes in this clean run. |

The tests cover:
- **Totals:**
  - Baseline: 1 booking, 1 vehicle, agreed and collected 15,000, outstanding 0.
  - The 50.00 return is not counted as income.
  - Approved expenses 2,950 (issued 3,000 is not an expense), with personal advance 250 and company 1,000 shown separately.
  - Unit funds: issued 3,000, spent 1,700 (only unit-fund-paid expenses), returned 50, remaining 1,250.
  - Draft 12,050 / 7,230 / 4,820, with `personal_advance_pending` listed; by-category amounts exact.
- **Group trip:** one booking with three vehicles (6,000 + 5,000 on unit A, 4,000 on unit B) counts as 1 booking and 3 vehicles. The 15,000 cash stored on each row is counted once. Allocations of 11,000 and 4,000 reconcile to 15,000.
- **Unreviewed expenses:** an 800.00 pending expense is not an approved deduction and does not reduce the draft base; the expense-status filter works.
- **Filters:** unit (15,000 vs 2,000 outstanding), payment status (paid, outstanding), and unit funds filtered by unit.
- **Dates:**
  - Saturday 23:30 is inside the week.
  - A Sunday 00:30 job is listed under review, not counted.
  - A job serviced the previous week but paid this week is listed as a late collection.
  - A custom range including Sunday counts it normally.
  - Funds issued before the period appear as the 500.00 opening balance; 300.00 issued in the period gives 800.00 remaining.
- **Historical personnel:** after approval, the unit roster and the crew record change. The original driver still shows the approved 1,446.00 from the snapshot; the new driver shows the job but no approved amount; both appear in the filter options.
- **Snapshots and payouts:** approved 12,050 / 7,230 / 4,820; only the recorded payout counts (1,446.00; the voided one is excluded); the statement-status filter works.
- **Exports:**
  - The page and the printable report show the same figures; the Excel link carries the filters.
  - The PDF is a real PDF.
  - The Excel file is re-opened with PhpSpreadsheet: 8 sheets, its Summary values equal the report, money cells use the peso format, and the policy line is in the header.
- **Draft labels:** an unconfirmed policy shows "Draft / Pending Client Confirmation" on screen, in print and in Excel.
- **Injection:** a customer named `+cmd|calc` and a unit named `=HYPERLINK(...)` are exported as text with a leading apostrophe.
- **Empty period:** the page, PDF and Excel all work, with clear "no data" rows and zero totals.
- **Access:** five non-Owner roles get 403 on the page, print, PDF and Excel; guests are redirected.
- **Rendered output:** the report page (desktop and 390 px) and the generated PDF were rendered with headless Chrome and Dompdf and reviewed: correct figures, 3-page landscape PDF.

## Phase 10: what was built

**Isolated demonstration environment.**
- **Isolation rules:** `FinanceDemoGuard` allows demo data, the demo reset and simulated payouts only when `FINANCE_DEMO_ENABLED=true`, the app is not in production, and the database name ends with `_demo`. The automated tests may build the scenarios inside their own `*_testing` database (transaction-wrapped) but can never run the reset.
- **Reset:** `php artisan finance:demo-reset --env=demo` drops and rebuilds the demo database (`migrate:fresh`), then builds the scenarios. It refuses anywhere else.
- **Demo page:** `/superadmin/finance/demo` (Owner) lists the scenarios, the walkthrough and a reset control (type "RESET DEMO"). It returns 404 outside a demo database.
- **Settings:** `.env.demo.example` is committed; `.env.demo` is git-ignored. Mail goes to the log, PayMongo keys are blank, the queue is sync, and the Team Leader arrival simulator is off.
- **Marking:** a demo database shows a red "DEMONSTRATION DATABASE" banner on every Finance page and a "DEMONSTRATION DATA" line on generated statements and reports.
- **Scenarios** (`FinanceDemoScenarios`, built only through the real services: crew recording; Team Leader → Dispatcher → Owner expenses with receipt files; unit funds; statements; payouts). All are serviced on Wednesday of last week:
  1. **Baseline:** 15,000 collected; 3,000 issued; 1,700 + 1,000 + 250 = 2,950 expenses; 50 returned (remaining 0); base 12,050 → Company 7,230, crew 4,820. Approved, with two simulated payouts (DEMO-SIMULATED references); two left for the live demo, then Mark Paid.
  2. **Missing Rider:** 12,050 job → 1,205 unallocated; statement For Review and blocked. Also a Team Leader expense awaiting the Dispatcher.
  3. **Partial payment:** 7,500 verified of 15,000 (a simulated online payment) → allocation pending, not approvable.
  4. **Two-unit booking:** another unit helped → allocation pending review. The 50:50 split exists only as a Client Review proposal, never applied.
  5. **Personal advance:** 2,000 repair paid personally → `personal_advance_pending`, plus a Client Review item.
  6. **Three vehicles:** one booking, three vehicles, two units → 1 booking, 3 vehicles, 15,000 counted once (11,000 / 4,000 allocation).
- **Demo commission policy:** "DEMONSTRATION ONLY — stand-in for pending client decisions" (`base_definition = demo_stand_in`). It exists only so statements and simulated payouts can be shown. Every screen and document labels it "DEMONSTRATION stand-in — not a client confirmation". It is refused by the services outside a demo or test database: it can't be confirmed there, can't approve statements and can't record payouts. The real policy v1 stays Draft / Pending Client Confirmation.
- **Shared policy label:** `CommissionPolicy::labelForId()` now gives one English label on the statement page, the printable statement, the report and Excel ("active, client-confirmed" only when truly client-confirmed and not a demo stand-in).

**Client Review** (`/superadmin/finance/client-review`; Owner; table `finance_client_review_items`):
- The 11 standard pending decisions are inserted by migration as project configuration: C1–C6, week cutoff and Sunday, monthly allocation, personal advance, partial or group payouts, refunds and fees. Each has the question, the current provisional behaviour, and what it blocks.
- The Owner records the client response, requested revisions and remarks, and a status (Pending, In discussion, Client answered — awaiting implementation, Revision requested, Closed). The Owner can also add new decisions, revision requests or remarks.
- **Recording an answer never confirms a policy or changes a financial record.** Every change is audited with before and after values. Rows can't be deleted (trigger).
- Downloadable summary: printable page (`/client-review/print`) and PDF (`/client-review/pdf`).

## Phase 10 verification

| Check | Result |
|---|---|
| `pest tests/Feature/Finance/DemoAndClientReviewTest.php` | **9 passed** (128 assertions) |
| `pest tests/Unit/Finance tests/Feature/Finance` | 231 passed, **1 failed** → `PayoutsTest` "draft that must not be paid" expected the old lowercase policy wording; updated to the shared label; re-run `PayoutsTest`: **15 passed** |
| Real reset on a new disposable database `towmate_finance_demo` (`php artisan finance:demo-reset --env=demo --force`) | **Succeeded**: all migrations ran and all six scenarios were built; the Owner sign-in and scenario references were printed |

The tests cover:
- **Each scenario's figures:** baseline 15,000 / 2,950 / 50 / 12,050 / 7,230 / 4,820, funds remaining 0, simulated payouts 1,446 + 1,205, integrity verified; Rider 1,205 unallocated with `missing_role` and For Review; partial 7,500 of 15,000 with allocation pending and no approvable line; personal advance 2,000 blocked; two-unit pending with no allocation applied and the 50:50 proposal recorded; three vehicles counted once.
- **Whole-demo reconciliation in the Finance Report:** 6 bookings, 8 vehicles, collected 74,550, outstanding 7,500, approved crew 4,820, payouts 2,651, personal advance 2,000, policy labelled as a demo stand-in.
- **Isolation:** on a live-like database name, seeding, confirming a stand-in policy, recording payouts and approving under the stand-in are all refused. The reset command refuses (database name not `_demo`, or demo not enabled) and nothing is dropped; the demo page and reset are 404 and no banner shows.
- **Demo page:** on a demo database the page, banner and document marking appear for the Owner, a wrong confirmation phrase is rejected, and five other roles get 403.
- **Client Review:** 11 pending decisions; "answered" needs a response; answering C1 leaves policy v1 draft and pending; changes are audited; revision requests can be added; rows can't be deleted; page, print and PDF work; five roles get 403 on view, download, add and update, and the service refuses them.

## Phase 11: final integration and QA

**Defects found and fixed in Phase 11 (all re-tested):**
1. **Rate-limit counter shared across routes.** Laravel's `throttle:X,Y` uses one counter per user unless given a prefix. The demo reset (`throttle:3,10`) therefore counted all the Owner's earlier Finance POSTs, and a real UI reset returned **429** in the first browser run. The report and Client Review download limits had the same flaw. Fixed with the prefixes `finance-demo-reset` and `finance-exports`; regression test added; browser rerun passes.
2. **Reset guard hardening.** `FinanceDemoGuard::assertCanReset()` now also requires the *real* connection's database name to end in `_demo`, so no test configuration can ever reach `migrate:fresh` on the test database. Regression test added.
3. **Test helper name collision.** The new demo test's global helper `dmUnit()` collided with an existing test's helper of the same name. The full suite stopped with a fatal error (running the file alone hid it). Renamed to the `fdm*` prefix; no duplicate global test helpers remain.
4. **Phone layout:** Client Review detail rows squeezed long text into a narrow column at 390 px; they now stack.

**Browser interaction test (real Chrome, DevTools Protocol, scripted):**
- The app was served with `APP_ENV=demo` against the disposable `towmate_finance_demo` database (built by the Phase 10 reset).
- First run: 23 passed, 3 failed. One was a script false positive (it treated the red demo banner as an error alert); two were defect 1. Rerun after the fix: **26 passed, 0 failed**:
  - The Owner signed in through the real login form; the demo banner was shown; the Overview total was ₱74,550.00; the unit filter auto-submitted and narrowed to ₱15,000.00.
  - The Owner recorded an expense through the multipart form with a **real receipt file upload**, and the receipt is listed.
  - Statement: Mark Paid was disabled → two payouts recorded through the forms (bank transfer + reference) → Mark Paid enabled → marked **Paid** → statement PDF downloaded (`%PDF`) → printable statement shows "APPROVED AND PAID" and the demo marking.
  - Reports: the unit filter applied through the form; Excel (XLSX zip, `PK`) and PDF downloaded with the same filter in their links.
  - Client Review: a status and remark saved through the form; the summary PDF downloaded.
  - **390 px phone emulation:** Overview, statement, Reports and Client Review have **0 px page-level horizontal overflow** (tables scroll inside their cards).
  - Dispatcher: signed in, **403** on Owner Finance, verified the Team Leader's pending expense through the Expense Review form.
  - **Demo reset through the UI:** signed out → after signing in again the baseline statement is back to Approved (not Paid) and Unit 2 is For Review.
- **Private files:** a real receipt path on the demo server returns 403 at `/protected-storage/…` without a signature and 404 at `/storage/…` and the bare path; the authorised evidence route redirects a signed-out visitor to login.

**English text:** the Finance views, generated documents, services and the Team Leader Flutter screens were scanned for common Filipino words: none found. Every screen, flash message, PDF and Excel label is English.

### Acceptance matrix

| # | Area | Result | Evidence |
|---|---|---|---|
| 1 | Calculations reconcile exactly (15,000 baseline; centavo rounding) | **Passed** | `CommissionCalculationTest` (baseline, rounding datasets), `DemoAndClientReviewTest` baseline, `FinanceReportsTest`; browser Overview totals |
| 2 | Unit funds, expenses, verified collections, returned funds and payouts separate | **Passed** | `FinanceReportsTest` (issued ≠ expense, returned ≠ income, personal advance separate), `OverviewTest`, `PayoutsTest` (company not payable) |
| 3 | Missing personnel and unresolved allocations block approval | **Passed** | `CommissionStatementsTest` (missing crew, rider, shared and combined roles), demo Unit 2 / Unit 4 |
| 4 | Partial or unverified payments cannot create approvable commission | **Passed** | `CommissionStatementsTest` (unverified, partly paid), demo Unit 3 |
| 5 | Approved statements immutable | **Passed** | `FinanceSchemaTest` triggers, `CommissionStatementsTest` (changes after approval), `PayoutsTest` (Paid frozen) |
| 6 | Duplicate approvals, payouts, expenses and fund submissions prevented | **Passed** | idempotency and unique-index tests in `CommissionStatementsTest`, `PayoutsTest`, `ExpensesTest`, `UnitFundsTest`, `TeamLeaderExpenseAppTest` |
| 7 | Multiple towing units never duplicate revenue | **Passed** | `JobsCollectionsTest`, `FinanceReportsTest` (3 vehicles / 2 units → 11,000 + 4,000 = 15,000), `RevenueGroupCashTest` |
| 8 | Historical personnel accurate | **Passed** | `CommissionStatementsTest` (roster change), `FinanceReportsTest` (personnel filter after correction), `PayoutsTest` (snapshot authority) |
| 9 | Financial permissions enforced on backend requests | **Passed** | 5 non-Owner roles get 403 on every Finance route in each feature test; service-level `AuthorizationException`; browser: Dispatcher gets 403 |
| 10 | Private receipts and payout evidence protected | **Passed** | `ExpensesTest` and `PayoutsTest` evidence access tests; live probe on the demo server (403 / 404 / login redirect) |
| 11 | Reports, PDF and Excel reconcile with displayed records | **Passed** | `FinanceReportsTest` (page = print = Excel totals, Excel re-opened); browser downloads |
| 12 | Demo cannot affect production records | **Passed** | `DemoAndClientReviewTest` isolation tests (seed, stand-in policy, approval, payout and reset refused on a live-like database; reset never runs on the real test database) |
| 13 | All application and document text in English | **Passed** | text scan; rendered pages and PDFs reviewed |
| 14 | Existing booking, dispatch, payment confirmation, quotation, Team Leader, Owner and reporting workflows | **Passed** | full backend suite (below); only two existing behaviours changed, both intended and tested (unit force-delete guard; grouped-trip Cash Received) |
| 15 | Desktop and phone layouts | **Passed** (browser emulation) | 26 browser checks incl. 390 px overflow checks; Phase 8 screenshots |
| 16 | Forms, filters, receipt upload, review actions, statement printing, PDF and Excel | **Passed** (headless Chrome) | browser run 2 |
| 17 | Printing through the browser's print dialog or a physical printer | **Unverified** | headless Chrome cannot open the print dialog; the print stylesheet and the PDF output were verified. Steps: open any printable page → Print / Save as PDF → check one A4 page per one-job statement |
| 18 | Excel file opened in Microsoft Excel | **Unverified** | validated by re-opening with PhpSpreadsheet. Steps: Finance Reports → Download Excel → open in Excel → 8 sheets, peso formats, Summary matches the screen |
| 19 | Team Leader Flutter expense workflow on a device or emulator | **Unverified** | no Android device or emulator is attached (`flutter devices` lists only Chrome and Edge web). Covered by 26 widget tests and the API contract tests. Steps: build the APK from this branch → sign in as a Team Leader → Expenses → New expense → take a receipt photo → submit → it appears as "Awaiting Dispatcher"; Dispatcher verifies → pull to refresh shows the new status |
| 20 | Real statement approval and payouts | **Blocked (by design)** | client decisions C1–C6 and others are pending; policy v1 is Draft / Pending Client Confirmation |
| 21 | Staging host (Railway) behaviour, including persistent private storage | **Unverified** | not deployed. See handoff.md §8 (`PRIVATE_STORAGE_ROOT` needs a persistent volume) |

### Regression runs

| Run | Result |
|---|---|
| `pest tests/Feature/Finance/DemoAndClientReviewTest.php` after the fixes | **11 passed** |
| Full backend suite, first Phase 11 run | **Fatal error** (defect 3: helper name collision), no results |
| Full backend suite after the rename | **3374 passed, 0 failed**, 52 risky (pre-existing); 1,474 s |
| `flutter analyze --no-pub` | 0 errors, 0 warnings; 27 infos (pre-existing, none in Finance files) |
| `flutter test --no-pub` | **1,560 passed** |
| Browser QA (headless Chrome) | run 1: 23 / 26; **run 2: 26 / 26** |

## Phase 12: staging preparation and handoff

- **[handoff.md](handoff.md)** contains:
  - status and database migrations, with an ERD (Mermaid)
  - every Finance screen, route, permission and integration
  - the exact calculation and reconciliation rules
  - demo setup and reset instructions
  - a short Owner usage guide and a 10-step client demonstration checklist
  - the remaining client decisions and the actions each blocks, plus how to enable real approvals once answered
  - migration, environment, private-storage (Railway volume) and rollback notes
  - what is verified and what is not
- **Final verification after the last code change:** `pest tests/Feature/Finance tests/Unit/Finance` + Revenue regression + sidebar test: **260 passed** (1,978 assertions). `php artisan finance:demo-reset --env=demo --force` rebuilt `towmate_finance_demo` cleanly. The last full suite (3,374 passed) ran on the same code; Phase 12 changed only documentation.
- **The demo database is left freshly reset** for review (Owner `demo.owner@towmate.test`; password is `FINANCE_DEMO_PASSWORD` in the local, git-ignored `.env.demo`). The QA demo server was stopped.

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
- **Revenue page "Cash Received" (fixed in Phase 8):** it summed `bookings.cash_received` over every vehicle row, so group cash was counted once per vehicle. It now counts each normalized group trip once. `cash_received` is still the cash tendered as recorded (not the verified collection); for a fully paid cash job they are equal.
- **Flutter in this worktree:** `flutter pub get` needs Windows symlink support (Developer Mode) for plugins, so Phase 8 ran `flutter analyze --no-pub` and `flutter test --no-pub` against the existing package configuration. No dependency was added or changed.
- The Team Leader app submits each expense with a single funding source; mixed funding stays an Owner correction on the web.
- Booking counts differ by design: Jobs & Collections counts a group trip as one booking with N vehicles; the existing Revenue page's "Completed Jobs" counts vehicle rows.
- Expenses with `funding_source = unit_fund` count as spending on their `expense_date` once approved. If an approval lands after the period has been reviewed, that period's figures change. This is intended (it reflects actual spending dates), but the Owner should know.
- `ActiveBookingsController::updateStatus()` can set `completed` without `completed_at`. Finance requires `completed_at`.
- `cash_received` is the cash tendered, not the amount collected.
- The finance tables use FK `RESTRICT` to bookings and units. `UnitController::forceDelete` now refuses units with finance history. The dev seeding commands (`SeedTlDemo*`, `SeedQuotationTestScenarios`) delete bookings and would be blocked only if those bookings had finance rows, which they never do.
- The migration `down()` drops the finance tables. It is for development rollback only and must never be run against real data.
