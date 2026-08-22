<?php

use App\Http\Controllers\Api\ServicesController;
use OpenApi\Attributes\Patch;

it('only documents fields supported by the update service endpoint', function () {
    $method = new ReflectionMethod(ServicesController::class, 'update_by_uuid');
    $patch = $method->getAttributes(Patch::class)[0]->newInstance();
    $documentedProperties = $patch->requestBody->content[0]->schema->properties;

    $openApi = json_decode(
        file_get_contents(__DIR__.'/../../openapi.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $generatedProperties = $openApi['paths']['/services/{uuid}']['patch']['requestBody']['content']['application/json']['schema']['properties'];

    $unsupportedFields = [
        'project_uuid',
        'environment_name',
        'environment_uuid',
        'server_uuid',
        'destination_uuid',
    ];

    expect(array_keys($generatedProperties))->toBe(array_keys($documentedProperties))
        ->and(array_intersect($unsupportedFields, array_keys($documentedProperties)))->toBeEmpty();
});
