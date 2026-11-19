<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/**
 * Answers every `stat -c '%F'` in the checkout with the given file type.
 */
class PreviewStorageTypeSyncJob extends ApplicationDeploymentJob
{
    public string $statResult = 'directory';

    public function __construct() {}

    public function execute_remote_command(...$commands): void
    {
        $property = new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs');
        $outputs = $property->getValue($this);
        foreach ($commands as $command) {
            if (isset($command['save'])) {
                $outputs->put($command['save'], $this->statResult);
            }
        }
    }
}

function runPreviewStorageTypeSync(Application $application, Server $server, int $pullRequestId, string $statResult): void
{
    $job = new PreviewStorageTypeSyncJob;
    $job->statResult = $statResult;
    foreach ([
        'application' => $application,
        'mainServer' => $server,
        'pull_request_id' => $pullRequestId,
        'preserveRepository' => true,
        'deployment_uuid' => 'deployment-uuid',
        'workdir' => '/artifacts/deployment-uuid',
        'saved_outputs' => collect(),
    ] as $name => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $name))->setValue($job, $value);
    }

    (new ReflectionMethod(ApplicationDeploymentJob::class, 'syncPreservedStorageTypesWithRepository'))->invoke($job);
}

beforeEach(function () {
    Server::flushIdentityMap();
    Bus::fake();
    Process::fake(fn ($process) => Process::result(
        output: str_contains(remoteProcessScript($process), 'empty-directory') ? '1:missing' : 'OK',
    ));
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false]);

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '5',
    ]);
    $this->storage = LocalFileVolume::create([
        'fs_path' => $this->application->workdir().'/config',
        'mount_path' => '/etc/config',
        'content' => 'production content',
        'is_directory' => false,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);
});

test('a preview checkout does not change the type of production file storages', function () {
    runPreviewStorageTypeSync($this->application, $this->server, 7, 'directory');

    $storage = $this->storage->fresh();
    expect($storage->is_directory)->toBeFalse()
        ->and($storage->content)->toBe('production content');
    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), 'config-pr-7'));
    Process::assertDidntRun(fn ($process) => preg_match('#/config(?!-pr-7)#', remoteProcessScript($process)) === 1
        && ! str_contains(remoteProcessScript($process), 'config-pr-7'));
});

test('a preview that shares the production path does not touch the production storage', function () {
    $this->storage->update(['is_preview_suffix_enabled' => false]);

    runPreviewStorageTypeSync($this->application, $this->server, 7, 'directory');

    expect($this->storage->fresh()->is_directory)->toBeFalse();
    Process::assertNothingRan();
});

test('a production checkout still updates the type of its file storages', function () {
    runPreviewStorageTypeSync($this->application, $this->server, 0, 'directory');

    $storage = $this->storage->fresh();
    expect($storage->is_directory)->toBeTrue()
        ->and($storage->content)->toBeNull();
});
