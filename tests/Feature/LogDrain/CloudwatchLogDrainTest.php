<?php

use App\Actions\Server\StartLogDrain;
use App\Actions\Server\StopLogDrain;
use App\Livewire\Server\LogDrains;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
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
        ->set('logDrainCloudwatchGroup', '/coolify/prod')
        ->set('logDrainCloudwatchRegion', 'us-east-1')
        ->set('logDrainCloudwatchStreamPrefix', 'docker/')
        ->call('toggleLogDrain', 'cloudwatch')
        ->assertHasNoErrors()
        ->assertSet('isLogDrainCloudwatchEnabled', true);

    $settings = $this->server->settings->fresh();
    expect($settings->is_logdrain_cloudwatch_enabled)->toBeTrue()
        ->and($settings->logdrain_cloudwatch_group)->toBe('/coolify/prod')
        ->and($settings->logdrain_cloudwatch_region)->toBe('us-east-1')
        ->and($settings->logdrain_cloudwatch_stream_prefix)->toBe('docker/');
});

it('requires a log group before enabling CloudWatch', function () {
    StartLogDrain::mock()->shouldNotReceive('handle');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('isLogDrainCloudwatchEnabled', true)
        ->call('instantSave')
        ->assertDispatched('error')
        ->assertSet('isLogDrainCloudwatchEnabled', false);

    expect($this->server->settings->fresh()->is_logdrain_cloudwatch_enabled)->toBeFalse();
});

it('requires a region before enabling CloudWatch', function () {
    StartLogDrain::mock()->shouldNotReceive('handle');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCloudwatchGroup', 'coolify')
        ->call('toggleLogDrain', 'cloudwatch')
        ->assertDispatched('error')
        ->assertSet('isLogDrainCloudwatchEnabled', false);

    expect($this->server->settings->fresh()->is_logdrain_cloudwatch_enabled)->toBeFalse();
});

it('rejects stream prefixes that could break the compose file', function (string $prefix) {
    StartLogDrain::mock()->shouldNotReceive('handle');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCloudwatchGroup', 'coolify')
        ->set('logDrainCloudwatchRegion', 'us-east-1')
        ->set('logDrainCloudwatchStreamPrefix', $prefix)
        ->set('isLogDrainCloudwatchEnabled', true)
        ->call('instantSave')
        ->assertDispatched('error')
        ->assertSet('isLogDrainCloudwatchEnabled', false);

    expect($this->server->settings->fresh()->is_logdrain_cloudwatch_enabled)->toBeFalse();
})->with(['docker/${HOME}', 'docker:', '{{.ID}}', 'with space']);

it('enables the CloudWatch drain from the status dropdown', function () {
    StartLogDrain::mock()->shouldReceive('handle')->once()->andReturn('ok');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCloudwatchGroup', 'coolify')
        ->set('logDrainCloudwatchRegion', 'us-east-1')
        ->set('logDrainCloudwatchStreamPrefix', 'docker/')
        ->set('isLogDrainCloudwatchEnabled', true)
        ->call('instantSave')
        ->assertDispatched('success');

    $settings = $this->server->settings->fresh();
    expect($settings->is_logdrain_cloudwatch_enabled)->toBeTrue()
        ->and($settings->logdrain_cloudwatch_stream_prefix)->toBe('docker/');
});

it('disables other drains when CloudWatch is enabled', function () {
    StartLogDrain::mock()->shouldReceive('handle')->andReturn('ok');
    $this->server->settings->update([
        'is_logdrain_newrelic_enabled' => true,
        'logdrain_newrelic_license_key' => 'abc123',
        'logdrain_newrelic_base_uri' => 'https://log-api.newrelic.com',
    ]);

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCloudwatchGroup', 'coolify')
        ->set('logDrainCloudwatchRegion', 'us-east-1')
        ->call('toggleLogDrain', 'cloudwatch')
        ->assertSet('isLogDrainNewRelicEnabled', false)
        ->assertSet('isLogDrainCloudwatchEnabled', true);

    $settings = $this->server->settings->fresh();
    expect($settings->is_logdrain_newrelic_enabled)->toBeFalsy()
        ->and($settings->is_logdrain_cloudwatch_enabled)->toBeTrue();
});

it('reverts the CloudWatch flag when starting the drain fails', function () {
    StartLogDrain::mock()->shouldReceive('handle')->andThrow(new RuntimeException('runtime boom'));

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCloudwatchGroup', 'coolify')
        ->set('logDrainCloudwatchRegion', 'us-east-1')
        ->call('toggleLogDrain', 'cloudwatch')
        ->assertSet('isLogDrainCloudwatchEnabled', false);

    expect($this->server->settings->fresh()->is_logdrain_cloudwatch_enabled)->toBeFalse();
});

