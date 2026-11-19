<?php

use App\Livewire\Server\Security\TerminalAccess;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'admin']);
    $this->otherTeam->members()->attach($this->user->id, ['role' => 'member']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update(['is_terminal_enabled' => false]);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('lets an admin of the server team toggle terminal access after the session switches to a team where they are a member', function () {
    $component = Livewire::test(TerminalAccess::class, ['server_uuid' => $this->server->uuid]);

    session(['currentTeam' => $this->otherTeam]);
    Cache::flush();

    $component->call('toggleTerminal', 'password')->assertHasNoErrors();

    expect($this->server->settings->fresh()->is_terminal_enabled)->toBeTrue();
});

it('does not let a user toggle terminal access after they become a member of the server team', function () {
    $component = Livewire::test(TerminalAccess::class, ['server_uuid' => $this->server->uuid]);

    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->user->unsetRelation('teams');
    Cache::flush();

    $component->call('toggleTerminal', 'password');

    expect($this->server->settings->fresh()->is_terminal_enabled)->toBeFalse();
});
