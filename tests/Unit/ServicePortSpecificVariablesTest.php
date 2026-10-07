<?php

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
