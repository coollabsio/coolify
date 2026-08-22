<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'user' => 'deploy',
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'build_pack' => 'dockerfile',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->preview = ApplicationPreview::create([
        'uuid' => 'preview-volume-cleanup-test',
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/42',
    ]);
});

it('deletes only named volumes that have the preview suffix enabled', function () {
    $this->application->persistentStorages()->create([
        'name' => 'app-data',
        'mount_path' => '/data',
        'host_path' => null,
        'is_preview_suffix_enabled' => true,
    ]);
    $this->application->persistentStorages()->create([
        'name' => 'shared-cache',
        'mount_path' => '/cache',
        'host_path' => null,
        'is_preview_suffix_enabled' => false,
    ]);
    $this->application->persistentStorages()->create([
        'name' => 'seed-data',
        'mount_path' => '/seed',
        'host_path' => '/srv/seed',
        'is_preview_suffix_enabled' => true,
    ]);
    Process::fake(['*' => Process::result(output: '')]);

    $this->preview->forceDelete();

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'docker volume rm'), 1);
    Process::assertRan(fn ($process) => str_contains($process->command, "docker volume rm -f 'app-data-pr-42'"));
    Process::assertRan(fn ($process) => str_contains($process->command, "sudo docker volume rm -f 'app-data-pr-42'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'shared-cache'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'seed-data'));
});

it('reports Docker volume removal failures and keeps the preview record', function () {
    $this->application->persistentStorages()->create([
        'name' => 'app-data',
        'mount_path' => '/data',
        'host_path' => null,
        'is_preview_suffix_enabled' => true,
    ]);
    Process::fake(['*' => Process::result(errorOutput: 'volume is in use', exitCode: 1)]);

    expect(fn () => $this->preview->forceDelete())
        ->toThrow(RuntimeException::class, 'volume is in use');

    expect(ApplicationPreview::find($this->preview->id))->not->toBeNull();
});

it('continues removing preview volumes when an earlier volume is already absent', function () {
    $this->application->persistentStorages()->create([
        'name' => 'already-removed',
        'mount_path' => '/removed',
        'host_path' => null,
        'is_preview_suffix_enabled' => true,
    ]);
    $this->application->persistentStorages()->create([
        'name' => 'app-data',
        'mount_path' => '/data',
        'host_path' => null,
        'is_preview_suffix_enabled' => true,
    ]);
    Process::fake(function ($process) {
        if (str_contains($process->command, "docker volume rm -f 'already-removed-pr-42'")) {
            return Process::result(
                errorOutput: 'Error response from daemon: volume already-removed-pr-42 not found',
                exitCode: 1,
            );
        }

        return Process::result(output: 'app-data-pr-42');
    });

    $this->preview->forceDelete();

    Process::assertRan(fn ($process) => str_contains($process->command, "docker volume rm -f 'already-removed-pr-42'"));
    Process::assertRan(fn ($process) => str_contains($process->command, "docker volume rm -f 'app-data-pr-42'"));
    expect(ApplicationPreview::find($this->preview->id))->toBeNull();
});
