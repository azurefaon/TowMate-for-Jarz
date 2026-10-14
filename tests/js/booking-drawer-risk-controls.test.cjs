const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'dispatcher', 'js', 'booking-drawer.js'), 'utf8');

let failures = 0;
function check(label, ok) {
    if (ok) {
        console.log('PASS: ' + label);
    } else {
        failures++;
        console.error('FAIL: ' + label);
    }
}

check('drawer state reads the risk data attributes', ['d.riskControls === "1"', 'd.customerRiskLevel', 'd.customerRiskLabel', 'd.customerRiskReason'].every((n) => source.includes(n)));
check('controls render only when the row opts in and a real booking is open', source.includes('if (!s.customerRiskControls || !s.bookingCode || s.isMockPreview) return "";'));
check('section is part of the drawer render', source.includes('requestSectionHtml(s) +\n            customerRiskSectionHtml(s) +'));
check('status is labelled as the current risk', source.includes('Customer risk (current)'));
check('copy states it is informational', source.includes('Informational: it does not change this booking.'));
check('mark posts to mark-risk and clear to clear-risk', source.includes('"mark-risk"') && source.includes('"clear-risk"'));
check('URLs are built from booking_code (the route key), never a numeric id', source.includes('encodeURIComponent(state.bookingCode) + "/" + action'));
check('clear requires a reason client-side', source.includes('Enter a reason for clearing the risk status.'));
check('requests carry the CSRF token', /submitCustomerRisk[\s\S]*X-CSRF-TOKEN/.test(source));
check('save and clear are wired after every render', source.includes('byId("rbRiskSave"') && source.includes('byId("rbRiskClear"'));

process.exit(failures ? 1 : 0);
