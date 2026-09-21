const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, '..', '..', 'public', 'dispatcher', 'js', 'booking-drawer.js');
const source = fs.readFileSync(filePath, 'utf8');

let failures = 0;
function assertContains(label, needle) {
    if (!source.includes(needle)) {
        failures++;
        console.error('FAIL: ' + label + ' -> expected source to contain "' + needle + '"');
    } else {
        console.log('PASS: ' + label);
    }
}

function extractFunctionBody(name) {
    const match = source.match(new RegExp('function ' + name + '\\s*\\([^)]*\\)\\s*\\{'));
    if (!match) throw new Error('function not found: ' + name);
    let i = match.index + match[0].length;
    let depth = 1;
    const start = i;
    while (depth > 0 && i < source.length) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') depth--;
        i++;
    }
    return source.slice(start, i - 1);
}

function assertBodyContains(fnName, label, needle) {
    const body = extractFunctionBody(fnName);
    if (!body.includes(needle)) {
        failures++;
        console.error('FAIL: ' + fnName + ' — ' + label + ' -> expected to contain "' + needle + '"');
    } else {
        console.log('PASS: ' + fnName + ' — ' + label);
    }
}

function assertBodyNotContains(fnName, label, needle) {
    const body = extractFunctionBody(fnName);
    if (body.includes(needle)) {
        failures++;
        console.error('FAIL: ' + fnName + ' — ' + label + ' -> unexpectedly contains "' + needle + '"');
    } else {
        console.log('PASS: ' + fnName + ' — ' + label);
    }
}

console.log('--- shared in-place refresh helper exists and hits the real endpoint ---');
assertContains('refreshDrawerFromServer is defined', 'function refreshDrawerFromServer(');
assertBodyContains('refreshDrawerFromServer', 'reuses fetchQuotationDetails (the real details/history endpoint)', 'fetchQuotationDetails(');
assertBodyContains('refreshDrawerFromServer', 'reuses mergeQuotationDetailsIntoState (the real rendering data)', 'mergeQuotationDetailsIntoState(');
assertBodyContains('refreshDrawerFromServer', 'reuses renderDrawer (the real rendering logic, no fake DOM injected)', 'renderDrawer()');

console.log('--- every quotation lifecycle action that leaves the drawer open refreshes in place ---');
['applySendSuccess', 'applySaveDraftSuccess', 'submitKeepPrice', 'submitAdjustPriceAfterReview', 'submitDecideOnCounter', 'submitUndoAdjustment'].forEach((fn) => {
    assertBodyContains(fn, 'calls the shared refresh helper', 'refreshDrawerFromServer(');
    assertBodyNotContains(fn, 'does not force a full page reload', 'reloadAfterSuccess()');
});

console.log('--- actions that remove the booking from the queue still do a full reload ---');
assertBodyContains('submitCancelQuote', 'still reloads (the booking leaves this queue tab)', 'reloadAfterSuccess()');

console.log('--- refresh only ever happens after res.ok, never before ---');
['submitKeepPrice', 'submitAdjustPriceAfterReview', 'submitDecideOnCounter'].forEach((fn) => {
    const body = extractFunctionBody(fn);
    const okIndex = body.indexOf('if (!res.ok)');
    const refreshIndex = body.indexOf('refreshDrawerFromServer(');
    if (okIndex === -1 || refreshIndex === -1 || refreshIndex < okIndex) {
        failures++;
        console.error('FAIL: ' + fn + ' — refresh must come after the res.ok guard');
    } else {
        console.log('PASS: ' + fn + ' — refresh happens strictly after the res.ok guard');
    }
});

if (failures > 0) {
    console.error(failures + ' assertion(s) failed');
    process.exit(1);
}
console.log('All booking-drawer.js history-refresh assertions passed');
