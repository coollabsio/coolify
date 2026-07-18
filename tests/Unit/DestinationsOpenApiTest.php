<?php

it('defines OpenAPI documentation for every destinations endpoint', function () {
    $controller = file_get_contents(__DIR__.'/../../app/Http/Controllers/Api/DestinationsController.php');
    $model = file_get_contents(__DIR__.'/../../app/Models/StandaloneDocker.php');

    expect($model)->toContain("schema: 'Destination'")
        ->and($controller)->toContain("operationId: 'list-destinations'")
        ->and($controller)->toContain("operationId: 'get-destination-by-uuid'")
        ->and($controller)->toContain("operationId: 'delete-destination-by-uuid'")
        ->and($controller)->toContain("operationId: 'list-server-destinations'")
        ->and($controller)->toContain("operationId: 'create-server-destination'");
});
