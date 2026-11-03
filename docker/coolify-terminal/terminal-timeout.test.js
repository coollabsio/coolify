import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
const source = readFileSync(new URL('./terminal-server.js', import.meta.url), 'utf8');
const extract = (name, next, globals) => vm.runInNewContext(`${source.slice(source.indexOf(name), source.lastIndexOf('\n', source.indexOf(next)))}; ${name.match(/function (\w+)/)[1]}`, globals);
test('requests destroy and reject on timeout', async () => {
    let expire, fail;
    const post = extract('async function postToCoolify', 'function parseResponseData', {
        process, Buffer, http: { request: () => ({
            setTimeout(ms, callback) { assert.equal(ms, 10000); expire = callback; },
            on(event, callback) { if (event === 'error') fail = callback; },
            end() {}, destroy(error) { fail(error); },
        }) },
    });
    const pending = post('/check', {});
    expire();
    await assert.rejects(pending, /timed out/);
});
test('idle check continues during pending access request', async () => {
    let tick, now = 0, ended = false;
    const watch = extract('function watchTerminalSession', 'function endTerminalSession', {
        setInterval(callback) { tick = callback; }, Date: { now: () => now },
        TERMINAL_IDLE_TIMEOUT_SECONDS: 60, TERMINAL_SESSION_CHECK_INTERVAL_MS: 1000,
        postToCoolify: () => new Promise(() => {}),
        endTerminalSession: async () => { ended = true; },
    });
    const pty = {};
    watch({ ptyProcess: pty, lastActivityAt: 0 }, pty, 'id');
    tick(); now = 60000; await tick();
    assert.equal(ended, true);
});
