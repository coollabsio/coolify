<?php

it('removes email addresses', function () {
    $input = 'User email is test@example.com and another@domain.org';
    $result = sanitizeLogsForExport($input);

    expect($result)->not->toContain('test@example.com');
    expect($result)->not->toContain('another@domain.org');
    expect($result)->toContain(REDACTED);
});

it('removes JWT/Bearer tokens', function () {
    $jwt = 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
    $input = "Authorization: {$jwt}";
    $result = sanitizeLogsForExport($input);

    expect($result)->not->toContain('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9');
    expect($result)->toContain('Bearer '.REDACTED);
});

it('removes API keys with common patterns', function () {
    $testCases = [
        'api_key=abcdef1234567890abcdef1234567890',
        'api-key: abcdef1234567890abcdef1234567890',
        'apikey=abcdef1234567890abcdef1234567890',
        'api_secret="abcdef1234567890abcdef1234567890"',
        'secret_key=abcdef1234567890abcdef1234567890',
    ];

    foreach ($testCases as $input) {
        $result = sanitizeLogsForExport($input);
        expect($result)->not->toContain('abcdef1234567890abcdef1234567890');
        expect($result)->toContain(REDACTED);
    }
});

it('removes database URLs with passwords', function () {
    $testCases = [
        'postgres://user:secretpassword@localhost:5432/db' => 'postgres://user:'.REDACTED.'@localhost:5432/db',
        'mysql://admin:mysecret123@db.example.com/app' => 'mysql://admin:'.REDACTED.'@db.example.com/app',
        'mongodb://user:pass123@mongo:27017' => 'mongodb://user:'.REDACTED.'@mongo:27017',
        'redis://default:redispass@redis:6379' => 'redis://default:'.REDACTED.'@redis:6379',
        'rediss://default:redispass@redis:6379' => 'rediss://default:'.REDACTED.'@redis:6379',
        'mariadb://root:rootpass@mariadb:3306/test' => 'mariadb://root:'.REDACTED.'@mariadb:3306/test',
    ];

    foreach ($testCases as $input => $expected) {
        $result = sanitizeLogsForExport($input);
        expect($result)->toBe($expected);
    }
});

it('removes private key blocks', function () {
    $privateKey = <<<'KEY'
-----BEGIN RSA PRIVATE KEY-----
MIIEowIBAAKCAQEAyZ3xL8v4xK3z9Z3
some-key-content-here
-----END RSA PRIVATE KEY-----
KEY;
    $input = "Config: {$privateKey} more text";
    $result = sanitizeLogsForExport($input);

    expect($result)->not->toContain('-----BEGIN RSA PRIVATE KEY-----');
    expect($result)->not->toContain('MIIEowIBAAKCAQEAyZ3xL8v4xK3z9Z3');
    expect($result)->toContain(REDACTED);
    expect($result)->toContain('more text');
});

it('removes x-access-token from git URLs', function () {
    $input = 'git clone https://x-access-token:gho_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx@github.com/user/repo.git';
    $result = sanitizeLogsForExport($input);

    expect($result)->not->toContain('gho_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
    expect($result)->toContain('x-access-token:'.REDACTED.'@github.com');
});

it('removes ANSI color codes', function () {
    $input = "\e[32mGreen text\e[0m and \e[31mred text\e[0m";
    $result = sanitizeLogsForExport($input);

    expect($result)->toBe('Green text and red text');
});

it('preserves normal log content', function () {
    $input = "2025-12-16T10:30:45.123456Z INFO: Application started\n2025-12-16T10:30:46.789012Z DEBUG: Processing request";
    $result = sanitizeLogsForExport($input);

    expect($result)->toBe($input);
});

it('handles empty string', function () {
    expect(sanitizeLogsForExport(''))->toBe('');
});

it('handles multiple sensitive items in same string', function () {
    $input = 'Email: user@test.com, DB: postgres://admin:secret@localhost/db, API: api_key=12345678901234567890';
    $result = sanitizeLogsForExport($input);

    expect($result)->not->toContain('user@test.com');
    expect($result)->not->toContain('secret');
    expect($result)->not->toContain('12345678901234567890');
    expect($result)->toContain(REDACTED);
});
