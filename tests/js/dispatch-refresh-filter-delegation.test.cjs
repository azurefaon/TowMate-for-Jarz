const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, '..', '..', 'public', 'dispatcher', 'js', 'dispatch.js');
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

function countOccurrences(haystack, needle) {
    let count = 0;
    let idx = 0;
    while ((idx = haystack.indexOf(needle, idx)) !== -1) {
        count++;
        idx += needle.length;
    }
    return count;
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

console.log('--- filters are delegated on the stable panel, not bound directly to controls that Refresh replaces ---');
assertBodyContains('initializeScheduledFilter', 'delegates on the stable #scheduledPanel ancestor', 'panel.addEventListener("change"');
assertBodyContains('initializeScheduledFilter', 'delegates input on the stable #scheduledPanel ancestor', 'panel.addEventListener("input"');
assertBodyNotContains('initializeScheduledFilter', 'no longer binds change directly on the search/filter controls', 'filterSelect.addEventListener');
assertBodyNotContains('initializeScheduledFilter', 'no longer binds input directly on the search/filter controls', 'searchInput.addEventListener');

assertBodyContains('initializeBookNowFilter', 'delegates change on the stable #bookNowPanel ancestor', 'panel.addEventListener("change"');
assertBodyContains('initializeBookNowFilter', 'delegates input on the stable #bookNowPanel ancestor', 'panel.addEventListener("input"');
assertBodyNotContains('initializeBookNowFilter', 'no longer binds change directly on the search/filter controls', 'filterSelect.addEventListener');
assertBodyNotContains('initializeBookNowFilter', 'no longer binds input directly on the search/filter controls', 'searchInput.addEventListener');

console.log('--- filter functions re-resolve controls/rows by id every call, never on stale cached references ---');
assertBodyContains('initializeScheduledFilter', 'resolves #schedFilter fresh inside applySchedFilter', 'document.getElementById("schedFilter")');
assertBodyContains('initializeScheduledFilter', 'resolves #schedSearch fresh inside applySchedFilter', 'document.getElementById("schedSearch")');
assertBodyContains('initializeBookNowFilter', 'resolves #rbBnFilter fresh inside applyBnFilter', 'document.getElementById("rbBnFilter")');
assertBodyContains('initializeBookNowFilter', 'resolves #bnSearch fresh inside applyBnFilter', 'document.getElementById("bnSearch")');

console.log('--- filter/refresh initializers run exactly once, so delegation can never stack duplicate listeners ---');
[
    ['initializeBookNowFilter();', 'initializeBookNowFilter'],
    ['initializeScheduledFilter();', 'initializeScheduledFilter'],
    ['initializeQueueRefresh();', 'initializeQueueRefresh'],
].forEach(([needle, label]) => {
    const count = countOccurrences(source, needle);
    if (count !== 1) {
        failures++;
        console.error('FAIL: ' + label + ' should be called exactly once at init, found ' + count);
    } else {
        console.log('PASS: ' + label + ' is called exactly once at init');
    }
});

console.log('--- refresh re-applies filters through bubbling events that reach the delegated listeners ---');
assertBodyContains('reapplySubFilter', 'change event bubbles so it reaches the panel-level listener', 'new Event("change", { bubbles: true })');
assertBodyContains('reapplySubFilter', 'input event bubbles so it reaches the panel-level listener', 'new Event("input", { bubbles: true })');

console.log('--- refresh replaces only the queue panels\' contents, never the stable panel ancestors themselves ---');
assertContains('bookNowPanel content is swapped via innerHTML, not element replacement', 'liveBookNow.innerHTML = freshBookNow.innerHTML');
assertContains('scheduledPanel content is swapped via innerHTML, not element replacement', 'liveScheduled.innerHTML = freshScheduled.innerHTML');

console.log('--- refresh failure clears the in-flight guard and restores the button so retry still works ---');
assertBodyContains('initializeQueueRefresh', 'catches a failed refresh without throwing', '.catch(function () {})');
assertBodyContains('initializeQueueRefresh', 'always resets isRefreshing via finally', 'isRefreshing = false;');
assertBodyContains('initializeQueueRefresh', 'always re-enables the button via finally', 'refreshBtn.disabled = false;');
assertBodyContains('initializeQueueRefresh', 'guards against overlapping refreshes', 'if (isRefreshing');

if (failures > 0) {
    console.error(failures + ' assertion(s) failed');
    process.exit(1);
}
console.log('All dispatch.js refresh/filter delegation assertions passed');