it('forbids team members from enabling CloudWatch', function () {
    StartLogDrain::mock()->shouldNotReceive('handle');
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCloudwatchGroup', 'coolify')
        ->set('logDrainCloudwatchRegion', 'us-east-1')
        ->call('toggleLogDrain', 'cloudwatch');

    expect($this->server->settings->fresh()->is_logdrain_cloudwatch_enabled)->toBeFalse();
});

it('does not deploy the Fluent Bit container for CloudWatch', function () {
    StopLogDrain::mock()->shouldReceive('handle')->once()->andReturn('removed');
    $this->server->settings->update([
        'is_logdrain_cloudwatch_enabled' => true,
        'logdrain_cloudwatch_group' => 'coolify',
        'logdrain_cloudwatch_region' => 'us-east-1',
    ]);

    $result = StartLogDrain::run($this->server->fresh());

    expect($result)->toContain('awslogs');
});

it('shows the Amazon CloudWatch Logs section', function () {
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Amazon CloudWatch Logs')
        ->assertSee('Log stream prefix');
});

it('turns off the legacy Highlight drain when CloudWatch is enabled', function () {
    StartLogDrain::mock()->shouldReceive('handle')->andReturn('ok');
    $this->server->settings->update([
        'is_logdrain_highlight_enabled' => true,
        'logdrain_highlight_project_id' => 'legacy-project',
    ]);

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCloudwatchGroup', 'coolify')
        ->set('logDrainCloudwatchRegion', 'us-east-1')
        ->set('isLogDrainCloudwatchEnabled', true)
        ->call('instantSave')
        ->assertDispatched('success');

    $settings = $this->server->settings->fresh();
    expect($settings->is_logdrain_highlight_enabled)->toBeFalsy()
        ->and($settings->is_logdrain_cloudwatch_enabled)->toBeTrue();
});

it('turns off the legacy Highlight drain when switching drains with toggleLogDrain', function () {
    StartLogDrain::mock()->shouldReceive('handle')->andReturn('ok');
    $this->server->settings->update(['is_logdrain_highlight_enabled' => true]);

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCloudwatchGroup', 'coolify')
        ->set('logDrainCloudwatchRegion', 'us-east-1')
        ->call('toggleLogDrain', 'cloudwatch');

    $settings = $this->server->settings->fresh();
    expect($settings->is_logdrain_highlight_enabled)->toBeFalsy()
        ->and($settings->is_logdrain_cloudwatch_enabled)->toBeTrue();
});

it('keeps the legacy Highlight drain when saving without enabling another drain', function () {
    StartLogDrain::mock()->shouldReceive('handle')->andReturn('ok');
    $this->server->settings->update(['is_logdrain_highlight_enabled' => true]);

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCloudwatchGroup', 'coolify')
        ->set('logDrainCloudwatchRegion', 'us-east-1')
        ->call('submit')
        ->assertDispatched('success');

    expect($this->server->settings->fresh()->is_logdrain_highlight_enabled)->toBeTruthy();
});

it('rejects a save that would enable two log drains at once', function () {
    StartLogDrain::mock()->shouldNotReceive('handle');
    $this->server->settings->update([
        'is_logdrain_newrelic_enabled' => true,
        'logdrain_newrelic_license_key' => 'abc123',
        'logdrain_newrelic_base_uri' => 'https://log-api.newrelic.com',
    ]);

    // The UI disables the CloudWatch dropdown here; this simulates a crafted request.
    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCloudwatchGroup', 'coolify')
        ->set('logDrainCloudwatchRegion', 'us-east-1')
        ->set('isLogDrainCloudwatchEnabled', true)
        ->call('instantSave')
        ->assertDispatched('error')
        ->assertSet('isLogDrainCloudwatchEnabled', false)
        ->assertSet('isLogDrainNewRelicEnabled', true);

    $settings = $this->server->settings->fresh();
    expect($settings->is_logdrain_cloudwatch_enabled)->toBeFalse()
        ->and($settings->is_logdrain_newrelic_enabled)->toBeTruthy();
});

it('keeps the Fluent Bit drain running if CloudWatch is also enabled, matching the containers logging config', function () {
    Process::fake();
    StopLogDrain::mock()->shouldReceive('handle')->andReturn('removed');
    $this->server->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id]);
    $this->server->settings->update([
        'is_logdrain_axiom_enabled' => true,
        'logdrain_axiom_dataset_name' => 'ds',
        'logdrain_axiom_api_key' => 'key',
        'is_logdrain_cloudwatch_enabled' => true,
        'logdrain_cloudwatch_group' => 'coolify',
        'logdrain_cloudwatch_region' => 'us-east-1',
    ]);
    $server = $this->server->fresh();

    expect(generate_log_drain_configuration($server)['driver'])->toBe('fluentd');

    StartLogDrain::run($server);

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker compose up -d'));
});
