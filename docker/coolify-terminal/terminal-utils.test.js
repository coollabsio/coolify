import test from 'node:test';
import assert from 'node:assert/strict';
import {
    MAX_TERMINAL_SESSION_TIMEOUT_SECONDS,
    TERMINAL_CLOSE_CODES,
    authenticateTerminalUpgrade,
    createTerminalUpgradeHandler,
    extractSshArgs,
    getTerminalAuthHeaders,
    extractTargetHost,
    getTerminalProcessEnv,
    getTerminalSessionTimeout,
    isAttachCommand,
    detachPtyProcess,
    isAuthorizedTargetHost,
    normalizeHostForAuthorization,
    rejectTerminalSocket,
    sanitizeSshArgs,
    validateSshArgs,
} from './terminal-utils.js';

test('isAttachCommand detects a docker attach session', () => {
    assert.equal(isAttachCommand('docker attach --detach-keys="ctrl-p,ctrl-q" --sig-proxy=false \'minecraft\''), true);
    assert.equal(isAttachCommand('sudo docker attach --sig-proxy=false \'minecraft\''), true);
});

test('isAttachCommand does not match a docker exec shell session', () => {
    assert.equal(isAttachCommand("docker exec -it 'web-1' sh -c 'exec $SHELL'"), false);
    assert.equal(isAttachCommand(''), false);
});

test('getTerminalProcessEnv preserves the PATH needed by SSH proxy commands', () => {
    assert.deepEqual(getTerminalProcessEnv({
        PATH: '/usr/local/bin:/usr/bin:/bin',
        APP_KEY: 'must-not-be-inherited',
    }), {
        PATH: '/usr/local/bin:/usr/bin:/bin',
    });
});

test('getTerminalProcessEnv uses the default PATH when PATH is absent', () => {
    assert.deepEqual(getTerminalProcessEnv({
        APP_KEY: 'must-not-be-inherited',
    }), {
        PATH: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    });
});

test('getTerminalProcessEnv uses the default PATH when PATH is empty', () => {
    assert.deepEqual(getTerminalProcessEnv({
        PATH: '',
        APP_KEY: 'must-not-be-inherited',
    }), {
        PATH: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    });
});

test('getTerminalProcessEnv passes the cloudflared Access service token to SSH proxy commands', () => {
    assert.deepEqual(getTerminalProcessEnv({
        PATH: '/usr/bin:/bin',
        TUNNEL_SERVICE_TOKEN_ID: 'token-id.access',
        TUNNEL_SERVICE_TOKEN_SECRET: 'token-secret',
        TUNNEL_SERVICE_URL: 'must-not-be-inherited',
        APP_KEY: 'must-not-be-inherited',
    }), {
        PATH: '/usr/bin:/bin',
        TUNNEL_SERVICE_TOKEN_ID: 'token-id.access',
        TUNNEL_SERVICE_TOKEN_SECRET: 'token-secret',
    });
});

test('getTerminalProcessEnv omits empty cloudflared service token variables', () => {
    assert.deepEqual(getTerminalProcessEnv({
        PATH: '/usr/bin:/bin',
        TUNNEL_SERVICE_TOKEN_ID: '',
    }), {
        PATH: '/usr/bin:/bin',
    });
});

test('extractTargetHost normalizes quoted IPv4 hosts from generated ssh commands', () => {
    const sshArgs = extractSshArgs(
        "timeout 3600 ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ServerAliveInterval=20 -o ConnectTimeout=10 'root'@'10.0.0.5' 'bash -se' << \\\\$abc\necho hi\nabc"
    );

    assert.equal(extractTargetHost(sshArgs), '10.0.0.5');
});

test('extractSshArgs strips shell quotes from port and user host arguments before spawning ssh', () => {
    const sshArgs = extractSshArgs(
        "timeout 3600 ssh -p '22' -o StrictHostKeyChecking=no 'root'@'10.0.0.5' 'bash -se' << \\\\$abc\necho hi\nabc"
    );

    assert.deepEqual(sshArgs.slice(0, 5), ['-p', '22', '-o', 'StrictHostKeyChecking=no', 'root@10.0.0.5']);
});

test('extractSshArgs preserves proxy command as a single normalized ssh option value', () => {
    const sshArgs = extractSshArgs(
        "timeout 3600 ssh -o ProxyCommand='cloudflared access ssh --hostname %h' -o StrictHostKeyChecking=no 'root'@'example.com' 'bash -se' << \\\\$abc\necho hi\nabc"
    );

    assert.equal(sshArgs[1], 'ProxyCommand=cloudflared access ssh --hostname %h');
    assert.equal(sshArgs[4], 'root@example.com');
});

