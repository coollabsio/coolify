<?php

use App\Actions\Database\StartDatabaseProxy;

it('has the correct default proxy timeout constant', function () {
    expect(StartDatabaseProxy::DEFAULT_PROXY_TIMEOUT)->toBe(31536000);
});

it('resolves proxy timeout of 0 to the default (1 year)', function () {
    $action = new StartDatabaseProxy;
    $method = new ReflectionMethod($action, 'resolveProxyTimeout');

    $database = Mockery::mock();
    $database->public_port_timeout = 0;

    $result = $method->invoke($action, $database);

    expect($result)->toBe(StartDatabaseProxy::DEFAULT_PROXY_TIMEOUT);
});

it('resolves null proxy timeout to the default (1 year)', function () {
    $action = new StartDatabaseProxy;
    $method = new ReflectionMethod($action, 'resolveProxyTimeout');

    $database = Mockery::mock();
    $database->public_port_timeout = null;

    $result = $method->invoke($action, $database);

    expect($result)->toBe(StartDatabaseProxy::DEFAULT_PROXY_TIMEOUT);
});

it('resolves a positive proxy timeout to the given value', function () {
    $action = new StartDatabaseProxy;
    $method = new ReflectionMethod($action, 'resolveProxyTimeout');

    $database = Mockery::mock();
    $database->public_port_timeout = 1800;

    $result = $method->invoke($action, $database);

    expect($result)->toBe(1800);
});

it('resolves a large proxy timeout correctly', function () {
    $action = new StartDatabaseProxy;
    $method = new ReflectionMethod($action, 'resolveProxyTimeout');

    $database = Mockery::mock();
    $database->public_port_timeout = 86400;

    $result = $method->invoke($action, $database);

    expect($result)->toBe(86400);
});
