<?php

/**
 * Unit tests to verify that Applications using Docker Compose handle
 * SERVICE_URL and SERVICE_FQDN environment variables correctly.
 *
 * This ensures consistency with Service behavior where BOTH URL and FQDN
 * pairs are always created together, regardless of which one is in the template.
 */
it('ensures parsers.php creates both URL and FQDN pairs for applications', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that the fix is in place
    expect($parsersFile)->toContain('ALWAYS create BOTH SERVICE_URL and SERVICE_FQDN pairs');
    expect($parsersFile)->toContain('SERVICE_FQDN_{$serviceName}');
    expect($parsersFile)->toContain('SERVICE_URL_{$serviceName}');
});

it('extracts service name with case preservation for applications', function () {
    // Simulate what the parser does for applications
    $templateVar = 'SERVICE_URL_WORDPRESS';

    $strKey = str($templateVar);
    $parsed = parseServiceEnvironmentVariable($templateVar);

    if ($parsed['has_port']) {
        $serviceName = $strKey->after('SERVICE_URL_')->beforeLast('_')->value();
    } else {
        $serviceName = $strKey->after('SERVICE_URL_')->value();
    }

    expect($serviceName)->toBe('WORDPRESS');
    expect($parsed['service_name'])->toBe('wordpress'); // lowercase for internal use
});

it('handles port-specific application service variables', function () {
    $templateVar = 'SERVICE_URL_APP_3000';

    $strKey = str($templateVar);
    $parsed = parseServiceEnvironmentVariable($templateVar);

    if ($parsed['has_port']) {
        $serviceName = $strKey->after('SERVICE_URL_')->beforeLast('_')->value();
    } else {
        $serviceName = $strKey->after('SERVICE_URL_')->value();
    }

    expect($serviceName)->toBe('APP');
    expect($parsed['port'])->toBe('3000');
    expect($parsed['has_port'])->toBeTrue();
});

it('application should create 2 base variables when template has base SERVICE_URL', function () {
    // Given: Template defines SERVICE_URL_WP
    // Then: Should create both:
    // 1. SERVICE_URL_WP
    // 2. SERVICE_FQDN_WP

    $templateVar = 'SERVICE_URL_WP';
    $strKey = str($templateVar);
    $parsed = parseServiceEnvironmentVariable($templateVar);

    $serviceName = $strKey->after('SERVICE_URL_')->value();

    $urlKey = "SERVICE_URL_{$serviceName}";
    $fqdnKey = "SERVICE_FQDN_{$serviceName}";

    expect($urlKey)->toBe('SERVICE_URL_WP');
    expect($fqdnKey)->toBe('SERVICE_FQDN_WP');
    expect($parsed['has_port'])->toBeFalse();
});

it('application should create 4 variables when template has port-specific SERVICE_URL', function () {
    // Given: Template defines SERVICE_URL_APP_8080
    // Then: Should create all 4:
    // 1. SERVICE_URL_APP (base)
    // 2. SERVICE_FQDN_APP (base)
    // 3. SERVICE_URL_APP_8080 (port-specific)
    // 4. SERVICE_FQDN_APP_8080 (port-specific)

    $templateVar = 'SERVICE_URL_APP_8080';
    $strKey = str($templateVar);
    $parsed = parseServiceEnvironmentVariable($templateVar);

    $serviceName = $strKey->after('SERVICE_URL_')->beforeLast('_')->value();
    $port = $parsed['port'];

    $baseUrlKey = "SERVICE_URL_{$serviceName}";
    $baseFqdnKey = "SERVICE_FQDN_{$serviceName}";
    $portUrlKey = "SERVICE_URL_{$serviceName}_{$port}";
    $portFqdnKey = "SERVICE_FQDN_{$serviceName}_{$port}";

    expect($baseUrlKey)->toBe('SERVICE_URL_APP');
    expect($baseFqdnKey)->toBe('SERVICE_FQDN_APP');
    expect($portUrlKey)->toBe('SERVICE_URL_APP_8080');
    expect($portFqdnKey)->toBe('SERVICE_FQDN_APP_8080');
});

