<?php

use Illuminate\Support\Facades\Route;

test('registers standalone and service database import routes with abilities', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName());

    $expected = [
        'api.databases.imports.upload' => 'api.ability:deploy',
        'api.databases.imports.store' => 'api.ability:deploy',
        'api.databases.imports.show' => 'api.ability:read',
        'api.service-databases.imports.upload' => 'api.ability:deploy',
        'api.service-databases.imports.store' => 'api.ability:deploy',
        'api.service-databases.imports.show' => 'api.ability:read',
    ];

    foreach ($expected as $name => $ability) {
        expect($routes)->toHaveKey($name);
        expect($routes[$name]->gatherMiddleware())->toContain($ability);
    }
});
