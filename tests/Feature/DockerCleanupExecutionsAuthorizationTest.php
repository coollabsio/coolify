<?php

use App\Livewire\Server\DockerCleanupExecutions;
use App\Models\DockerCleanupExecution;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'member']);
    $this->otherTeam = Team::factory()->create();
    $this->user->teams()->attach($this->otherTeam, ['role' => 'owner']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->execution = DockerCleanupExecution::create([
        'server_id' => $this->server->id,
        'status' => 'running',
        'message' => implode("\n", array_fill(0, 150, 'cleanup output')),
    ]);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('denies log actions after server team access is removed', function (string $action) {
    $component = Livewire::test(DockerCleanupExecutions::class, ['server' => $this->server])
        ->call('selectExecution', $this->execution->id);

    $this->team->members()->detach($this->user->id);
    $this->user->refresh();
    session(['currentTeam' => $this->otherTeam]);

    $arguments = $action === 'selectExecution' ? [$this->execution->id] : [];
    $component->call($action, ...$arguments)->assertForbidden();
})->with(['refreshExecutions', 'selectExecution', 'polling', 'loadMoreLogs']);

it('allows log actions for a server team member after switching session teams', function () {
    $component = Livewire::test(DockerCleanupExecutions::class, ['server' => $this->server]);
    session(['currentTeam' => $this->otherTeam]);

    $component->call('refreshExecutions')
        ->call('selectExecution', $this->execution->id)
        ->assertSet('selectedExecution.id', $this->execution->id)
        ->assertSet('isPollingActive', true)
        ->call('loadMoreLogs')
        ->assertSet('currentPage', 2);

    $this->execution->update(['status' => 'success', 'message' => 'finished cleanup']);

    $component->call('polling')
        ->assertSet('isPollingActive', false)
        ->assertSee('finished cleanup');
});
