<?php

use App\Models\Server;
use App\Models\ServerSetting;

function serverWithLogDrainSettings(array $attributes): Server
{
    $server = new Server;
    $server->setRelation('settings', new ServerSetting($attributes));

    return $server;
}

it('uses the fluentd driver when CloudWatch is not the active drain', function () {
    $server = serverWithLogDrainSettings(['is_logdrain_axiom_enabled' => true]);

    expect(generate_log_drain_configuration($server))->toBe(generate_fluentd_configuration());
});

it('uses the awslogs driver with every configured option', function () {
    $server = serverWithLogDrainSettings([
        'is_logdrain_cloudwatch_enabled' => true,
        'logdrain_cloudwatch_region' => 'eu-west-1',
        'logdrain_cloudwatch_group' => '/coolify/prod',
        'logdrain_cloudwatch_stream_prefix' => 'docker/',
    ]);

    expect(generate_log_drain_configuration($server))->toBe([
        'driver' => 'awslogs',
        'options' => [
            'awslogs-region' => 'eu-west-1',
            'awslogs-group' => '/coolify/prod',
            'awslogs-create-group' => 'true',
            'tag' => 'docker/{{.Name}}',
        ],
    ]);
});

it('names streams after the container when no prefix is set', function () {
    $server = serverWithLogDrainSettings([
        'is_logdrain_cloudwatch_enabled' => true,
        'logdrain_cloudwatch_region' => 'us-east-1',
        'logdrain_cloudwatch_group' => 'coolify',
    ]);

    expect(generate_log_drain_configuration($server)['options']['tag'])->toBe('{{.Name}}');
});

it('does not fall back to a default region', function () {
    $server = serverWithLogDrainSettings([
        'is_logdrain_cloudwatch_enabled' => true,
        'logdrain_cloudwatch_group' => 'coolify',
    ]);

    expect(generate_log_drain_configuration($server)['options'])->not->toHaveKey('awslogs-region');
});

it('never sets awslogs-stream because it would override the container-name tag', function () {
    $server = serverWithLogDrainSettings([
        'is_logdrain_cloudwatch_enabled' => true,
        'logdrain_cloudwatch_group' => 'coolify',
        'logdrain_cloudwatch_stream_prefix' => 'apps/',
    ]);

    expect(generate_log_drain_configuration($server)['options'])->not->toHaveKey('awslogs-stream');
});

it('reports CloudWatch as an enabled drain that does not use Fluent Bit', function () {
    $cloudwatch = serverWithLogDrainSettings(['is_logdrain_cloudwatch_enabled' => true]);
    $axiom = serverWithLogDrainSettings(['is_logdrain_axiom_enabled' => true]);

    expect($cloudwatch->isLogDrainEnabled())->toBeTrue()
        ->and($cloudwatch->isFluentBitLogDrainEnabled())->toBeFalse()
        ->and($axiom->isLogDrainEnabled())->toBeTrue()
        ->and($axiom->isFluentBitLogDrainEnabled())->toBeTrue();
});
