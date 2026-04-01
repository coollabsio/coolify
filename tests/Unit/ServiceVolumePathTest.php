<?php

/**
 * Unit test to verify that LocalFileVolume::reResolveForService() uses the
 * correct /services/ storage root (not /applications/).
 *
 * Bug: The service re-resolve method was using base_configuration_dir().'/applications/'
 * instead of base_configuration_dir().'/services/', causing volume paths to be
 * resolved against the wrong directory.
 *
 * Related Issue: #8854
 * Related Files:
 *  - app/Models/LocalFileVolume.php
 *  - bootstrap/helpers/parsers.php (serviceParser uses /services/)
 */
it('uses /services/ storage root for service volume path resolution, not /applications/', function () {
    $source = file_get_contents(__DIR__.'/../../app/Models/LocalFileVolume.php');

    // Find the reResolveForService method body
    $methodStart = strpos($source, 'private static function reResolveForService');
    expect($methodStart)->not->toBeFalse('reResolveForService method should exist in LocalFileVolume');

    // Find the next method boundary to scope our search
    $nextMethod = strpos($source, 'private static function ', $methodStart + 1);
    $methodBody = substr($source, $methodStart, $nextMethod ? $nextMethod - $methodStart : null);

    // The mainDirectory for services must use /services/, not /applications/
    expect($methodBody)->toContain("base_configuration_dir().'/services/'")
        ->and($methodBody)->not->toContain("base_configuration_dir().'/applications/'");
});

it('service and application re-resolve methods use distinct storage roots', function () {
    // Verify that the service_configuration_dir helper returns /services/
    expect(service_configuration_dir())->toBe(base_configuration_dir().'/services');

    // Verify that the application_configuration_dir helper returns /applications/
    expect(application_configuration_dir())->toBe(base_configuration_dir().'/applications');

    // These must be different
    expect(service_configuration_dir())->not->toBe(application_configuration_dir());
});
