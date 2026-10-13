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
| TC004.02 | For any syntactically valid email, registered or not, the same confirmation appears: "If an account uses this email, we'll send password reset instructions. Please check your inbox." Blank email shows a required error; malformed email shows an invalid-format error. Only eligible existing accounts receive a code. Rationale: showing an error for unregistered emails enables account enumeration. | Approved by project owner in session instruction of 2026-10-13. Formal QA/product-owner sign-off of the revised wording is still required. |
| TC015.12 | Risk is a customer-wide, informational flag. An authorized Dispatcher can add/update/clear it (level + reason required; reason required to clear). Dispatcher-facing booking views show a badge of the customer's *current* risk status; history views label it "Current:". The flag never blocks bookings, changes quotations, cancels tasks or affects assignments. Customers never see it. | Approved by project owner, session instruction of 2026-10-13. |
| TC028.03 | Owner can export Booking, Vehicle/Fleet and Financial reports as genuine .xlsx for the selected date range, with figures matching the on-screen report. PDF and CSV exports are unchanged. | Approved by project owner, session instruction of 2026-10-13. |

## Retest records (append below)

| Test Case ID | Historical Status | Revised Expected Result | Actual Retest Result | Retest Status | Tester | Execution Date | Build Version | Environment | Evidence | Remarks |
|---|---|---|---|---|---|---|---|---|---|---|
| TC004.02 | Failed | see above | — | **Not Run** | — | — | — | — | — | Awaiting QA execution on a deployed build. |
| TC015.12 | Failed | see above | — | **Not Run** | — | — | — | — | — | Awaiting QA execution on a deployed build. |
| TC028.03 | Failed | see above | — | **Not Run** | — | — | — | — | — | Awaiting QA execution; open the downloaded .xlsx in Excel/LibreOffice. |

## Engineering evidence (developer-run, NOT QA execution)

See `docs/qa/engineering-report-2026-10-13.md` for commands, counts and the
pre-existing failure classification.

## Suggested manual retest steps

**TC004.02** — (1) Web `/forgot-password`: submit blank, `abc`, a registered staff email, and an unregistered email; confirm the two valid-format submissions show identical text and land on the same code-entry page. (2) Enter a wrong code; confirm one generic "Invalid or expired" message. (3) Enter the emailed code and complete a reset; confirm the old password stops working. (4) Repeat the request/verify flow in the mobile app (customer account).

**TC015.12** — (1) As Dispatcher open a booking's Details, choose a level, enter a reason, press Mark risk; confirm badge appears on dispatch cards, Active Jobs, Active Bookings and Booking History ("Current: …"). (2) Refresh and sign in again; badge persists. (3) Create a new booking for the same customer; badge shows. (4) Clear risk with a reason; badge disappears. (5) Confirm booking status/price/assignment were unchanged throughout. (6) Confirm the customer mobile app shows no risk information.

**TC028.03** — (1) As Owner open Reports, pick a date range, press Export Booking/Fleet/Financial Excel. (2) Open each file; confirm figures equal the page and PDF, numeric cells are numbers, dates are dates. (3) Export an empty range. (4) Confirm PDF export still works.
