import test from 'node:test';
import assert from 'node:assert/strict';
import {
    TERMINAL_CONNECTION_ERRORS,
    TERMINAL_SESSION_START_TIMEOUT_MS,
    terminalSessionStartMethods,
} from './terminal-connection.js';

/**
 * Builds the smallest host that the terminal Alpine component gives to the mixin.
 */
function createTerminalHost(overrides = {}) {
    const host = {
        starting: false,
        terminalActive: false,
        connectionError: null,
        authRejected: false,
        pendingCommand: null,
        sessionStartTimeoutId: null,
        toasts: [],
        websocketStarts: 0,
        $wire: {
            dispatch(name, message) {
                host.toasts.push([name, message]);
            },
        },
        ensureWebSocketConnection() {
            host.websocketStarts++;
        },
        ...terminalSessionStartMethods,
        ...overrides,
    };

    return host;
}

/** Same steps as `init()` in terminal.js for `data-auto-start="true"`. */
function autoStart(host) {
    host.starting = true;
    host.armTerminalSessionStartTimeout();
}

test('auto-start without a token leaves connecting and shows the timeout error', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const host = createTerminalHost();

    autoStart(host);
    t.mock.timers.tick(TERMINAL_SESSION_START_TIMEOUT_MS - 1);

    assert.equal(host.starting, true);
    assert.equal(host.connectionError, null);

    t.mock.timers.tick(1);

    assert.equal(host.starting, false);
    assert.equal(host.connectionError, TERMINAL_CONNECTION_ERRORS.timeout);
    assert.equal(host.sessionStartTimeoutId, null);
    assert.deepEqual(host.toasts, [['error', TERMINAL_CONNECTION_ERRORS.timeout]]);
});

test('a token after auto-start replaces the timer and pty-ready clears it', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const host = createTerminalHost();

    autoStart(host);
    t.mock.timers.tick(TERMINAL_SESSION_START_TIMEOUT_MS - 1000);

    // send-terminal-token
    host.beginTerminalSessionStart();
    assert.equal(host.websocketStarts, 1);

    // The auto-start timer was replaced, so its deadline passes without an error.
    t.mock.timers.tick(2000);
    assert.equal(host.starting, true);
    assert.equal(host.connectionError, null);

    // pty-ready
    host.terminalActive = true;
    host.completeTerminalSessionStart();
    t.mock.timers.tick(TERMINAL_SESSION_START_TIMEOUT_MS * 2);

    assert.equal(host.starting, false);
    assert.equal(host.connectionError, null);
    assert.equal(host.sessionStartTimeoutId, null);
    assert.deepEqual(host.toasts, []);
});

test('a token that arrives after the timeout error still starts the session', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const host = createTerminalHost();

    autoStart(host);
    t.mock.timers.tick(TERMINAL_SESSION_START_TIMEOUT_MS);
    assert.equal(host.connectionError, TERMINAL_CONNECTION_ERRORS.timeout);

    host.beginTerminalSessionStart();

    assert.equal(host.starting, true);
    assert.equal(host.connectionError, null);

    host.terminalActive = true;
    host.completeTerminalSessionStart();

    assert.equal(host.starting, false);
    assert.equal(host.sessionStartTimeoutId, null);
});

test('arming the timer twice keeps one timer only', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const host = createTerminalHost();

    autoStart(host);
    host.armTerminalSessionStartTimeout();
    // Teardown must clear the only timer; an orphaned first timer would still fire.
    host.clearSessionStartTimeout();
    t.mock.timers.tick(TERMINAL_SESSION_START_TIMEOUT_MS * 2);

    assert.equal(host.starting, true);
    assert.equal(host.connectionError, null);
    assert.deepEqual(host.toasts, []);
});

test('a server-side start failure stops the spinner at once with its message', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const host = createTerminalHost({ pendingCommand: { terminalToken: 'stale' } });

    autoStart(host);
    host.failTerminalSessionStart('The container is not running.');

    assert.equal(host.starting, false);
    assert.equal(host.connectionError, 'The container is not running.');
    assert.equal(host.pendingCommand, null);
    assert.equal(host.sessionStartTimeoutId, null);

    t.mock.timers.tick(TERMINAL_SESSION_START_TIMEOUT_MS);

    assert.deepEqual(host.toasts, [['error', 'The container is not running.']]);
});

test('a server-side start failure replaces an earlier timeout error', () => {
    const host = createTerminalHost({ connectionError: TERMINAL_CONNECTION_ERRORS.timeout });

    host.failTerminalSessionStart('No shell is available in this container.');

    assert.equal(host.connectionError, 'No shell is available in this container.');
});

test('a server-side start failure without a message falls back to the generic error', () => {
    const host = createTerminalHost();

    host.failTerminalSessionStart(undefined);

    assert.equal(host.connectionError, TERMINAL_CONNECTION_ERRORS.connectionFailed);
});

test('a cancelled auto-start waits for a container choice without an error', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const host = createTerminalHost();

    autoStart(host);
    host.cancelTerminalAutoStart();
    t.mock.timers.tick(TERMINAL_SESSION_START_TIMEOUT_MS * 2);

    assert.equal(host.starting, false);
    assert.equal(host.connectionError, null);
    assert.equal(host.sessionStartTimeoutId, null);
    assert.deepEqual(host.toasts, []);
});

test('a cancelled auto-start does not stop a session that is already starting', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const host = createTerminalHost({ pendingCommand: { terminalToken: 'token' } });

    autoStart(host);
    host.cancelTerminalAutoStart();

    assert.equal(host.starting, true);
    assert.notEqual(host.sessionStartTimeoutId, null);
});
