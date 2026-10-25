// Regression test: customer-controlled text must reach the dispatcher UI as TEXT, never as HTML.
//   - "View Full Booking" panel (public/dispatcher/js/dispatch.js: openCustomerBookingPanel)
//   - dispatcher notification list (resources/views/admin-dashboard/layouts/app.blade.php)
// The functions are loaded from the real source files and run against a tiny recording DOM stub.
// Only harmless HTML strings are used.
//
// How the page really feeds the panel: the cards are rendered as data-customer="{{ e($name) }}", i.e. the name
// is HTML-encoded twice, so getAttribute() returns the name HTML-ENCODED ONCE. enc() below reproduces that.

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..', '..');
const dispatchJs = fs.readFileSync(path.join(root, 'public', 'dispatcher', 'js', 'dispatch.js'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'resources', 'views', 'admin-dashboard', 'layouts', 'app.blade.php'), 'utf8');

let failures = 0;
function check(label, ok, detail) {
    if (ok) {
        console.log('PASS: ' + label);
    } else {
        failures++;
        console.error('FAIL: ' + label + (detail ? '  -> ' + detail : ''));
    }
}

const PAYLOADS = [
    '<b id="probe">bold</b>',
    '<img src=x onerror="window.__probe=1">',
    '</div><script>window.__probe=1</script>',
    '"><span onmouseover="window.__probe=1">',
];
const UNSAFE = /<(b|img|script|span)\b[^>]*(id="probe"|onerror|onmouseover|src=x)|<script/i;

const enc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
const dec = (s) => String(s).replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&');

// ── 1. View Full Booking panel ───────────────────────────────────────────────
const helper = dispatchJs.indexOf('function cbpDecode(');
const start = helper > -1 ? helper : dispatchJs.indexOf('function openCustomerBookingPanel(');
const end = dispatchJs.indexOf('function closeCustomerBookingPanel');
const dateFn = dispatchJs.indexOf('function fmtCbpDate');
check('panel source is present', start > -1 && end > start && dateFn > -1);

function runPanel(attrs) {
    const els = {};
    const make = (id) => {
        const node = { id, _children: [], textContent: '', style: {}, _html: '' };
        Object.defineProperty(node, 'innerHTML', {
            configurable: true,
            get() { return node._html; },
            set(v) { node._html = String(v); node._children = []; },
        });
        node.appendChild = (child) => { node._children.push(child); }; // children carry text only; node._html records what was ever parsed as HTML
        return node;
    };
    const element = (id) => (els[id] = els[id] || make(id));
    const sandbox = {
        document: {
            getElementById: element,
            // a <textarea> never parses markup: assigning innerHTML only decodes entities into .value
            createElement: (tag) => {
                const n = make(tag);
                n.tag = tag;
                n.style = { set cssText(v) {} };
                if (tag === 'textarea') {
                    Object.defineProperty(n, 'innerHTML', { get: () => n._raw || '', set: (v) => { n._raw = v; n.value = dec(v); } });
                }
                return n;
            },
        },
        JSON, Object, parseFloat, String, Number, Array, Date,
    };
    vm.createContext(sandbox);
    vm.runInContext(dispatchJs.slice(start, end) + dispatchJs.slice(dateFn, dateFn + 800), sandbox);

    const card = { getAttribute: (name) => (name in attrs ? attrs[name] : null) };
    sandbox.openCustomerBookingPanel(card);

    const info = element('cbpCustomerInfo');
    return {
        nameText: info._children[0] ? info._children[0].textContent : null,
        phoneText: info._children[1] ? info._children[1].textContent : null,
        infoHtml: info.innerHTML,
        list: element('cbpBookingsList').innerHTML,
    };
}