test('extractSshArgs supports the generated bash or sh fallback command', () => {
    const sshArgs = extractSshArgs(
        "timeout 3600 ssh -o StrictHostKeyChecking=no 'root'@'10.0.0.5' 'if command -v bash >/dev/null 2>&1; then exec bash -se; else exec sh -se; fi' << \\\\$abc\necho hi\nabc"
    );

    assert.equal(extractTargetHost(sshArgs), '10.0.0.5');
});

test('isAuthorizedTargetHost matches normalized hosts against plain allowlist values', () => {
    assert.equal(isAuthorizedTargetHost("'10.0.0.5'", ['10.0.0.5']), true);
    assert.equal(isAuthorizedTargetHost('"host.docker.internal"', ['host.docker.internal']), true);
});

test('normalizeHostForAuthorization unwraps bracketed IPv6 hosts', () => {
    assert.equal(normalizeHostForAuthorization("'[2001:db8::10]'"), '2001:db8::10');
    assert.equal(isAuthorizedTargetHost("'[2001:db8::10]'", ['2001:db8::10']), true);
});

test('isAuthorizedTargetHost rejects hosts that are not in the allowlist', () => {
    assert.equal(isAuthorizedTargetHost("'10.0.0.9'", ['10.0.0.5']), false);
});

test('validateSshArgs accepts the SSH arguments generated by Coolify', () => {
    const sshArgs = extractSshArgs(
        "timeout 3600 ssh -i /var/www/html/storage/app/ssh/keys/ssh_key@cm123 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PasswordAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=20 -o RequestTTY=no -o LogLevel=ERROR -p '22' 'root'@'10.0.0.5' 'bash -se' << \\$abc\necho hi\nabc"
    );

    assert.equal(validateSshArgs(sshArgs, ['10.0.0.5']), true);
});

test('validateSshArgs rejects an injected ProxyCommand', () => {
    const sshArgs = extractSshArgs(
        "timeout 300 ssh -o 'ProxyCommand=/bin/busybox id >/tmp/marker' root@10.0.0.5 'bash -se' << \\ENDSSH\nENDSSH"
    );

    assert.equal(validateSshArgs(sshArgs, ['10.0.0.5']), false);
});

test('validateSshArgs accepts only the fixed Cloudflare ProxyCommand', () => {
    const validArgs = extractSshArgs(
        "timeout 3600 ssh -o ProxyCommand='cloudflared access ssh --hostname %h' -i /var/www/html/storage/app/ssh/keys/ssh_key@cm123 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PasswordAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=20 -o RequestTTY=no -o LogLevel=ERROR -p 22 root@example.com 'bash -se' << \\$abc\necho hi\nabc"
    );
    const maliciousArgs = [...validArgs];
    maliciousArgs[1] = 'ProxyCommand=cloudflared access ssh --hostname %h; id';

    assert.equal(validateSshArgs(validArgs, ['example.com']), true);
    assert.equal(validateSshArgs(maliciousArgs, ['example.com']), false);
});

test('validateSshArgs rejects unknown SSH options and key paths', () => {
    const baseArgs = extractSshArgs(
        "timeout 3600 ssh -i /var/www/html/storage/app/ssh/keys/ssh_key@cm123 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PasswordAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=20 -o RequestTTY=no -o LogLevel=ERROR -p 22 root@10.0.0.5 'bash -se' << \\$abc\necho hi\nabc"
    );

    assert.equal(validateSshArgs(['-F', '/tmp/config', ...baseArgs], ['10.0.0.5']), false);
    assert.equal(validateSshArgs(['-i', '/tmp/attacker-key', ...baseArgs.slice(2)], ['10.0.0.5']), false);
    assert.equal(validateSshArgs(['-i', '/var/www/html/storage/app/ssh/keys/../ssh_key@victim', ...baseArgs.slice(2)], ['10.0.0.5']), false);
    assert.equal(validateSshArgs(['-o', 'Include=/tmp/config', ...baseArgs], ['10.0.0.5']), false);
    assert.equal(validateSshArgs(['-o', 'IdentityFile=/tmp/key', ...baseArgs], ['10.0.0.5']), false);
    assert.equal(validateSshArgs(['-o', 'PermitLocalCommand=yes', ...baseArgs], ['10.0.0.5']), false);
    assert.equal(validateSshArgs(['-o', 'LocalCommand=id', ...baseArgs], ['10.0.0.5']), false);
});

