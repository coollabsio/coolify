<?php

use App\Actions\Server\StartLogDrain;
use App\Actions\Server\StopLogDrain;
use App\Enums\LogDrainType;
use App\Livewire\Server\LogDrains;
use App\Models\Server;
use App\Models\User;
use App\Services\LogDrain\LegacyLogDrainFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->first();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('enables the CloudWatch drain and persists its options', function () {
    StartLogDrain::mock()->shouldReceive('handle')->once()->andReturn('ok');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainConfig.cloudwatch.group', '/coolify/prod')
        ->set('logDrainConfig.cloudwatch.region', 'us-east-1')
        ->set('logDrainConfig.cloudwatch.stream_prefix', 'docker/')
        ->call('toggleLogDrain', 'cloudwatch')
        ->assertHasNoErrors()
        ->assertSet('logDrainType', 'cloudwatch');

    $settings = $this->server->settings->fresh();
    expect($settings->logdrain_type)->toBe(LogDrainType::Cloudwatch)
        ->and($settings->logDrainConfig())->toBe([
            'region' => 'us-east-1',
            'group' => '/coolify/prod',
            'stream_prefix' => 'docker/',
        ]);
});

it('requires a log group before enabling CloudWatch', function () {
    StartLogDrain::mock()->shouldNotReceive('handle');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainConfig.cloudwatch.region', 'us-east-1')
        ->call('toggleLogDrain', 'cloudwatch')
        ->assertDispatched('error')
        ->assertHasErrors('logDrainConfig.cloudwatch.group')
        ->assertSet('logDrainType', null);

    expect($this->server->settings->fresh()->logdrain_type)->toBeNull();
});

it('requires a region before enabling CloudWatch', function () {
    StartLogDrain::mock()->shouldNotReceive('handle');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainConfig.cloudwatch.group', 'coolify')
        ->call('toggleLogDrain', 'cloudwatch')
        ->assertDispatched('error')
        ->assertHasErrors('logDrainConfig.cloudwatch.region')
        ->assertSet('logDrainType', null);

    expect($this->server->settings->fresh()->logdrain_type)->toBeNull();
});

it('rejects stream prefixes that could break the compose file', function (string $prefix) {
    StartLogDrain::mock()->shouldNotReceive('handle');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainConfig.cloudwatch.group', 'coolify')
        ->set('logDrainConfig.cloudwatch.region', 'us-east-1')
        ->set('logDrainConfig.cloudwatch.stream_prefix', $prefix)
        ->call('toggleLogDrain', 'cloudwatch')
        ->assertDispatched('error')
        ->assertHasErrors('logDrainConfig.cloudwatch.stream_prefix')
        ->assertSet('logDrainType', null);

    expect($this->server->settings->fresh()->logdrain_type)->toBeNull();
})->with(['docker/${HOME}', 'docker:', '{{.ID}}', 'with space']);

it('rejects an invalid stream prefix when the settings are saved', function () {
    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainConfig.cloudwatch.stream_prefix', 'docker:')
        ->call('submit')
        ->assertHasErrors('logDrainConfig.cloudwatch.stream_prefix');

    expect($this->server->settings->fresh()->logdrain_config)->toBeNull();
});

it('replaces the active drain when CloudWatch is enabled', function () {
    StartLogDrain::mock()->shouldReceive('handle')->andReturn('ok');
    $this->server->settings->update([
        'logdrain_type' => LogDrainType::NewRelic,
        'logdrain_config' => ['newrelic' => ['license_key' => 'abc123', 'base_uri' => 'https://log-api.newrelic.com']],
    ]);

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainConfig.cloudwatch.group', 'coolify')
        ->set('logDrainConfig.cloudwatch.region', 'us-east-1')
        ->call('toggleLogDrain', 'cloudwatch')
        ->assertSet('logDrainType', 'cloudwatch');

    $settings = $this->server->settings->fresh();
    expect($settings->logdrain_type)->toBe(LogDrainType::Cloudwatch)
        ->and($settings->logDrainConfig(LogDrainType::NewRelic))->toBe([
            'license_key' => 'abc123',
            'base_uri' => 'https://log-api.newrelic.com',
        ]);
});

it('reverts the CloudWatch type when starting the drain fails', function () {
    StartLogDrain::mock()->shouldReceive('handle')->andThrow(new RuntimeException('runtime boom'));

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainConfig.cloudwatch.group', 'coolify')
        ->set('logDrainConfig.cloudwatch.region', 'us-east-1')
        ->call('toggleLogDrain', 'cloudwatch')
        ->assertSet('logDrainType', null);

    expect($this->server->settings->fresh()->logdrain_type)->toBeNull();
});

it('forbids team members from enabling CloudWatch', function () {
    StartLogDrain::mock()->shouldNotReceive('handle');
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainConfig.cloudwatch.group', 'coolify')
        ->set('logDrainConfig.cloudwatch.region', 'us-east-1')
        ->call('toggleLogDrain', 'cloudwatch');

    expect($this->server->settings->fresh()->logdrain_type)->toBeNull();
});

it('does not deploy the Fluent Bit container for CloudWatch', function () {
    StopLogDrain::mock()->shouldReceive('handle')->once()->andReturn('removed');
    $this->server->settings->update([
        'logdrain_type' => LogDrainType::Cloudwatch,
        'logdrain_config' => ['cloudwatch' => ['group' => 'coolify', 'region' => 'us-east-1']],
    ]);

    $result = StartLogDrain::run($this->server->fresh());

    expect($result)->toBe('Amazon CloudWatch Logs uses the built-in Docker awslogs driver.');
});

it('keeps the Fluent Bit drain when old settings had CloudWatch and another drain enabled', function () {
    $settings = LegacyLogDrainFields::convert([
        'is_logdrain_axiom_enabled' => true,
        'logdrain_axiom_dataset_name' => 'ds',
        'logdrain_axiom_api_key' => 'key',
        'is_logdrain_cloudwatch_enabled' => true,
        'logdrain_cloudwatch_group' => 'coolify',
        'logdrain_cloudwatch_region' => 'us-east-1',
    ]);

    expect($settings)->toBe([
        'logdrain_type' => 'axiom',
        'logdrain_config' => [
            'axiom' => ['dataset_name' => 'ds', 'api_key' => 'key'],
            'cloudwatch' => ['region' => 'us-east-1', 'group' => 'coolify'],
        ],
    ]);
});
