import test from 'node:test';
import assert from 'node:assert/strict';
import {
    TERMINAL_CLOSE_CODES,
    TERMINAL_CONNECT_TIMEOUT_MS,
    TERMINAL_CONNECTION_ERRORS,
    TERMINAL_SESSION_START_TIMEOUT_MS,
    classifyTerminalServerMessage,
    isTerminalAuthRejectionCode,
    resolveTerminalCloseOutcome,
} from './terminal-connection.js';

const closeOutcome = (overrides = {}) => resolveTerminalCloseOutcome({
    code: 1006,
    sessionPending: false,
    reconnectAttempts: 0,
    maxReconnectAttempts: 10,
    ...overrides,
});

test('terminal close codes match the codes sent by the terminal server', () => {
    assert.equal(TERMINAL_CLOSE_CODES.AUTH_REJECTED, 4401);
    assert.equal(TERMINAL_CLOSE_CODES.TOKEN_REJECTED, 4403);
    assert.equal(isTerminalAuthRejectionCode(4401), true);
    assert.equal(isTerminalAuthRejectionCode(4403), true);
    assert.equal(isTerminalAuthRejectionCode(1006), false);
    assert.equal(isTerminalAuthRejectionCode(4000), false);
});

test('auth rejection closes stop reconnecting and show the reload message', () => {
    for (const code of [4401, 4403]) {
        for (const sessionPending of [true, false]) {
            assert.deepEqual(closeOutcome({ code, sessionPending }), {
                authRejected: true,
                reconnect: false,
                error: 'Terminal access was rejected. Reload the page and try again.',
            });
        }
    }
});

test('a clean close neither reconnects nor reports an error', () => {
    assert.deepEqual(closeOutcome({ code: 1000, sessionPending: true }), {
        authRejected: false,
        reconnect: false,
        error: null,
    });
});

test('a close before the session is ready leaves the connecting state with a generic error', () => {
    for (const code of [1006, 1011, 1001, 4000]) {
        assert.deepEqual(closeOutcome({ code, sessionPending: true }), {
            authRejected: false,
            reconnect: true,
            error: TERMINAL_CONNECTION_ERRORS.connectionFailed,
        });
    }
});

test('an idle close reconnects silently until the retry limit, then reports an error', () => {
    assert.deepEqual(closeOutcome({ reconnectAttempts: 9 }), {
        authRejected: false,
        reconnect: true,
        error: null,
    });
    assert.deepEqual(closeOutcome({ reconnectAttempts: 10 }), {
        authRejected: false,
        reconnect: false,
        error: TERMINAL_CONNECTION_ERRORS.connectionFailed,
    });
});

test('server text messages are classified for backward compatible rejection handling', () => {
    assert.equal(classifyTerminalServerMessage('Unauthorized: Invalid terminal token'), 'auth-rejected');
    assert.equal(classifyTerminalServerMessage('Unauthorized: Terminal token was rejected'), 'auth-rejected');
    assert.equal(classifyTerminalServerMessage('Unauthorized: Missing required tokens'), 'auth-rejected');
    assert.equal(classifyTerminalServerMessage('Unauthorized: Target host 10.0.0.5 not in authorized list'), 'startup-rejected');
    assert.equal(classifyTerminalServerMessage('Invalid SSH command: No target host found'), 'startup-rejected');
    assert.equal(classifyTerminalServerMessage('pty-ready'), null);
    assert.equal(classifyTerminalServerMessage('root@host:~# '), null);
    assert.equal(classifyTerminalServerMessage(new Blob([])), null);
});

test('connection timeouts stay within a bounded window', () => {
    assert.equal(TERMINAL_CONNECT_TIMEOUT_MS, 15000);
    assert.ok(TERMINAL_SESSION_START_TIMEOUT_MS >= TERMINAL_CONNECT_TIMEOUT_MS);
    assert.ok(TERMINAL_SESSION_START_TIMEOUT_MS <= 30000);
    assert.equal(TERMINAL_CONNECTION_ERRORS.timeout, 'Timed out while connecting to the terminal. Reload the page and try again.');
});
