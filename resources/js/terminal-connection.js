/**
 * Close codes sent by the terminal WebSocket server (docker/coolify-terminal).
 * 4401: the browser session was rejected; 4403: the terminal token was rejected.
 */
export const TERMINAL_CLOSE_CODES = Object.freeze({
    NORMAL: 1000,
    AUTH_REJECTED: 4401,
    TOKEN_REJECTED: 4403,
});

/** Maximum time to wait for the WebSocket to open on the first attempt. */
export const TERMINAL_CONNECT_TIMEOUT_MS = 15000;

/** Maximum time to wait for the server's `pty-ready` after a session was requested. */
export const TERMINAL_SESSION_START_TIMEOUT_MS = 20000;

export const TERMINAL_CONNECTION_ERRORS = Object.freeze({
    authRejected: 'Terminal access was rejected. Reload the page and try again.',
    connectionFailed: 'Could not connect to the terminal server. Reload the page and try again.',
    timeout: 'Timed out while connecting to the terminal. Reload the page and try again.',
});

const AUTH_REJECTION_MESSAGES = new Set([
    'Unauthorized: Missing required tokens',
    'Unauthorized: Invalid terminal token',
    'Unauthorized: Terminal token was rejected',
]);

export function isTerminalAuthRejectionCode(code) {
    return code === TERMINAL_CLOSE_CODES.AUTH_REJECTED || code === TERMINAL_CLOSE_CODES.TOKEN_REJECTED;
}

/**
 * Classifies a plain-text message from the terminal server.
 *
 * @returns {'auth-rejected'|'startup-rejected'|null}
 */
export function classifyTerminalServerMessage(data) {
    if (typeof data !== 'string') {
        return null;
    }

    if (AUTH_REJECTION_MESSAGES.has(data)) {
        return 'auth-rejected';
    }

    if (data.startsWith('Unauthorized:') || data.startsWith('Invalid SSH command:')) {
        return 'startup-rejected';
    }

    return null;
}

/**
 * Decides how the terminal reacts to a WebSocket close event.
 *
 * - Auth rejections never reconnect: a new socket would be rejected again.
 * - A clean close (1000) is intentional and needs no action.
 * - Any other close reconnects with backoff until the attempt limit, and
 *   reports an error when a session was waiting to start or retries ran out.
 *
 * @param {{ code: number, sessionPending: boolean, reconnectAttempts: number, maxReconnectAttempts: number }} state
 * @returns {{ authRejected: boolean, reconnect: boolean, error: string|null }}
 */
export function resolveTerminalCloseOutcome({ code, sessionPending, reconnectAttempts, maxReconnectAttempts }) {
    if (isTerminalAuthRejectionCode(code)) {
        return { authRejected: true, reconnect: false, error: TERMINAL_CONNECTION_ERRORS.authRejected };
    }

    if (code === TERMINAL_CLOSE_CODES.NORMAL) {
        return { authRejected: false, reconnect: false, error: null };
    }

    const reconnect = reconnectAttempts < maxReconnectAttempts;

    return {
        authRejected: false,
        reconnect,
        error: sessionPending || !reconnect ? TERMINAL_CONNECTION_ERRORS.connectionFailed : null,
    };
}
