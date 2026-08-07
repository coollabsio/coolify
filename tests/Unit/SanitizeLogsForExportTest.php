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

it('removes GitHub tokens', function () {
    $testCases = [
        'ghp_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789' => 'ghp_ personal access token',
        'gho_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789' => 'gho_ OAuth token',
        'ghu_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789' => 'ghu_ user-to-server token',
        'ghs_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789' => 'ghs_ server-to-server token',
        'ghr_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789' => 'ghr_ refresh token',
    ];

    foreach ($testCases as $token => $description) {
        $input = "Token: {$token}";
        $result = sanitizeLogsForExport($input);
        expect($result)->not->toContain($token, "Failed to redact {$description}");
        expect($result)->toContain(REDACTED);
    }
});

it('removes GitLab tokens', function () {
    $testCases = [
        'glpat-aBcDeFgHiJkLmNoPqRsTu' => 'glpat- personal access token',
        'glcbt-aBcDeFgHiJkLmNoPqRsTu' => 'glcbt- CI build token',
        'glrt-aBcDeFgHiJkLmNoPqRsTuV' => 'glrt- runner token',
    ];

    foreach ($testCases as $token => $description) {
        $input = "Token: {$token}";
        $result = sanitizeLogsForExport($input);
        expect($result)->not->toContain($token, "Failed to redact {$description}");
        expect($result)->toContain(REDACTED);
    }
});

it('removes AWS credentials', function () {
    // AWS Access Key ID (starts with AKIA, ABIA, ACCA, or ASIA)
    $accessKeyId = 'AKIAIOSFODNN7EXAMPLE';
    $input = "AWS_ACCESS_KEY_ID={$accessKeyId}";
    $result = sanitizeLogsForExport($input);

    expect($result)->not->toContain($accessKeyId);
    expect($result)->toContain(REDACTED);
});

it('removes AWS secret access key', function () {
    $secretKey = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
    $input = "aws_secret_access_key={$secretKey}";
    $result = sanitizeLogsForExport($input);

    expect($result)->not->toContain($secretKey);
    expect($result)->toContain('aws_secret_access_key='.REDACTED);
});

it('removes HTTPS basic auth passwords from git URLs', function () {
    $testCases = [
        'https://oauth2:glpat-xxxxxxxxxxxx@gitlab.com/user/repo.git' => 'https://oauth2:'.REDACTED.'@'.REDACTED,
        'https://user:my-secret-token@gitlab.example.com/group/repo.git' => 'https://user:'.REDACTED.'@'.REDACTED,
        'http://deploy:token123@git.internal.com/repo.git' => 'http://deploy:'.REDACTED.'@'.REDACTED,
    ];

    foreach ($testCases as $input => $notExpected) {
        $result = sanitizeLogsForExport($input);
        // The password should be redacted
        expect($result)->not->toContain('glpat-xxxxxxxxxxxx');
        expect($result)->not->toContain('my-secret-token');
        expect($result)->not->toContain('token123');
    }
});

it('removes generic URL passwords', function () {
    $testCases = [
        'ftp://user:ftppass@ftp.example.com/path' => 'ftp://user:'.REDACTED.'@ftp.example.com/path',
        'sftp://deploy:secret123@sftp.example.com' => 'sftp://deploy:'.REDACTED.'@sftp.example.com',
        'ssh://git:sshpass@git.example.com/repo' => 'ssh://git:'.REDACTED.'@git.example.com/repo',
        'amqp://rabbit:bunny123@rabbitmq:5672' => 'amqp://rabbit:'.REDACTED.'@rabbitmq:5672',
        'ldap://admin:ldappass@ldap.example.com' => 'ldap://admin:'.REDACTED.'@ldap.example.com',
        's3://access:secretkey@bucket.s3.amazonaws.com' => 's3://access:'.REDACTED.'@bucket.s3.amazonaws.com',
    ];

    foreach ($testCases as $input => $expected) {
        $result = sanitizeLogsForExport($input);
        expect($result)->toBe($expected);
    }
});

it('does not corrupt logs when URL password regex would cross line boundaries', function () {
    $input = implode("\n", [
        '{"timestamp":"2025-01-01T00:00:00Z","message":"connecting to postgres://user:secretpass"}',
        '{"timestamp":"2025-01-01T00:00:01Z","message":"normal log line with no sensitive data"}',
        '{"timestamp":"2025-01-01T00:00:02Z","message":"notification sent to admin@example.com"}',
    ]);
    $result = sanitizeLogsForExport($input);
    $lines = explode("\n", $result);

    // All 3 lines must be preserved as separate lines
    expect($lines)->toHaveCount(3);

    // Line 1 remains intact (no @ on this line means the URL regex does not match)
    expect($lines[0])->toContain('postgres://user:secretpass');

    // Line 2 should be completely untouched
    expect($lines[1])->toBe('{"timestamp":"2025-01-01T00:00:01Z","message":"normal log line with no sensitive data"}');

    // Line 3 email gets redacted independently
    expect($lines[2])->not->toContain('admin@example.com');
    expect($lines[2])->toContain(REDACTED);
});

it('sanitizes complete credential URLs on individual lines independently', function () {
    $input = implode("\n", [
        'postgres://user:secret1@localhost:5432/db',
        'mysql://admin:secret2@db.host.com/app',
    ]);
    $result = sanitizeLogsForExport($input);
    $lines = explode("\n", $result);

    expect($lines)->toHaveCount(2);
    expect($lines[0])->toBe('postgres://user:'.REDACTED.'@localhost:5432/db');
    expect($lines[1])->toBe('mysql://admin:'.REDACTED.'@db.host.com/app');
});

it('still redacts private key blocks spanning multiple lines after line-by-line fix', function () {
    $input = implode("\n", [
        'Config:',
        '-----BEGIN RSA PRIVATE KEY-----',
        'MIIEowIBAAKCAQEAyZ3xL8v4xK3z9Z3',
        'some-key-content-here',
        '-----END RSA PRIVATE KEY-----',
        'After key',
    ]);
    $result = sanitizeLogsForExport($input);

    expect($result)->not->toContain('-----BEGIN RSA PRIVATE KEY-----');
    expect($result)->not->toContain('MIIEowIBAAKCAQEAyZ3xL8v4xK3z9Z3');
    expect($result)->not->toContain('some-key-content-here');
    expect($result)->not->toContain('-----END RSA PRIVATE KEY-----');
    expect($result)->toContain(REDACTED);
    expect($result)->toContain('Config:');
    expect($result)->toContain('After key');
});

it('preserves line count in multi-line log output', function () {
    $input = implode("\n", [
        '2025-01-01 INFO: Application started',
        '2025-01-01 DEBUG: Connected to redis://default:mypass@redis:6379',
        '2025-01-01 INFO: Processing complete',
        '2025-01-01 WARN: Timeout on request',
        '2025-01-01 INFO: Shutting down',
    ]);
    $result = sanitizeLogsForExport($input);
    $inputLines = explode("\n", $input);
    $outputLines = explode("\n", $result);

    expect($outputLines)->toHaveCount(count($inputLines));
    expect($outputLines[1])->toContain(REDACTED);
    expect($outputLines[1])->not->toContain('mypass');
    expect($outputLines[0])->toBe('2025-01-01 INFO: Application started');
    expect($outputLines[2])->toBe('2025-01-01 INFO: Processing complete');
});
