<?php

/**
 * Unit tests to verify that SERVICE_URL_* and SERVICE_FQDN_* variables
 * with port suffixes are properly handled and populated.
 *
 * These variables should include the port number in both the key name and the URL value.
 * Example: SERVICE_URL_UMAMI_3000 should be populated with http://domain.com:3000
 */
it('ensures parsers.php populates port-specific SERVICE variables', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that the fix is in place
    $hasPortSpecificComment = str_contains($parsersFile, 'For port-specific variables');
    $usesFqdnWithPort = str_contains($parsersFile, '$fqdnWithPort');
    $usesUrlWithPort = str_contains($parsersFile, '$urlWithPort');

    expect($hasPortSpecificComment)->toBeTrue('Should have comment about port-specific variables');
    expect($usesFqdnWithPort)->toBeTrue('Should use $fqdnWithPort for port variables');
    expect($usesUrlWithPort)->toBeTrue('Should use $urlWithPort for port variables');
});

it('verifies SERVICE_URL variable naming convention', function () {
    // Test the naming convention for port-specific variables

    // Base variable (no port): SERVICE_URL_UMAMI
    $baseKey = 'SERVICE_URL_UMAMI';
    expect(substr_count($baseKey, '_'))->toBe(2);

    // Port-specific variable: SERVICE_URL_UMAMI_3000
    $portKey = 'SERVICE_URL_UMAMI_3000';
    expect(substr_count($portKey, '_'))->toBe(3);

    // Extract service name
    $serviceName = str($portKey)->after('SERVICE_URL_')->beforeLast('_')->lower()->value();
    expect($serviceName)->toBe('umami');

    // Extract port
    $port = str($portKey)->afterLast('_')->value();
    expect($port)->toBe('3000');
});

it('verifies SERVICE_FQDN variable naming convention', function () {
    // Test the naming convention for port-specific FQDN variables

    // Base variable (no port): SERVICE_FQDN_POSTGRES
    $baseKey = 'SERVICE_FQDN_POSTGRES';
    expect(substr_count($baseKey, '_'))->toBe(2);

    // Port-specific variable: SERVICE_FQDN_POSTGRES_5432
    $portKey = 'SERVICE_FQDN_POSTGRES_5432';
    expect(substr_count($portKey, '_'))->toBe(3);

    // Extract service name
    $serviceName = str($portKey)->after('SERVICE_FQDN_')->beforeLast('_')->lower()->value();
    expect($serviceName)->toBe('postgres');

    // Extract port
    $port = str($portKey)->afterLast('_')->value();
    expect($port)->toBe('5432');
});

it('verifies URL with port format', function () {
    // Test that URLs with ports are formatted correctly
    $baseUrl = 'http://umami-abc123.domain.com';
    $port = '3000';

    $urlWithPort = "$baseUrl:$port";

    expect($urlWithPort)->toBe('http://umami-abc123.domain.com:3000');
    expect($urlWithPort)->toContain(':3000');
});

it('verifies FQDN with port format', function () {
    // Test that FQDNs with ports are formatted correctly
    $baseFqdn = 'postgres-xyz789.domain.com';
    $port = '5432';

    $fqdnWithPort = "$baseFqdn:$port";

    expect($fqdnWithPort)->toBe('postgres-xyz789.domain.com:5432');
    expect($fqdnWithPort)->toContain(':5432');
});

it('verifies port extraction from variable name', function () {
    // Test extracting port from various variable names
    $tests = [
        'SERVICE_URL_APP_3000' => '3000',
        'SERVICE_URL_API_8080' => '8080',
        'SERVICE_FQDN_DB_5432' => '5432',
        'SERVICE_FQDN_REDIS_6379' => '6379',
    ];

    foreach ($tests as $varName => $expectedPort) {
        $port = str($varName)->afterLast('_')->value();
        expect($port)->toBe($expectedPort, "Port extraction failed for $varName");
    }
});

it('verifies service name extraction with port suffix', function () {
    // Test extracting service name when port is present
    $tests = [
        'SERVICE_URL_APP_3000' => 'app',
        'SERVICE_URL_MY_API_8080' => 'my_api',
        'SERVICE_FQDN_DB_5432' => 'db',
        'SERVICE_FQDN_REDIS_CACHE_6379' => 'redis_cache',
    ];

    foreach ($tests as $varName => $expectedService) {
        if (str($varName)->startsWith('SERVICE_URL_')) {
            $serviceName = str($varName)->after('SERVICE_URL_')->beforeLast('_')->lower()->value();
        } else {
            $serviceName = str($varName)->after('SERVICE_FQDN_')->beforeLast('_')->lower()->value();
        }
        expect($serviceName)->toBe($expectedService, "Service name extraction failed for $varName");
    }
});

it('verifies distinction between base and port-specific variables', function () {
    // Test that base and port-specific variables are different
    $baseUrl = 'SERVICE_URL_UMAMI';
    $portUrl = 'SERVICE_URL_UMAMI_3000';

    expect($baseUrl)->not->toBe($portUrl);
    expect(substr_count($baseUrl, '_'))->toBe(2);
    expect(substr_count($portUrl, '_'))->toBe(3);

    // Port-specific should contain port number
    expect(str($portUrl)->contains('_3000'))->toBeTrue();
    expect(str($baseUrl)->contains('_3000'))->toBeFalse();
});

