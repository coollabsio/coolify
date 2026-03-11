import test from 'node:test';
import assert from 'node:assert/strict';
import {
    extractSshArgs,
    extractTargetHost,
    isAuthorizedTargetHost,
    normalizeHostForAuthorization,
} from './terminal-utils.js';

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
