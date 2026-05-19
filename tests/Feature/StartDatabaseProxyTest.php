<?php

use App\Actions\Database\StartDatabaseProxy;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

test('database proxy is disabled on port already allocated error', function () {
    $team = Team::factory()->create();

    $database = StandalonePostgresql::factory()->create([
        'team_id' => $team->id,
        'is_public' => true,
        'public_port' => 5432,
    ]);

    expect($database->is_public)->toBeTrue();

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

test('buildProxyTimeoutConfig normalizes invalid values to default', function (?int $input, string $expected) {
    $action = new StartDatabaseProxy;
    $method = new ReflectionMethod($action, 'buildProxyTimeoutConfig');

    expect($method->invoke($action, $input))->toBe($expected);
})->with([
    [null, 'proxy_timeout 3600s;'],
    [0, 'proxy_timeout 3600s;'],
    [-10, 'proxy_timeout 3600s;'],
    [120, 'proxy_timeout 120s;'],
]);
