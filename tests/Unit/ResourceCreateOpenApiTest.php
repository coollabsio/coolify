<?php

test('create endpoints do not require both environment_name and environment_uuid', function () {
    $document = json_decode((string) file_get_contents(__DIR__.'/../../openapi.json'), true, flags: JSON_THROW_ON_ERROR);

    $createPaths = collect($document['paths'])
        ->filter(fn (array $operations, string $path) => isset($operations['post']['requestBody']['content']['application/json']['schema']['properties']['environment_name']));

    expect($createPaths)->toHaveCount(15);
    foreach ($createPaths as $path => $operations) {
        $required = $operations['post']['requestBody']['content']['application/json']['schema']['required'] ?? [];

        expect(array_intersect(['environment_name', 'environment_uuid'], $required))->toBe([], $path);
    }
});

test('database and resource list endpoints document typed responses', function () {
    $document = json_decode((string) file_get_contents(__DIR__.'/../../openapi.json'), true, flags: JSON_THROW_ON_ERROR);
    $response = fn (string $path) => $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];

    expect($response('/databases'))->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Database']])
        ->and($response('/databases/{uuid}'))->toBe(['$ref' => '#/components/schemas/Database'])
        ->and($response('/resources')['type'])->toBe('array');
});
