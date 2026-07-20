<?php

use App\Actions\Server\ValidateServer;
use App\Models\Server;

it('tracks validation until the server checks finish', function () {
    $validationStates = [];
    $server = Mockery::mock(Server::class)->makePartial();
    $server->forceFill([
        'vultr_instance_id' => null,
        'digitalocean_droplet_id' => null,
    ]);
    $server->shouldReceive('update')->andReturnUsing(function (array $attributes) use (&$validationStates): bool {
        if (array_key_exists('is_validating', $attributes)) {
            $validationStates[] = $attributes['is_validating'];
        }

        return true;
    });
    $server->shouldReceive('validateConnection')->once()->andReturn(['uptime' => '1 day', 'error' => null]);
    $server->shouldReceive('validateOS')->once()->andReturn(str('ubuntu'));
    $server->shouldReceive('validatePrerequisites')->once()->andReturn([
        'success' => true,
        'missing' => [],
        'found' => ['curl', 'tar'],
    ]);
    $server->shouldReceive('validateDockerEngine')->once()->andReturnTrue();
    $server->shouldReceive('validateDockerCompose')->once()->andReturnTrue();
    $server->shouldReceive('validateDockerEngineVersion')->once()->andReturnTrue();

    expect((new ValidateServer)->handle($server))->toBe('OK')
        ->and($validationStates)->toBe([true, false]);
});

it('clears the validation state when a server check fails', function () {
    $validationStates = [];
    $server = Mockery::mock(Server::class)->makePartial();
    $server->forceFill([
        'vultr_instance_id' => null,
        'digitalocean_droplet_id' => null,
    ]);
    $server->shouldReceive('update')->andReturnUsing(function (array $attributes) use (&$validationStates): bool {
        if (array_key_exists('is_validating', $attributes)) {
            $validationStates[] = $attributes['is_validating'];
        }

        return true;
    });
    $server->shouldReceive('validateConnection')->once()->andReturn(['uptime' => false, 'error' => 'Connection refused']);

    expect(fn () => (new ValidateServer)->handle($server))
        ->toThrow(Exception::class, 'Connection refused')
        ->and($validationStates)->toBe([true, false]);
});