test('validateSshArgs rejects repeated flags and option-like connection values', () => {
    const baseArgs = extractSshArgs(
        "timeout 3600 ssh -i /var/www/html/storage/app/ssh/keys/ssh_key@cm123 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PasswordAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=20 -o RequestTTY=no -o LogLevel=ERROR -p 22 root@10.0.0.5 'bash -se' << \\$abc\necho hi\nabc"
    );

    assert.equal(validateSshArgs(['-i', baseArgs[1], ...baseArgs], ['10.0.0.5']), false);
    assert.equal(validateSshArgs([...baseArgs.slice(0, -1), '-p', '22', 'root@10.0.0.5'], ['10.0.0.5']), false);
    assert.equal(validateSshArgs([...baseArgs.slice(0, -1), 'root@-oProxyCommand=id'], ['-oproxycommand=id']), false);
    assert.equal(validateSshArgs([...baseArgs.slice(0, -1), '-root@10.0.0.5'], ['10.0.0.5']), false);
});

test('validateSshArgs rejects a destination that begins with an option prefix', () => {
    const sshArgs = extractSshArgs(
        "timeout 3600 ssh -i /var/www/html/storage/app/ssh/keys/ssh_key@cm123 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PasswordAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=20 -o RequestTTY=no -o LogLevel=ERROR -p 22 -evil@10.0.0.5 'bash -se' << \\$abc\necho hi\nabc"
    );

    assert.equal(validateSshArgs(sshArgs, ['10.0.0.5']), false);
});

test('sanitizeSshArgs removes SSH multiplexing options before spawning SSH', () => {
    const sshArgs = extractSshArgs(
        "timeout 3600 ssh -o ControlMaster=auto -o ControlPath=/var/www/html/storage/app/ssh/mux/mux_cm123 -o ControlPersist=3600 -i /var/www/html/storage/app/ssh/keys/ssh_key@cm123 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PasswordAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=20 -o RequestTTY=no -o LogLevel=ERROR -p 22 root@10.0.0.5 'bash -se' << \\$abc\necho hi\nabc"
    );

    assert.equal(validateSshArgs(sshArgs, ['10.0.0.5']), true);
    assert.deepEqual(sanitizeSshArgs(sshArgs), [
        '-i',
        '/var/www/html/storage/app/ssh/keys/ssh_key@cm123',
        '-o',
        'StrictHostKeyChecking=no',
        '-o',
        'UserKnownHostsFile=/dev/null',
        '-o',
        'PasswordAuthentication=no',
        '-o',
        'ConnectTimeout=10',
        '-o',
        'ServerAliveInterval=20',
        '-o',
        'RequestTTY=yes',
        '-o',
        'LogLevel=ERROR',
        '-p',
        '22',
        'root@10.0.0.5',
    ]);
});


test('getTerminalSessionTimeout always enforces the maximum terminal session lifetime', () => {
    assert.equal(getTerminalSessionTimeout(null), MAX_TERMINAL_SESSION_TIMEOUT_SECONDS);
    assert.equal(getTerminalSessionTimeout(60), MAX_TERMINAL_SESSION_TIMEOUT_SECONDS);
    assert.equal(getTerminalSessionTimeout(MAX_TERMINAL_SESSION_TIMEOUT_SECONDS + 60), MAX_TERMINAL_SESSION_TIMEOUT_SECONDS);
});

function createFakeSocket() {
    const listeners = {};

    return {
        destroyed: false,
        on(event, listener) {
            (listeners[event] ??= []).push(listener);
        },
        removeListener(event, listener) {
            listeners[event] = (listeners[event] ?? []).filter((candidate) => candidate !== listener);
        },
        emit(event, ...args) {
            (listeners[event] ?? []).forEach((listener) => listener(...args));
        },
        destroy() {
            this.destroyed = true;
        },
        listenerCount(event) {
            return (listeners[event] ?? []).length;
        },
    };
}

