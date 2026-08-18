import test from 'node:test';
import assert from 'node:assert/strict';
import {
    MAX_TERMINAL_SESSION_SECONDS,
    formatTerminalSessionRemainingTime,
} from './terminal-session-timer.js';

test('formatTerminalSessionRemainingTime formats the eight hour terminal limit countdown', () => {
    assert.equal(MAX_TERMINAL_SESSION_SECONDS, 8 * 60 * 60);
    assert.equal(formatTerminalSessionRemainingTime(MAX_TERMINAL_SESSION_SECONDS), '8h 00m 00s');
    assert.equal(formatTerminalSessionRemainingTime((7 * 60 * 60) + (59 * 60) + 59), '7h 59m 59s');
    assert.equal(formatTerminalSessionRemainingTime(65 * 60), '1h 05m 00s');
    assert.equal(formatTerminalSessionRemainingTime(59), '0m 59s');
    assert.equal(formatTerminalSessionRemainingTime(0), 'expired');
});
