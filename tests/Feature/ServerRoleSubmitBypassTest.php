<?php

use App\Livewire\Server\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Process::fake();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'server_role' => 'both',
        'is_build_server' => false,
        'is_sentinel_enabled' => false,
    ]);
    $this->server->refresh()->load('settings');

    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

it('does not save a server role through submit', function () {
    Livewire::actingAs($this->user)
        ->test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('serverRole', 'build')
        ->call('submit')
        ->assertDispatched('success')
        ->assertSet('serverRole', 'both');

    $settings = $this->server->settings->fresh();
    expect($settings->server_role->value)->toBe('both')
        ->and($settings->is_build_server)->toBeFalse();
});

it('still saves a confirmed role change', function () {
    $this->server->settings()->update(['server_role' => 'deployment']);
    $buildServer = Server::factory()->create(['team_id' => $this->team->id]);
    $buildServer->settings()->update(['server_role' => 'build', 'is_build_server' => true, 'is_reachable' => true, 'is_usable' => true]);

    Livewire::actingAs($this->user)
        ->test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('serverRole', 'deployment')
        ->set('serverRole', 'both')
        ->call('requestServerRoleChange')
        ->assertSet('pendingServerRole', 'both')
        ->call('confirmServerRoleChange')
        ->assertSet('serverRole', 'both');

    expect($this->server->settings->fresh()->server_role->value)->toBe('both');
});
