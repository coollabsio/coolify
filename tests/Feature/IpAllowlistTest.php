<?php

test('IP allowlist with single IPs', function () {
    $testCases = [
        ['ip' => '192.168.1.100', 'allowlist' => ['192.168.1.100'], 'expected' => true],
        ['ip' => '192.168.1.101', 'allowlist' => ['192.168.1.100'], 'expected' => false],
        ['ip' => '10.0.0.1', 'allowlist' => ['10.0.0.1', '192.168.1.100'], 'expected' => true],
    ];

    foreach ($testCases as $case) {
        $result = checkIPAgainstAllowlist($case['ip'], $case['allowlist']);
        expect($result)->toBe($case['expected']);
    }
});

test('IP allowlist with CIDR notation', function () {
    $testCases = [
        ['ip' => '192.168.1.50', 'allowlist' => ['192.168.1.0/24'], 'expected' => true],
        ['ip' => '192.168.2.50', 'allowlist' => ['192.168.1.0/24'], 'expected' => false],
        ['ip' => '10.0.0.5', 'allowlist' => ['10.0.0.0/8'], 'expected' => true],
        ['ip' => '11.0.0.5', 'allowlist' => ['10.0.0.0/8'], 'expected' => false],
        ['ip' => '172.16.5.10', 'allowlist' => ['172.16.0.0/12'], 'expected' => true],
        ['ip' => '172.32.0.1', 'allowlist' => ['172.16.0.0/12'], 'expected' => false],
    ];

    foreach ($testCases as $case) {
        $result = checkIPAgainstAllowlist($case['ip'], $case['allowlist']);
        expect($result)->toBe($case['expected']);
    }
});

test('IP allowlist with 0.0.0.0 allows all', function () {
    $testIps = [
        '1.2.3.4',
        '192.168.1.1',
        '10.0.0.1',
        '255.255.255.255',
        '127.0.0.1',
    ];

    // Test 0.0.0.0 without subnet
    foreach ($testIps as $ip) {
        $result = checkIPAgainstAllowlist($ip, ['0.0.0.0']);
        expect($result)->toBeTrue();
    }

    // Test 0.0.0.0 with any subnet notation - should still allow all
    foreach ($testIps as $ip) {
        expect(checkIPAgainstAllowlist($ip, ['0.0.0.0/0']))->toBeTrue();
        expect(checkIPAgainstAllowlist($ip, ['0.0.0.0/8']))->toBeTrue();
        expect(checkIPAgainstAllowlist($ip, ['0.0.0.0/24']))->toBeTrue();
        expect(checkIPAgainstAllowlist($ip, ['0.0.0.0/32']))->toBeTrue();
    }
});

test('IP allowlist with mixed entries', function () {
    $allowlist = ['192.168.1.100', '10.0.0.0/8', '172.16.0.0/16'];

    $testCases = [
        ['ip' => '192.168.1.100', 'expected' => true],  // Exact match
        ['ip' => '192.168.1.101', 'expected' => false], // No match
        ['ip' => '10.5.5.5', 'expected' => true],       // Matches 10.0.0.0/8
        ['ip' => '172.16.255.255', 'expected' => true], // Matches 172.16.0.0/16
        ['ip' => '172.17.0.1', 'expected' => false],    // Outside 172.16.0.0/16
        ['ip' => '8.8.8.8', 'expected' => false],       // No match
    ];

    foreach ($testCases as $case) {
        $result = checkIPAgainstAllowlist($case['ip'], $allowlist);
        expect($result)->toBe($case['expected']);
    }
});

