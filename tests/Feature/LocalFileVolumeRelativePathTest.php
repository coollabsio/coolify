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
        str_contains($process->command, 'readlink -f') => 'OK',
        str_contains($process->command, 'test -') => 'NOK',
        default => '',
    }));
});

function relativePathFileVolume(string $fsPath, bool $isDirectory, ?string $content = null): LocalFileVolume
{
    return LocalFileVolume::create([
        'fs_path' => $fsPath,
        'mount_path' => '/data',
        'content' => $content,
        'is_directory' => $isDirectory,
        'resource_id' => test()->serviceApplication->id,
        'resource_type' => test()->serviceApplication->getMorphClass(),
    ])->fresh();
}

/**
 * Remote commands run from the SSH user's home directory, so a relative path in a command
 * would create files and directories in `~` instead of the resource directory.
 */
function assertNoRelativeRemotePath(): void
{
    Process::assertDidntRun(fn ($process) => str_contains($process->command, "'./"));
}

test('a relative directory path is created inside the resource directory, not in the home directory', function () {
    $workdir = $this->service->workdir();
    relativePathFileVolume('./data/sub', isDirectory: true)->saveStorageOnServer();

    Process::assertRan(fn ($process) => str_contains($process->command, "mkdir -p '{$workdir}/data/sub'"));
    Process::assertRan(fn ($process) => str_contains($process->command, "mkdir -p '{$workdir}/data'"));
    assertNoRelativeRemotePath();
});

test('a relative file path without content gets its parent directory inside the resource directory', function () {
    $workdir = $this->service->workdir();
    relativePathFileVolume('./config/app.conf', isDirectory: false)->saveStorageOnServer();

    Process::assertRan(fn ($process) => str_contains($process->command, "mkdir -p '{$workdir}/config'"));
    Process::assertRan(fn ($process) => str_contains($process->command, "touch '{$workdir}/config/app.conf'"));
    assertNoRelativeRemotePath();
});

test('a relative file path with content is written inside the resource directory', function () {
    $workdir = $this->service->workdir();
    relativePathFileVolume('./config/app.conf', isDirectory: false, content: 'listen 8080;')->saveStorageOnServer();

    Process::assertRan(fn ($process) => str_contains($process->command, "| base64 -d | tee '{$workdir}/config/app.conf' > /dev/null"));
    assertNoRelativeRemotePath();
});

test('an absolute directory path keeps working unchanged', function () {
    $workdir = $this->service->workdir();
    relativePathFileVolume($workdir.'/data', isDirectory: true)->saveStorageOnServer();

    Process::assertRan(fn ($process) => str_contains($process->command, "mkdir -p '{$workdir}/data'"));
    assertNoRelativeRemotePath();
});

test('deleting a relative path removes it from the resource directory', function () {
    Process::fake(fn ($process) => Process::result(output: match (true) {
        str_contains($process->command, 'readlink -f') => 'OK',
        str_contains($process->command, 'test -f') => 'OK',
        default => 'NOK',
    }));
    $workdir = $this->service->workdir();
    relativePathFileVolume('./config/app.conf', isDirectory: false)->deleteStorageOnServer();

    Process::assertRan(fn ($process) => str_contains($process->command, "rm -rf '{$workdir}/config/app.conf'"));
    assertNoRelativeRemotePath();
});

test('the content path on the server resolves a relative path against the resource directory', function () {
    expect(relativePathFileVolume('./config/app.conf', isDirectory: false, content: 'x')->contentPathOnServer())
        ->toBe($this->service->workdir().'/config/app.conf');
});