function createFakeWebSocket() {
    const closeListeners = [];
    const errorListeners = [];

    return {
        events: [],
        terminated: false,
        on(event, listener) {
            if (event === 'error') {
                errorListeners.push(listener);
            }
        },
        emitError(error) {
            if (errorListeners.length === 0) {
                throw error;
            }
            errorListeners.forEach((listener) => listener(error));
        },
        send(message) {
            this.events.push(['send', message]);
        },
        close(code, reason) {
            this.events.push(['close', code, reason]);
        },
        terminate() {
            this.terminated = true;
        },
        once(event, listener) {
            if (event === 'close') {
                closeListeners.push(listener);
            }
        },
        peerClosed() {
            closeListeners.forEach((listener) => listener());
        },
    };
}

function createFakeWebSocketServer({ handles = true } = {}) {
    return {
        connections: [],
        upgrades: [],
        sockets: [],
        shouldHandle() {
            return handles;
        },
        handleUpgrade(req, socket, head, callback) {
            this.upgrades.push(req);
            if (!handles) {
                return;
            }
            const ws = createFakeWebSocket();
            this.sockets.push(ws);
            callback(ws);
        },
        emit(event, ws, req) {
            if (event === 'connection') {
                this.connections.push([ws, req]);
            }
        },
    };
}

const flushPromises = () => new Promise((resolve) => setImmediate(resolve));

test('getTerminalAuthHeaders forwards the whole cookie header so Laravel reads its own session cookie name', () => {
    // APP_NAME="Coolify - Prod" gives the Laravel cookie "coolify_prod_session";
    // the terminal server must not guess that name (issue #10549).
    const cookieHeader = 'theme=dark; coolify_prod_session=session-value; XSRF-TOKEN=xsrf-value';

    assert.deepEqual(getTerminalAuthHeaders(cookieHeader), [{
        'Cookie': cookieHeader,
        'X-XSRF-TOKEN': 'xsrf-value',
    }]);
});

test('getTerminalAuthHeaders makes one candidate for each XSRF-TOKEN cookie', () => {
    // A Laravel app on a sibling subdomain with SESSION_DOMAIN=.example.com sets a
    // second XSRF-TOKEN cookie that the browser also sends to Coolify (issue #10397).
    const cookieHeader = 'XSRF-TOKEN=sibling%3D; coolify_session=session; XSRF-TOKEN=coolify%3D; XSRF-TOKEN=sibling%3D';

    assert.deepEqual(getTerminalAuthHeaders(cookieHeader).map((headers) => headers['X-XSRF-TOKEN']), ['sibling=', 'coolify=']);
});

test('getTerminalAuthHeaders limits the number of XSRF-TOKEN candidates', () => {
    const cookieHeader = Array.from({ length: 50 }, (_, index) => `XSRF-TOKEN=token-${index}`).join('; ');

    assert.deepEqual(getTerminalAuthHeaders(cookieHeader).map((headers) => headers['X-XSRF-TOKEN']), ['token-0', 'token-1', 'token-2', 'token-3', 'token-4']);
});

test('authenticateTerminalUpgrade rejects missing cookies with the auth close code without calling Coolify', async () => {
    let requests = 0;
    const postToCoolify = async () => {
        requests++;
        return { status: 200 };
    };

    for (const cookieHeader of ['', undefined, 'coolify_session=session', 'coolify_session=session; XSRF-TOKEN=']) {
        assert.deepEqual(await authenticateTerminalUpgrade(getTerminalAuthHeaders(cookieHeader), postToCoolify), {
            authenticated: false,
            closeCode: TERMINAL_CLOSE_CODES.AUTH_REJECTED,
            reason: 'Unauthorized: Missing required tokens',
        });
    }

    assert.equal(requests, 0);
    assert.equal(TERMINAL_CLOSE_CODES.AUTH_REJECTED, 4401);
    assert.equal(TERMINAL_CLOSE_CODES.TOKEN_REJECTED, 4403);
});

test('authenticateTerminalUpgrade checks the Laravel session exactly like the previous verifyClient', async () => {
    const requests = [];
    const result = await authenticateTerminalUpgrade(
        getTerminalAuthHeaders('coolify_session=session-value; XSRF-TOKEN=xsrf-value'),
        async (path, headers) => {
            requests.push([path, headers]);
            return { status: 200 };
        },
    );

    const authHeaders = { 'Cookie': 'coolify_session=session-value; XSRF-TOKEN=xsrf-value', 'X-XSRF-TOKEN': 'xsrf-value' };
    assert.deepEqual(result, { authenticated: true, authHeaders });
    assert.deepEqual(requests, [['/terminal/auth', authHeaders]]);
});

