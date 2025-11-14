<?php

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Mockery;

it('filters servers by proxy type using whereProxyType scope', function () {
    // Mock the Builder
    $mockBuilder = Mockery::mock(Builder::class);

    // Expect the where method to be called with the correct parameters
    $mockBuilder->shouldReceive('where')
        ->once()
        ->with('proxy->type', ProxyTypes::TRAEFIK->value)
        ->andReturnSelf();

    // Create a server instance and call the scope
    $server = new Server;
    $result = $server->scopeWhereProxyType($mockBuilder, ProxyTypes::TRAEFIK->value);

    // Assert the builder is returned
    expect($result)->toBe($mockBuilder);
});

it('can chain whereProxyType scope with other query methods', function () {
    // Mock the Builder
    $mockBuilder = Mockery::mock(Builder::class);

    // Expect multiple chained calls
    $mockBuilder->shouldReceive('where')
        ->once()
        ->with('proxy->type', ProxyTypes::CADDY->value)
        ->andReturnSelf();

    // Create a server instance and call the scope
    $server = new Server;
    $result = $server->scopeWhereProxyType($mockBuilder, ProxyTypes::CADDY->value);

    // Assert the builder is returned for chaining
    expect($result)->toBe($mockBuilder);
});

it('accepts any proxy type string value', function () {
    // Mock the Builder
    $mockBuilder = Mockery::mock(Builder::class);

    // Test with a custom proxy type
    $customProxyType = 'custom-proxy';

    $mockBuilder->shouldReceive('where')
        ->once()
        ->with('proxy->type', $customProxyType)
        ->andReturnSelf();

    // Create a server instance and call the scope
    $server = new Server;
    $result = $server->scopeWhereProxyType($mockBuilder, $customProxyType);

    // Assert the builder is returned
    expect($result)->toBe($mockBuilder);
});
