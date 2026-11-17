<?php

use App\Http\Controllers\Api\DatabasesController;
use App\Http\Controllers\Api\ServiceApplicationsController;
use App\Models\EnvironmentVariable;
use App\Models\Service;
use App\Models\ServiceApplication;

test('shown-once database variables stay hidden even with sensitive read access', function () {
    $variable = new EnvironmentVariable;
    $variable->forceFill([
        'key' => 'SECRET',
        'value' => 'secret-value',
        'is_shown_once' => true,
    ]);
    request()->attributes->set('can_read_sensitive', true);

    $method = new ReflectionMethod(DatabasesController::class, 'removeSensitiveEnvData');
    $result = $method->invoke(new DatabasesController, $variable);

    expect($result)->not->toHaveKey('value')
        ->not->toHaveKey('real_value');
});

test('ordinary database variables remain visible with sensitive read access', function () {
    $variable = new EnvironmentVariable;
    $variable->forceFill([
        'key' => 'SECRET',
        'value' => 'secret-value',
        'is_shown_once' => false,
    ]);
    request()->attributes->set('can_read_sensitive', true);

    $method = new ReflectionMethod(DatabasesController::class, 'removeSensitiveEnvData');
    $result = $method->invoke(new DatabasesController, $variable);

    expect($result)->toHaveKey('value');
});

test('service application responses exclude their nested service', function () {
    $application = new ServiceApplication;
    $application->forceFill(['name' => 'app']);
    $application->setRelation('service', (new Service)->forceFill(['name' => 'service']));
    $method = new ReflectionMethod(ServiceApplicationsController::class, 'removeSensitiveData');

    $result = $method->invoke(new ServiceApplicationsController, $application);

    expect($result)->toHaveKey('name')
        ->not->toHaveKey('service');
});
