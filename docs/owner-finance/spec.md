# Owner Finance and Commission Module — Specification

Status: Phase 1 (database and authorization foundation) implemented on branch `feat/owner-finance`. Last updated: 2026-11-09.
Items marked **[CLIENT DECISION]** are commission interpretation questions that still need the client's confirmation.

## 1. Scope

Six Owner pages: **Overview, Jobs & Collections, Unit Funds, Expenses, Weekly Commission, Reports**.
All UI text and generated documents are in English.

Rules that apply to every page:

- Five money flows stay separate, and they are never netted into one column: **issued funds**, **expenses**, **customer collections**, **returned funds**, **crew payouts**.
- Money is exact. It is stored in PostgreSQL `NUMERIC(12,2)` and calculated with the `App\Support\Money` helper (bcmath strings). Floats are refused.
- Records are permanent. A correction is a void or a supersede, never an edit or delete. Every write produces an `AuditLog` row.
- Owner-only actions are enforced on the backend (Gates plus service checks). PostgreSQL triggers enforce immutability underneath.
- Existing TowMate functionality is unchanged. Finance only reads booking and payment data and never writes it.

### Confirmed by the client (2026-11-09)
- **Reporting and commission week: Monday to Saturday** (`config('finance.week_*')`, enforced by the DB CHECK `fcs_week`).
- **Expense flow: Team Leader submits → Dispatcher verifies → Owner approves.** The Owner may also enter and approve a company expense directly.
- **Expense categories** (confirmed, in `config/finance.php`): Fuel / Diesel, Toll, Parking, Food / Travel Allowance, Maintenance, Repairs, Parts / Equipment, Assistance from Another Unit, Other Expenses.
- **Unit fund balance:** Opening Balance + Funds Issued − Actual Advance-Funded Spending − Funds Returned = Remaining Balance. Outstanding funds carry across periods.
- **Operating funds are issued per unit per day.** There is no limit on issuances, and there is no forced daily settlement.
- **Funding sources:** issued unit funds, direct company payment, and personal advance. One expense may mix them, and only the unit-fund part reduces issued funds.
- **Overspending is allowed** and recorded as entered, with a warning. No Team Leader debt is created automatically.
- **Evidence is required** before an expense can be verified or approved.
- Still pending client confirmation: monthly maintenance allocation, and personal-advance reimbursement rules (flagged on each affected expense; nothing is automated).
- Unit Funds and Expenses are **not** blocked on any client decision.

## 2. Existing architecture (verified in the repo)

| Concern | What exists | File |
|---|---|---|
| Owner role | `role_id = 1` ("Owner"). Roles: 2 Admin/Dispatcher, 3 Team Leader, 4 Driver, 5 Customer, 6 System Admin | `database/seeders/SuperAdminSeeder.php` |
| Owner routes | `Route::prefix('superadmin')->name('superadmin.')` with `auth, idle.timeout, role:1, force.password.change` | `routes/web.php` (~line 250) |
| Role guard | `RoleMiddleware` matches `role_id` and revokes the session for archived or inactive users | `app/Http/Middleware/RoleMiddleware.php` |
| Owner layout and sidebar | Nav array with Revenue, Reports and Activity Report entries | `resources/views/layouts/superadmin.blade.php` (~line 323) |
| Database | PostgreSQL (prod, local, and the test database `towmate_jarz_testing`); `bcmath` is installed | `.env*`, `php -m` |
| Payment verified | `status='completed'` together with `completed_at` is written only by `Admin\JobsController::confirmPayment()` | `PAYMENT_INVARIANT` in `ReportMetricsService` |
| Payment method | `bookings.payment_method` = what the Team Leader actually collected. `cash_received` = cash tendered (it can exceed `final_total`) | `Api\TeamLeader\TLTaskController` |
| Online payments | `payment_transactions` (`amount_centavos`, `status`, `paid_at`) | `app/Models/PaymentTransaction.php` |
| Revenue rule | `ReportMetricsService::completedPaidJobsQuery()` and `allocatedRevenue()` (group adjustment); demo bookings excluded | `app/Services/ReportMetricsService.php` |
| Group trips | `Invoice::sameTripMembers()` | `app/Models/Invoice.php` |
| Crew | `units` holds only the **current** crew slots; `personnel` (`driver`, `crew`, `team_leader`); `unit_crew_loans` | Models |
| Audit | `AuditLog` and `AuditLogService` (now with a `finance` category) | `app/Services/AuditLogService.php` |

