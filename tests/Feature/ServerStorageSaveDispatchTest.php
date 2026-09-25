<?php

use App\Jobs\ServerStorageSaveJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);

    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
        'queue.default' => 'sync',
        'constants.ssh.mux_enabled' => false,
    ]);

    $team = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    Process::fake(fn ($process) => Process::result(
        output: str_contains($process->command, 'realpath -m') ? 'OK' : 'NOK'
    ));

    $this->processedSaves = 0;
    Event::listen(JobProcessed::class, function (JobProcessed $event): void {
        if ($event->job->resolveName() === ServerStorageSaveJob::class) {
            $this->processedSaves++;
        }
    });
});

function createStorageSaveTestMount(Application $application): LocalFileVolume
{
    return LocalFileVolume::create([
        'fs_path' => application_configuration_dir().'/'.$application->uuid.'/data',
        'mount_path' => '/app/data',
        'is_directory' => true,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
}

it('runs the storage save after its mount is committed', function () {
    DB::beginTransaction();
    $volume = createStorageSaveTestMount($this->application);

    expect($this->processedSaves)->toBe(0);
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'mkdir -p'));

    DB::commit();

    expect($this->processedSaves)->toBe(1)
        ->and($volume->exists)->toBeTrue();
    Process::assertRan(fn ($process) => str_contains($process->command, 'mkdir -p'));
});

it('does not run the storage save after its mount is rolled back', function () {
    DB::beginTransaction();
    createStorageSaveTestMount($this->application);
    DB::rollBack();

    expect($this->processedSaves)->toBe(0)
        ->and(LocalFileVolume::query()->count())->toBe(0);
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'mkdir -p'));
});

it('writes a new file mount immediately when there is no transaction', function () {
    LocalFileVolume::create([
        'fs_path' => application_configuration_dir().'/'.$this->application->uuid.'/config.env',
        'mount_path' => '/app/config.env',
        'content' => 'KEY=value',
        'is_directory' => false,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    expect($this->processedSaves)->toBe(1);
    Process::assertRan(fn ($process) => str_contains($process->command, base64_encode('KEY=value')));
});
