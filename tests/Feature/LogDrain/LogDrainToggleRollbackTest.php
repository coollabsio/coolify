<?php

use App\Actions\Server\StartLogDrain;
use App\Livewire\Server\LogDrains;
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

it('reverts the persisted enabled flag when starting the log drain fails', function () {
    StartLogDrain::mock()->shouldReceive('handle')->andThrow(new RuntimeException('runtime boom'));

    expect($this->server->settings->fresh()->is_logdrain_newrelic_enabled)->toBeFalsy();

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainNewRelicLicenseKey', 'abc123')
        ->set('logDrainNewRelicBaseUri', 'https://log-api.newrelic.com')
        ->call('toggleLogDrain', 'newrelic')
        ->assertSet('isLogDrainNewRelicEnabled', false);

    expect($this->server->settings->fresh()->is_logdrain_newrelic_enabled)->toBeFalsy();
});

it('keeps the enabled flag persisted when starting the log drain succeeds', function () {
    StartLogDrain::mock()->shouldReceive('handle')->andReturn('ok');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainNewRelicLicenseKey', 'abc123')
        ->set('logDrainNewRelicBaseUri', 'https://log-api.newrelic.com')
        ->call('toggleLogDrain', 'newrelic')
        ->assertSet('isLogDrainNewRelicEnabled', true);

    expect($this->server->settings->fresh()->is_logdrain_newrelic_enabled)->toBeTruthy();
});
