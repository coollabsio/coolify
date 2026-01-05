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

test('IP allowlist with various subnet sizes', function () {
    // /32 - single host
    expect(checkIPAgainstAllowlist('192.168.1.1', ['192.168.1.1/32']))->toBeTrue();
    expect(checkIPAgainstAllowlist('192.168.1.2', ['192.168.1.1/32']))->toBeFalse();

    // /31 - point-to-point link
    expect(checkIPAgainstAllowlist('192.168.1.0', ['192.168.1.0/31']))->toBeTrue();
    expect(checkIPAgainstAllowlist('192.168.1.1', ['192.168.1.0/31']))->toBeTrue();
    expect(checkIPAgainstAllowlist('192.168.1.2', ['192.168.1.0/31']))->toBeFalse();

    // /16 - class B
    expect(checkIPAgainstAllowlist('172.16.0.1', ['172.16.0.0/16']))->toBeTrue();
    expect(checkIPAgainstAllowlist('172.16.255.255', ['172.16.0.0/16']))->toBeTrue();
    expect(checkIPAgainstAllowlist('172.17.0.1', ['172.16.0.0/16']))->toBeFalse();

    // /0 - all addresses
    expect(checkIPAgainstAllowlist('1.1.1.1', ['0.0.0.0/0']))->toBeTrue();
    expect(checkIPAgainstAllowlist('255.255.255.255', ['0.0.0.0/0']))->toBeTrue();
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
    expect($validate('192.168.1.1'))->toBeTrue(); // Valid IP
    expect($validate('192.168.1.0/24'))->toBeTrue(); // Valid CIDR
    expect($validate('10.0.0.0/8'))->toBeTrue(); // Valid CIDR
    expect($validate('192.168.1.1,10.0.0.1'))->toBeTrue(); // Multiple valid IPs
    expect($validate('192.168.1.0/24,10.0.0.0/8'))->toBeTrue(); // Multiple CIDRs
    expect($validate('0.0.0.0/0'))->toBeTrue(); // 0.0.0.0 with subnet
    expect($validate('0.0.0.0/24'))->toBeTrue(); // 0.0.0.0 with any subnet
    expect($validate(' 192.168.1.1 '))->toBeTrue(); // With spaces

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
