import test from 'node:test';
import assert from 'node:assert/strict';
import { dismissSecurityCallout, securityCallout } from './security-callout.js';

const dismissedOf = (current, stored = null) => dismissSecurityCallout(current, stored);

test('shows the callout until it is dismissed', () => {
    assert.deepEqual(securityCallout(['a', 'b'], null), { visible: true, reset: false });
    assert.deepEqual(securityCallout(['a', 'b'], 'not json'), { visible: true, reset: false });
});

test('stays hidden while no resource is new: 3 dismissed, then 2, then the same 3', () => {
    const stored = dismissedOf(['a', 'b', 'c']);
    assert.deepEqual(securityCallout(['a', 'b', 'c'], stored), { visible: false, reset: false });
    assert.deepEqual(securityCallout(['a', 'b'], stored), { visible: false, reset: false });
    assert.deepEqual(securityCallout(['a', 'b', 'c'], stored), { visible: false, reset: false });
});

test('shows again when a new resource needs an update, also with the same number', () => {
    const stored = dismissedOf(['a', 'b', 'c']);
    assert.deepEqual(securityCallout(['a', 'b', 'd'], stored), { visible: true, reset: false });
    assert.deepEqual(securityCallout(['a', 'b', 'c', 'd'], stored), { visible: true, reset: false });
});

test('resets when no resource needs an update: 3, then 0, then 1 shows again', () => {
    const stored = dismissedOf(['a', 'b', 'c']);
    assert.deepEqual(securityCallout([], stored), { visible: false, reset: true });
    // The page removes the stored set on reset.
    assert.deepEqual(securityCallout(['a'], null), { visible: true, reset: false });
});

test('a dismiss keeps the resources dismissed before', () => {
    const stored = dismissedOf(['a', 'b', 'd'], dismissedOf(['a', 'b', 'c']));
    assert.deepEqual(JSON.parse(stored), ['a', 'b', 'c', 'd']);
    assert.deepEqual(securityCallout(['c'], stored), { visible: false, reset: false });
});

test('never shows the callout without resources', () => {
    assert.deepEqual(securityCallout([], null), { visible: false, reset: false });
});
