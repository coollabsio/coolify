<?php

use App\Actions\Server\StartLogDrain;
use App\Actions\Server\StopLogDrain;
use App\Enums\LogDrainType;
use App\Livewire\Server\LogDrains;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\User;
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

it('reverts the saved log drain type when starting the log drain fails', function () {
    StartLogDrain::mock()->shouldReceive('handle')->andThrow(new RuntimeException('runtime boom'));

    expect($this->server->settings->fresh()->logdrain_type)->toBeNull();

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainConfig.newrelic.license_key', 'abc123')
        ->set('logDrainConfig.newrelic.base_uri', 'https://log-api.newrelic.com')
        ->call('toggleLogDrain', 'newrelic')
        ->assertSet('logDrainType', null);

    expect($this->server->settings->fresh()->logdrain_type)->toBeNull();
});

it('keeps the log drain type saved when starting the log drain succeeds', function () {
    StartLogDrain::mock()->shouldReceive('handle')->andReturn('ok');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainConfig.newrelic.license_key', 'abc123')
        ->set('logDrainConfig.newrelic.base_uri', 'https://log-api.newrelic.com')
        ->call('toggleLogDrain', 'newrelic')
        ->assertSet('logDrainType', 'newrelic');

    expect($this->server->settings->fresh()->logdrain_type)->toBe(LogDrainType::NewRelic);
});

it('opens the log drain page without writing an audit event', function () {
    $this->withoutDefer();

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->assertOk()
        ->assertSet('logDrainType', null);

    expect(AuditEvent::query()->where('event', 'like', 'ui.server.log_drain.%')->count())->toBe(0);
});

it('audits enabling and disabling a log drain with its provider', function () {
    $this->withoutDefer();
    StartLogDrain::mock()->shouldReceive('handle')->andReturn('ok');
    StopLogDrain::mock()->shouldReceive('handle')->andReturn('ok');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainConfig.newrelic.license_key', 'abc123')
        ->set('logDrainConfig.newrelic.base_uri', 'https://log-api.newrelic.com')
        ->call('toggleLogDrain', 'newrelic')
        ->call('toggleLogDrain', 'newrelic');

    $events = AuditEvent::query()->whereIn('event', ['ui.server.log_drain.enabled', 'ui.server.log_drain.disabled'])->orderBy('id')->get();

    expect($events->pluck('event')->all())->toBe(['ui.server.log_drain.enabled', 'ui.server.log_drain.disabled'])
        ->and($events->pluck('metadata.provider')->all())->toBe(['newrelic', 'newrelic']);
});
