<?php

use App\Helpers\SshMultiplexingHelper;
use App\Jobs\VolumeRestoreJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['constants.ssh.mux_enabled' => false]);
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'ip' => '10.0.0.9',
    ]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();

    $this->database = StandalonePostgresql::create([
        'name' => 'pg-restore-test',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->volume = LocalPersistentVolume::create([
        'name' => 'postgres-data-restore',
        'mount_path' => '/var/lib/postgresql/data',
        'resource_id' => $this->database->id,
        'resource_type' => $this->database->getMorphClass(),
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

test('builds an inline ssh command that can accept stdin', function () {
    $command = SshMultiplexingHelper::generateSshCommandInline(
        $this->server,
        'docker run --rm -i alpine tar -xzf - -C /target'
    );

    expect($command)
        ->toContain('ssh')
        ->toContain("'10.0.0.9'")
        ->toContain('docker run --rm -i alpine tar -xzf - -C /target');
});

test('restores a volume archive by piping tar into docker', function () {
    Process::fake([
        '*' => Process::result(output: 'ok'),
    ]);

    $job = new VolumeRestoreJob(
        '/tmp/volume-data.tar.gz',
        'target-volume',
        $this->server,
        $this->volume,
    );

    $job->handle();

    Process::assertRan(function ($process) {
        $command = $process->command;

        return str_contains($command, "'10.0.0.9'")
            && str_contains($command, 'target-volume')
            && str_contains($command, 'tar -xzf -')
            && str_contains($command, '/tmp/volume-data.tar.gz');
    });
});