test('authenticateTerminalUpgrade tries the next XSRF-TOKEN when Coolify rejects one', async () => {
    const triedTokens = [];
    const result = await authenticateTerminalUpgrade(
        getTerminalAuthHeaders('XSRF-TOKEN=sibling; coolify_session=session; XSRF-TOKEN=coolify; XSRF-TOKEN=other'),
        async (path, headers) => {
            triedTokens.push(headers['X-XSRF-TOKEN']);
            return { status: headers['X-XSRF-TOKEN'] === 'coolify' ? 200 : 419 };
        },
    );

    assert.equal(result.authenticated, true);
    assert.equal(result.authHeaders['X-XSRF-TOKEN'], 'coolify');
    assert.deepEqual(triedTokens, ['sibling', 'coolify']);
});

test('authenticateTerminalUpgrade rejects every non-200 Coolify response', async () => {
    for (const status of [201, 204, 302, 401, 403, 419, 500]) {
        const result = await authenticateTerminalUpgrade(
            getTerminalAuthHeaders('coolify_session=session; XSRF-TOKEN=xsrf; XSRF-TOKEN=other'),
            async () => ({ status }),
        );

        assert.equal(result.authenticated, false, `status ${status} must not authenticate`);
        assert.equal(result.closeCode, TERMINAL_CLOSE_CODES.AUTH_REJECTED);
        assert.equal(result.reason, 'Unauthorized: Invalid credentials');
        assert.equal(result.status, status);
    }
});

test('authenticateTerminalUpgrade does not authenticate when Coolify is unreachable', async () => {
    const result = await authenticateTerminalUpgrade(
        getTerminalAuthHeaders('coolify_session=session; XSRF-TOKEN=xsrf'),
        async () => {
            throw new Error('connect ECONNREFUSED');
        },
    );

    assert.equal(result.authenticated, false);
    assert.equal(result.closeCode, TERMINAL_CLOSE_CODES.AUTH_UNAVAILABLE);
    assert.equal(result.error.message, 'connect ECONNREFUSED');
});

test('rejectTerminalSocket closes with a readable code and sends no text message', () => {
    const ws = createFakeWebSocket();

    rejectTerminalSocket(ws, TERMINAL_CLOSE_CODES.TOKEN_REJECTED, 'Unauthorized: Terminal token was rejected');
    ws.peerClosed();

    assert.deepEqual(ws.events, [['close', 4403, 'Unauthorized: Terminal token was rejected']]);
});

test('rejectTerminalSocket terminates peers that ignore the close handshake', async () => {
    const ws = createFakeWebSocket();

    rejectTerminalSocket(ws, TERMINAL_CLOSE_CODES.AUTH_REJECTED, 'Unauthorized: Invalid credentials', { terminateAfterMs: 5 });
    await new Promise((resolve) => setTimeout(resolve, 20));

    assert.deepEqual(ws.events, [['close', 4401, 'Unauthorized: Invalid credentials']]);
    assert.equal(ws.terminated, true);
});

test('createTerminalUpgradeHandler only emits connection for authenticated upgrades', async () => {
    const wss = createFakeWebSocketServer();
    const req = { url: '/terminal/ws' };
    const handler = createTerminalUpgradeHandler({ wss, authenticate: async () => ({ authenticated: true }) });

    handler(req, createFakeSocket(), Buffer.alloc(0));
    await flushPromises();

    assert.equal(wss.connections.length, 1);
    assert.equal(wss.connections[0][1], req);
    assert.deepEqual(wss.sockets[0].events, []);
});

test('createTerminalUpgradeHandler closes rejected upgrades with the auth close code and never emits connection', async () => {
    const wss = createFakeWebSocketServer();
    const rejected = [];
    const handler = createTerminalUpgradeHandler({
        wss,
        authenticate: async () => ({
            authenticated: false,
            closeCode: TERMINAL_CLOSE_CODES.AUTH_REJECTED,
            reason: 'Unauthorized: Invalid credentials',
        }),
        onRejected: (result) => rejected.push(result.closeCode),
    });

    handler({ url: '/terminal/ws' }, createFakeSocket(), Buffer.alloc(0));
    await flushPromises();

    assert.equal(wss.connections.length, 0);
    assert.deepEqual(rejected, [4401]);
    assert.deepEqual(wss.sockets[0].events, [['close', 4401, 'Unauthorized: Invalid credentials']]);
});

