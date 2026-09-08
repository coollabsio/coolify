<?php

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->storage = $this->application->persistentStorages()->create([
        'name' => 'delete-resource-job-test',
        'mount_path' => '/data',
        'host_path' => null,
    ]);

    $this->application->delete();
    Queue::fake();
});

it('deletes the Coolify resource when remote cleanup fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'SSH connection timed out', exitCode: 255)]);

    (new DeleteResourceJob($this->application))->handle();

    expect(Application::withTrashed()->find($this->application->id))->toBeNull();
    Queue::assertNotPushed(QueuedCommand::class);
});

it('rolls back local metadata deletion when deleting the resource fails', function () {
    Process::fake(['*' => Process::result(output: '')]);
    $applicationUuid = $this->application->uuid;
    Application::deleting(function (Application $application) use ($applicationUuid): void {
        if ($application->uuid === $applicationUuid && $application->isForceDeleting()) {
            throw new RuntimeException('Local deletion failed.');
        }
    });

    expect(fn () => (new DeleteResourceJob($this->application))->handle())
        ->toThrow(RuntimeException::class, 'Local deletion failed.');

    expect($this->storage->fresh())->not->toBeNull()
        ->and(Application::withTrashed()->find($this->application->id))->not->toBeNull();
});

it('deletes scheduled volume backups outside the local deletion transaction', function () {
    $backup = $this->storage->scheduledBackups()->create([
        'team_id' => $this->application->environment->project->team_id,
        'frequency' => 'daily',
        'timeout' => 3600,
    ]);
    $transactionLevel = DB::transactionLevel();
    $deletingTransactionLevel = null;

    ScheduledVolumeBackup::deleting(function (ScheduledVolumeBackup $deletingBackup) use ($backup, &$deletingTransactionLevel): void {
        if ($deletingBackup->is($backup)) {
            $deletingTransactionLevel = DB::transactionLevel();
        }
    });
    Process::fake(['*' => Process::result(output: '')]);

    (new DeleteResourceJob($this->application))->handle();

    expect($deletingTransactionLevel)->toBe($transactionLevel);
});

it('deletes preview metadata locally when its application destination is missing', function () {
    $this->application->restore();
    $this->application->update(['destination_id' => PHP_INT_MAX]);
    $preview = ApplicationPreview::create([
        'uuid' => 'preview-without-destination',
        'application_id' => $this->application->id,
        'pull_request_id' => 45,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/45',
    ]);
    $previewStorage = $preview->persistentStorages()->create([
        'name' => 'preview-without-destination-data',
        'mount_path' => '/preview-data',
        'host_path' => null,
    ]);
    Process::fake();

    (new DeleteResourceJob($preview))->handle();

    Process::assertNothingRan();
    expect(ApplicationPreview::withTrashed()->find($preview->id))->toBeNull()
        ->and($previewStorage->fresh())->toBeNull();
});
