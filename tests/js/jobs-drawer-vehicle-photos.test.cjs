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

const photosLogicBlock = extractBlock(source, /let vehicleImages = \[\];[\s\S]*?if \(vehiclePhotosSection && vehiclePhotosGrid\) \{/);

const runPhotosLogic = new Function(
    'row', 'document', 'vehiclePhotosSection', 'vehiclePhotosGrid',
    photosLogicBlock,
);

function makeMockDocument() {
    const created = [];
    return {
        created,
        createElement(tag) {
            const el = {
                tag: tag,
                className: '',
                href: '',
                target: '',
                rel: '',
                src: '',
                alt: '',
                children: [],
                appendChild(child) {
                    this.children.push(child);
                },
            };
            created.push(el);
            return el;
        },
    };
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
    const document = makeMockDocument();
    const vehiclePhotosSection = { style: {} };
    const vehiclePhotosGrid = { textContent: '', children: [], appendChild(child) { this.children.push(child); } };
    runPhotosLogic(row, document, vehiclePhotosSection, vehiclePhotosGrid);
    return { document, vehiclePhotosSection, vehiclePhotosGrid };
}

const withImages = run({
    dataset: {
        vehicleImages: JSON.stringify([
            { url: 'https://example.test/anchor-1.jpg', booking_code: '0000235' },
            { url: 'https://example.test/sibling-1.jpg', booking_code: '0000240' },
        ]),
    },
});
assertEqual('with images: section visible', withImages.vehiclePhotosSection.style.display, '');
assertEqual('with images: grid has two thumbnails', withImages.vehiclePhotosGrid.children.length, 2);
assertEqual('with images: first thumb href', withImages.vehiclePhotosGrid.children[0].href, 'https://example.test/anchor-1.jpg');
assertEqual('with images: first thumb opens in new tab', withImages.vehiclePhotosGrid.children[0].target, '_blank');
assertEqual('with images: first thumb img src', withImages.vehiclePhotosGrid.children[0].children[0].src, 'https://example.test/anchor-1.jpg');
assertEqual('with images: second thumb href', withImages.vehiclePhotosGrid.children[1].href, 'https://example.test/sibling-1.jpg');

const withoutImages = run({ dataset: { vehicleImages: '[]' } });
assertEqual('without images: section hidden', withoutImages.vehiclePhotosSection.style.display, 'none');
assertEqual('without images: grid stays empty', withoutImages.vehiclePhotosGrid.children.length, 0);

const missingAttribute = run({ dataset: {} });
assertEqual('missing dataset attribute: section hidden', missingAttribute.vehiclePhotosSection.style.display, 'none');

const malformedJson = run({ dataset: { vehicleImages: 'not-json' } });
assertEqual('malformed json: section hidden, no throw', malformedJson.vehiclePhotosSection.style.display, 'none');

if (failures > 0) {
    console.error(failures + ' assertion(s) failed');
    process.exit(1);
}
console.log('All jobs.js vehicle-photos drawer assertions passed');
