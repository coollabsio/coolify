<?php

use Illuminate\Support\Facades\Route;

test('registers standalone and service database import routes with abilities', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->keyBy(fn ($route) => $route->getName());

    foreach (['api.databases.imports.upload', 'api.databases.imports.store', 'api.databases.imports.show', 'api.service-databases.imports.upload', 'api.service-databases.imports.store', 'api.service-databases.imports.show'] as $name) {
        expect($routes)->toHaveKey($name);
    }

    expect($routes['api.databases.imports.store']->gatherMiddleware())->toContain('api.ability:deploy')
        ->and($routes['api.databases.imports.show']->gatherMiddleware())->toContain('api.ability:read');
});
