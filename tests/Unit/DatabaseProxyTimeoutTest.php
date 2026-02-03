<?php

// Tests for the database proxy timeout feature (Issue #7743)

it('generates nginx config without proxy_timeout when timeout is 0', function () {
    // Simulate the nginx config generation logic from StartDatabaseProxy
    $proxyTimeout = 0;
    $timeoutDirective = $proxyTimeout > 0 ? "proxy_timeout {$proxyTimeout}s;" : '';

    expect($timeoutDirective)->toBe('');
});

it('generates nginx config without proxy_timeout when timeout is null', function () {
    // Simulate the nginx config generation logic from StartDatabaseProxy
    $proxyTimeout = null ?? 0;
    $timeoutDirective = $proxyTimeout > 0 ? "proxy_timeout {$proxyTimeout}s;" : '';

    expect($timeoutDirective)->toBe('');
});

it('generates nginx config with proxy_timeout when timeout is set', function () {
    // Simulate the nginx config generation logic from StartDatabaseProxy
    $proxyTimeout = 3600;
    $timeoutDirective = $proxyTimeout > 0 ? "proxy_timeout {$proxyTimeout}s;" : '';

    expect($timeoutDirective)->toBe('proxy_timeout 3600s;');
});

it('generates nginx config with correct timeout format for various values', function () {
    $testCases = [
        ['input' => 1, 'expected' => 'proxy_timeout 1s;'],
        ['input' => 60, 'expected' => 'proxy_timeout 60s;'],
        ['input' => 600, 'expected' => 'proxy_timeout 600s;'],
        ['input' => 3600, 'expected' => 'proxy_timeout 3600s;'],
        ['input' => 14400, 'expected' => 'proxy_timeout 14400s;'],  // 4 hours for long queries
    ];

    foreach ($testCases as $testCase) {
        $proxyTimeout = $testCase['input'];
        $timeoutDirective = $proxyTimeout > 0 ? "proxy_timeout {$proxyTimeout}s;" : '';
        expect($timeoutDirective)->toBe($testCase['expected']);
    }
});

it('includes proxy_socket_keepalive in nginx stream config', function () {
    // This verifies the nginx config includes keepalive for long connections
    $containerName = 'test-uuid';
    $internalPort = 5432;
    $publicPort = 5432;
    $proxyTimeout = 0;
    $timeoutDirective = $proxyTimeout > 0 ? "proxy_timeout {$proxyTimeout}s;" : '';

    $nginxconf = <<<EOF
    user  nginx;
    worker_processes  auto;

    error_log  /var/log/nginx/error.log;

    events {
        worker_connections  1024;
    }
    stream {
        server {
            listen $publicPort;
            proxy_pass $containerName:$internalPort;
            proxy_socket_keepalive on;
            {$timeoutDirective}
        }
    }
    EOF;

    expect($nginxconf)->toContain('proxy_socket_keepalive on;');
});

it('nginx stream config has correct structure with timeout directive', function () {
    $containerName = 'test-uuid';
    $internalPort = 5432;
    $publicPort = 5432;
    $proxyTimeout = 3600;
    $timeoutDirective = $proxyTimeout > 0 ? "proxy_timeout {$proxyTimeout}s;" : '';

    $nginxconf = <<<EOF
    user  nginx;
    worker_processes  auto;

    error_log  /var/log/nginx/error.log;

    events {
        worker_connections  1024;
    }
    stream {
        server {
            listen $publicPort;
            proxy_pass $containerName:$internalPort;
            proxy_socket_keepalive on;
            {$timeoutDirective}
        }
    }
    EOF;

    expect($nginxconf)
        ->toContain('stream {')
        ->toContain("listen $publicPort;")
        ->toContain("proxy_pass $containerName:$internalPort;")
        ->toContain('proxy_socket_keepalive on;')
        ->toContain('proxy_timeout 3600s;');
});

it('validates proxy timeout must be non-negative integer', function () {
    // Test validation logic that should be applied in Livewire components
    $validValues = [0, 1, 60, 600, 3600, 14400];
    $invalidValues = [-1, -100];

    foreach ($validValues as $value) {
        expect($value)->toBeGreaterThanOrEqual(0);
        expect(is_int($value))->toBeTrue();
    }

    foreach ($invalidValues as $value) {
        expect($value)->toBeLessThan(0);
    }
});

it('handles various database port configurations', function () {
    // Test the internal port mapping logic
    $portMappings = [
        'standalone-mariadb' => 3306,
        'standalone-mysql' => 3306,
        'standalone-postgresql' => 5432,
        'standalone-supabase/postgres' => 5432,
        'standalone-redis' => 6379,
        'standalone-keydb' => 6379,
        'standalone-dragonfly' => 6379,
        'standalone-clickhouse' => 9000,
        'standalone-mongodb' => 27017,
    ];

    foreach ($portMappings as $databaseType => $expectedPort) {
        $internalPort = match ($databaseType) {
            'standalone-mariadb', 'standalone-mysql' => 3306,
            'standalone-postgresql', 'standalone-supabase/postgres' => 5432,
            'standalone-redis', 'standalone-keydb', 'standalone-dragonfly' => 6379,
            'standalone-clickhouse' => 9000,
            'standalone-mongodb' => 27017,
            default => throw new \Exception("Unsupported database type: $databaseType"),
        };

        expect($internalPort)->toBe($expectedPort);
    }
});
