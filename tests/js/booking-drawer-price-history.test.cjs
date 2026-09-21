const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, '..', '..', 'public', 'dispatcher', 'js', 'booking-drawer.js');
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
    return src.slice(startMatch.index, i) + (src[i] === ';' ? ';' : '');
}

function extractFunction(src, name) {
    return extractBlock(src, new RegExp('function ' + name + '\\s*\\([^)]*\\)\\s*\\{'));
}

const iconPaths = extractBlock(source, /var ICON_PATHS = \{/);
const fnNames = ['icon', 'peso', 'esc', 'timeAgoLabel', 'effectiveStatus', 'netAdjustment', 'baseSubtotal', 'distanceFeeFor', 'adjustedTotal', 'vatPercentLabel', 'pricingSectionHtml'];
const fns = fnNames.map((name) => extractFunction(source, name)).join('\n');

const harness = new Function(
    iconPaths + '\n' + fns + '\nreturn { ' + fnNames.concat('ICON_PATHS').join(', ') + ' };',
);
const { pricingSectionHtml } = harness();

let failures = 0;
function assertContains(label, html, needle) {
    if (!html.includes(needle)) {
        failures++;
        console.error('FAIL: ' + label + ' -> expected to contain "' + needle + '"');
    } else {
        console.log('PASS: ' + label + ' -> contains "' + needle + '"');
    }
}
function assertNotContains(label, html, needle) {
    if (html.includes(needle)) {
        failures++;
        console.error('FAIL: ' + label + ' -> unexpectedly contains "' + needle + '"');
    } else {
        console.log('PASS: ' + label + ' -> does not contain "' + needle + '"');
    }
}

const base = {
    quotationStatus: 'draft',
    status: 'requested',
    currentPrice: 4278.4,
    vatExclusiveTotal: 3820,
    vatAmount: 458.4,
    baseRate: 2500,
    perKmRate: 300,
    distanceKm: 8.4,
    additionalFee: 0,
    priceChangeLog: [],
    priceAdjustments: [],
    adjustments: [],
    counterOfferAmount: null,
    reviewReason: null,
    isScheduled: false,
    truckType: 'Medium Duty',
    historyOpen: false,
    adjFormOpen: false,
};

console.log('--- zero recorded adjustments ---');
const htmlZero = pricingSectionHtml(base);
assertContains('shows plain "Price history" with no count', htmlZero, ' Price history</span>');
assertNotContains('does not show a zero count', htmlZero, '(0 adjustments)');
assertContains('collapsed by default', htmlZero, 'id="rbHistoryList" style="display:none;"');
assertContains('always shows the initial calculated price row', htmlZero, 'Initial calculated price');
assertContains('chevron present and not open', htmlZero, 'class="rb-chevron" id="rbHistoryChevron"');

console.log('--- one active adjustment record ---');
const now = new Date().toISOString();
const oneEntry = Object.assign({}, base, {
    priceAdjustments: [
        { id: 1, type: 'add', amount: 500, reason: 'Reason A', status: 'active', created_at: now, reverted_at: null },
    ],
});
const htmlOne = pricingSectionHtml(oneEntry);
assertContains('singular wording for exactly one adjustment', htmlOne, 'Price history (1 adjustment)</span>');
assertNotContains('does not pluralize a single adjustment', htmlOne, '(1 adjustments)');
assertContains('shows the add amount', htmlOne, '+' + '₱500.00');
assertContains('shows the reason', htmlOne, 'Reason A');
assertContains('shows an Undo button for an active adjustment on an editable quotation', htmlOne, 'data-undo-adjustment="1"');

console.log('--- three active adjustment records (mixed add/deduct) ---');
const threeEntries = Object.assign({}, base, {
    priceAdjustments: [
        { id: 1, type: 'add', amount: 500, reason: 'Reason A', status: 'active', created_at: now, reverted_at: null },
        { id: 2, type: 'deduct', amount: 100, reason: 'Reason B', status: 'active', created_at: now, reverted_at: null },
        { id: 3, type: 'add', amount: 200, reason: 'Reason C', status: 'active', created_at: now, reverted_at: null },
    ],
});
const htmlThree = pricingSectionHtml(threeEntries);
assertContains('plural wording for three adjustments', htmlThree, 'Price history (3 adjustments)</span>');

console.log('--- a reverted adjustment shows both its original event and its reversal, with no Undo button ---');
const reverted = Object.assign({}, base, {
    priceAdjustments: [
        { id: 5, type: 'add', amount: 300, reason: 'Reason D', status: 'reverted', created_at: now, reverted_at: now },
    ],
});
const htmlReverted = pricingSectionHtml(reverted);
assertContains('shows the reverted badge instead of Undo', htmlReverted, 'rb-adj-reverted-badge');
assertNotContains('no Undo button for a reverted adjustment', htmlReverted, 'data-undo-adjustment="5"');
assertContains('shows a distinct "Adjustment reverted" row', htmlReverted, 'Adjustment reverted');

console.log('--- Undo is hidden once the quotation is locked (accepted) ---');
const lockedAccepted = Object.assign({}, base, {
    quotationStatus: 'accepted',
    priceAdjustments: [
        { id: 9, type: 'add', amount: 300, reason: 'Reason E', status: 'active', created_at: now, reverted_at: null },
    ],
});
const htmlLockedAccepted = pricingSectionHtml(lockedAccepted);
assertNotContains('no Undo button once the quotation is accepted', htmlLockedAccepted, 'data-undo-adjustment="9"');

console.log('--- lifecycle-only price_change_log entries are not counted as adjustments ---');
const lifecycleOnly = Object.assign({}, base, {
    priceChangeLog: [
        { at: now, type: 'quotation_sent', version: 1 },
        { at: now, type: 'price_review_requested', reason: 'Please review' },
        { at: now, type: 'price_review_kept', old: 4320, new: 4320 },
    ],
});
const htmlLifecycle = pricingSectionHtml(lifecycleOnly);
assertContains('label omits a count when there are no recorded adjustments', htmlLifecycle, ' Price history</span>');
assertNotContains('lifecycle rows do not inflate the count', htmlLifecycle, '(3 adjustments)');
assertNotContains('lifecycle rows do not inflate the count', htmlLifecycle, '(1 adjustment)');

console.log('--- an unsaved staged adjustment counts toward the total ---');
const withStaged = Object.assign({}, base, {
    priceAdjustments: [
        { id: 1, type: 'add', amount: 500, reason: 'Reason A', status: 'active', created_at: now, reverted_at: null },
    ],
    adjustments: [{ amount: 200, reason: 'Reason B (staged)' }],
});
const htmlStaged = pricingSectionHtml(withStaged);
assertContains('one saved + one staged = 2 adjustments', htmlStaged, 'Price history (2 adjustments)</span>');
assertContains('staged row still marked as not sent yet', htmlStaged, '(not sent yet)');

console.log('--- expanded state shows the open chevron and visible list ---');
const expanded = Object.assign({}, threeEntries, { historyOpen: true });
const htmlExpanded = pricingSectionHtml(expanded);
assertContains('chevron open class applied when expanded', htmlExpanded, 'class="rb-chevron rb-is-open"');
assertNotContains('history list is not force-hidden when expanded', htmlExpanded, 'id="rbHistoryList" style="display:none;"');

console.log('--- NEW ADJUSTMENT heading and old helper text ---');
const editableHtml = pricingSectionHtml(base);
assertContains('shows the new section heading', editableHtml, '<div class="rb-sub-label">New adjustment</div>');
assertNotContains('old heading text is gone', editableHtml, '<div class="rb-sub-label">Add price adjustment</div>');
assertNotContains('old helper hint text is gone', editableHtml, 'You can add multiple adjustments if needed.');
assertContains('primary submit button keeps its existing label', editableHtml, ' Add adjustment</button>');

if (source.includes('You can add multiple adjustments if needed.')) {
    failures++;
    console.error('FAIL: source still contains the removed helper hint text');
} else {
    console.log('PASS: source no longer contains the removed helper hint text');
}

if (failures > 0) {
    console.error(failures + ' assertion(s) failed');
    process.exit(1);
}
console.log('All booking-drawer.js price-history UI assertions passed');
