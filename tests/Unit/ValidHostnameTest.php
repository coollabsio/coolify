<?php

use App\Rules\ValidHostname;

it('accepts valid RFC 1123 hostnames', function (string $hostname) {
    $rule = new ValidHostname;
    $failCalled = false;

    $rule->validate('server_name', $hostname, function () use (&$failCalled) {
        $failCalled = true;
    });

    expect($failCalled)->toBeFalse();
})->with([
    'simple hostname' => 'myserver',
    'hostname with hyphen' => 'my-server',
    'hostname with numbers' => 'server123',
    'hostname starting with number' => '123server',
    'all numeric hostname' => '12345',
    'fqdn' => 'server.example.com',
    'subdomain' => 'web.app.example.com',
    'max label length' => str_repeat('a', 63),
    'max total length' => str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 59),
]);

it('rejects invalid RFC 1123 hostnames', function (string $hostname, string $expectedError) {
    $rule = new ValidHostname;
    $failCalled = false;
    $errorMessage = '';

    $rule->validate('server_name', $hostname, function ($message) use (&$failCalled, &$errorMessage) {
        $failCalled = true;
        $errorMessage = $message;
    });

    expect($failCalled)->toBeTrue();
    expect($errorMessage)->toContain($expectedError);
})->with([
    'uppercase letters' => ['MyServer', 'lowercase letters (a-z), numbers (0-9), hyphens (-), and dots (.)'],
    'underscore' => ['my_server', 'lowercase letters (a-z), numbers (0-9), hyphens (-), and dots (.)'],
    'starts with hyphen' => ['-myserver', 'cannot start or end with a hyphen'],
    'ends with hyphen' => ['myserver-', 'cannot start or end with a hyphen'],
    'starts with dot' => ['.myserver', 'cannot start or end with a dot'],
    'ends with dot' => ['myserver.', 'cannot start or end with a dot'],
    'consecutive dots' => ['my..server', 'consecutive dots'],
    'too long total' => [str_repeat('a', 254), 'must not exceed 253 characters'],
    'label too long' => [str_repeat('a', 64), 'must be 1-63 characters'],
    'empty label' => ['my..server', 'consecutive dots'],
    'special characters' => ['my@server', 'lowercase letters (a-z), numbers (0-9), hyphens (-), and dots (.)'],
    'space' => ['my server', 'lowercase letters (a-z), numbers (0-9), hyphens (-), and dots (.)'],
    'shell metacharacters' => ['my;server', 'lowercase letters (a-z), numbers (0-9), hyphens (-), and dots (.)'],
]);

it('accepts empty hostname', function () {
    $rule = new ValidHostname;
    $failCalled = false;

    $rule->validate('server_name', '', function () use (&$failCalled) {
        $failCalled = true;
    });

    expect($failCalled)->toBeFalse();
});

it('trims whitespace before validation', function () {
    $rule = new ValidHostname;
    $failCalled = false;

    $rule->validate('server_name', '  myserver  ', function () use (&$failCalled) {
        $failCalled = true;
    });

    expect($failCalled)->toBeFalse();
});
