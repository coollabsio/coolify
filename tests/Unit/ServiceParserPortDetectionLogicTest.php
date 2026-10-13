<?php

/**
 * Unit tests to verify the parser logic for detecting port-specific SERVICE variables.
 * These tests simulate the logic used in bootstrap/helpers/parsers.php without database operations.
 *
 * The parser should detect when a SERVICE_URL_* or SERVICE_FQDN_* variable has a numeric
 * port suffix and extract both the service name and port correctly.
 */
it('detects port suffix using numeric check (correct logic)', function () {
    // This tests the CORRECT logic: check if last segment is numeric
    $testCases = [
        // [variable_name, expected_service_name, expected_port, is_port_specific]

        // 2-underscore pattern: SERVICE_URL_{SERVICE}_{PORT}
        ['SERVICE_URL_MYAPP_3000', 'myapp', '3000', true],
        ['SERVICE_URL_REDIS_6379', 'redis', '6379', true],
        ['SERVICE_FQDN_NGINX_80', 'nginx', '80', true],

        // 3-underscore pattern: SERVICE_URL_{SERVICE}_{NAME}_{PORT}
        ['SERVICE_URL_MY_API_8080', 'my_api', '8080', true],
        ['SERVICE_URL_WEB_APP_3000', 'web_app', '3000', true],
        ['SERVICE_FQDN_DB_SERVER_5432', 'db_server', '5432', true],

        // 4-underscore pattern: SERVICE_URL_{SERVICE}_{NAME}_{OTHER}_{PORT}
        ['SERVICE_URL_REDIS_CACHE_SERVER_6379', 'redis_cache_server', '6379', true],
        ['SERVICE_URL_MY_LONG_APP_8080', 'my_long_app', '8080', true],
        ['SERVICE_FQDN_POSTGRES_PRIMARY_DB_5432', 'postgres_primary_db', '5432', true],

        // Non-numeric suffix: should NOT be port-specific
        ['SERVICE_URL_MY_APP', 'my_app', null, false],
        ['SERVICE_URL_REDIS_PRIMARY', 'redis_primary', null, false],
        ['SERVICE_FQDN_WEB_SERVER', 'web_server', null, false],
        ['SERVICE_URL_APP_CACHE_REDIS', 'app_cache_redis', null, false],

        // Single word without port
        ['SERVICE_URL_APP', 'app', null, false],
        ['SERVICE_FQDN_DB', 'db', null, false],

        // Edge cases with numbers in service name
        ['SERVICE_URL_REDIS2_MASTER', 'redis2_master', null, false],
        ['SERVICE_URL_WEB3_APP', 'web3_app', null, false],
    ];

    foreach ($testCases as [$varName, $expectedService, $expectedPort, $isPortSpecific]) {
        // Use the actual helper function from bootstrap/helpers/services.php
        $parsed = parseServiceEnvironmentVariable($varName);

        // Assertions
        expect($parsed['service_name'])->toBe($expectedService, "Service name mismatch for $varName");
        expect($parsed['port'])->toBe($expectedPort, "Port mismatch for $varName");
        expect($parsed['has_port'])->toBe($isPortSpecific, "Port detection mismatch for $varName");
    }
});

it('correctly identifies service name with various patterns', function () {
    // Test service name extraction with different patterns

    $testCases = [
        // After parsing, service names should preserve underscores
        ['SERVICE_URL_MY_API_8080', 'my_api'],
        ['SERVICE_URL_REDIS_CACHE_6379', 'redis_cache'],
        ['SERVICE_URL_NEW_API_3000', 'new_api'],
        ['SERVICE_FQDN_DB_SERVER_5432', 'db_server'],

        // Single-word services
        ['SERVICE_URL_UMAMI_3000', 'umami'],
        ['SERVICE_URL_MYAPP_8080', 'myapp'],

        // Without port
        ['SERVICE_URL_MY_APP', 'my_app'],
        ['SERVICE_URL_REDIS_PRIMARY', 'redis_primary'],
    ];

    foreach ($testCases as [$varName, $expectedServiceName]) {
        // Use the actual helper function from bootstrap/helpers/services.php
        $parsed = parseServiceEnvironmentVariable($varName);

        expect($parsed['service_name'])->toBe($expectedServiceName, "Service name extraction for $varName");
    }
});
