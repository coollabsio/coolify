import test from 'node:test';
import assert from 'node:assert/strict';
import { nextStepValue } from './number-stepper.js';

const field = (value, { step = '', min = '', max = '' } = {}) => ({ value, step, min, max });

test('steps by 1 when the step is empty or "any"', () => {
    assert.equal(nextStepValue(field('5'), 1), '6');
    assert.equal(nextStepValue(field('5', { step: 'any' }), -1), '4');
});

test('uses the step attribute without floating point noise', () => {
    assert.equal(nextStepValue(field('0.1', { step: '0.2' }), 1), '0.3');
});

test('starts from 0 when the field is empty', () => {
    assert.equal(nextStepValue(field(''), 1), '1');
    assert.equal(nextStepValue(field(''), -1), '-1');
});

test('clamps to min and max', () => {
    assert.equal(nextStepValue(field('0', { min: '0' }), -1), '0');
    assert.equal(nextStepValue(field('99', { max: '99' }), 1), '99');
    assert.equal(nextStepValue(field('', { min: '60' }), 1), '60');
});