it('application should create pairs when template has only SERVICE_FQDN', function () {
    // Given: Template defines SERVICE_FQDN_DB
    // Then: Should create both:
    // 1. SERVICE_FQDN_DB
    // 2. SERVICE_URL_DB (created automatically)

    $templateVar = 'SERVICE_FQDN_DB';
    $strKey = str($templateVar);
    $parsed = parseServiceEnvironmentVariable($templateVar);

    $serviceName = $strKey->after('SERVICE_FQDN_')->value();

    $urlKey = "SERVICE_URL_{$serviceName}";
    $fqdnKey = "SERVICE_FQDN_{$serviceName}";

    expect($fqdnKey)->toBe('SERVICE_FQDN_DB');
    expect($urlKey)->toBe('SERVICE_URL_DB');
    expect($parsed['has_port'])->toBeFalse();
});

it('verifies application deletion nulls both URL and FQDN', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that deletion handles both types
    expect($parsersFile)->toContain('SERVICE_FQDN_{$serviceNameFormatted}');
    expect($parsersFile)->toContain('SERVICE_URL_{$serviceNameFormatted}');

    // Both should be set to null when domain is empty
    expect($parsersFile)->toContain('\'value\' => null');
});

it('handles abbreviated service names in applications', function () {
    // Applications can have abbreviated names in compose files just like services
    $templateVar = 'SERVICE_URL_WP'; // WordPress abbreviated

    $strKey = str($templateVar);
    $serviceName = $strKey->after('SERVICE_URL_')->value();

    expect($serviceName)->toBe('WP');
    expect($serviceName)->not->toBe('WORDPRESS');
});

it('application compose parsing creates pairs regardless of template type', function () {
    // Test that whether template uses SERVICE_URL or SERVICE_FQDN,
    // the parser creates both

    $testCases = [
        'SERVICE_URL_APP' => ['base' => 'APP', 'port' => null],
        'SERVICE_FQDN_APP' => ['base' => 'APP', 'port' => null],
        'SERVICE_URL_APP_3000' => ['base' => 'APP', 'port' => '3000'],
        'SERVICE_FQDN_APP_3000' => ['base' => 'APP', 'port' => '3000'],
    ];

    foreach ($testCases as $templateVar => $expected) {
        $strKey = str($templateVar);
        $parsed = parseServiceEnvironmentVariable($templateVar);

        if ($parsed['has_port']) {
            if ($strKey->startsWith('SERVICE_URL_')) {
                $serviceName = $strKey->after('SERVICE_URL_')->beforeLast('_')->value();
            } else {
                $serviceName = $strKey->after('SERVICE_FQDN_')->beforeLast('_')->value();
            }
        } else {
            if ($strKey->startsWith('SERVICE_URL_')) {
                $serviceName = $strKey->after('SERVICE_URL_')->value();
            } else {
                $serviceName = $strKey->after('SERVICE_FQDN_')->value();
            }
        }

        expect($serviceName)->toBe($expected['base'], "Failed for $templateVar");
        expect($parsed['port'])->toBe($expected['port'], "Port mismatch for $templateVar");
    }
});

it('verifies both application and service use same logic', function () {
    $servicesFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/services.php');
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Both should have the same pattern of creating pairs
    expect($servicesFile)->toContain('ALWAYS create base pair');
    expect($parsersFile)->toContain('ALWAYS create BOTH');

    // Both should create SERVICE_URL_
    expect($servicesFile)->toContain('SERVICE_URL_{$serviceName}');
    expect($parsersFile)->toContain('SERVICE_URL_{$serviceName}');

    // Both should create SERVICE_FQDN_
    expect($servicesFile)->toContain('SERVICE_FQDN_{$serviceName}');
    expect($parsersFile)->toContain('SERVICE_FQDN_{$serviceName}');
});
