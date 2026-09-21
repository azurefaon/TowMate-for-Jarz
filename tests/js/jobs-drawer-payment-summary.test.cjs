const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, '..', '..', 'public', 'dispatcher', 'js', 'jobs.js');
const source = fs.readFileSync(filePath, 'utf8');

function extractBlock(src, startPattern) {
    const startMatch = src.match(startPattern);
    if (!startMatch) throw new Error('pattern not found: ' + startPattern);
    let i = startMatch.index + startMatch[0].length;
    let depth = 1;
    while (depth > 0 && i < src.length) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}') depth--;
        i++;
    }
    return src.slice(startMatch.index, i);
}

const summaryLogicBlock = extractBlock(source, /const paymentReady = row\.dataset\.paymentReady === "1";[\s\S]*?if \(isAwaiting\) \{/);

const runSummaryLogic = new Function('row', 'isAwaiting', 'document', 'fillField', 'paymentSection', summaryLogicBlock);

function makeMockDocument(elements) {
    return {
        getElementById(id) {
            return elements[id] || null;
        },
    };
}

function makeFillField(elements) {
    return (id, value) => {
        const el = elements[id];
        if (el) el.textContent = value || '—';
    };
}

function makeElements() {
    return {
        'drawer-payment-method': { textContent: '' },
        'drawer-submitted-at': { textContent: '' },
        'drawer-amount-due-wrap': { style: {} },
        'drawer-amount-due': { textContent: '' },
        'drawer-amount-submitted-wrap': { style: {} },
        'drawer-amount-submitted-label': { textContent: '' },
        'drawer-amount-submitted': { textContent: '' },
        'drawer-difference-wrap': { style: {} },
        'drawer-difference-label': { textContent: '' },
        'drawer-difference': { textContent: '' },
        'drawer-amount-paid-wrap': { style: {} },
        'drawer-amount-paid': { textContent: '' },
    };
}

let failures = 0;
function assertEqual(label, actual, expected) {
    if (actual !== expected) {
        failures++;
        console.error('FAIL: ' + label + ' -> expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
    } else {
        console.log('PASS: ' + label + ' -> ' + JSON.stringify(actual));
    }
}

function run(row) {
    const elements = makeElements();
    const document = makeMockDocument(elements);
    const fillField = makeFillField(elements);
    runSummaryLogic(row, true, document, fillField, { style: {} });
    return elements;
}

const cashMismatch = run({
    dataset: {
        paymentReady: '1',
        paymentMethod: 'Cash',
        paymentSubmittedAt: 'Sep 18, 2026 5:25 PM',
        total: '25,870.40',
        amountSubmitted: '26,000.00',
    },
});
assertEqual('cash mismatch: amount due label unchanged', cashMismatch['drawer-amount-due-wrap'].style.display, '');
assertEqual('cash mismatch: amount due value', cashMismatch['drawer-amount-due'].textContent, '₱25,870.40');
assertEqual('cash mismatch: submitted label becomes Cash Received', cashMismatch['drawer-amount-submitted-label'].textContent, 'Cash Received');
assertEqual('cash mismatch: submitted value', cashMismatch['drawer-amount-submitted'].textContent, '₱26,000.00');
assertEqual('cash mismatch: difference label becomes Change', cashMismatch['drawer-difference-label'].textContent, 'Change');
assertEqual('cash mismatch: change value has no leading +', cashMismatch['drawer-difference'].textContent, '₱129.60');
assertEqual('cash mismatch: payment method unchanged', cashMismatch['drawer-payment-method'].textContent, 'Cash');
assertEqual('cash mismatch: submitted at unchanged', cashMismatch['drawer-submitted-at'].textContent, 'Sep 18, 2026 5:25 PM');

const gcashMismatch = run({
    dataset: {
        paymentReady: '1',
        paymentMethod: 'GCash',
        paymentSubmittedAt: 'Sep 18, 2026 5:25 PM',
        total: '25,870.40',
        amountSubmitted: '26,000.00',
    },
});
assertEqual('gcash mismatch: submitted label stays Amount Submitted', gcashMismatch['drawer-amount-submitted-label'].textContent, 'Amount Submitted');
assertEqual('gcash mismatch: difference label stays Difference', gcashMismatch['drawer-difference-label'].textContent, 'Difference');
assertEqual('gcash mismatch: difference value keeps leading +', gcashMismatch['drawer-difference'].textContent, '₱+129.60');

const bankMismatch = run({
    dataset: {
        paymentReady: '1',
        paymentMethod: 'Bank Transfer',
        paymentSubmittedAt: 'Sep 18, 2026 5:25 PM',
        total: '25,870.40',
        amountSubmitted: '26,000.00',
    },
});
assertEqual('bank transfer mismatch: submitted label stays Amount Submitted', bankMismatch['drawer-amount-submitted-label'].textContent, 'Amount Submitted');
assertEqual('bank transfer mismatch: difference label stays Difference', bankMismatch['drawer-difference-label'].textContent, 'Difference');

const cashExactMatch = run({
    dataset: {
        paymentReady: '1',
        paymentMethod: 'Cash',
        paymentSubmittedAt: 'Sep 18, 2026 5:25 PM',
        total: '25,870.40',
        amountSubmitted: '25,870.40',
    },
});
assertEqual('cash exact match: submitted wrap hidden', cashExactMatch['drawer-amount-submitted-wrap'].style.display, 'none');
assertEqual('cash exact match: difference wrap hidden', cashExactMatch['drawer-difference-wrap'].style.display, 'none');
assertEqual('cash exact match: paid wrap shown', cashExactMatch['drawer-amount-paid-wrap'].style.display, '');
assertEqual('cash exact match: amount paid value', cashExactMatch['drawer-amount-paid'].textContent, '₱25,870.40');

const notReady = run({
    dataset: {
        paymentReady: '0',
        paymentMethod: '',
        total: '25,870.40',
    },
});
assertEqual('not ready: submitted wrap hidden', notReady['drawer-amount-submitted-wrap'].style.display, 'none');
assertEqual('not ready: difference wrap hidden', notReady['drawer-difference-wrap'].style.display, 'none');
assertEqual('not ready: payment method placeholder', notReady['drawer-payment-method'].textContent, 'Not yet submitted');

if (failures > 0) {
    console.error(failures + ' assertion(s) failed');
    process.exit(1);
}
console.log('All jobs.js payment-summary wording assertions passed');