## 3. Reuse decisions (no duplicate financial records)

| Need | Decision |
|---|---|
| Customer collections | **Not stored again.** `finance_revenue_allocations` references `bookings`, `invoices` and `payment_transactions` by id and stores only the unit's attributed share |
| Audit history | Existing `audit_logs` (actions `finance_*`, category `finance`, listed in `BUSINESS_ACTIONS` so they appear in the Owner Activity Report) |
| Settings | Rates live in versioned `finance_commission_policies` rows, not `system_settings`, so approved weeks keep the exact rates they used |
| Exports (later) | `DocumentGenerationService` (PDF) and `ReportWorkbookBuilder` (XLSX) |
| Owner shell (later) | `layouts/superadmin.blade.php`, the `role:1` route group |

## 4. Data model (implemented in Phase 1)

Migration: `database/migrations/2026_11_10_000000_create_owner_finance_tables.php`. Models: `app/Models/Finance/*`.
All amounts are `NUMERIC(12,2)` (statement totals `NUMERIC(14,2)`).

| Table | Purpose | Key integrity rules |
|---|---|---|
| `finance_fund_entries` | Cash **issued** to a unit and cash **returned** | amount > 0; type ∈ {issuance, return}; a return may link to an issuance of the same unit; `idempotency_key` unique; status recorded → voided only (with a reason); core fields immutable; no deletes |
| `finance_expenses` | Unit expenses with a **funding source** (`unit_fund`, `company_direct`, `crew_out_of_pocket`) | amount > 0; status submitted → verified → approved → voided (or rejected); details editable only while `submitted`; approval fields frozen; no deletes |
| `finance_expense_evidence` | **Protected** receipts on the private `local` disk | `(expense_id, sha256)` unique; can only be added while the expense is submitted or verified; the path is hidden from serialization; no deletes or edits |
| `finance_job_personnel` | **Historical** crew per job (slot, personnel/user, name snapshot, borrowed-from unit, source, `needs_review`) | one current row per (booking, slot); a correction supersedes the old row (the history row is never revived); no deletes |
| `finance_revenue_allocations` | **Per-booking, per-unit** collected share (gross, VAT, net) with references to invoice and payment | net = gross − VAT; one current row per (booking, unit); supersede-only; no deletes |
| `finance_commission_policies` | **Versioned** rates plus the interpretation fields (`base_definition`, `allocation_rule`, `interpretation_status`) | rates ≥ 0 and total exactly 100; at most one active; can only become active if `confirmed`; frozen after draft |
| `finance_commission_statements` | Weekly statement with **immutable approval details** (`approved_by_name`, `approved_at`, `approval_snapshot`, sha256 `approval_hash`) | Monday start + 5 days = Saturday; company + personnel = total; one draft and one approved per week; frozen after approval; draft → approved → voided; cannot be voided while payouts are recorded |
| `finance_commission_lines` | One share of one job's base, per role and recipient | writable only while the statement is a draft; unique (statement, allocation, role, recipient) |
| `finance_payouts` | Money **paid** to a crew member, with `audit_log_id` | only against an approved statement; one recorded payout per (statement, recipient); never to `company`; recorded → voided only; no deletes |

The initial rates (60/12/10/10/8) are seeded as **policy v1, draft, `pending_client_confirmation`**. This is configuration, not a financial record.

### Immutability enforcement (PostgreSQL triggers)
Generic functions take column lists as trigger arguments:
- `finance_guard_columns`: listed columns cannot change.
- `finance_guard_actor_columns`: an actor can be set once or cleared when the user is deleted, but never reassigned.
- `finance_guard_status`: only the listed transitions are allowed.
- `finance_block_delete`, `finance_block_delete_unless_draft`: prevent deletes.
- `finance_lines_require_draft`, `finance_payout_requires_approved`, `finance_statement_void_guard`, `finance_evidence_requires_open_expense`: status-dependent rules.

These rules hold even for raw SQL or tinker. Laravel `migrate:fresh` still works, because it drops tables rather than deleting rows.

## 5. Exact money

`App\Support\Money` holds immutable two-place decimal strings and does all arithmetic with bcmath.
- `of()` refuses floats and refuses more than 2 decimal places, so no value is rounded silently.
- `percent()` rounds half-up once.
- `allocateByPercent()` splits an amount by percentages. The parts always sum exactly to the base, and the remainder goes to a named share (the company).
- `App\Casts\MoneyCast` keeps model attributes as exact strings and refuses floats.
- Database sums use `NUMERIC` aggregates.

