const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, '..', '..', 'public', 'dispatcher', 'js', 'booking-detail-modal.js');
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

function extractAssignedFunctionBody(assignmentNeedle) {
    const idx = source.indexOf(assignmentNeedle);
    if (idx === -1) throw new Error('assignment not found: ' + assignmentNeedle);
    const braceStart = source.indexOf('{', idx);
    let depth = 1;
    let i = braceStart + 1;
    while (depth > 0 && i < source.length) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') depth--;
        i++;
    }
    return source.slice(braceStart + 1, i - 1);
}

function assertBodyContains(body, label, needle) {
    if (!body.includes(needle)) {
        failures++;
        console.error('FAIL: ' + label + ' -> expected to contain "' + needle + '"');
    } else {
        console.log('PASS: ' + label);
    }
}

function indexOfAll(haystack, needle) {
    return haystack.indexOf(needle);
}

console.log('--- opening a booking resets all previous sections before fetching ---');
const openBody = extractAssignedFunctionBody('window.openBookingDetailModal = function');
assertBodyContains(openBody, 'resetModalContent() is called', 'resetModalContent()');
const resetIndex = indexOfAll(openBody, 'resetModalContent()');
const fetchIndex = indexOfAll(openBody, 'fetch(url');
if (resetIndex === -1 || fetchIndex === -1 || resetIndex > fetchIndex) {
    failures++;
    console.error('FAIL: resetModalContent() must run before the fetch starts');
} else {
    console.log('PASS: resetModalContent() runs before the fetch starts');
}

console.log('--- resetModalContent clears header and every data section, including invoice ---');
const resetBody = extractFunctionBody('resetModalContent');
[
    'bdmBookingCode',
    'bdmServiceType',
    'bdmStatus',
    'bdmCustomerSection',
    'bdmTripSection',
    'bdmVehiclesSection',
    'bdmQuotationSection',
    'bdmAssignmentSection',
    'bdmInvoiceSection',
    'bdmReceiptSection',
].forEach((id) => {
    assertBodyContains(resetBody, 'resetModalContent clears ' + id, id);
});
assertBodyContains(resetBody, 'resetModalContent clears the cached bundle used by View Quotation', 'lastBundleData = null');

console.log('--- out-of-order and closed-modal responses cannot render ---');
assertBodyContains(openBody, 'success callback is guarded by request identity', 'requestId !== requestSeq');
assertBodyContains(openBody, 'success callback is guarded by open state', '!isModalOpen');
const successIndex = openBody.indexOf('.then(function (data)');
const successGuardIndex = openBody.indexOf('requestId !== requestSeq', successIndex);
if (successIndex === -1 || successGuardIndex === -1 || successGuardIndex < successIndex) {
    failures++;
    console.error('FAIL: the success handler must check requestId before rendering');
} else {
    console.log('PASS: the success handler checks requestId before rendering');
}
const catchIndex = openBody.indexOf('.catch(function ()');
const catchGuardIndex = openBody.indexOf('requestId !== requestSeq', catchIndex);
if (catchIndex === -1 || catchGuardIndex === -1 || catchGuardIndex < catchIndex) {
    failures++;
    console.error('FAIL: the failure handler must check requestId before showing an error');
} else {
    console.log('PASS: the failure handler checks requestId before showing an error');
}

console.log('--- each open call gets a new, higher request id ---');
assertContains('requestSeq is incremented per open call', '++requestSeq');

console.log('--- closing the modal marks it closed so late responses are ignored ---');
const closeBody = extractAssignedFunctionBody('window.closeBookingDetailModal = function');
assertBodyContains(closeBody, 'close sets isModalOpen to false', 'isModalOpen = false');

console.log('--- committed invoice Void & Replace UI (9e19024) is untouched ---');
[
    'function renderInvoice(',
    'function voidReplaceFormHtml(',
    'function wireVoidReplaceForm(',
    'VOID_REPLACE_ELIGIBLE_STATUSES',
    'bdmVoidReplaceForm',
].forEach((needle) => {
    assertContains('invoice UI still present: ' + needle, needle);
});

console.log('--- existing modal interactions are untouched ---');
[
    'function lockScroll()',
    'function unlockScroll()',
    'window.viewQuotationDetails = function',
    "e.key !== \"Escape\"",
].forEach((needle) => {
    assertContains('existing behavior preserved: ' + needle, needle);
});

if (failures > 0) {
    console.error(failures + ' assertion(s) failed');
    process.exit(1);
}
console.log('All booking-detail-modal.js stale-state assertions passed');
