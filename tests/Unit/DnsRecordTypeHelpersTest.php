<?php

it('returns A for ipv4 addresses', function () {
    expect(dnsRecordTypeForIp('203.0.113.10'))->toBe('A')
        ->and(dnsRecordTypeForIp('172.16.0.3 (coolify-testing-host)'))->toBe('A');
});

it('returns AAAA for ipv6 addresses', function () {
    expect(dnsRecordTypeForIp('2001:db8::1'))->toBe('AAAA')
        ->and(dnsRecordTypeForIp('[2001:db8::1]'))->toBe('AAAA')
        ->and(dnsRecordTypeForIp('2001:db8::1 (my-host)'))->toBe('AAAA');
});

it('defaults to A when the address is missing or not an ip', function () {
    expect(dnsRecordTypeForIp(null))->toBe('A')
        ->and(dnsRecordTypeForIp(''))->toBe('A')
        ->and(dnsRecordTypeForIp('coolify-testing-host'))->toBe('A');
});

it('builds a short A-record guidance message for ipv4 targets', function () {
    $message = dnsMismatchGuidanceMessage('172.16.0.3 (coolify-testing-host)', '172.16.0.3');

    expect($message)->toBe('Required DNS record type A pointing to 172.16.0.3')
        ->and($message)->not->toContain('—')
        ->and($message)->not->toContain('continue');
});

it('builds a short AAAA-record guidance message for ipv6 targets', function () {
    $message = dnsMismatchGuidanceMessage('2001:db8::1', '2001:db8::1');

    expect($message)->toBe('Required DNS record type AAAA pointing to 2001:db8::1')
        ->and($message)->not->toContain('—')
        ->and($message)->not->toContain('continue');
});

it('prefers the bare ip over a hostname label', function () {
    expect(dnsMismatchGuidanceMessage('coolify-testing-host', '172.16.0.3'))
        ->toBe('Required DNS record type A pointing to 172.16.0.3');
});

it('falls back when no target is available', function () {
    expect(dnsMismatchGuidanceMessage(null))->toBe('DNS validation failed. Check your DNS records.');
});

it('accepts Cloudflare ipv6 proxy addresses', function () {
    expect(isCloudflareIp('2606:4700:3037::6815:4f2b'))->toBeTrue()
        ->and(isCloudflareIp('2001:db8::1'))->toBeFalse();
});