## 6. Commission (pending client confirmation)

Initial rates: **Company 60%, Driver 12%, Team Leader 10%, Rider 10%, Crew Mate 8%** (total 100%).
Policy v1 stays a draft until these questions are answered. Statements cannot be approved under an unconfirmed policy (this is enforced in `CommissionStatementService::approve` and by the DB CHECK `fcp_active_confirmed`).

| # | Open interpretation question | Blocks |
|---|---|---|
| C1 | **Commission base**: VAT-inclusive collected amount, VAT-exclusive, or net of approved job expenses | Line computation |
| C2 | **Pool or per person**: is a role's percentage shared by everyone in that role on the job (e.g. 2 crew mates share 8%) or paid to each person | Line computation |
| C3 | **Empty roles**: when nobody held a role on a job, does its share go to the company or get redistributed | Line computation |
| C4 | **Rider and Crew Mate**: the repo has slots `driver_1`, `driver_2`, `crew_member_1/2` and personnel roles `driver`, `crew`, `team_leader`. Proposed mapping: Rider = `driver_2` slot, Crew Mate = `crew_member_*` | `finance_job_personnel.commission_role` mapping |
| C5 | **Group trips**: is each vehicle's allocated share (the existing `allocatedRevenue` rule) the commission base per job | Revenue allocation |
| C6 | **Refunds or corrections after approval**: is the adjustment made in the next open week | Statement revisions |

Already decided and implemented: the week (Mon–Sat), the rounding rule (half-up per line, remainder to Company, lines sum exactly to the base), and the separation of earned from paid.

## 7. Page outline (Phase 2 onward)

- **Overview**: separate cards for collections, issued funds, returned funds, expenses, commission earned, and payouts. Only one card, clearly labelled "Net position", combines figures, and its formula is shown.
- **Jobs & Collections**: read-only; completed and verified jobs, amount, method, unit, crew, documents.
- **Unit Funds** (built in Phase 2, `/superadmin/finance/unit-funds`):
  - Issue initial or additional funds and record returned funds, each with date, amount, unit, person, reference and remarks.
  - `UnitFundLedger` computes Opening + Issued − Advance-funded spending (approved `unit_fund` expenses) − Returned = Remaining, and the opening balance carries forward.
  - Expenses awaiting review are shown but not deducted, and are set aside from what a unit can return.
  - Corrections void the original and record a linked replacement.
- **Expenses** (built in Phase 3):
  - Owner `/superadmin/finance/expenses`, Dispatcher `/admin-dashboard/expense-review`, and Team Leader API `/api/v1/team-leader/expenses`.
  - Each expense records unit, booking (job) or month covered (unit/monthly), date, category, description, amount, funding splits (`finance_expense_fundings`, summing exactly to the amount), evidence on the private disk, status, reviewers and timestamps.
  - Approval is blocked without evidence, and the database enforces this as well.
  - Corrections close the original and record a linked replacement.
  - `UnitFundLedger` deducts only approved `unit_fund` splits.
- **Weekly Commission**: choose a week (Mon–Sat), prepare (after C1–C6), approve, record payouts.
- **Reports**: PDF and XLSX exports.

## 8. Authorization (implemented)

`App\Support\Finance\FinanceAccess::register()` (called from `AppServiceProvider::boot`) defines these Gates. Every one requires an active, non-archived account.

| Ability | Who |
|---|---|
| `finance.view`, `finance.funds.manage`, `finance.expenses.approve`, `finance.policies.manage`, `finance.statements.prepare`, `finance.statements.approve`, `finance.payouts.record` | **Owner only** |
| `finance.expenses.submit` | Team Leader (own unit only, checked in the service) and Owner |
| `finance.expenses.verify` | Dispatcher (a Dispatcher may also reject at this step) |

Every finance service method calls `Gate::forUser($actor)->authorize(...)`, so a future controller cannot skip the check. Phase 2 routes will sit under `/superadmin/finance/*` (`role:1`) and also use `can:` middleware.

Additional guard in existing code: `SuperAdmin\UnitController::forceDelete` now refuses to purge a unit that has finance history. The unit stays archived. This is an additive change, and FK `RESTRICT` provides a backstop.
