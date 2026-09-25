<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

test('file storage resolves its bind path again for each operation', function () {
    $team = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  app:\n    image: alpine\n    volumes:\n      - '\${DATA_PATH:-./first}/file:/data/file'\n",
    ]);
    ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => 'bind-path-test',
        'status' => 'finished',
        'pull_request_id' => 0,
        'restart_only' => false,
        'server_id' => $server->id,
    ]);
    $volume = LocalFileVolume::create([
        'resource_type' => $application->getMorphClass(),
        'resource_id' => $application->id,
        'fs_path' => '${DATA_PATH:-./first}/file',
        'mount_path' => '/data/file',
        'content' => 'configured content',
        'is_directory' => false,
    ]);

    $source = '/srv/first file';
    $fileExists = false;
    $yamlOutput = false;
    Process::fake(function ($process) use (&$source, &$fileExists, &$yamlOutput) {
        if (str_contains($process->command, 'docker compose') && str_contains($process->command, 'config --format json')) {
            $config = ['services' => ['app' => ['volumes' => [[
                'type' => 'bind', 'source' => $source, 'target' => '/data/file',
            ]]]]];

            return Process::result(output: $yamlOutput ? Yaml::dump($config, 5) : json_encode($config));
        }
        if (str_contains($process->command, '.env-main') && str_contains($process->command, 'test -f')) {
            return Process::result(output: 'OK');
        }
        if (str_contains($process->command, 'test -f')) {
            return Process::result(output: $fileExists ? 'OK' : 'NOK');
        }
        if (str_contains($process->command, 'test -d')) {
            return Process::result(output: 'NOK');
        }
        if (str_contains($process->command, 'stat -c%s')) {
            return Process::result(output: '14');
        }
        if (str_contains($process->command, 'head -c')) {
            return Process::result(output: 'remote content');
        }

        return Process::result(output: 'OK');
    });

    $volume->saveStorageOnServer();
    $source = '/srv/second file';
    $fileExists = true;
    $volume->loadStorageOnServer();
    expect($volume->content)->toBe('remote content');
    $volume->content = 'updated content';
    $volume->saveStorageOnServer();
    $volume->deleteStorageOnServer();

    $yamlOutput = true;
    expect($volume->resolvedStoragePath($application->workdir(), $server))->toBe('/srv/second file');

    Process::assertRan(fn ($process) => str_contains($process->command, 'config --format json') && str_contains($process->command, '.env-main') && str_contains($process->command, '/artifacts/bind-path-test') && str_contains($process->command, '--no-env-resolution'));
    Process::assertRan(fn ($process) => str_contains($process->command, '/srv/first file') && str_contains($process->command, 'tee'));
    Process::assertRan(fn ($process) => str_contains($process->command, '/srv/second file') && str_contains($process->command, 'test -f'));
    Process::assertRan(fn ($process) => str_contains($process->command, '/srv/second file') && str_contains($process->command, 'rm -rf'));
    Process::assertNotRan(fn ($process) => ! str_contains($process->command, 'docker compose') && str_contains($process->command, '${DATA_PATH'));

    $source = '/tmp/x;id';
    expect(fn () => $volume->deleteStorageOnServer())->toThrow(Exception::class);
    Process::assertNotRan(fn ($process) => ! str_contains($process->command, 'docker compose') && str_contains($process->command, '/tmp/x;id'));

    $source = '/srv/safe';
    $volume->fs_path = '/tmp/$(id)';
    expect(fn () => $volume->saveStorageOnServer())->toThrow(Exception::class);
    Process::assertNotRan(fn ($process) => str_contains($process->command, '/tmp/$(id)'));
});
