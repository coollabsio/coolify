<?php

it('returns a literal ipv4 address unchanged', function () {
    $result = resolveServerIpAddress('203.0.113.10');

    expect($result['ip'])->toBe('203.0.113.10')
        ->and($result['configured'])->toBe('203.0.113.10')
        ->and($result['resolved_from_hostname'])->toBeFalse();
});

it('returns a literal ipv6 address unchanged', function () {
    $result = resolveServerIpAddress('2001:db8::1');

    expect($result['ip'])->toBe('2001:db8::1')
        ->and($result['configured'])->toBe('2001:db8::1')
        ->and($result['resolved_from_hostname'])->toBeFalse();
});

it('strips brackets from ipv6 addresses', function () {
    $result = resolveServerIpAddress('[2001:db8::1]');

    expect($result['ip'])->toBe('2001:db8::1')
        ->and($result['resolved_from_hostname'])->toBeFalse();
});

it('resolves localhost hostname to a real ip address', function () {
    $result = resolveServerIpAddress('localhost');

    expect($result['configured'])->toBe('localhost')
        ->and($result['resolved_from_hostname'])->toBeTrue()
        ->and($result['ip'])->not->toBeNull()
        ->and(filter_var($result['ip'], FILTER_VALIDATE_IP))->not->toBeFalse();
});

it('returns nulls for empty input', function () {
    expect(resolveServerIpAddress(null))->toMatchArray([
        'ip' => null,
        'configured' => null,
        'resolved_from_hostname' => false,
    ])->and(resolveServerIpAddress(''))->toMatchArray([
        'ip' => null,
        'configured' => null,
        'resolved_from_hostname' => false,
    ]);
});

it('keeps configured hostname when it cannot be resolved', function () {
    $host = 'this-hostname-should-not-resolve-'.bin2hex(random_bytes(8)).'.invalid';
    $result = resolveServerIpAddress($host);

    expect($result['configured'])->toBe($host)
        ->and($result['ip'])->toBeNull()
        ->and($result['resolved_from_hostname'])->toBeFalse();
});
