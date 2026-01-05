<?php

/**
 * Unit tests to verify that service parser correctly handles image updates
 * without creating duplicate ServiceApplication or ServiceDatabase records.
 *
 * These tests verify the fix for the issue where changing an image in a
 * docker-compose file would create a new service instead of updating the existing one.
 */
it('ensures service parser does not include image in firstOrCreate query', function () {
    // Read the serviceParser function from parsers.php
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that firstOrCreate is called with only name and service_id
    // and NOT with image parameter in the ServiceApplication presave loop
    expect($parsersFile)
        ->toContain("firstOrCreate([\n                'name' => \$serviceName,\n                'service_id' => \$resource->id,\n            ]);")
        ->not->toContain("firstOrCreate([\n                'name' => \$serviceName,\n                'image' => \$image,\n                'service_id' => \$resource->id,\n            ]);");
});

it('ensures service parser updates image after finding or creating service', function () {
    // Read the serviceParser function from parsers.php
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that image update logic exists after firstOrCreate
    expect($parsersFile)
        ->toContain('// Update image if it changed')
        ->toContain('if ($savedService->image !== $image) {')
        ->toContain('$savedService->image = $image;')
        ->toContain('$savedService->save();');
});

it('ensures parseDockerComposeFile does not create duplicates on null savedService', function () {
    // Read the parseDockerComposeFile function from shared.php
    $sharedFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/shared.php');

    // Check that the duplicate creation logic after is_null check has been fixed
    // The old code would create a duplicate if savedService was null
    // The new code checks for null within the else block and creates only if needed
    expect($sharedFile)
        ->toContain('if (is_null($savedService)) {')
        ->toContain('$savedService = ServiceDatabase::create([');
});

it('verifies image update logic is present in parseDockerComposeFile', function () {
    // Read the parseDockerComposeFile function from shared.php
    $sharedFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/shared.php');

    // Verify the image update logic exists
    expect($sharedFile)
        ->toContain('// Check if image changed')
        ->toContain('if ($savedService->image !== $image) {')
        ->toContain('$savedService->image = $image;')
        ->toContain('$savedService->save();');
});
