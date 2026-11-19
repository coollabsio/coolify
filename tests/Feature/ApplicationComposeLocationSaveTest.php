<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
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
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $team = Team::factory()->create();
    $this->user = User::factory()->create();
    $team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $this->actingAs($this->user);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);

    $this->application = Application::factory()->create([
        'environment_id' => Environment::factory()->create(['project_id' => $project->id])->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'git_repository' => 'https://github.com/coollabsio/compose-app',
        'git_branch' => 'main',
        'base_directory' => '/',
        'docker_compose_location' => '/docker-compose.yaml',
        'docker_compose_raw' => null,
        'redirect' => 'both',
    ])->fresh();
});

/**
 * The repository has only the Compose file at $existingPath.
 */
function fakeRepositoryWithComposeFileAt(string $existingPath): void
{
    Process::fake(function ($process) use ($existingPath) {
        $command = is_array(remoteProcessScript($process)) ? implode(' ', remoteProcessScript($process)) : remoteProcessScript($process);
        if (str_contains($command, 'git ls-tree') && ! str_contains($command, escapeshellarg(ltrim($existingPath, '/')))) {
            return Process::result(errorOutput: 'No such file', exitCode: 1);
        }
        if (str_contains($command, 'git cat-file blob')) {
            return Process::result(output: "services:\n  web:\n    image: nginx:alpine\n");
        }

        return Process::result(output: '');
    });
}

test('a changed Docker Compose location that loads is kept after a page reload', function () {
    fakeRepositoryWithComposeFileAt('/docker-compose.stage.yml');

    Livewire::test(General::class, ['application' => $this->application])
        ->set('dockerComposeLocation', '/docker-compose.stage.yml')
        ->call('submit')
        ->assertDispatched('success', 'Application settings updated!')
        ->assertSet('dockerComposeLocation', '/docker-compose.stage.yml');

    expect($this->application->fresh())
        ->docker_compose_location->toBe('/docker-compose.stage.yml')
        ->docker_compose_raw->toContain('nginx:alpine');

    Livewire::test(General::class, ['application' => $this->application->fresh()])
        ->assertSet('dockerComposeLocation', '/docker-compose.stage.yml');
});

test('a changed Docker Compose location that does not exist is not saved', function () {
    fakeRepositoryWithComposeFileAt('/docker-compose.yaml');

    Livewire::test(General::class, ['application' => $this->application])
        ->set('dockerComposeLocation', '/missing.yml')
        ->call('submit')
        ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[0], 'Docker Compose file not found at: /missing.yml'))
        ->assertSet('dockerComposeLocation', '/docker-compose.yaml');

    expect($this->application->fresh()->docker_compose_location)->toBe('/docker-compose.yaml');
});
