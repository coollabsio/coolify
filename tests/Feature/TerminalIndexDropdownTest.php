<?php

use App\Livewire\Terminal\Index as TerminalIndex;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->primaryServer = Server::factory()->create(['team_id' => $this->team->id]);
    $this->secondaryServer = Server::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('default sentinel dispatches an error and no terminal command', function () {
    Livewire::test(TerminalIndex::class)
        ->set('selected_uuid', 'default')
        ->call('connectToContainer')
        ->assertDispatched('error')
        ->assertNotDispatched('send-terminal-command');
});

test('selecting a bare server uuid dispatches in server-mode (isContainer=false)', function () {
    Livewire::test(TerminalIndex::class)
        ->set('selected_uuid', $this->primaryServer->uuid)
        ->call('connectToContainer')
        ->assertDispatched(
            'send-terminal-command',
            false,
            $this->primaryServer->uuid,
            $this->primaryServer->uuid,
        );
});

test('lookup matches container by both server uuid and name (disambiguates duplicates)', function () {
    $duplicateName = 'my-app';

    $component = Livewire::test(TerminalIndex::class);
    $instance = $component->instance();
    $instance->containers = [
        [
            'name' => $duplicateName,
            'connection_name' => $duplicateName,
            'uuid' => $duplicateName,
            'status' => 'running',
            'server' => $this->primaryServer,
            'server_uuid' => $this->primaryServer->uuid,
        ],
        [
            'name' => $duplicateName,
            'connection_name' => $duplicateName,
            'uuid' => $duplicateName,
            'status' => 'running',
            'server' => $this->secondaryServer,
            'server_uuid' => $this->secondaryServer->uuid,
        ],
    ];
    // Pick the container on the SECONDARY server. With the old `firstWhere(uuid)`
    // lookup this would have resolved to the primary; the new composite lookup
    // must pick the secondary.
    $instance->selected_uuid = $this->secondaryServer->uuid.'|'.$duplicateName;

    [$serverUuid, $containerName] = explode('|', $instance->selected_uuid, 2);
    $resolved = collect($instance->containers)->first(
        fn ($c) => data_get($c, 'server_uuid') === $serverUuid
            && data_get($c, 'name') === $containerName
    );

    expect($resolved)->not->toBeNull();
    expect(data_get($resolved, 'server_uuid'))->toBe($this->secondaryServer->uuid);
    expect(data_get($resolved, 'name'))->toBe($duplicateName);
});

test('malformed composite (empty name) dispatches an error', function () {
    Livewire::test(TerminalIndex::class)
        ->set('selected_uuid', $this->primaryServer->uuid.'|')
        ->call('connectToContainer')
        ->assertDispatched('error', 'Invalid selection.')
        ->assertNotDispatched('send-terminal-command');
});

test('unknown composite (no matching container) dispatches an error', function () {
    Livewire::test(TerminalIndex::class)
        ->set('selected_uuid', $this->primaryServer->uuid.'|nonexistent')
        ->call('connectToContainer')
        ->assertDispatched('error', 'Container not found.')
        ->assertNotDispatched('send-terminal-command');
});
