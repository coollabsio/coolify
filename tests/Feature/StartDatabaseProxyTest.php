<?php

use App\Actions\Database\StartDatabaseProxy;

test('database proxy is disabled on port already allocated error', function () {
    $action = new StartDatabaseProxy;

    // Use reflection to test the private method directly
    $method = new ReflectionMethod($action, 'isNonTransientError');

    expect($method->invoke($action, 'Bind for 0.0.0.0:5432 failed: port is already allocated'))->toBeTrue();
    expect($method->invoke($action, 'address already in use'))->toBeTrue();
    expect($method->invoke($action, 'some other error'))->toBeFalse();
});

test('isNonTransientError detects port conflict patterns', function () {
    $action = new StartDatabaseProxy;
    $method = new ReflectionMethod($action, 'isNonTransientError');

    expect($method->invoke($action, 'Bind for 0.0.0.0:5432 failed: port is already allocated'))->toBeTrue()
        ->and($method->invoke($action, 'address already in use'))->toBeTrue()
        ->and($method->invoke($action, 'Bind for 0.0.0.0:3306 failed: port is already allocated'))->toBeTrue()
        ->and($method->invoke($action, 'network timeout'))->toBeFalse()
        ->and($method->invoke($action, 'connection refused'))->toBeFalse();
});
