// Runs the REAL renderInvoice() from booking-detail-modal.js against stubs and
// checks when the Correct Invoice / Void & Replace form is offered.
//
// Eligibility must follow data.correction_locked_by_receipt (the server's own
// answer, computed with the same transaction/member semantics the Void &
// Replace endpoint enforces) - NOT data.receipt, which is a group_code-wide
// display lookup and can belong to an unrelated legacy sibling.
const fs = require('fs');
const path = require('path');

const filePath = process.env.MODAL_JS_PATH
    || path.join(__dirname, '..', '..', 'public', 'dispatcher', 'js', 'booking-detail-modal.js');
const source = fs.readFileSync(filePath, 'utf8');

let failures = 0;
function check(label, condition) {
    if (condition) {
        console.log('PASS: ' + label);
    } else {
        failures++;
        console.error('FAIL: ' + label);
    }
}

function extractFunction(name) {
    const match = source.match(new RegExp('function ' + name + '\\s*\\([^)]*\\)\\s*\\{'));
    if (!match) throw new Error('function not found: ' + name);
    let i = match.index + match[0].length;
    let depth = 1;
    while (depth > 0 && i < source.length) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') depth--;
        i++;
    }
    return source.slice(match.index, i);
}

const FORM = '[[VOID-REPLACE-FORM]]';
const FINALIZED = 'finalized when its receipt was issued';

// renderInvoice with every collaborator stubbed so we only observe its decisions.
function run(invoice, data) {
    const section = { innerHTML: '' };
    const calls = { wired: 0 };
    const factory = new Function(
        'el', 'row', 'emptyState', 'esc', 'fmt', 'fmtDate', 'humanize',
        'voidReplaceFormHtml', 'wireVoidReplaceForm', 'VOID_REPLACE_ELIGIBLE_STATUSES',
        extractFunction('renderInvoice') + '\nreturn renderInvoice;'
    );
    const renderInvoice = factory(
        () => section,
        (label, value) => (value == null ? '' : '<row>' + label + '</row>'),
        (message) => '<empty>' + message + '</empty>',
        (s) => String(s),
        (n) => String(n),
        (d) => String(d),
        (s) => String(s),
        () => FORM,
        () => { calls.wired++; },
        ['waiting_verification', 'completed']
    );
    renderInvoice(invoice, data);
    return { html: section.innerHTML, wired: calls.wired };
}

const current = { invoice_number: 'INV-1', status: 'issued', is_current: true, total: 100, subtotal: 90 };
const receipt = { id: 1, receipt_number: 'R-1' };

for (const status of ['waiting_verification', 'completed']) {
    // Server says: not locked -> offered, whatever data.receipt shows.
    let r = run(current, { booking: { status }, vehicles: [], receipt: null, correction_locked_by_receipt: false });
    check(status + ' + not locked (no receipt) -> correction form offered and wired', r.html.includes(FORM) && r.wired === 1);
    check(status + ' + not locked -> no "finalized" notice', !r.html.includes(FINALIZED));

    // THE legacy case: a sibling sharing only group_code has a receipt, so the bundle
    // still DISPLAYS a receipt, but this booking's own transaction is not locked.
    r = run(current, { booking: { status, group_code: 'G-LEG' }, vehicles: [{}, {}], receipt, correction_locked_by_receipt: false });
    check(status + ' + legacy sibling receipt displayed but NOT locked -> form still offered and wired', r.html.includes(FORM) && r.wired === 1);
    check(status + ' + legacy sibling receipt displayed but NOT locked -> no "finalized" notice', !r.html.includes(FINALIZED));

    // Server says: locked -> hidden + explained.
    r = run(current, { booking: { status }, vehicles: [], receipt, correction_locked_by_receipt: true });
    check(status + ' + locked -> correction form hidden and NOT wired', !r.html.includes(FORM) && r.wired === 0);
    check(status + ' + locked -> clear "finalized by receipt" notice shown', r.html.includes(FINALIZED));

    r = run(current, { booking: { status, group_code: 'G-1' }, vehicles: [{}, {}, {}], receipt, correction_locked_by_receipt: true });
    check(status + ' + locked normalized group -> hidden', !r.html.includes(FORM) && r.wired === 0);

    // Locked even when there is no displayable receipt (e.g. receipt reached only via invoice lineage).
    r = run(current, { booking: { status }, vehicles: [], receipt: null, correction_locked_by_receipt: true });
    check(status + ' + locked with NO data.receipt -> still hidden (the flag, not data.receipt, decides)', !r.html.includes(FORM) && r.wired === 0 && r.html.includes(FINALIZED));
}

let r = run({ ...current, status: 'voided', is_current: false }, { booking: { status: 'completed' }, vehicles: [], receipt, correction_locked_by_receipt: true });
check('a voided / non-current invoice shows neither the form nor the notice', !r.html.includes(FORM) && !r.html.includes(FINALIZED));

r = run(current, { booking: { status: 'on_job' }, vehicles: [], receipt, correction_locked_by_receipt: true });
check('an ineligible status shows neither the form nor the notice (notice only where correction would otherwise be offered)', !r.html.includes(FORM) && !r.html.includes(FINALIZED));

r = run(current, { booking: { status: 'completed' }, vehicles: [], receipt: null });
check('a bundle with no flag at all is treated as not locked (offered)', r.html.includes(FORM));

r = run(current, { booking: { status: 'completed' }, vehicles: [], receipt });
check('data.receipt alone (flag absent) must NOT hide the form', r.html.includes(FORM));

if (failures > 0) {
    console.error(failures + ' assertion(s) failed');
    process.exit(1);
}
console.log('All booking-detail-modal.js receipt-guard assertions passed');