test('IP allowlist handles empty and invalid entries', function () {
    // Empty allowlist blocks all
    expect(checkIPAgainstAllowlist('192.168.1.1', []))->toBeFalse();
    expect(checkIPAgainstAllowlist('192.168.1.1', ['']))->toBeFalse();

    // Handles spaces
    expect(checkIPAgainstAllowlist('192.168.1.100', [' 192.168.1.100 ']))->toBeTrue();
    expect(checkIPAgainstAllowlist('10.0.0.5', [' 10.0.0.0/8 ']))->toBeTrue();

    // Invalid entries are skipped
    expect(checkIPAgainstAllowlist('192.168.1.1', ['invalid.ip']))->toBeFalse();
    expect(checkIPAgainstAllowlist('192.168.1.1', ['192.168.1.0/33']))->toBeFalse(); // Invalid mask
    expect(checkIPAgainstAllowlist('192.168.1.1', ['192.168.1.0/-1']))->toBeFalse(); // Invalid mask
});

test('IP allowlist with various IPv4 subnet sizes', function () {
    // /32 - single host
    expect(checkIPAgainstAllowlist('192.168.1.1', ['192.168.1.1/32']))->toBeTrue();
    expect(checkIPAgainstAllowlist('192.168.1.2', ['192.168.1.1/32']))->toBeFalse();

    // /31 - point-to-point link
    expect(checkIPAgainstAllowlist('192.168.1.0', ['192.168.1.0/31']))->toBeTrue();
    expect(checkIPAgainstAllowlist('192.168.1.1', ['192.168.1.0/31']))->toBeTrue();
    expect(checkIPAgainstAllowlist('192.168.1.2', ['192.168.1.0/31']))->toBeFalse();

    // /25 - half a /24
    expect(checkIPAgainstAllowlist('192.168.1.1', ['192.168.1.0/25']))->toBeTrue();
    expect(checkIPAgainstAllowlist('192.168.1.127', ['192.168.1.0/25']))->toBeTrue();
    expect(checkIPAgainstAllowlist('192.168.1.128', ['192.168.1.0/25']))->toBeFalse();

    // /16
    expect(checkIPAgainstAllowlist('172.16.0.1', ['172.16.0.0/16']))->toBeTrue();
    expect(checkIPAgainstAllowlist('172.16.255.255', ['172.16.0.0/16']))->toBeTrue();
    expect(checkIPAgainstAllowlist('172.17.0.1', ['172.16.0.0/16']))->toBeFalse();

    // /12
    expect(checkIPAgainstAllowlist('172.16.0.1', ['172.16.0.0/12']))->toBeTrue();
    expect(checkIPAgainstAllowlist('172.31.255.255', ['172.16.0.0/12']))->toBeTrue();
    expect(checkIPAgainstAllowlist('172.32.0.1', ['172.16.0.0/12']))->toBeFalse();

    // /8
    expect(checkIPAgainstAllowlist('10.255.255.255', ['10.0.0.0/8']))->toBeTrue();
    expect(checkIPAgainstAllowlist('11.0.0.1', ['10.0.0.0/8']))->toBeFalse();

    // /0 - all addresses
    expect(checkIPAgainstAllowlist('1.1.1.1', ['0.0.0.0/0']))->toBeTrue();
    expect(checkIPAgainstAllowlist('255.255.255.255', ['0.0.0.0/0']))->toBeTrue();
});

