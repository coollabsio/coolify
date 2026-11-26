<?php

it('documents the count and deployments envelope of the application deployments endpoint', function () {
    $openApi = json_decode(
        file_get_contents(__DIR__.'/../../openapi.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $schema = $openApi['paths']['/deployments/applications/{uuid}']['get']['responses']['200']['content']['application/json']['schema'];

    expect($schema['type'])->toBe('object')
        ->and($schema['properties']['count']['type'])->toBe('integer')
        ->and($schema['properties']['deployments']['type'])->toBe('array')
        ->and($schema['properties']['deployments']['items']['$ref'])->toBe('#/components/schemas/ApplicationDeploymentQueue');
});