test('createTerminalUpgradeHandler treats authentication errors and malformed results as rejections', async () => {
    for (const authenticate of [
        async () => {
            throw new Error('boom');
        },
        async () => ({ authenticated: 'yes', closeCode: TERMINAL_CLOSE_CODES.AUTH_REJECTED, reason: 'Unauthorized' }),
    ]) {
        const wss = createFakeWebSocketServer();
        createTerminalUpgradeHandler({ wss, authenticate })({ url: '/terminal/ws' }, createFakeSocket(), Buffer.alloc(0));
        await flushPromises();

        assert.equal(wss.connections.length, 0);
        assert.equal(wss.sockets[0].events[0][0], 'close');
        assert.notEqual(wss.sockets[0].events[0][1], 1000);
    }
});

test('createTerminalUpgradeHandler does not authenticate requests for other paths', async () => {
    const wss = createFakeWebSocketServer({ handles: false });
    let authenticated = 0;

    createTerminalUpgradeHandler({ wss, authenticate: async () => ++authenticated })({ url: '/other' }, createFakeSocket(), Buffer.alloc(0));
    await flushPromises();

    assert.equal(authenticated, 0);
    assert.equal(wss.upgrades.length, 1);
    assert.equal(wss.connections.length, 0);
});

test('createTerminalUpgradeHandler drops sockets that disconnect while authentication is pending', async () => {
    const wss = createFakeWebSocketServer();
    const socket = createFakeSocket();
    let finishAuthentication;

    createTerminalUpgradeHandler({
        wss,
        authenticate: () => new Promise((resolve) => {
            finishAuthentication = resolve;
        }),
    })({ url: '/terminal/ws' }, socket, Buffer.alloc(0));

    socket.emit('error', new Error('ECONNRESET'));
    await flushPromises();
    finishAuthentication({ authenticated: true });
    await flushPromises();

    assert.equal(socket.destroyed, true);
    assert.equal(socket.listenerCount('error'), 0);
    assert.equal(wss.upgrades.length, 0);
    assert.equal(wss.connections.length, 0);
});

test('rejectTerminalSocket handles socket errors so unauthenticated clients cannot crash the server', () => {
    const ws = createFakeWebSocket();

    rejectTerminalSocket(ws, TERMINAL_CLOSE_CODES.AUTH_REJECTED, 'Unauthorized: Invalid credentials');

    assert.doesNotThrow(() => ws.emitError(new Error('Invalid WebSocket frame: RSV1 must be clear')));
    assert.equal(ws.terminated, true);
    ws.peerClosed();
});

function attachSession() {
    const listeners = new Set();
    const process = {
        writes: [],
        kills: 0,
        write(data) { this.writes.push(data); },
        kill() { this.kills++; },
        onExit(listener) {
            listeners.add(listener);
            return { dispose: () => listeners.delete(listener) };
        },
        exit() { for (const listener of [...listeners]) listener({ exitCode: 0 }); },
    };
    return { ptyProcess: process, isActive: true, terminalSessionTimer: null };
}

test('attach teardown waits for delayed exit after the kill fallback', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const session = attachSession();
    const process = session.ptyProcess;
    let resolved = false;
    const completion = detachPtyProcess(session).then(result => { resolved = true; return result; });
    for (let i = 0; i < 3; i++) t.mock.timers.tick(500);
    await Promise.resolve();
    assert.equal(process.kills, 1);
    assert.equal(resolved, false);
    assert.deepEqual(process.writes, ['\x10\x11', '\x10\x11', '\x10\x11']);
    process.exit();
    assert.equal(await completion, true);
    assert.equal(session.isActive, false);
    assert.equal(session.ptyProcess, null);
});

test('late attach exit cannot clear a replacement session or its timer', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const session = attachSession();
    const process = session.ptyProcess;
    const completion = detachPtyProcess(session);
    const replacement = attachSession().ptyProcess;
    session.ptyProcess = replacement;
    const timer = setTimeout(() => {}, 10000);
    session.terminalSessionTimer = timer;
    process.exit();
    assert.equal(await completion, true);
    assert.equal(session.isActive, true);
    assert.equal(session.ptyProcess, replacement);
    assert.equal(session.terminalSessionTimer, timer);
    t.mock.timers.tick(3000);
    assert.equal(replacement.writes.length, 0);
});

test('attach teardown fails closed if the PTY never exits', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const session = attachSession();
    const completion = detachPtyProcess(session);
    for (let i = 0; i < 5; i++) t.mock.timers.tick(500);
    assert.equal(await completion, false);
    assert.equal(session.isActive, true);
});
