<?php

use App\Http\Controllers\Api\ServicesController;
use Illuminate\Support\Facades\Route;

test('deprecated docker compose application endpoint is not registered', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('POST', $route->methods(), true))
        ->filter(fn ($route) => $route->uri() === 'api/v1/applications/dockercompose');

    expect($routes)->toBeEmpty();

    $this->postJson('/api/v1/applications/dockercompose')->assertNotFound();
});

test('custom docker compose services endpoint remains registered', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => in_array('POST', $route->methods(), true) && $route->uri() === 'api/v1/services');

    expect($route)->not->toBeNull()
        ->and($route->getActionName())->toBe(ServicesController::class.'@create_service');
});
