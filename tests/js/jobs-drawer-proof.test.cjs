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

const proofLogicBlock = extractBlock(source, /const hasProof = !!row\.dataset\.proofUrl;[\s\S]*?if \(isAwaiting && paymentReady\) \{/);

const runProofLogic = new Function(
    'row', 'isAwaiting', 'paymentReady', 'isCash', 'proofSection', 'proofLink', 'proofImg', 'cashNote',
    proofLogicBlock,
);

function makeEl() {
    return { style: {}, textContent: '', src: '', href: '' };
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
    const proofSection = makeEl();
    const proofLink = makeEl();
    const proofImg = makeEl();
    const cashNote = makeEl();
    const isCash = row.dataset.paymentMethod === 'Cash';
    runProofLogic(row, true, true, isCash, proofSection, proofLink, proofImg, cashNote);
    return { proofSection, proofLink, proofImg, cashNote };
}

const cashWithProof = run({
    dataset: { proofUrl: 'https://example.test/proof-cash.jpg', paymentMethod: 'Cash', cashReceived: '26,000.00' },
});
assertEqual('cash + proof: proof image src set', cashWithProof.proofImg.src, 'https://example.test/proof-cash.jpg');
assertEqual('cash + proof: proof link visible', cashWithProof.proofLink.style.display, '');
assertEqual('cash + proof: cash note still shown', cashWithProof.cashNote.style.display, '');
assertEqual('cash + proof: cash note text', cashWithProof.cashNote.textContent, 'Cash received: ₱26,000.00');

const cashWithoutProof = run({
    dataset: { proofUrl: '', paymentMethod: 'Cash', cashReceived: '' },
});
assertEqual('cash + no proof: proof link hidden', cashWithoutProof.proofLink.style.display, 'none');
assertEqual('cash + no proof: cash note shown', cashWithoutProof.cashNote.style.display, '');
assertEqual(
    'cash + no proof: fallback cash note text',
    cashWithoutProof.cashNote.textContent,
    'Cash received on-site — no proof image required.',
);

const gcashWithProof = run({
    dataset: { proofUrl: 'https://example.test/proof-gcash.jpg', paymentMethod: 'GCash', cashReceived: '' },
});
assertEqual('gcash + proof: proof image src set', gcashWithProof.proofImg.src, 'https://example.test/proof-gcash.jpg');
assertEqual('gcash + proof: proof link visible', gcashWithProof.proofLink.style.display, '');
assertEqual('gcash + proof: cash note hidden', gcashWithProof.cashNote.style.display, 'none');

const bankTransferWithProof = run({
    dataset: { proofUrl: 'https://example.test/proof-bank.jpg', paymentMethod: 'Bank Transfer', cashReceived: '' },
});
assertEqual(
    'bank transfer + proof: proof image src set',
    bankTransferWithProof.proofImg.src,
    'https://example.test/proof-bank.jpg',
);
assertEqual('bank transfer + proof: cash note hidden', bankTransferWithProof.cashNote.style.display, 'none');

if (failures > 0) {
    console.error(failures + ' assertion(s) failed');
    process.exit(1);
}
console.log('All jobs.js payment-proof drawer assertions passed');
