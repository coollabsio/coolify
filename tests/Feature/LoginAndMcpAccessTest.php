<?php

use App\Http\Middleware\ApiAllowed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

it('limits login attempts by normalized email independent of IP', function () {
    $limiter = RateLimiter::limiter('login');
    $first = Request::create('/login', 'POST', ['email' => 'First.Name+tag@gmail.com'], [], [], ['REMOTE_ADDR' => '192.0.2.10']);
    $second = Request::create('/login', 'POST', ['email' => 'firstname@gmail.com'], [], [], ['REMOTE_ADDR' => '198.51.100.10']);

    $firstLimits = $limiter($first);
    $secondLimits = $limiter($second);

    expect($firstLimits)->toHaveCount(2)
        ->and($firstLimits[0]->key)->not->toBe($secondLimits[0]->key)
        ->and($firstLimits[1]->key)->toBe($secondLimits[1]->key);
});

it('keeps MCP independent from the REST API access check', function () {
    $mcp = Route::getRoutes()->match(Request::create('/mcp', 'POST'));
    $enable = Route::getRoutes()->match(Request::create('/api/v1/mcp/enable', 'POST'));
    $disable = Route::getRoutes()->match(Request::create('/api/v1/mcp/disable', 'POST'));

    expect($mcp->gatherMiddleware())->not->toContain(ApiAllowed::class)
        ->and($enable->gatherMiddleware())->not->toContain(ApiAllowed::class)
        ->and($disable->gatherMiddleware())->not->toContain(ApiAllowed::class)
        ->and($enable->gatherMiddleware())->toContain('auth:sanctum', 'api.token.team', 'api.ability:write')
        ->and($disable->gatherMiddleware())->toContain('auth:sanctum', 'api.token.team', 'api.ability:write');
});