test('IP allowlist with various IPv6 subnet sizes', function () {
    // /128 - single host
    expect(checkIPAgainstAllowlist('2001:db8::1', ['2001:db8::1/128']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:db8::2', ['2001:db8::1/128']))->toBeFalse();

    // /127 - point-to-point link
    expect(checkIPAgainstAllowlist('2001:db8::0', ['2001:db8::/127']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:db8::1', ['2001:db8::/127']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:db8::2', ['2001:db8::/127']))->toBeFalse();

    // /64 - standard subnet
    expect(checkIPAgainstAllowlist('2001:db8:abcd:1234::1', ['2001:db8:abcd:1234::/64']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:db8:abcd:1234:ffff:ffff:ffff:ffff', ['2001:db8:abcd:1234::/64']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:db8:abcd:1235::1', ['2001:db8:abcd:1234::/64']))->toBeFalse();

    // /48 - site prefix
    expect(checkIPAgainstAllowlist('2001:db8:1234::1', ['2001:db8:1234::/48']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:db8:1234:ffff::1', ['2001:db8:1234::/48']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:db8:1235::1', ['2001:db8:1234::/48']))->toBeFalse();

    // /32 - ISP allocation
    expect(checkIPAgainstAllowlist('2001:db8::1', ['2001:db8::/32']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:db8:ffff:ffff::1', ['2001:db8::/32']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:db9::1', ['2001:db8::/32']))->toBeFalse();

    // /16
    expect(checkIPAgainstAllowlist('2001:0000::1', ['2001::/16']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:ffff:ffff::1', ['2001::/16']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2002::1', ['2001::/16']))->toBeFalse();
});

test('IP allowlist with bare IPv6 addresses', function () {
    expect(checkIPAgainstAllowlist('2001:db8::1', ['2001:db8::1']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:db8::2', ['2001:db8::1']))->toBeFalse();
    expect(checkIPAgainstAllowlist('::1', ['::1']))->toBeTrue();
    expect(checkIPAgainstAllowlist('::1', ['::2']))->toBeFalse();
});

test('IP allowlist with IPv6 CIDR notation', function () {
    // /64 prefix — issue #8729 exact case
    expect(checkIPAgainstAllowlist('2a01:e0a:21d:8230::1', ['2a01:e0a:21d:8230::/64']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2a01:e0a:21d:8230:abcd:ef01:2345:6789', ['2a01:e0a:21d:8230::/64']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2a01:e0a:21d:8231::1', ['2a01:e0a:21d:8230::/64']))->toBeFalse();

    // /128 — single host
    expect(checkIPAgainstAllowlist('2001:db8::1', ['2001:db8::1/128']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:db8::2', ['2001:db8::1/128']))->toBeFalse();

    // /48 prefix
    expect(checkIPAgainstAllowlist('2001:db8:1234::1', ['2001:db8:1234::/48']))->toBeTrue();
    expect(checkIPAgainstAllowlist('2001:db8:1235::1', ['2001:db8:1234::/48']))->toBeFalse();
});

test('IP allowlist with mixed IPv4 and IPv6', function () {
    $allowlist = ['192.168.1.100', '10.0.0.0/8', '2a01:e0a:21d:8230::/64'];

    expect(checkIPAgainstAllowlist('192.168.1.100', $allowlist))->toBeTrue();
    expect(checkIPAgainstAllowlist('10.5.5.5', $allowlist))->toBeTrue();
    expect(checkIPAgainstAllowlist('2a01:e0a:21d:8230::cafe', $allowlist))->toBeTrue();
    expect(checkIPAgainstAllowlist('2a01:e0a:21d:8231::1', $allowlist))->toBeFalse();
    expect(checkIPAgainstAllowlist('8.8.8.8', $allowlist))->toBeFalse();
});

test('IP allowlist handles invalid IPv6 masks', function () {
    expect(checkIPAgainstAllowlist('2001:db8::1', ['2001:db8::/129']))->toBeFalse(); // mask > 128
    expect(checkIPAgainstAllowlist('2001:db8::1', ['2001:db8::/-1']))->toBeFalse();  // negative mask
});

test('IP allowlist comma-separated string input', function () {
    // Test with comma-separated string (as it would come from the settings)
    $allowlistString = '192.168.1.100,10.0.0.0/8,172.16.0.0/16';
    $allowlist = explode(',', $allowlistString);

    expect(checkIPAgainstAllowlist('192.168.1.100', $allowlist))->toBeTrue();
    expect(checkIPAgainstAllowlist('10.5.5.5', $allowlist))->toBeTrue();
    expect(checkIPAgainstAllowlist('172.16.10.10', $allowlist))->toBeTrue();
    expect(checkIPAgainstAllowlist('8.8.8.8', $allowlist))->toBeFalse();
});

test('ValidIpOrCidr validation rule', function () {
    $rule = new \App\Rules\ValidIpOrCidr;

    // Helper function to test validation
    $validate = function ($value) use ($rule) {
        $errors = [];
        $fail = function ($message) use (&$errors) {
            $errors[] = $message;
        };
        $rule->validate('allowed_ips', $value, $fail);

        return empty($errors);
    };

    // Valid cases - should pass
    expect($validate(''))->toBeTrue(); // Empty is allowed
    expect($validate('0.0.0.0'))->toBeTrue(); // 0.0.0.0 is allowed
    expect($validate('192.168.1.1'))->toBeTrue(); // Valid IPv4
    expect($validate('192.168.1.0/24'))->toBeTrue(); // Valid IPv4 CIDR
    expect($validate('10.0.0.0/8'))->toBeTrue(); // Valid IPv4 CIDR
    expect($validate('192.168.1.1,10.0.0.1'))->toBeTrue(); // Multiple valid IPs
    expect($validate('192.168.1.0/24,10.0.0.0/8'))->toBeTrue(); // Multiple CIDRs
    expect($validate('0.0.0.0/0'))->toBeTrue(); // 0.0.0.0 with subnet
    expect($validate('0.0.0.0/24'))->toBeTrue(); // 0.0.0.0 with any subnet
    expect($validate(' 192.168.1.1 '))->toBeTrue(); // With spaces
    // IPv6 valid cases — issue #8729
    expect($validate('2001:db8::1'))->toBeTrue(); // Valid bare IPv6
    expect($validate('::1'))->toBeTrue(); // Loopback IPv6
    expect($validate('2a01:e0a:21d:8230::/64'))->toBeTrue(); // IPv6 /64 CIDR
    expect($validate('2001:db8::/48'))->toBeTrue(); // IPv6 /48 CIDR
    expect($validate('2001:db8::1/128'))->toBeTrue(); // IPv6 /128 CIDR
    expect($validate('192.168.1.1,2a01:e0a:21d:8230::/64'))->toBeTrue(); // Mixed IPv4 + IPv6 CIDR

    // Invalid cases - should fail
    expect($validate('1'))->toBeFalse(); // Single digit
    expect($validate('abc'))->toBeFalse(); // Invalid text
    expect($validate('192.168.1.256'))->toBeFalse(); // Invalid IP (256)
    expect($validate('192.168.1.0/33'))->toBeFalse(); // Invalid CIDR mask (>32)
    expect($validate('192.168.1.0/-1'))->toBeFalse(); // Invalid CIDR mask (<0)
    expect($validate('192.168.1.1,abc'))->toBeFalse(); // Mix of valid and invalid
    expect($validate('192.168.1.1,192.168.1.256'))->toBeFalse(); // Mix with invalid IP
    expect($validate('192.168.1.0/24/32'))->toBeFalse(); // Invalid CIDR format
    expect($validate('not.an.ip.address'))->toBeFalse(); // Invalid format
    expect($validate('192.168'))->toBeFalse(); // Incomplete IP
    expect($validate('192.168.1.1.1'))->toBeFalse(); // Too many octets
    expect($validate('2001:db8::/129'))->toBeFalse(); // IPv6 mask > 128
});

test('ValidIpOrCidr validation rule error messages', function () {
    $rule = new \App\Rules\ValidIpOrCidr;

    // Helper function to get error message
    $getError = function ($value) use ($rule) {
        $errors = [];
        $fail = function ($message) use (&$errors) {
            $errors[] = $message;
        };
        $rule->validate('allowed_ips', $value, $fail);

        return $errors[0] ?? null;
    };

    // Test error messages
    $error = $getError('1');
    expect($error)->toContain('not valid IP addresses or CIDR notations');
    expect($error)->toContain('1');

    $error = $getError('192.168.1.1,abc,10.0.0.256');
    expect($error)->toContain('abc');
    expect($error)->toContain('10.0.0.256');
    expect($error)->not->toContain('192.168.1.1'); // Valid IP should not be in error
});

test('deduplicateAllowlist removes bare IPv4 covered by various subnets', function () {
    // /24
    expect(deduplicateAllowlist(['192.168.1.5', '192.168.1.0/24']))->toBe(['192.168.1.0/24']);
    // /16
    expect(deduplicateAllowlist(['172.16.5.10', '172.16.0.0/16']))->toBe(['172.16.0.0/16']);
    // /8
    expect(deduplicateAllowlist(['10.50.100.200', '10.0.0.0/8']))->toBe(['10.0.0.0/8']);
    // /32 — same host, first entry wins (both equivalent)
    expect(deduplicateAllowlist(['192.168.1.1', '192.168.1.1/32']))->toBe(['192.168.1.1']);
    // /31 — point-to-point
    expect(deduplicateAllowlist(['192.168.1.0', '192.168.1.0/31']))->toBe(['192.168.1.0/31']);
    // IP outside subnet — both preserved
    expect(deduplicateAllowlist(['172.17.0.1', '172.16.0.0/16']))->toBe(['172.17.0.1', '172.16.0.0/16']);
});

test('deduplicateAllowlist removes narrow IPv4 CIDR covered by broader CIDR', function () {
    // /32 inside /24
    expect(deduplicateAllowlist(['192.168.1.1/32', '192.168.1.0/24']))->toBe(['192.168.1.0/24']);
    // /25 inside /24
    expect(deduplicateAllowlist(['192.168.1.0/25', '192.168.1.0/24']))->toBe(['192.168.1.0/24']);
    // /24 inside /16
    expect(deduplicateAllowlist(['192.168.1.0/24', '192.168.0.0/16']))->toBe(['192.168.0.0/16']);
    // /16 inside /12
    expect(deduplicateAllowlist(['172.16.0.0/16', '172.16.0.0/12']))->toBe(['172.16.0.0/12']);
    // /16 inside /8
    expect(deduplicateAllowlist(['10.1.0.0/16', '10.0.0.0/8']))->toBe(['10.0.0.0/8']);
    // /24 inside /8
    expect(deduplicateAllowlist(['10.1.2.0/24', '10.0.0.0/8']))->toBe(['10.0.0.0/8']);
    // /12 inside /8
    expect(deduplicateAllowlist(['172.16.0.0/12', '172.0.0.0/8']))->toBe(['172.0.0.0/8']);
    // /31 inside /24
    expect(deduplicateAllowlist(['192.168.1.0/31', '192.168.1.0/24']))->toBe(['192.168.1.0/24']);
    // Non-overlapping CIDRs — both preserved
    expect(deduplicateAllowlist(['192.168.1.0/24', '10.0.0.0/8']))->toBe(['192.168.1.0/24', '10.0.0.0/8']);
    expect(deduplicateAllowlist(['172.16.0.0/16', '192.168.0.0/16']))->toBe(['172.16.0.0/16', '192.168.0.0/16']);
});

test('deduplicateAllowlist removes bare IPv6 covered by various prefixes', function () {
    // /64 — issue #8729 exact scenario
    expect(deduplicateAllowlist(['2a01:e0a:21d:8230::', '127.0.0.1', '2a01:e0a:21d:8230::/64']))
        ->toBe(['127.0.0.1', '2a01:e0a:21d:8230::/64']);
    // /48
    expect(deduplicateAllowlist(['2001:db8:1234::1', '2001:db8:1234::/48']))->toBe(['2001:db8:1234::/48']);
    // /128 — same host, first entry wins (both equivalent)
    expect(deduplicateAllowlist(['2001:db8::1', '2001:db8::1/128']))->toBe(['2001:db8::1']);
    // IP outside prefix — both preserved
    expect(deduplicateAllowlist(['2001:db8:1235::1', '2001:db8:1234::/48']))
        ->toBe(['2001:db8:1235::1', '2001:db8:1234::/48']);
});

test('deduplicateAllowlist removes narrow IPv6 CIDR covered by broader prefix', function () {
    // /128 inside /64
    expect(deduplicateAllowlist(['2a01:e0a:21d:8230::5/128', '2a01:e0a:21d:8230::/64']))->toBe(['2a01:e0a:21d:8230::/64']);
    // /127 inside /64
    expect(deduplicateAllowlist(['2001:db8:1234:5678::/127', '2001:db8:1234:5678::/64']))->toBe(['2001:db8:1234:5678::/64']);
    // /64 inside /48
    expect(deduplicateAllowlist(['2001:db8:1234:5678::/64', '2001:db8:1234::/48']))->toBe(['2001:db8:1234::/48']);
    // /48 inside /32
    expect(deduplicateAllowlist(['2001:db8:abcd::/48', '2001:db8::/32']))->toBe(['2001:db8::/32']);
    // /32 inside /16
    expect(deduplicateAllowlist(['2001:db8::/32', '2001::/16']))->toBe(['2001::/16']);
    // /64 inside /32
    expect(deduplicateAllowlist(['2001:db8:1234:5678::/64', '2001:db8::/32']))->toBe(['2001:db8::/32']);
    // Non-overlapping IPv6 — both preserved
    expect(deduplicateAllowlist(['2001:db8::/32', 'fd00::/8']))->toBe(['2001:db8::/32', 'fd00::/8']);
    expect(deduplicateAllowlist(['2001:db8:1234::/48', '2001:db8:5678::/48']))->toBe(['2001:db8:1234::/48', '2001:db8:5678::/48']);
});

test('deduplicateAllowlist mixed IPv4 and IPv6 subnets', function () {
    $result = deduplicateAllowlist([
        '192.168.1.5',           // covered by 192.168.0.0/16
        '192.168.0.0/16',
        '2a01:e0a:21d:8230::1',  // covered by ::/64
        '2a01:e0a:21d:8230::/64',
        '10.0.0.1',              // not covered by anything
        '::1',                   // not covered by anything
    ]);
    expect($result)->toBe(['192.168.0.0/16', '2a01:e0a:21d:8230::/64', '10.0.0.1', '::1']);
});

test('deduplicateAllowlist preserves non-overlapping entries', function () {
    $result = deduplicateAllowlist(['192.168.1.1', '10.0.0.1', '172.16.0.0/16']);
    expect($result)->toBe(['192.168.1.1', '10.0.0.1', '172.16.0.0/16']);
});

test('deduplicateAllowlist handles exact duplicates', function () {
    expect(deduplicateAllowlist(['192.168.1.1', '192.168.1.1']))->toBe(['192.168.1.1']);
    expect(deduplicateAllowlist(['10.0.0.0/8', '10.0.0.0/8']))->toBe(['10.0.0.0/8']);
    expect(deduplicateAllowlist(['2001:db8::1', '2001:db8::1']))->toBe(['2001:db8::1']);
});

test('deduplicateAllowlist handles single entry and empty array', function () {
    expect(deduplicateAllowlist(['10.0.0.1']))->toBe(['10.0.0.1']);
    expect(deduplicateAllowlist([]))->toBe([]);
});

test('deduplicateAllowlist with 0.0.0.0 removes everything else', function () {
    $result = deduplicateAllowlist(['192.168.1.1', '0.0.0.0', '10.0.0.0/8']);
    expect($result)->toBe(['0.0.0.0']);
});

test('deduplicateAllowlist multiple nested CIDRs keeps only broadest', function () {
    // IPv4: three levels of nesting
    expect(deduplicateAllowlist(['10.1.2.0/24', '10.1.0.0/16', '10.0.0.0/8']))->toBe(['10.0.0.0/8']);
    // IPv6: three levels of nesting
    expect(deduplicateAllowlist(['2001:db8:1234:5678::/64', '2001:db8:1234::/48', '2001:db8::/32']))->toBe(['2001:db8::/32']);
});
