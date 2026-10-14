# QA Retest Log — TC004.02, TC015.12, TC028.03

**Append-only.** Historical results are preserved below and must not be edited.
New retest results are appended as new rows. Developer-run automated tests are
listed under "Engineering evidence" only; they are **not** QA execution.

## Historical results (unchanged)

| Test Case ID | Historical Status | Original expected | Original actual |
|---|---|---|---|
| TC004.02 | **Failed** | Error message shown for an unregistered email | Request proceeded, no error message, user could not continue without a recovery code |
| TC015.12 | **Failed** | Clicking "Mark Risk" flags the customer on the booking | Not yet implemented |
| TC028.03 | **Failed** | Excel workbook downloads | Only PDF export available (PDF export passed separately as TC028.04) |

## Approved revisions of expected results (not a re-write of history)

| Test Case ID | Revised expected result | Approval |
|---|---|---|
| TC004.02 | For any syntactically valid email, registered or not, the same confirmation appears: "If an account uses this email, we'll send password reset instructions. Please check your inbox." Blank email shows a required error; malformed email shows an invalid-format error. Only eligible existing accounts receive a code. Rationale: showing an error for unregistered emails enables account enumeration. | **Project-owner scope decision, 2026-10-13:** the project owner explicitly approved that registered and unregistered valid email addresses receive the same generic confirmation and equivalent navigation. Recorded as the owner's decision, subject to any additional QA approval the project's sign-off process requires (not yet obtained). |
| TC015.12 | Risk is a customer-wide, informational flag. An authorized Dispatcher can add/update/clear it (level + reason required; reason required to clear). Dispatcher-facing booking views show a badge of the customer's *current* risk status; history views label it "Current:". The flag never blocks bookings, changes quotations, cancels tasks or affects assignments. Customers never see it. | Approved by project owner, session instruction of 2026-10-13. |
| TC028.03 | Owner can export Booking, Vehicle/Fleet and Financial reports as genuine .xlsx for the selected date range, with figures matching the on-screen report. PDF and CSV exports are unchanged. | Approved by project owner, session instruction of 2026-10-13. |

## Retest records (append below)

| Test Case ID | Historical Status | Revised Expected Result | Actual Retest Result | Retest Status | Tester | Execution Date | Build Version | Environment | Evidence | Remarks |
|---|---|---|---|---|---|---|---|---|---|---|
| TC004.02 | Failed | see above | — | **Not Run** | — | — | — | — | — | Awaiting QA execution on a deployed build. |
| TC015.12 | Failed | see above | — | **Not Run** | — | — | — | — | — | Awaiting QA execution on a deployed build. |
| TC028.03 | Failed | see above | — | **Not Run** | — | — | — | — | — | Awaiting QA execution; open the downloaded .xlsx in Excel/LibreOffice. |

### Retest executions — 2026-10-14 (appended; the "Not Run" placeholders above are kept as originally written)

**Who executed these:** Claude Code (AI assistant), using Chrome 155 browser automation, direct HTTP calls and Microsoft Excel 16 (COM automation) — **not a human QA tester.** The results are real, observed behaviour on the staging site, but they do **not** replace formal human QA sign-off; that column is still pending. Staging email is a log sink, so "inbox" steps were done by reading the logged email.

Common fields — Build: branch `qa/towmate-retest-20261013`, code identical to release candidate `cad4ace` (later commits are docs-only) · Environment: Railway **staging**, https://towmate-staging-staging.up.railway.app, PHP 8.2.34 · Execution date: 2026-10-14

