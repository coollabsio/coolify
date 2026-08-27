import test from 'node:test';
import assert from 'node:assert/strict';
import {
    MAX_TERMINAL_SESSION_TIMEOUT_SECONDS,
    extractSshArgs,
    extractTargetHost,
    getTerminalSessionTimeout,
    isAttachCommand,
    isAuthorizedTargetHost,
    normalizeHostForAuthorization,
} from './terminal-utils.js';

test('isAttachCommand detects a docker attach session', () => {
    assert.equal(isAttachCommand('docker attach --detach-keys="ctrl-p,ctrl-q" --sig-proxy=false \'minecraft\''), true);
    assert.equal(isAttachCommand('sudo docker attach --sig-proxy=false \'minecraft\''), true);
});

test('isAttachCommand does not match a docker exec shell session', () => {
    assert.equal(isAttachCommand("docker exec -it 'web-1' sh -c 'exec $SHELL'"), false);
    assert.equal(isAttachCommand(''), false);
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


test('getTerminalSessionTimeout always enforces the maximum terminal session lifetime', () => {
    assert.equal(getTerminalSessionTimeout(null), MAX_TERMINAL_SESSION_TIMEOUT_SECONDS);
    assert.equal(getTerminalSessionTimeout(60), MAX_TERMINAL_SESSION_TIMEOUT_SECONDS);
    assert.equal(getTerminalSessionTimeout(MAX_TERMINAL_SESSION_TIMEOUT_SECONDS + 60), MAX_TERMINAL_SESSION_TIMEOUT_SECONDS);
});
