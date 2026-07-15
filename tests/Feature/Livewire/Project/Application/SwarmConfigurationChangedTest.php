<?php

use App\Livewire\Project\Application\Swarm;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('dispatches configuration changed when Swarm settings are saved', function (string $method) {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $server->standaloneDockers()->firstOrFail()->id,
        'destination_type' => $server->standaloneDockers()->firstOrFail()->getMorphClass(),
        'swarm_replicas' => 1,
    ]);

    $this->actingAs($user);

    Livewire::test(Swarm::class, ['application' => $application])
        ->set('isSwarmOnlyWorkerNodes', false)
        ->call($method)
        ->assertHasNoErrors()
        ->assertDispatched('configurationChanged');
})->with(['instantSave', 'submit']);
