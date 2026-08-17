<?php

use App\Http\Controllers\Api\ApplicationsController;

function currentRollbackImageTag(string $imageReference): ?string
{
    $method = new ReflectionMethod(ApplicationsController::class, 'currentRollbackImageTag');
    $method->setAccessible(true);

    return $method->invoke(null, $imageReference);
}

it('extracts the current rollback image tag when the registry includes a port', function () {
    expect(currentRollbackImageTag('registry.example.com:5000/team/application:commit-sha'))
        ->toBe('commit-sha');
});

it('does not treat a digest as the current rollback image tag', function () {
    expect(currentRollbackImageTag('registry.example.com:5000/team/application@sha256:'.str_repeat('a', 64)))
        ->toBeNull();
});