| Test Case ID | Historical Status | Revised Expected Result | Actual Retest Result | Retest Status | Tester | Evidence | Remarks |
|---|---|---|---|---|---|---|---|
| TC004.02 | Failed | Same confirmation for registered and unregistered valid emails; required/format errors for blank/malformed; code needed to reset | Blank -> "The email field is required."; `abc` -> "The email field must be a valid email address."; unregistered and registered emails both went to the code-entry page with the identical approved message; exactly 1 email produced for the registered account and 0 for the unregistered; wrong code -> "Invalid or expired verification code…" (same text for an unknown account); correct code -> reset page -> password changed; new password signs in, old does not; the used code is rejected. Customer API: blank/malformed 422, identical responses, 1 email vs 0, reset works once, token and code cannot be reused, new password signs in | **Passed** (web + customer API) | Claude Code (automated; not human QA) | `evidence/tc004-1…9-*.jpg`, `evidence/tc004-web-run.txt`, `evidence/tc004-api-run.txt` | **Mobile app UI not run** (no device). Real email delivery not tested on staging (log sink); production SMTP checked separately without sending. Native browser validation fires first for blank/malformed (`valueMissing`/`typeMismatch` = true); server messages were observed with client validation switched off. |
| TC015.12 | Failed | Customer-wide informational risk flag; Dispatcher adds/updates/clears with required reason; badge on dispatcher views ("Current:" in history); audited; hidden from customers; nothing blocked | In Chrome: row click opens the booking drawer with a "Customer risk (current)" section; empty submit shows an inline error; Mark High + reason saved and the High risk badge showed on the queue row and on the same customer's Returned card; reopened drawer showed status and reason; clear without reason blocked; High -> Medium update worked; Clear via the Returned-card modal removed all badges. HTTP: Owner -> 403, anonymous -> 419, other customer unaffected, flag persisted across a new login; customer login/profile/history/current/detail responses contain no risk data; Owner's business-activity page lists "Customer Risk Updated" entries with the Dispatcher's name, reasons and old/new values | **Passed** | Claude Code (automated; not human QA) | `evidence/tc015-1…6-*.jpg`, `evidence/tc015-run.txt` | The Dispatcher's Active Jobs / Booking History pages were checked in automated PHP tests and the history badge over HTTP, not by eye in Chrome. Mobile app not run. |
| TC028.03 | Failed | Genuine .xlsx for Booking, Fleet and Financial reports matching the report for the chosen dates; PDF unchanged | Three Export Excel buttons present; booking/vehicle/financial downloads are real workbooks (`PK` zip, correct MIME and filenames); opened in Microsoft Excel 16 with no error, 0 formula cells, 0 error cells; amounts are numbers with ₱ format, dates are dates; totals (₱12,176.50 for 6 bookings; ₱6,836.50 revenue) match the data; Oct 13 = 2 bookings, Oct 14 = 4, Oct 13–14 = 6 (inclusive, totals add up); 2025 range = valid empty workbook with 0; a customer named `=HYPERLINK(...)` is stored as literal text; Dispatcher -> 403; invalid section/date rejected; PDF export still downloads | **Passed** | Claude Code (automated Excel COM; not human QA) | `evidence/tc028-*.xlsx`, `evidence/tc028-excel-verification.txt`, `evidence/tc028-run.txt` | Excel was driven by automation, not viewed by a person — a human should still open one file. "Average Revenue per Completed Booking" shows ₱0.00 because the staging test bookings have no payment records (existing metric rule). |

### Human QA sign-off — PENDING (to be completed by QA; do not fill in on anyone's behalf)

| Test Case ID | Human QA tester | Execution date | Build / environment | Result (Passed / Failed / Blocked) | Evidence | Signed off by (QA lead / product owner) | Date |
|---|---|---|---|---|---|---|---|
| TC004.02 | _pending_ | _pending_ | _pending_ | _pending_ | _pending_ | _pending_ | _pending_ |
| TC015.12 | _pending_ | _pending_ | _pending_ | _pending_ | _pending_ | _pending_ | _pending_ |
| TC028.03 | _pending_ | _pending_ | _pending_ | _pending_ | _pending_ | _pending_ | _pending_ |

Until this table is completed, the "Passed" results above stand only as AI-executed staging results. The TC004.02 revised expected result (same confirmation for registered and unregistered emails) is recorded as the project owner's decision of 2026-10-13; any additional QA approval the sign-off process requires is also pending.

## Engineering evidence (developer-run, NOT QA execution)

See `docs/qa/engineering-report-2026-10-13.md` for commands, counts and the
pre-existing failure classification.

## Suggested manual retest steps

**TC004.02** — (1) Web `/forgot-password`: submit blank, `abc`, a registered staff email, and an unregistered email; confirm the two valid-format submissions show identical text and land on the same code-entry page. (2) Enter a wrong code; confirm one generic "Invalid or expired" message. (3) Enter the emailed code and complete a reset; confirm the old password stops working. (4) Repeat the request/verify flow in the mobile app (customer account).

**TC015.12** — (1) As Dispatcher open a booking's Details, choose a level, enter a reason, press Mark risk; confirm badge appears on dispatch cards, Active Jobs, Active Bookings and Booking History ("Current: …"). (2) Refresh and sign in again; badge persists. (3) Create a new booking for the same customer; badge shows. (4) Clear risk with a reason; badge disappears. (5) Confirm booking status/price/assignment were unchanged throughout. (6) Confirm the customer mobile app shows no risk information.

**TC028.03** — (1) As Owner open Reports, pick a date range, press Export Booking/Fleet/Financial Excel. (2) Open each file; confirm figures equal the page and PDF, numeric cells are numbers, dates are dates. (3) Export an empty range. (4) Confirm PDF export still works.
