<?php

use App\Livewire\Project\Shared\Logs;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function runtimeLogsApplication(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $team->id])->id,
    ]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    return [$team, $server, $application];
}

it('reloads containers after a status check when the first load found none', function () {
    [$team, $server, $application] = runtimeLogsApplication();
    $server->settings->update(['is_swarm_manager' => true]);

    $component = new Logs;
    $component->resource = $application;
    $component->servers = collect([$server]);

    // The first load happened while no container was running (for example during a redeploy).
    $component->serverContainers = [$server->id => []];
    $component->containersLoaded = true;

    $listener = $component->getListeners()["echo-private:team.{$team->id},ServiceChecked"];
    expect($listener)->toBe('loadAllContainers');

    $component->{$listener}();

    expect($component->serverContainers[$server->id])->toHaveCount(1)
        ->and($component->serverContainers[$server->id][0]['Names'])->toBe($application->uuid.'_'.$application->uuid);
});

it('keeps the container lookup error instead of reporting no running containers', function () {
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    [, $server, $application] = runtimeLogsApplication();
    // A server without its private key fails the SSH lookup before any command runs.
    $server->updateQuietly(['private_key_id' => 999999]);

    $component = new Logs;
    $component->resource = $application;
    $component->servers = collect([$server]);
    $component->loadAllContainers();

    expect($component->serverContainers[$server->id])->toBe([])
        ->and($component->serverErrors[$server->id])->toContain('No query results for model [App\\Models\\PrivateKey]');

    $html = view('livewire.project.shared.logs', [
        'type' => null,
        'resource' => $application,
        'servers' => $component->servers,
        'serverContainers' => $component->serverContainers,
        'serverErrors' => $component->serverErrors,
        'containersLoaded' => true,
    ])->render();

    expect($html)->toContain('Could not load containers')
        ->and($html)->toContain('No query results for model [App\\Models\\PrivateKey]')
        ->and($html)->not->toContain('No containers are running');
});
