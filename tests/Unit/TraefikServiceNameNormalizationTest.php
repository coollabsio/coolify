<?php

/**
 * Unit tests to verify that service names with dots are normalized to hyphens
 * in Traefik label generation to prevent label parsing issues.
 *
 * Service names like "api.test" should generate labels with "api-test" instead
 * of "api.test" to avoid breaking Traefik's label structure.
 *
 * Additionally, a 4-character hash is appended to ensure uniqueness and prevent
 * collisions between services like "api.test" and "api-test".
 */
it('normalizes service names with dots to hyphens in traefik labels', function () {
    // Read the fqdnLabelsForTraefik function from docker.php
    $dockerFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/docker.php');

    // Check that service name normalization is present
    expect($dockerFile)
        ->toContain('// Normalize service name for Traefik labels by replacing dots with hyphens')
        ->toContain('$normalized_service_name = str($service_name)->replace(\'.\', \'-\')->value();');
});

it('uses normalized service name in http label construction', function () {
    // Read the fqdnLabelsForTraefik function from docker.php
    $dockerFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/docker.php');

    // Check that normalized service name with hash is used in label construction
    expect($dockerFile)
        ->toContain('$http_label = "http-{$loop}-{$uuid}-{$normalized_service_name}-{$hash}";')
        ->toContain('$https_label = "https-{$loop}-{$uuid}-{$normalized_service_name}-{$hash}";');
});

it('generates valid traefik labels for service names with dots', function () {
    $uuid = 'test-uuid-123';
    $domains = collect(['http://example.com']);
    $serviceName = 'api.test';

    // Call the function with a service name containing a dot
    $labels = fqdnLabelsForTraefik(
        uuid: $uuid,
        domains: $domains,
        service_name: $serviceName
    );

    // Convert collection to array for easier testing
    $labelsArray = $labels->toArray();

    // Check that labels contain normalized service name (api-test) not original (api.test)
    $hasNormalizedLabel = collect($labelsArray)->contains(function ($label) use ($uuid) {
        return str_contains($label, "http-0-{$uuid}-api-test");
    });

    expect($hasNormalizedLabel)->toBeTrue(
        'Expected Traefik labels to contain normalized service name "api-test" instead of "api.test"'
    );

    // Verify no labels contain the original dotted service name in the label identifier
    $hasInvalidLabel = collect($labelsArray)->contains(function ($label) use ($uuid) {
        // Check if label identifier (before the = sign) contains the problematic pattern
        if (str_contains($label, '=')) {
            [$labelName, $labelValue] = explode('=', $label, 2);

            return str_contains($labelName, "{$uuid}-api.test");
        }

        return false;
    });

    expect($hasInvalidLabel)->toBeFalse(
        'Traefik labels should not contain service name with dots in label identifiers'
    );
});

it('generates valid traefik labels for service names without dots', function () {
    $uuid = 'test-uuid-456';
    $domains = collect(['http://example.com']);
    $serviceName = 'api-backend';

    // Call the function with a service name without dots (should work as before)
    $labels = fqdnLabelsForTraefik(
        uuid: $uuid,
        domains: $domains,
        service_name: $serviceName
    );

    // Convert collection to array for easier testing
    $labelsArray = $labels->toArray();

    // Check that labels contain the service name unchanged
    $hasLabel = collect($labelsArray)->contains(function ($label) use ($uuid) {
        return str_contains($label, "http-0-{$uuid}-api-backend");
    });

    expect($hasLabel)->toBeTrue(
        'Expected Traefik labels to contain service name "api-backend" for services without dots'
    );
});

it('handles multiple dots in service names', function () {
    $uuid = 'test-uuid-789';
    $domains = collect(['http://example.com']);
    $serviceName = 'api.v1.test';

    // Call the function with a service name containing multiple dots
    $labels = fqdnLabelsForTraefik(
        uuid: $uuid,
        domains: $domains,
        service_name: $serviceName
    );

    // Convert collection to array for easier testing
    $labelsArray = $labels->toArray();

    // Check that labels contain fully normalized service name (all dots replaced)
    $hasNormalizedLabel = collect($labelsArray)->contains(function ($label) use ($uuid) {
        return str_contains($label, "http-0-{$uuid}-api-v1-test");
    });

    expect($hasNormalizedLabel)->toBeTrue(
        'Expected Traefik labels to normalize all dots in service name "api.v1.test" to "api-v1-test"'
    );
});

it('generates unique hashes for different service names', function () {
    // Test that the serviceNameHash function exists and generates consistent hashes
    $hash1 = serviceNameHash('api.test');
    $hash2 = serviceNameHash('api-test');
    $hash3 = serviceNameHash('api.test'); // Same as hash1

    // Hashes should be exactly 4 characters
    expect(strlen($hash1))->toBe(4);
    expect(strlen($hash2))->toBe(4);

    // Same input should generate same hash (stable)
    expect($hash1)->toBe($hash3);

    // Different inputs should generate different hashes (unique)
    expect($hash1)->not->toBe($hash2);
});

it('includes hash in traefik labels to prevent collisions', function () {
    $uuid = 'test-uuid-collision';
    $domains = collect(['http://example.com']);

    // Test both "api.test" and "api-test" which would otherwise collide
    $serviceName1 = 'api.test';
    $serviceName2 = 'api-test';

    $labels1 = fqdnLabelsForTraefik(
        uuid: $uuid,
        domains: $domains,
        service_name: $serviceName1
    );

    $labels2 = fqdnLabelsForTraefik(
        uuid: $uuid,
        domains: $domains,
        service_name: $serviceName2
    );

    // Get the hashes for both service names
    $hash1 = serviceNameHash($serviceName1);
    $hash2 = serviceNameHash($serviceName2);

    // Check that labels include the hash
    $labels1Array = $labels1->toArray();
    $labels2Array = $labels2->toArray();

    $hasHash1 = collect($labels1Array)->contains(function ($label) use ($uuid, $hash1) {
        return str_contains($label, "http-0-{$uuid}-api-test-{$hash1}");
    });

    $hasHash2 = collect($labels2Array)->contains(function ($label) use ($uuid, $hash2) {
        return str_contains($label, "http-0-{$uuid}-api-test-{$hash2}");
    });

    expect($hasHash1)->toBeTrue(
        'Expected labels for "api.test" to include hash suffix'
    );

    expect($hasHash2)->toBeTrue(
        'Expected labels for "api-test" to include hash suffix'
    );

    // Verify that the labels are different (no collision)
    $router1 = collect($labels1Array)->first(function ($label) {
        return str_contains($label, 'traefik.http.routers.');
    });

    $router2 = collect($labels2Array)->first(function ($label) {
        return str_contains($label, 'traefik.http.routers.');
    });

    expect($router1)->not->toBe($router2,
        'Traefik router labels should be unique for "api.test" and "api-test"'
    );
});

it('stores original service names in docker_compose_domains', function () {
    // Test that the parsers.php file stores original service names
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that we capture the original service name
    expect($parsersFile)
        ->toContain('$actualServiceName = $serviceNameKey; // Store the ORIGINAL service name')
        ->toContain('// Use the ORIGINAL service name as the key to avoid collisions')
        ->toContain('$domains->put($actualServiceName,');
});