for (const payload of PAYLOADS) {
    const label = JSON.stringify(payload).slice(0, 38);
    const out = runPanel({
        'data-customer': enc(payload + ' Doe'), // exactly what the page's getAttribute() returns
        'data-phone': enc(payload),
        'data-ref': 'TM-0001',
        'data-final-total': '1000',
        'data-truck': payload,
        'data-status': payload,
        'data-queue': 'book-now',
        'data-group-siblings': JSON.stringify([{ booking_code: payload, truck_type: payload, status: payload, service_type: 'book_now', final_total: 500 }]),
    });

    check('name is shown as literal text, not markup: ' + label, out.nameText === payload + ' Doe', String(out.nameText).slice(0, 100));
    check('phone is shown as literal text: ' + label, out.phoneText === payload, String(out.phoneText).slice(0, 100));
    check('no HTML was built from the name or phone: ' + label, !UNSAFE.test(out.infoHtml));
    check('booking list (code, truck, status) is escaped: ' + label, !UNSAFE.test(out.list) && out.list.includes('&lt;'), out.list.slice(0, 160));

    // Defence in depth: even a RAW (not pre-encoded) value must stay inert
    const raw = runPanel({ 'data-customer': payload, 'data-phone': '', 'data-ref': 'TM-1', 'data-group-siblings': '[]' });
    check('a raw, unencoded value is also inert: ' + label, raw.nameText === payload && !UNSAFE.test(raw.infoHtml));
}

const plain = runPanel({
    'data-customer': enc("Maria O'Brien & Sons"),
    'data-phone': '+639171234567',
    'data-ref': 'TM-0002',
    'data-final-total': '1500',
    'data-truck': 'Light Duty',
    'data-status': 'confirmed',
    'data-queue': 'book-now',
    'data-group-siblings': '[]',
});
check("a normal name shows exactly as typed (apostrophe and ampersand)", plain.nameText === "Maria O'Brien & Sons", plain.nameText);
check('the phone shows as typed', plain.phoneText === '+639171234567');
check('a normal booking row still shows code, truck and status label', plain.list.includes('TM-0002') && plain.list.includes('Light Duty') && plain.list.includes('Confirmed'));

// ── 2. Dispatcher notification list ──────────────────────────────────────────
const objStart = layout.indexOf('window.dispatcherNotifications = {');
check('notification object is present', objStart > -1);

let depth = 0;
let objEnd = -1;
for (let i = layout.indexOf('{', objStart); i < layout.length; i++) {
    if (layout[i] === '{') depth++;
    if (layout[i] === '}') {
        depth--;
        if (depth === 0) {
            objEnd = i + 1;
            break;
        }
    }
}
const objSource = layout.slice(objStart, objEnd);

function runNotification(payload) {
    let rendered = null;
    const sandbox = {
        window: {},
        document: {
            getElementById: () => ({ querySelector: () => null, prepend: (item) => (rendered = item) }),
            createElement: () => ({ dataset: {}, className: '', href: '', innerHTML: '' }),
            querySelector: () => null,
        },
        String,
        parseInt,
        Math,
    };
    vm.createContext(sandbox);
    vm.runInContext(objSource, sandbox);
    sandbox.window.dispatcherNotifications.refreshBadge = () => {};
    sandbox.window.dispatcherNotifications.add(payload);
    return rendered;
}

for (const payload of PAYLOADS) {
    // The customer-inquiry broadcast carries the customer's name and typed message as RAW text.
    const item = runNotification({ title: payload + ' asked about their price', body: payload, time: payload, url: 'javascript:window.__probe=1' });
    const label = JSON.stringify(payload).slice(0, 38);
    check('notification title/body/time are escaped: ' + label, item && !UNSAFE.test(item.innerHTML) && item.innerHTML.includes('&lt;'));
    check('notification rejects a javascript: link: ' + label, item && item.href === '#');
}

const normal = runNotification({ title: 'Job returned', body: 'Vehicle issue', time: 'Just now', url: '/admin-dashboard/jobs' });
check('a normal notification still renders its text and relative link', normal && normal.innerHTML.includes('Job returned') && normal.innerHTML.includes('Vehicle issue') && normal.href === '/admin-dashboard/jobs');

process.exit(failures ? 1 : 0);
