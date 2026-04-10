<?php

use App\Helpers\SshMultiplexingHelper;
use App\Rules\ValidHostname;
use App\Rules\ValidServerIp;

// -------------------------------------------------------------------------
// ValidServerIp rule
// -------------------------------------------------------------------------

it('accepts a valid IPv4 address', function () {
    $rule = new ValidServerIp;
    $failed = false;
    $rule->validate('ip', '192.168.1.1', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

it('accepts a valid IPv6 address', function () {
    $rule = new ValidServerIp;
    $failed = false;
    $rule->validate('ip', '2001:db8::1', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

it('accepts a valid hostname', function () {
    $rule = new ValidServerIp;
    $failed = false;
    $rule->validate('ip', 'my-server.example.com', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

it('rejects injection payloads in server ip', function (string $payload) {
    $rule = new ValidServerIp;
    $failed = false;
    $rule->validate('ip', $payload, function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeTrue("ValidServerIp should reject: $payload");
})->with([
    'semicolon' => ['192.168.1.1; rm -rf /'],
    'pipe' => ['192.168.1.1 | cat /etc/passwd'],
    'backtick' => ['192.168.1.1`id`'],
    'dollar subshell' => ['192.168.1.1$(id)'],
    'ampersand' => ['192.168.1.1 & id'],
    'newline' => ["192.168.1.1\nid"],
    'null byte' => ["192.168.1.1\0id"],
]);

// -------------------------------------------------------------------------
// Server model setter casts
// -------------------------------------------------------------------------

it('strips dangerous characters from server ip on write', function () {
    $server = new App\Models\Server;
    $server->ip = '192.168.1.1;rm -rf /';
    // Regex [^0-9a-zA-Z.:%-] removes ; space and /; hyphen is allowed
    expect($server->ip)->toBe('192.168.1.1rm-rf');
});

it('strips dangerous characters from server user on write', function () {
    $server = new App\Models\Server;
    $server->user = 'root$(id)';
    expect($server->user)->toBe('rootid');
});

it('strips non-numeric characters from server port on write', function () {
    $server = new App\Models\Server;
    $server->port = '22; evil';
    expect($server->port)->toBe(22);
});

// -------------------------------------------------------------------------
// escapeshellarg() in generated SSH commands (source-level verification)
// -------------------------------------------------------------------------

it('has escapedUserAtHost private static helper in SshMultiplexingHelper', function () {
    $reflection = new ReflectionClass(SshMultiplexingHelper::class);
    expect($reflection->hasMethod('escapedUserAtHost'))->toBeTrue();

    $method = $reflection->getMethod('escapedUserAtHost');
    expect($method->isPrivate())->toBeTrue();
    expect($method->isStatic())->toBeTrue();
});

it('wraps port with escapeshellarg in getCommonSshOptions', function () {
    $reflection = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('escapeshellarg((string) $server->port)');
});

it('has no raw user@ip string interpolation in SshMultiplexingHelper', function () {
    $reflection = new ReflectionClass(SshMultiplexingHelper::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->not->toContain('{$server->user}@{$server->ip}');
});

// -------------------------------------------------------------------------
// ValidHostname rejects shell metacharacters
// -------------------------------------------------------------------------

it('rejects semicolon in hostname', function () {
    $rule = new ValidHostname;
    $failed = false;
    $rule->validate('hostname', 'example.com;id', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeTrue();
});

it('rejects backtick in hostname', function () {
    $rule = new ValidHostname;
    $failed = false;
    $rule->validate('hostname', 'example.com`id`', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeTrue();
});