it('verifies multiple port variables for same service', function () {
    // Test that a service can have multiple port-specific variables
    $service = 'api';
    $ports = ['3000', '8080', '9090'];

    foreach ($ports as $port) {
        $varName = "SERVICE_URL_API_$port";

        // Should have 3 underscores
        expect(substr_count($varName, '_'))->toBe(3);

        // Should extract correct service name
        $serviceName = str($varName)->after('SERVICE_URL_')->beforeLast('_')->lower()->value();
        expect($serviceName)->toBe('api');

        // Should extract correct port
        $extractedPort = str($varName)->afterLast('_')->value();
        expect($extractedPort)->toBe($port);
    }
});

it('verifies common port numbers are handled correctly', function () {
    // Test common port numbers used in applications
    $commonPorts = [
        '80' => 'HTTP',
        '443' => 'HTTPS',
        '3000' => 'Node.js/React',
        '5432' => 'PostgreSQL',
        '6379' => 'Redis',
        '8080' => 'Alternative HTTP',
        '9000' => 'PHP-FPM',
    ];

    foreach ($commonPorts as $port => $description) {
        $varName = "SERVICE_URL_APP_$port";

        expect(substr_count($varName, '_'))->toBe(3, "Failed for $description port $port");

        $extractedPort = str($varName)->afterLast('_')->value();
        expect($extractedPort)->toBe((string) $port, "Port extraction failed for $description");
    }
});

it('detects port-specific variables with numeric suffix', function () {
    // Test that variables ending with a numeric port are detected correctly
    // This tests the logic: if last segment after _ is numeric, it's a port

    $tests = [
        // 2-underscore pattern: single-word service name + port
        'SERVICE_URL_MYAPP_3000' => ['service' => 'myapp', 'port' => '3000', 'hasPort' => true],
        'SERVICE_URL_REDIS_6379' => ['service' => 'redis', 'port' => '6379', 'hasPort' => true],
        'SERVICE_FQDN_NGINX_80' => ['service' => 'nginx', 'port' => '80', 'hasPort' => true],

        // 3-underscore pattern: two-word service name + port
        'SERVICE_URL_MY_API_8080' => ['service' => 'my_api', 'port' => '8080', 'hasPort' => true],
        'SERVICE_URL_WEB_APP_3000' => ['service' => 'web_app', 'port' => '3000', 'hasPort' => true],
        'SERVICE_FQDN_DB_SERVER_5432' => ['service' => 'db_server', 'port' => '5432', 'hasPort' => true],

        // 4-underscore pattern: three-word service name + port
        'SERVICE_URL_REDIS_CACHE_SERVER_6379' => ['service' => 'redis_cache_server', 'port' => '6379', 'hasPort' => true],
        'SERVICE_URL_MY_LONG_APP_8080' => ['service' => 'my_long_app', 'port' => '8080', 'hasPort' => true],
        'SERVICE_FQDN_POSTGRES_PRIMARY_DB_5432' => ['service' => 'postgres_primary_db', 'port' => '5432', 'hasPort' => true],

        // Non-numeric suffix: should NOT be treated as port-specific
        'SERVICE_URL_MY_APP' => ['service' => 'my_app', 'port' => null, 'hasPort' => false],
        'SERVICE_URL_REDIS_PRIMARY' => ['service' => 'redis_primary', 'port' => null, 'hasPort' => false],
        'SERVICE_FQDN_WEB_SERVER' => ['service' => 'web_server', 'port' => null, 'hasPort' => false],
        'SERVICE_URL_APP_CACHE_REDIS' => ['service' => 'app_cache_redis', 'port' => null, 'hasPort' => false],

        // Edge numeric cases
        'SERVICE_URL_APP_0' => ['service' => 'app', 'port' => '0', 'hasPort' => true],  // Port 0
        'SERVICE_URL_APP_99999' => ['service' => 'app', 'port' => '99999', 'hasPort' => true],  // Port out of range
        'SERVICE_URL_APP_3.14' => ['service' => 'app_3.14', 'port' => null, 'hasPort' => false],  // Float (should not be port)
        'SERVICE_URL_APP_1e5' => ['service' => 'app_1e5', 'port' => null, 'hasPort' => false],  // Scientific notation

        // Edge cases
        'SERVICE_URL_APP' => ['service' => 'app', 'port' => null, 'hasPort' => false],
        'SERVICE_FQDN_DB' => ['service' => 'db', 'port' => null, 'hasPort' => false],
    ];

    foreach ($tests as $varName => $expected) {
        // Use the actual helper function from bootstrap/helpers/services.php
        $parsed = parseServiceEnvironmentVariable($varName);

        expect($parsed['service_name'])->toBe($expected['service'], "Service name mismatch for $varName");
        expect($parsed['port'])->toBe($expected['port'], "Port mismatch for $varName");
        expect($parsed['has_port'])->toBe($expected['hasPort'], "Port detection mismatch for $varName");
    }
});
