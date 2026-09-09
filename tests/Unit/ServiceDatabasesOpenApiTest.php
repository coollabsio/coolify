<?php

it('documents service database management endpoints', function () {
    $openApi = json_decode(
        file_get_contents(__DIR__.'/../../openapi.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($openApi['paths'])
        ->toHaveKey('/services/{uuid}/databases')
        ->toHaveKey('/services/{uuid}/databases/{database_uuid}')
        ->toHaveKey('/services/{uuid}/databases/{database_uuid}/logs')
        ->toHaveKey('/services/{uuid}/databases/{database_uuid}/start')
        ->toHaveKey('/services/{uuid}/databases/{database_uuid}/restart')
        ->toHaveKey('/services/{uuid}/databases/{database_uuid}/stop')
        ->and($openApi['paths']['/services/{uuid}/databases']['get'])
        ->toBeArray()
        ->and($openApi['paths']['/services/{uuid}/databases/{database_uuid}'])
        ->toHaveKeys(['get', 'patch'])
        ->and($openApi['paths']['/services/{uuid}/databases/{database_uuid}/logs']['get'])
        ->toBeArray()
        ->and($openApi['paths']['/services/{uuid}/databases/{database_uuid}/start']['post'])
        ->toBeArray()
        ->and($openApi['paths']['/services/{uuid}/databases/{database_uuid}/restart']['post'])
        ->toBeArray()
        ->and($openApi['paths']['/services/{uuid}/databases/{database_uuid}/stop']['post'])
        ->toBeArray();
});
