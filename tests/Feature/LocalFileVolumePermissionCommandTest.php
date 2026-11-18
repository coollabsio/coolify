<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    config([
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n",
    ]);
    $this->serviceApplication = ServiceApplication::create(['name' => 'app', 'service_id' => $this->service->id]);

    Process::fake(fn ($process) => Process::result(output: match (true) {
        str_contains(remoteProcessScript($process), 'empty-directory') => remoteFileStatesOutput('missing'),
        str_contains(remoteProcessScript($process), 'readlink -f') => 'OK',
        default => '',
    }));
});

test('file storage quotes owner and mode as single command arguments', function () {
    $owner = 'root:root; touch /tmp/pwn';
    $mode = '644 $(id)';
    $volume = LocalFileVolume::create([
        'fs_path' => './config/app.conf',
        'mount_path' => '/etc/app.conf',
        'content' => 'listen 8080;',
        'is_directory' => false,
        'resource_id' => $this->serviceApplication->id,
        'resource_type' => $this->serviceApplication->getMorphClass(),
    ])->fresh();
    $volume->forceFill(['chown' => $owner, 'chmod' => $mode])->save();

    $volume->fresh()->saveStorageOnServer();

    $file = escapeshellarg($this->service->workdir().'/config/app.conf');
    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), "\nchown -- ".escapeshellarg($owner)." {$file}\n")
        && str_contains(remoteProcessScript($process), "\nchmod -- ".escapeshellarg($mode)." {$file}"));
    Process::assertDidntRun(fn ($process) => str_contains(remoteProcessScript($process), "chown {$owner}")
        || str_contains(remoteProcessScript($process), "chmod {$mode}"));
});
