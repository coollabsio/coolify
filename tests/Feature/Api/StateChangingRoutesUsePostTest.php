<?php

use App\Http\Controllers\Api\OtherController;
use Illuminate\Routing\Route;

it('uses POST for state changes and keeps GET as a non-mutating compatibility response', function (string $uri) {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => $route->uri() === $uri);

    $postRoute = $routes->first(fn (Route $route): bool => $route->methods() === ['POST']);
    $getRoute = $routes->first(fn (Route $route): bool => $route->methods() === ['GET', 'HEAD']);

    expect($postRoute)->not->toBeNull()
        ->and($getRoute)->not->toBeNull()
        ->and($getRoute->getActionName())->toBe(OtherController::class.'@post_required');
})->with([
    'enable API' => 'api/v1/enable',
    'disable API' => 'api/v1/disable',
    'deploy' => 'api/v1/deploy',
    'validate server' => 'api/v1/servers/{uuid}/validate',
    'start application' => 'api/v1/applications/{uuid}/start',
    'restart application' => 'api/v1/applications/{uuid}/restart',
    'stop application' => 'api/v1/applications/{uuid}/stop',
    'start database' => 'api/v1/databases/{uuid}/start',
    'restart database' => 'api/v1/databases/{uuid}/restart',
    'stop database' => 'api/v1/databases/{uuid}/stop',
    'start service' => 'api/v1/services/{uuid}/start',
    'restart service' => 'api/v1/services/{uuid}/restart',
    'stop service' => 'api/v1/services/{uuid}/stop',
    'start service application' => 'api/v1/services/{uuid}/applications/{app_uuid}/start',
    'restart service application' => 'api/v1/services/{uuid}/applications/{app_uuid}/restart',
    'stop service application' => 'api/v1/services/{uuid}/applications/{app_uuid}/stop',
]);

it('tells GET callers to use POST without changing state', function () {
    $response = app(OtherController::class)->post_required();

    expect($response->getStatusCode())->toBe(405)
        ->and($response->headers->get('Allow'))->toBe('POST')
        ->and($response->getData(true))->toBe([
            'message' => 'This endpoint has changed to a POST request.',
        ]);
});
