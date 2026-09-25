<?php

use App\Livewire\Project\Application\InternalAccess;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('refreshes exposed ports when application networking changes', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['id' => 50, 'team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'ports_exposes' => '3000',
    ]);

    $component = Livewire::test(InternalAccess::class, ['application' => $application])
        ->assertSee('3000');

    $application->update(['ports_exposes' => '8080']);

    $component
        ->dispatch('applicationNetworkingUpdated')
        ->assertSee('8080')
        ->assertDontSee('value="3000"', escape: false);
});
