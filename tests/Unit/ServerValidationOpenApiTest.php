<?php

it('documents optional installation during server validation', function () {
    $openApi = json_decode(
        file_get_contents(__DIR__.'/../../openapi.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $operation = $openApi['paths']['/servers/{uuid}/validate']['post'];
    $install = $operation['requestBody']['content']['application/json']['schema']['properties']['install'];

    expect($install)
        ->toMatchArray([
            'type' => 'boolean',
            'default' => false,
        ])
        ->and($install['description'])->toContain('restart the Docker daemon');
});
