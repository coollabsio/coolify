<?php

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
