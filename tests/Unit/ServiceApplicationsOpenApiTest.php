<?php

it('documents POST for service application actions', function () {
    $openApi = json_decode(
        file_get_contents(__DIR__.'/../../openapi.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $actionPaths = [
        '/services/{uuid}/applications/{app_uuid}/start',
        '/services/{uuid}/applications/{app_uuid}/restart',
        '/services/{uuid}/applications/{app_uuid}/stop',
    ];

    foreach ($actionPaths as $path) {
        expect($openApi['paths'][$path])
            ->toHaveKey('post')
            ->not->toHaveKey('get')
            ->and($openApi['paths'][$path]['post']['responses'])
            ->toHaveKeys(['200', '400', '401', '404', '501']);
    }

    expect($openApi['paths'][$actionPaths[0]]['post']['responses']['200']['content']['application/json']['schema']['properties'])
        ->toHaveKey('message')
        ->and($openApi['paths'][$actionPaths[1]]['post']['responses']['200']['content']['application/json']['schema']['properties'])
        ->toHaveKey('message')
        ->and($openApi['paths'][$actionPaths[2]]['post']['responses']['200']['content']['application/json']['schema']['properties'])
        ->toHaveKey('message');
});
