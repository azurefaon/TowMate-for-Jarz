const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'dispatcher', 'js', 'booking-detail-modal.js'), 'utf8');

let failures = 0;
function check(label, ok) {
    if (ok) {
        console.log('PASS: ' + label);
    } else {
        failures++;
        console.error('FAIL: ' + label);
    }
}

check('risk controls only render when the bundle carries a risk key', source.includes('hasOwnProperty.call(customer, "risk")'));
check('status is labelled as the customer\'s current risk', source.includes('Customer risk (current)'));
check('copy states the flag is informational', source.includes('informational and does not change this booking'));
check('mark action posts to mark-risk', source.includes('"mark-risk"'));
check('clear action posts to clear-risk', source.includes('"clear-risk"'));
check('clear requires a reason client-side', source.includes('Enter a reason for clearing the risk status.'));
check('requests carry the CSRF token', /submit\([\s\S]*X-CSRF-TOKEN/.test(source));
check('customer name/phone/email are escaped via row()', /renderCustomer[\s\S]*row\("Name", customer\.full_name\)/.test(source));

process.exit(failures ? 1 : 0);
