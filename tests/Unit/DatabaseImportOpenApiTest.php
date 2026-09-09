<?php

test('documents standalone and service database import endpoints', function () {
    $document = json_decode(file_get_contents(__DIR__.'/../../openapi.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($document['paths'])
        ->toHaveKey('/databases/{uuid}/imports/uploads')
        ->toHaveKey('/databases/{uuid}/imports')
        ->toHaveKey('/databases/{uuid}/imports/{activity_id}')
        ->toHaveKey('/services/{uuid}/databases/{database_uuid}/imports/uploads')
        ->toHaveKey('/services/{uuid}/databases/{database_uuid}/imports')
        ->toHaveKey('/services/{uuid}/databases/{database_uuid}/imports/{activity_id}');
});
