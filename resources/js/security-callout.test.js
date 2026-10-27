import test from 'node:test';
import assert from 'node:assert/strict';
import { securityCallout } from './security-callout.js';

test('shows the callout until it is dismissed', () => {
    assert.deepEqual(securityCallout(2, null), { visible: true, dismissed: null });
    assert.deepEqual(securityCallout(2, 'not a number'), { visible: true, dismissed: null });
});

test('stays hidden after a dismiss while the number does not go up', () => {
    assert.deepEqual(securityCallout(2, '2'), { visible: false, dismissed: 2 });
});

test('shows again when the number goes up', () => {
    assert.deepEqual(securityCallout(3, '2'), { visible: true, dismissed: 2 });
});

test('remembers a lower number, so the next rise shows the callout again', () => {
    assert.deepEqual(securityCallout(1, '3'), { visible: false, dismissed: 1 });
    assert.deepEqual(securityCallout(0, '3'), { visible: false, dismissed: 0 });
    assert.deepEqual(securityCallout(2, '1'), { visible: true, dismissed: 1 });
});

test('never shows the callout for 0', () => {
    assert.deepEqual(securityCallout(0, null), { visible: false, dismissed: null });
});
