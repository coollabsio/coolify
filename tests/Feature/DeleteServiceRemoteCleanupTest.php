<?php

use App\Actions\Service\DeleteService;
use App\Jobs\DeleteResourceJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Queue::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server->update(['private_key_id' => $privateKey->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->serviceApplication = ServiceApplication::create(['service_id' => $this->service->id, 'name' => 'web', 'image' => 'nginx:alpine']);
    $this->serviceDatabase = ServiceDatabase::create(['service_id' => $this->service->id, 'name' => 'db', 'image' => 'postgres:17-alpine']);
    $this->inUseVolume = $this->serviceApplication->persistentStorages()->create([
        'name' => "{$this->service->uuid}_web-data",
        'mount_path' => '/data',
        'host_path' => null,
    ]);
    $this->serviceDatabase->persistentStorages()->create([
        'name' => "{$this->service->uuid}_db-data",
        'mount_path' => '/var/lib/postgresql/data',
        'host_path' => null,
    ]);
});

it('removes the other volumes, networks and the configuration directory when one volume is in use', function () {
    $commands = collect();
    Process::fake(function ($process) use ($commands) {
        $commands->push($process->command);
        if (str_contains($process->command, "docker volume rm -f '{$this->service->uuid}_web-data'")) {
            return Process::result(errorOutput: "Error response from daemon: remove {$this->service->uuid}_web-data: volume is in use", exitCode: 1);
        }

        return Process::result(output: '');
    });

    expect(fn () => app(DeleteService::class)->cleanupRemote($this->service, deleteVolumes: true, deleteConnectedNetworks: true, deleteConfigurations: true))
        ->toThrow(RuntimeException::class, "{$this->service->uuid}_web-data");

    $commandList = $commands->implode("\n");
    expect($commandList)
        ->toContain("docker volume rm -f '{$this->service->uuid}_db-data'")
        ->toContain("docker network rm {$this->service->uuid}")
        ->toContain('rm -rf '.$this->service->workdir());
});

it('removes volume backup schedules when a service is deleted from Coolify only', function () {
    $backup = $this->inUseVolume->scheduledBackups()->create([
        'team_id' => $this->service->environment->project->team_id,
        'frequency' => 'daily',
        'timeout' => 3600,
    ]);
    Process::fake();

    (new DeleteResourceJob($this->service, deleteFromCoolifyOnly: true))->handle();

    Process::assertNothingRan();
    expect(ScheduledVolumeBackup::query()->find($backup->id))->toBeNull()
        ->and(Service::withTrashed()->find($this->service->id))->toBeNull();
});
