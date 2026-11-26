<?php

use App\Enums\LogDrainType;
use App\Models\Server;
use App\Models\ServerSetting;
use Tests\TestCase;

uses(TestCase::class);

function serverWithLogDrain(LogDrainType $type, array $config = []): Server
{
    $server = new Server;
    $server->setRelation('settings', new ServerSetting([
        'logdrain_type' => $type,
        'logdrain_config' => [$type->value => $config],
    ]));

    return $server;
}

it('uses the fluentd driver when CloudWatch is not the active drain', function () {
    $server = serverWithLogDrain(LogDrainType::Axiom);

    expect(generate_log_drain_configuration($server))->toBe(generate_fluentd_configuration());
});

it('uses the awslogs driver with every configured option', function () {
    $server = serverWithLogDrain(LogDrainType::Cloudwatch, [
        'region' => 'eu-west-1',
        'group' => '/coolify/prod',
        'stream_prefix' => 'docker/',
    ]);

    expect(generate_log_drain_configuration($server))->toBe([
        'driver' => 'awslogs',
        'options' => [
            'awslogs-region' => 'eu-west-1',
            'awslogs-group' => '/coolify/prod',
            'awslogs-create-group' => 'true',
            'mode' => 'non-blocking',
            'tag' => 'docker/{{.Name}}',
        ],
    ]);
});

it('names streams after the container when no prefix is set', function () {
    $server = serverWithLogDrain(LogDrainType::Cloudwatch, [
        'region' => 'us-east-1',
        'group' => 'coolify',
    ]);

    expect(generate_log_drain_configuration($server)['options']['tag'])->toBe('{{.Name}}');
});

it('does not fall back to a default region', function () {
    $server = serverWithLogDrain(LogDrainType::Cloudwatch, [
        'group' => 'coolify',
    ]);

    expect(generate_log_drain_configuration($server)['options'])->not->toHaveKey('awslogs-region');
});

it('never sets awslogs-stream because it would override the container-name tag', function () {
    $server = serverWithLogDrain(LogDrainType::Cloudwatch, [
        'group' => 'coolify',
        'stream_prefix' => 'apps/',
    ]);

    expect(generate_log_drain_configuration($server)['options'])->not->toHaveKey('awslogs-stream');
});

it('reports CloudWatch as an enabled drain that does not use Fluent Bit', function () {
    $cloudwatch = serverWithLogDrain(LogDrainType::Cloudwatch);
    $axiom = serverWithLogDrain(LogDrainType::Axiom);

    expect($cloudwatch->isLogDrainEnabled())->toBeTrue()
        ->and($cloudwatch->isFluentBitLogDrainEnabled())->toBeFalse()
        ->and($axiom->isLogDrainEnabled())->toBeTrue()
        ->and($axiom->isFluentBitLogDrainEnabled())->toBeTrue();
});

it('sends the Coolify labels with fluentd, so containers without the default variables are identified', function () {
    $server = serverWithLogDrain(LogDrainType::Custom);

    expect(generate_log_drain_configuration($server)['options'])
        ->toHaveKey('env', 'COOLIFY_APP_NAME,COOLIFY_PROJECT_NAME,COOLIFY_SERVER_IP,COOLIFY_ENVIRONMENT_NAME')
        ->toHaveKey('labels', 'coolify.projectName,coolify.environmentName,coolify.resourceName,coolify.serviceName');
});
