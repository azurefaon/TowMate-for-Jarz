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
const built = harness();
const { adjustedTotal, pricingSectionHtml } = built;

let failures = 0;
function assertEqual(label, actual, expected) {
    const a = Math.round(actual * 100) / 100;
    const e = Math.round(expected * 100) / 100;
    if (a !== e) {
        failures++;
        console.error('FAIL: ' + label + ' -> expected ' + e + ', got ' + a);
    } else {
        console.log('PASS: ' + label + ' -> ' + a);
    }
}
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

const tm00128Base = {
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

[
    [500, 4778.4],
    [700, 4978.4],
    [-500, 3778.4],
].forEach(([amount, expectedTotal]) => {
    const state = Object.assign({}, tm00128Base, { adjustments: [{ amount: amount, reason: 'test' }] });
    const adj = adjustedTotal(state);
    assertEqual('adjustedTotal vat for staged ' + amount, adj.vat, 458.4);
    assertEqual('adjustedTotal final for staged ' + amount, adj.total, expectedTotal);
});

const persistedPlusStaged = adjustedTotal(Object.assign({}, tm00128Base, {
    additionalFee: 500,
    adjustments: [{ amount: 500, reason: 'increment' }],
}));
assertEqual('adjustedTotal adjustment adds persisted + staged', persistedPlusStaged.adjustment, 1000);
assertEqual('adjustedTotal final for persisted 500 + staged 500', persistedPlusStaged.total, 5278.4);
assertEqual('adjustedTotal vat unaffected by persisted + staged', persistedPlusStaged.vat, 458.4);

const doubleVatBug = ((tm00128Base.vatExclusiveTotal + 500) * 1.12).toFixed(2);
const stagedFixed = adjustedTotal(Object.assign({}, tm00128Base, { adjustments: [{ amount: 500 }] })).total.toFixed(2);
if (stagedFixed === doubleVatBug) {
    failures++;
    console.error('FAIL: staged result still matches the double-VAT bug value ' + doubleVatBug);
} else {
    console.log('PASS: staged result (' + stagedFixed + ') does not match the double-VAT bug value (' + doubleVatBug + ')');
}

console.log('--- rendered upper breakdown, no adjustment yet ---');
const htmlNoAdjustment = pricingSectionHtml(tm00128Base);
assertContains('upper VAT row', htmlNoAdjustment, '<span>VAT (12%)</span><span class="rb-mono">₱458.40</span>');
assertContains('upper Total row', htmlNoAdjustment, 'Total (incl. VAT)</span><span class="rb-mono">₱4,278.40</span>');
assertNotContains('no adjustments summary shown yet', htmlNoAdjustment, 'Base total (incl. VAT)');

console.log('--- rendered upper breakdown, PERSISTED +500 fee, no staged edit (reopened Draft) ---');
const reopened = Object.assign({}, tm00128Base, { currentPrice: 4778.4, additionalFee: 500 });
const htmlReopened = pricingSectionHtml(reopened);
assertNotContains('manual adjustment excluded from upper breakdown', htmlReopened, 'Additional fee');
assertContains('upper still shows canonical VAT', htmlReopened, '<span>VAT (12%)</span><span class="rb-mono">₱458.40</span>');
assertContains('upper still shows canonical pre-adjustment total', htmlReopened, 'Total (incl. VAT)</span><span class="rb-mono">₱4,278.40</span>');
assertContains('lower summary shows the persisted current adjustment', htmlReopened, 'Base total (incl. VAT)</span><span class="rb-mono">₱4,278.40</span>');
assertContains('lower summary adjustment amount', htmlReopened, '+₱500.00');
assertContains('lower summary final total', htmlReopened, 'Final total</span><span class="rb-mono">₱4,778.40</span>');

console.log('--- rendered upper breakdown, staged +500 increment on top of a persisted +500 ---');
const increment500 = Object.assign({}, tm00128Base, {
    currentPrice: 4778.4,
    additionalFee: 500,
    adjustments: [{ amount: 500, reason: 'increment' }],
});
const htmlIncrement500 = pricingSectionHtml(increment500);
assertContains('effective adjustment is the sum, not a replacement', htmlIncrement500, '+₱1,000.00');
assertContains('final total reflects the additive sum', htmlIncrement500, 'Final total</span><span class="rb-mono">₱5,278.40</span>');
assertNotContains('staged amount alone is not shown as the effective adjustment', htmlIncrement500, 'Final total</span><span class="rb-mono">₱4,978.40</span>');

console.log('--- rendered upper breakdown, staged deduct 500 offsetting a persisted +500 ---');
const offsetting500 = Object.assign({}, tm00128Base, {
    currentPrice: 4778.4,
    additionalFee: 500,
    adjustments: [{ amount: -500, reason: 'offset' }],
});
const htmlOffsetting500 = pricingSectionHtml(offsetting500);
assertNotContains('no adjustments row when the net effective adjustment is zero', htmlOffsetting500, 'Base total (incl. VAT)');

console.log('--- rendered upper breakdown, staged deduct 700 against a persisted +500 (net negative) ---');
const netNegative = Object.assign({}, tm00128Base, {
    currentPrice: 4778.4,
    additionalFee: 500,
    adjustments: [{ amount: -700, reason: 'big discount' }],
});
const htmlNetNegative = pricingSectionHtml(netNegative);
assertContains('net negative effective adjustment amount', htmlNetNegative, 'Adjustments</span><span class="rb-mono rb-is-deduct">₱-200.00</span>');
assertContains('net negative final total', htmlNetNegative, 'Final total</span><span class="rb-mono">₱4,078.40</span>');

console.log('--- rendered upper breakdown, plain -500 with nothing persisted ---');
const plainDiscount = Object.assign({}, tm00128Base, {
    additionalFee: 0,
    adjustments: [{ amount: -500, reason: 'discount' }],
});
const htmlPlainDiscount = pricingSectionHtml(plainDiscount);
assertContains('discount adjustment amount', htmlPlainDiscount, 'Adjustments</span><span class="rb-mono rb-is-deduct">₱-500.00</span>');
assertContains('discount final total', htmlPlainDiscount, 'Final total</span><span class="rb-mono">₱3,778.40</span>');

const oldLabel = 'Total after adjustments';
const newLabels = ['Base total (incl. VAT)', 'Final total'];
if (source.includes(oldLabel)) {
    failures++;
    console.error('FAIL: source still contains the old label "' + oldLabel + '"');
} else {
    console.log('PASS: old label "' + oldLabel + '" absent from source');
}
newLabels.forEach((label) => {
    if (!source.includes(label)) {
        failures++;
        console.error('FAIL: source is missing expected label "' + label + '"');
    } else {
        console.log('PASS: source contains expected label "' + label + '"');
    }
});

if (failures > 0) {
    console.error(failures + ' assertion(s) failed');
    process.exit(1);
}
console.log('All booking-drawer.js pricing assertions passed');
