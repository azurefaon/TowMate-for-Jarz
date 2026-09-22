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

const fnNames = ['esc', 'peso', 'effectiveStatus', 'previewVehicleTotal', 'unpricedGroupVehicles', 'unpricedVehiclesSectionHtml'];
const fns = fnNames.map((name) => extractFunction(source, name)).join('\n');

const harness = new Function(fns + '\nreturn { ' + fnNames.join(', ') + ' };');
const { unpricedGroupVehicles, unpricedVehiclesSectionHtml, previewVehicleTotal } = harness();

let failures = 0;
function assertEqual(label, actual, expected) {
    if (actual !== expected) {
        failures++;
        console.error('FAIL: ' + label + ' -> expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
    } else {
        console.log('PASS: ' + label + ' -> ' + JSON.stringify(actual));
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

const baseState = {
    bookingCode: 'TM-00001',
    isScheduled: true,
    quotationStatus: '',
    status: 'scheduled',
    vatRate: 0.12,
    distanceKm: 12,
    groupVehicles: [],
    groupRoster: [
        { booking_code: 'TM-00001', truck_type_name: 'Light Duty', base_rate: 1500, per_km_rate: 60 },
        { booking_code: 'TM-00002', truck_type_name: 'Medium Duty', base_rate: 900, per_km_rate: 40 },
    ],
};

// A fresh 2-vehicle Scheduled group with nothing priced yet: the sibling
// (not the currently open primary booking) should show up as needing a price.
const freshUnpriced = unpricedGroupVehicles(baseState);
assertEqual('fresh group excludes the currently open primary booking', freshUnpriced.length, 1);
assertEqual('fresh group lists the sibling booking code', freshUnpriced[0].booking_code, 'TM-00002');

const freshHtml = unpricedVehiclesSectionHtml(baseState);
assertContains('fresh drawer shows the price-vehicle action for the sibling', freshHtml, 'data-price-vehicle="TM-00002"');
assertContains('fresh drawer previews the sibling total', freshHtml, 'Needs pricing');

// Once the sibling has been priced (appears in groupVehicles from the
// server), it should drop out of the "needs pricing" list.
const pricedState = Object.assign({}, baseState, {
    groupVehicles: [{ booking_code: 'TM-00002', final_total: 1120 }],
});
assertEqual('priced sibling no longer needs pricing', unpricedGroupVehicles(pricedState).length, 0);
assertNotContains('drawer hides the section once every sibling is priced', unpricedVehiclesSectionHtml(pricedState), 'data-price-vehicle');

// A single-vehicle (non-grouped) booking should never show the section.
const soloState = Object.assign({}, baseState, { groupRoster: [{ booking_code: 'TM-00001', base_rate: 1500, per_km_rate: 60 }] });
assertEqual('single-vehicle booking has nothing to price', unpricedGroupVehicles(soloState).length, 0);

// The section must not render once the quotation has been sent, even if the
// roster still technically lists an unpriced sibling.
const sentState = Object.assign({}, baseState, { quotationStatus: 'sent' });
assertEqual('sent quotation shows no pending pricing section', unpricedVehiclesSectionHtml(sentState), '');

// Book Now bookings never carry a group roster, so the section is a no-op.
const bookNowState = Object.assign({}, baseState, { isScheduled: false, groupRoster: [] });
assertEqual('Book Now bookings never show the pending pricing section', unpricedVehiclesSectionHtml(bookNowState), '');

const preview = previewVehicleTotal({ base_rate: 900, per_km_rate: 40 }, { distanceKm: 12, vatRate: 0.12 });
assertEqual('previewVehicleTotal matches base + distance fee + VAT', preview, 1366.4);

if (failures > 0) {
    console.error(failures + ' failure(s)');
    process.exit(1);
}
console.log('All booking-drawer unpriced-vehicles tests passed.');
