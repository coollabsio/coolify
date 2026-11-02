<?php

use App\Livewire\Project\Shared\Logs;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function runtimeLogsApplication(): array
{
    InstanceSettings::forceCreate(['id' => 0]);
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

it('shows one log viewer for the selected deployment instead of an accordion', function () {
    [$team, $server, $application] = runtimeLogsApplication();
    $edge = Server::factory()->create(['team_id' => $team->id, 'name' => 'Edge']);

    $component = Livewire::test(Logs::class)
        ->set('resource', $application)
        ->set('type', 'application')
        ->set('servers', collect([$server, $edge]))
        ->set('serverContainers', [
            $server->id => [
                ['ID' => 'app', 'Names' => 'app', 'State' => 'running', 'Labels' => 'coolify.pullRequestId=0,com.docker.compose.service=web'],
                ['ID' => 'preview-web', 'Names' => 'app-pr-12-web', 'State' => 'running', 'Labels' => 'coolify.pullRequestId=12,com.docker.compose.service=web'],
                ['ID' => 'preview-api', 'Names' => 'app-pr-12-api', 'State' => 'running', 'Labels' => 'coolify.pullRequestId=12,com.docker.compose.service=api'],
            ],
            $edge->id => [
                ['ID' => 'edge-app', 'Names' => 'edge-app', 'State' => 'running', 'Labels' => 'coolify.pullRequestId=0,com.docker.compose.service=web'],
            ],
        ])
        ->set('containersLoaded', true)
        ->set('pullRequestId', '12');

    $component->assertSet('service', 'api')
        ->assertDontSee('Search servers')
        ->assertSee('Search deployments')
        ->assertSee('PR #12')
        ->assertDontSee('Preview #12')
        ->assertSee('Search services')
        ->assertDontSee('runtime-log-trigger');

    expect(runtimeLogViewerCount($component->html()))->toBe(1);
});

it('hides server and service selectors when production is the only container', function () {
    [, $server, $application] = runtimeLogsApplication();

    $component = Livewire::test(Logs::class)
        ->set('resource', $application)
        ->set('type', 'application')
        ->set('servers', collect([$server]))
        ->set('serverContainers', [
            $server->id => [
                ['ID' => 'app', 'Names' => 'app', 'State' => 'running', 'Labels' => 'coolify.pullRequestId=0'],
            ],
        ])
        ->set('containersLoaded', true)
        ->call('syncLogSelection');

    $component->assertSet('service', '')
        ->assertDontSee('Search servers')
        ->assertDontSee('Search deployments')
        ->assertDontSee('Search services')
        ->assertDontSee('runtime-log-trigger');

    expect(runtimeLogViewerCount($component->html()))->toBe(1);
});

it('merges every container of a service and does not offer a server selector', function () {
    [$team, $server, $application] = runtimeLogsApplication();
    $server->update(['name' => 'Frankfurt']);
    $edge = Server::factory()->create(['team_id' => $team->id, 'name' => 'Helsinki']);

    $component = Livewire::test(Logs::class)
        ->set('resource', $application)
        ->set('type', 'application')
        ->set('servers', collect([$server, $edge]))
        ->set('serverContainers', [
            $server->id => [
                ['ID' => 'web-new', 'Names' => 'web-new', 'State' => 'running', 'Labels' => 'coolify.pullRequestId=0,com.docker.compose.service=web'],
                ['ID' => 'web-old', 'Names' => 'web-old', 'State' => 'exited', 'Labels' => 'coolify.pullRequestId=0,com.docker.compose.service=web'],
            ],
            $edge->id => [
                ['ID' => 'web-edge', 'Names' => 'web-edge', 'State' => 'running', 'Labels' => 'coolify.pullRequestId=0,com.docker.compose.service=web'],
            ],
        ])
        ->set('containersLoaded', true)
        ->call('syncLogSelection');

    $component->assertSet('service', '')
        ->assertDontSee('Search servers')
        ->assertDontSee('runtime-log-trigger');

    expect($component->instance()->render()->getData()['targets']['sources'])->toBe([
        ['server' => $server->uuid, 'container' => 'web-new', 'label' => 'Frankfurt web-new'],
        ['server' => $server->uuid, 'container' => 'web-old', 'label' => 'Frankfurt web-old'],
        ['server' => $edge->uuid, 'container' => 'web-edge', 'label' => 'Helsinki'],
    ]);

    expect(runtimeLogViewerCount($component->html()))->toBe(1);
});

function runtimeLogViewerCount(string $html): int
{
    $workspace = (string) str($html)->after('class="runtime-logs-workspace"')->before('</section>');

    return preg_match_all('/project\.shared\.get-logs|<div wire:id="/', $workspace);
}
