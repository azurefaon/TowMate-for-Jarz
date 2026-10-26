// Regression: a two-vehicle booking must stay two vehicles (with Vehicle 1 / Vehicle 2 tabs and the
// complete group total) even when the quotation-details payload lists only ONE vehicle.
//
// Production shape (TM-00148 + TM-00149): the quotation created at booking time lists only the extra
// vehicle in extra_vehicles, so getQuotationDetails() returns a single-entry group_vehicles.
// Runs the REAL functions from booking-drawer.js against a stubbed state.
const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, '..', '..', 'public', 'dispatcher', 'js', 'booking-drawer.js');
const source = fs.readFileSync(filePath, 'utf8');

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

const fnNames = [
    'esc', 'isGrouped', 'vehicleDisplayName', 'activeVehicle', 'activeVehicleIndex',
    'sortGroupVehiclesCanonical', 'enrichGroupVehicle', 'vehicleNavigatorHtml', 'mergeQuotationDetailsIntoState',
];
const body = 'var state = null;\n' + fnNames.map(extractFunction).join('\n')
    + '\nreturn { setState: function (s) { state = s; }, getState: function () { return state; }, '
    + fnNames.join(', ') + ' };';
const h = new Function(body)();

let failures = 0;
function check(label, condition) {
    if (condition) {
        console.log('PASS: ' + label);
    } else {
        failures++;
        console.error('FAIL: ' + label);
    }
}

function roster() {
    return [
        { booking_id: 148, booking_code: 'TM-00148', vehicle_name: 'Sedan', truck_type_name: 'Light Duty', final_total: 1680 },
        { booking_id: 149, booking_code: 'TM-00149', vehicle_name: 'Sedan', truck_type_name: 'Light Duty', final_total: 1680 },
    ].map(h.enrichGroupVehicle);
}

// The drawer opens with the server-built two-vehicle roster.
h.setState({ groupVehicles: roster(), activeVehicleId: 148, currentPrice: 3360 });
check('drawer starts grouped with two vehicles', h.isGrouped(h.getState()) && h.getState().groupVehicles.length === 2);

// Quotation details arrive listing only the extra vehicle (TM-00149) and the full group price.
h.mergeQuotationDetailsIntoState({
    estimated_price: 3360,
    group_vehicles: [{ booking_id: 149, booking_code: 'TM-00149', final_total: 1680 }],
});
const s = h.getState();
check('still two vehicles after a one-vehicle details payload', s.groupVehicles.length === 2);
check('both TM-00148 and TM-00149 remain', s.groupVehicles.map((v) => v.booking_code).join(',') === 'TM-00148,TM-00149');
check('still treated as a grouped booking', h.isGrouped(s));
check('active vehicle is unchanged', String(s.activeVehicleId) === '148');
check('group total is the full 3360', s.currentPrice === 3360);
check('vehicle prices still sum to the group total', s.groupVehicles.reduce((t, v) => t + v.final_total, 0) === 3360);

const nav = h.vehicleNavigatorHtml(s);
check('Vehicle 1 tab is rendered', nav.includes('Vehicle 1'));
check('Vehicle 2 tab is rendered', nav.includes('Vehicle 2'));
check('navigator counts two vehicles', nav.includes('Vehicle 1 of 2'));

// A genuine multi-vehicle details payload still refreshes the roster.
h.mergeQuotationDetailsIntoState({
    group_vehicles: [
        { booking_id: 149, booking_code: 'TM-00149', final_total: 1700 },
        { booking_id: 148, booking_code: 'TM-00148', final_total: 1700 },
    ],
});
check('a full two-vehicle payload still updates and re-sorts the roster',
    h.getState().groupVehicles.map((v) => v.booking_id).join(',') === '148,149' && h.getState().groupVehicles[0].final_total === 1700);

// A drawer with no group yet still adopts a single-vehicle payload (unchanged behaviour).
h.setState({ groupVehicles: [], activeVehicleId: null });
h.mergeQuotationDetailsIntoState({ group_vehicles: [{ booking_id: 7, booking_code: 'TM-00007', final_total: 500 }] });
check('an ungrouped drawer still adopts the payload', h.getState().groupVehicles.length === 1);

if (failures > 0) {
    console.error(failures + ' failure(s)');
    process.exit(1);
}
console.log('All booking-drawer group-vehicles-preserved tests passed.');
