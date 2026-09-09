<?php

test('documents standalone and service database import endpoints', function () {
    $document = json_decode((string) file_get_contents(__DIR__.'/../../openapi.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($document['paths'])
        ->toHaveKey('/databases/{uuid}/imports/uploads')
        ->toHaveKey('/databases/{uuid}/imports')
        ->toHaveKey('/databases/{uuid}/imports/{activity_id}')
        ->toHaveKey('/services/{uuid}/databases/{database_uuid}/imports/uploads')
        ->toHaveKey('/services/{uuid}/databases/{database_uuid}/imports')
        ->toHaveKey('/services/{uuid}/databases/{database_uuid}/imports/{activity_id}');

    foreach ($document['components']['schemas']['DatabaseImportRequest']['oneOf'] as $source) {
        expect($source['properties']['replace_existing'])
            ->toMatchArray(['type' => 'boolean', 'default' => false]);
    }

    $statusRef = [
        'description' => 'Import status',
        'content' => [
            'application/json' => [
                'schema' => [
                    '$ref' => '#/components/schemas/DatabaseImportStatus',
                ],
            ],
        ],
    ];

    expect($document['paths']['/databases/{uuid}/imports/{activity_id}']['get']['responses']['200'])
        ->toMatchArray($statusRef)
        ->and($document['paths']['/services/{uuid}/databases/{database_uuid}/imports/{activity_id}']['get']['responses']['200'])
        ->toMatchArray($statusRef)
        ->and($document['components']['schemas'])
        ->toHaveKey('DatabaseImportStatus');
});

test('documents path parameters for database import endpoints', function () {
    $document = json_decode((string) file_get_contents(__DIR__.'/../../openapi.json'), true, flags: JSON_THROW_ON_ERROR);

    $operations = [
        ['/databases/{uuid}/imports/uploads', 'post', ['uuid']],
        ['/databases/{uuid}/imports', 'post', ['uuid']],
        ['/databases/{uuid}/imports/{activity_id}', 'get', ['uuid', 'activity_id']],
        ['/services/{uuid}/databases/{database_uuid}/imports/uploads', 'post', ['uuid', 'database_uuid']],
        ['/services/{uuid}/databases/{database_uuid}/imports', 'post', ['uuid', 'database_uuid']],
        ['/services/{uuid}/databases/{database_uuid}/imports/{activity_id}', 'get', ['uuid', 'database_uuid', 'activity_id']],
    ];

    foreach ($operations as [$path, $method, $expectedNames]) {
        $parameters = $document['paths'][$path][$method]['parameters'] ?? [];
        $pathParameters = collect($parameters)
            ->filter(fn (array $parameter): bool => ($parameter['in'] ?? null) === 'path')
            ->map(fn (array $parameter): string => $parameter['name'])
            ->values()
            ->all();

        expect($pathParameters)->toEqual($expectedNames);
    }
});

test('constrains additional properties on each database import source branch', function () {
    $document = json_decode((string) file_get_contents(__DIR__.'/../../openapi.json'), true, flags: JSON_THROW_ON_ERROR);
    $schema = $document['components']['schemas']['DatabaseImportRequest'];

    expect($schema)->not->toHaveKey('additionalProperties');

    $expectedProperties = [
        ['source', 'upload_id', 'dump_all', 'replace_existing'],
        ['source', 's3_storage_uuid', 'path', 'dump_all', 'replace_existing'],
        ['source', 'path', 'dump_all', 'replace_existing'],
    ];

    expect($schema['oneOf'])->toHaveCount(count($expectedProperties));

    foreach ($schema['oneOf'] as $index => $source) {
        expect($source['additionalProperties'])->toBeFalse()
            ->and($source['properties'])->toHaveKeys($expectedProperties[$index])
            ->and($source['properties']['replace_existing'])
            ->toMatchArray(['type' => 'boolean', 'default' => false]);
    }
});
