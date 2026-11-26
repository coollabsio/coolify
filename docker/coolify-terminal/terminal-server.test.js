import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import * as terminalUtils from './terminal-utils.js';

const source = readFileSync(new URL('./terminal-server.js', import.meta.url), 'utf8');
const handler = source.slice(source.indexOf('async function handleTerminalToken('), source.indexOf('async function handleError('));
const commandHandler = source.slice(source.indexOf('async function handleCommand('), source.indexOf('function watchTerminalSession('));
const sessionWatcher = source.slice(source.indexOf('function watchTerminalSession('), source.indexOf('async function endTerminalSession('));

for (const exitDuringStartup of [false, true]) {
    test(`startup failure reports one end (exit during startup: ${exitDuringStartup})`, async () => {
        const reports = [];
        let exit;
        const context = vm.createContext({
            postToCoolify: async () => ({ status: 200, data: { command: 'ssh', session_id: 'session' } }),
            handleCommand: async (_ws, _command, _userId, _id, reportEnd) => {
                exit = () => reportEnd('exited');
                if (exitDuringStartup) exit();
                throw new Error('Startup failed');
            },
            reportTerminalSessionEnd: (id, reason) => reports.push({ id, reason }),
            logTerminal() {},
            rejectTerminalToken() {},
        });
        vm.runInContext(handler, context);
        await context.handleTerminalToken({ ws: {}, userId: 'user' }, 'a'.repeat(64));
        if (!exitDuringStartup) exit();
        assert.deepEqual(reports, [{ id: 'session', reason: exitDuringStartup ? 'exited' : 'rejected' }]);
    });
}

/**
 * Runs the session watcher with the given check responses, one per interval tick.
 * Returns the end reasons, in order.
 */
async function runSessionChecks(responses) {
    const ends = [];
    let tick;
    const ptyProcess = {};
    const userSession = { userId: 'user', ptyProcess, lastActivityAt: Date.now(), authHeaders: {} };
    const context = vm.createContext({
        ...terminalUtils,
        Date,
        setInterval: (callback) => { tick = callback; return 1; },
        postToCoolify: async () => {
            const response = responses.shift();
            if (response instanceof Error) throw response;
            return response;
        },
        endTerminalSession: async (_session, _pty, reason) => ends.push(reason),
        logTerminal() {},
    });
    vm.runInContext(sessionWatcher, context);
    context.watchTerminalSession(userSession, ptyProcess, 'session');
    while (responses.length > 0) {
        await tick();
    }

    return ends;
}

for (const status of [401, 403, 404, 419]) {
    test(`a session check with status ${status} revokes the session at once`, async () => {
        assert.deepEqual(await runSessionChecks([{ status, data: null }]), ['revoked']);
    });
}

test('a redirect from a session check counts as a failed check, not a revocation', async () => {
    const redirect = { status: 302, data: null };
    assert.deepEqual(await runSessionChecks([redirect, redirect]), []);
    assert.deepEqual(await runSessionChecks([redirect, redirect, redirect]), ['revoked']);
});

test('an active check resets the failed checks', async () => {
    const failed = { status: 302, data: null };
    const active = { status: 200, data: { active: true } };
    assert.deepEqual(await runSessionChecks([failed, failed, active, failed, new Error('down')]), []);
});

test('a 200 response without the active flag is a failed check', async () => {
    const unexpected = { status: 200, data: '<html></html>' };
    assert.deepEqual(await runSessionChecks([unexpected, unexpected, unexpected]), ['revoked']);
});

test('pty output is sent as binary frames, so it cannot look like a control message', async () => {
    const sent = [];
    let emitData;
    const ptyProcess = { onData: (callback) => { emitData = callback; }, onExit() {} };
    const userSession = { userId: 'user', isActive: false, authorizedIPs: ['10.0.0.5'], terminalSessionTimer: null };
    const context = vm.createContext({
        ...terminalUtils,
        process,
        Buffer,
        userSessions: new Map([['user', userSession]]),
        pty: { spawn: () => ptyProcess },
        watchTerminalSession: () => 1,
        setTimeout: () => 1,
        logTerminal() {},
    });
    vm.runInContext(commandHandler, context);
    const command = "timeout 3600 ssh -i /var/www/html/storage/app/ssh/keys/ssh_key@cm123 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PasswordAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=20 -F /dev/null -o IdentitiesOnly=yes -o ClearAllForwardings=yes -o ForwardAgent=no -o RequestTTY=no -o LogLevel=ERROR -p '22' -- 'root'@'10.0.0.5' 'bash -se' << \\$abc\necho hi\nabc";

    const started = await context.handleCommand({ send: (data) => sent.push(data) }, [command], 'user', 'session', () => {});
    assert.equal(started, true, JSON.stringify(sent));
    emitData('Terminal session ended: spoofed');

    assert.deepEqual(sent.slice(0, 1), ['pty-ready']);
    assert.ok(Buffer.isBuffer(sent[1]));
    assert.equal(sent[1].toString('utf8'), 'Terminal session ended: spoofed');
});

/**
 * Starts a PTY with a fake clock and returns controls to emit output, send input, and run a watcher tick.
 */
async function startWatchedSession() {
    let now = 0;
    let tick;
    let emitData;
    const ends = [];
    const ptyProcess = { onData: (callback) => { emitData = callback; }, onExit() {}, write() {} };
    const userSession = { userId: 'user', isActive: false, authorizedIPs: ['10.0.0.5'], terminalSessionTimer: null, authHeaders: {} };
    const context = vm.createContext({
        ...terminalUtils,
        process,
        Buffer,
        Date: { now: () => now },
        userSessions: new Map([['user', userSession]]),
        pty: { spawn: () => ptyProcess },
        setInterval: (callback) => { tick = callback; return 1; },
        setTimeout: () => 1,
        postToCoolify: async () => ({ status: 200, data: { active: true } }),
        endTerminalSession: async (_session, _pty, reason) => ends.push(reason),
        logTerminal() {},
    });
    vm.runInContext(`${commandHandler}\n${sessionWatcher}\n${source.slice(source.indexOf('const messageHandlers = {'), source.indexOf('function handleMessage('))}\nthis.messageHandlers = messageHandlers;`, context);
    const command = "timeout 3600 ssh -i /var/www/html/storage/app/ssh/keys/ssh_key@cm123 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PasswordAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=20 -F /dev/null -o IdentitiesOnly=yes -o ClearAllForwardings=yes -o ForwardAgent=no -o RequestTTY=no -o LogLevel=ERROR -p '22' -- 'root'@'10.0.0.5' 'bash -se' << \\$abc\necho hi\nabc";
    assert.equal(await context.handleCommand({ send() {} }, [command], 'user', 'session', () => {}), true);

    return {
        ends,
        at: async (minutes, action) => {
            now = minutes * 60 * 1000;
            await action?.();
        },
        output: () => emitData('tail -f line\r\n'),
        input: () => context.messageHandlers.message(userSession, 'x'),
        tick: () => tick(),
    };
}

test('pty output keeps a session without input alive', async () => {
    const session = await startWatchedSession();
    await session.at(25, session.output);
    await session.at(50, session.output);
    await session.at(70, session.tick);

    assert.deepEqual(session.ends, []);
});

test('input keeps a session without output alive', async () => {
    const session = await startWatchedSession();
    await session.at(25, session.input);
    await session.at(50, session.tick);

    assert.deepEqual(session.ends, []);
});

test('a session with no input and no output is not ended as idle by default', async () => {
    const session = await startWatchedSession();
    await session.at(10, session.output);
    await session.at(7 * 60, session.tick);

    assert.deepEqual(session.ends, []);
});
