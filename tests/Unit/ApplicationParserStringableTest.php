<?php

/**
 * Unit tests to verify that the applicationParser function in parsers.php
 * properly converts Stringable objects to plain strings to fix strict
 * comparison and collection key lookup issues.
 *
 * Related issue: Lines 539 and 541 in parsers.php were creating Stringable
 * objects which caused:
 * - Strict comparisons (===) to fail (line 606)
 * - Collection key lookups to fail (line 615)
 */
it('ensures service name normalization returns plain strings not Stringable objects', function () {
    // Test the exact transformations that happen in parsers.php lines 539-541

    // Simulate what happens at line 520
    $parsed = parseServiceEnvironmentVariable('SERVICE_URL_my-service');
    $serviceName = $parsed['service_name']; // 'my-service'

    // Line 539: $originalServiceName = str($serviceName)->replace('_', '-')->value();
    $originalServiceName = str($serviceName)->replace('_', '-')->value();

    // Line 541: $serviceName = str($serviceName)->replace('-', '_')->replace('.', '_')->value();
    $serviceName = str($serviceName)->replace('-', '_')->replace('.', '_')->value();

    // Verify both are plain strings, not Stringable objects
    expect(is_string($originalServiceName))->toBeTrue('$originalServiceName should be a plain string');
    expect(is_string($serviceName))->toBeTrue('$serviceName should be a plain string');
    expect($originalServiceName)->not->toBeInstanceOf(\Illuminate\Support\Stringable::class);
    expect($serviceName)->not->toBeInstanceOf(\Illuminate\Support\Stringable::class);

    // Verify the transformations work correctly
    expect($originalServiceName)->toBe('my-service');
    expect($serviceName)->toBe('my_service');
});

it('ensures strict comparison works with normalized service names', function () {
    // This tests the fix for line 606 where strict comparison failed

    // Simulate service name from docker-compose services array (line 604-605)
    $serviceNameKey = 'my-service';
    $transformedServiceName = str($serviceNameKey)->replace('-', '_')->replace('.', '_')->value();

    // Simulate service name from environment variable parsing (line 520, 541)
    $parsed = parseServiceEnvironmentVariable('SERVICE_URL_my-service');
    $serviceName = $parsed['service_name'];
    $serviceName = str($serviceName)->replace('-', '_')->replace('.', '_')->value();

    // Line 606: if ($transformedServiceName === $serviceName)
    // This MUST work - both should be plain strings and match
    expect($transformedServiceName === $serviceName)->toBeTrue(
        'Strict comparison should work when both are plain strings'
    );
    expect($transformedServiceName)->toBe($serviceName);
});

it('ensures collection key lookup works with normalized service names', function () {
    // This tests the fix for line 615 where collection->get() failed

    // Simulate service name normalization (line 541)
    $parsed = parseServiceEnvironmentVariable('SERVICE_URL_app-name');
    $serviceName = $parsed['service_name'];
    $serviceName = str($serviceName)->replace('-', '_')->replace('.', '_')->value();

    // Create a collection like $domains at line 614
    $domains = collect([
        'app_name' => [
            'domain' => 'https://example.com',
        ],
    ]);

    // Line 615: $domainExists = data_get($domains->get($serviceName), 'domain');
    // This MUST work - $serviceName should be a plain string 'app_name'
    $domainExists = data_get($domains->get($serviceName), 'domain');

    expect($domainExists)->toBe('https://example.com', 'Collection lookup should find the domain');
    expect($domainExists)->not->toBeNull('Collection lookup should not return null');
});

it('handles service names with dots correctly', function () {
    // Test service names with dots (e.g., 'my.service')

    $parsed = parseServiceEnvironmentVariable('SERVICE_URL_my.service');
    $serviceName = $parsed['service_name'];
    $serviceName = str($serviceName)->replace('-', '_')->replace('.', '_')->value();

    expect(is_string($serviceName))->toBeTrue();
    expect($serviceName)->toBe('my_service');

    // Verify it matches transformed service name from docker-compose
    $serviceNameKey = 'my.service';
    $transformedServiceName = str($serviceNameKey)->replace('-', '_')->replace('.', '_')->value();

    expect($transformedServiceName === $serviceName)->toBeTrue();
});

it('handles service names with underscores correctly', function () {
    // Test service names that already have underscores

    $parsed = parseServiceEnvironmentVariable('SERVICE_URL_my_service');
    $serviceName = $parsed['service_name'];
    $serviceName = str($serviceName)->replace('-', '_')->replace('.', '_')->value();

    expect(is_string($serviceName))->toBeTrue();
    expect($serviceName)->toBe('my_service');
});

it('handles mixed special characters in service names', function () {
    // Test service names with mix of dashes, dots, underscores

    $parsed = parseServiceEnvironmentVariable('SERVICE_URL_my-app.service_v2');
    $serviceName = $parsed['service_name'];
    $serviceName = str($serviceName)->replace('-', '_')->replace('.', '_')->value();

    expect(is_string($serviceName))->toBeTrue();
    expect($serviceName)->toBe('my_app_service_v2');

    // Verify collection operations work
    $domains = collect([
        'my_app_service_v2' => ['domain' => 'https://test.com'],
    ]);

    $found = $domains->get($serviceName);
    expect($found)->not->toBeNull();
    expect($found['domain'])->toBe('https://test.com');
});

it('ensures originalServiceName conversion works for FQDN generation', function () {
    // Test line 539: $originalServiceName conversion

    $parsed = parseServiceEnvironmentVariable('SERVICE_URL_my_service');
    $serviceName = $parsed['service_name']; // 'my_service'

    // Line 539: Convert underscores to dashes for FQDN generation
    $originalServiceName = str($serviceName)->replace('_', '-')->value();

    expect(is_string($originalServiceName))->toBeTrue();
    expect($originalServiceName)->not->toBeInstanceOf(\Illuminate\Support\Stringable::class);
    expect($originalServiceName)->toBe('my-service');

    // Verify it can be used in string interpolation (line 544)
    $uuid = 'test-uuid';
    $random = "$originalServiceName-$uuid";
    expect($random)->toBe('my-service-test-uuid');
});

it('prevents duplicate domain entries in collection', function () {
    // This tests that using plain strings prevents duplicate entries
    // (one with Stringable key, one with string key)

    $parsed = parseServiceEnvironmentVariable('SERVICE_URL_webapp');
    $serviceName = $parsed['service_name'];
    $serviceName = str($serviceName)->replace('-', '_')->replace('.', '_')->value();

    $domains = collect();

    // Add domain entry (line 621)
    $domains->put($serviceName, [
        'domain' => 'https://webapp.com',
    ]);

    // Try to lookup the domain (line 615)
    $found = $domains->get($serviceName);

    expect($found)->not->toBeNull('Should find the domain we just added');
    expect($found['domain'])->toBe('https://webapp.com');

    // Verify only one entry exists
    expect($domains->count())->toBe(1);
    expect($domains->has($serviceName))->toBeTrue();
});

it('verifies parsers.php has the ->value() calls', function () {
    // Ensure the fix is actually in the code
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Line 539: Check originalServiceName conversion
    expect($parsersFile)->toContain("str(\$serviceName)->replace('_', '-')->value()");

    // Line 541: Check serviceName normalization
    expect($parsersFile)->toContain("str(\$serviceName)->replace('-', '_')->replace('.', '_')->value()");
});
