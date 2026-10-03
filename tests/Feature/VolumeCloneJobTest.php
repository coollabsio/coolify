<?php

use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false]);

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'user' => 'root',
        'private_key_id' => $this->privateKey->id,
    ]);
    $this->volume = LocalPersistentVolume::forceCreate([
        'name' => 'clone-target',
        'mount_path' => '/data',
        'resource_type' => Application::class,
        'resource_id' => 1,
        'ignores_compose_driver_options' => false,
    ]);
});

/**
 * Fakes the server: `docker volume inspect` prints the given source volume, everything else succeeds.
 *
 * @param  array<string, mixed>|null  $sourceVolume
 */
function fakeVolumeCloneServer(?array $sourceVolume): void
{
    Process::fake(fn ($process) => Process::result(
        output: str_contains($process->command, 'docker volume inspect')
            ? json_encode($sourceVolume === null ? [] : [$sourceVolume])
            : '',
    ));
}

function volumeCloneCommands(): string
{
    $commands = [];
    Process::assertRan(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return true;
    });

    return implode("\n", $commands);
}

test('a local clone creates the target volume with the driver options of the source volume', function () {
    fakeVolumeCloneServer([
        'Name' => 'clone-source',
        'Driver' => 'local',
        'Options' => ['type' => 'nfs', 'o' => 'addr=10.0.0.5,rw,nfsvers=4', 'device' => ':/exports/data'],
    ]);

    (new VolumeCloneJob('clone-source', 'clone-target', $this->server, $this->server, $this->volume))->handle();

    $commands = volumeCloneCommands();
    expect($commands)->toContain('docker volume inspect '.escapeshellarg('clone-source'))
        ->and($commands)->toContain(escapeshellarg("docker volume create --opt 'type=nfs' --opt 'o=addr=10.0.0.5,rw,nfsvers=4' --opt 'device=:/exports/data' 'clone-target'"))
        ->and($commands)->toContain('cp -a /source/. /target/')
        ->and($this->volume->fresh()->ignores_compose_driver_options)->toBeFalse();
});

test('a local clone keeps a driver that is not local', function () {
    fakeVolumeCloneServer(['Name' => 'clone-source', 'Driver' => 'rclone', 'Options' => ['remote' => 'minio:bucket']]);

    (new VolumeCloneJob('clone-source', 'clone-target', $this->server, $this->server, $this->volume))->handle();

    expect(volumeCloneCommands())->toContain(escapeshellarg("docker volume create --driver 'rclone' --opt 'remote=minio:bucket' 'clone-target'"));
});

test('driver options are escaped for the shell', function () {
    fakeVolumeCloneServer([
        'Name' => 'clone-source',
        'Driver' => 'local',
        'Options' => ['type' => 'cifs', 'o' => "username=a,password=x'; touch /tmp/pwned; echo '\$(id)&&b"],
    ]);

    (new VolumeCloneJob('clone-source', 'clone-target', $this->server, $this->server, $this->volume))->handle();

    $inner = 'docker volume create --opt '.escapeshellarg('type=cifs').' --opt '
        .escapeshellarg("o=username=a,password=x'; touch /tmp/pwned; echo '\$(id)&&b").' '.escapeshellarg('clone-target');
    expect(volumeCloneCommands())->toContain('sh -c '.escapeshellarg($inner));
});

test('a source volume without driver options gives a plain target volume and a name-only declaration', function () {
    fakeVolumeCloneServer(['Name' => 'clone-source', 'Driver' => 'local', 'Options' => null]);

    (new VolumeCloneJob('clone-source', 'clone-target', $this->server, $this->server, $this->volume))->handle();

    expect(volumeCloneCommands())->toContain(escapeshellarg("docker volume create 'clone-target'"))
        ->and($this->volume->fresh()->ignores_compose_driver_options)->toBeTrue();
});

test('a missing source volume still creates an empty target volume', function () {
    fakeVolumeCloneServer(null);

    (new VolumeCloneJob('clone-source', 'clone-target', $this->server, $this->server, $this->volume))->handle();

    expect(volumeCloneCommands())->toContain(escapeshellarg("docker volume create 'clone-target'"));
});

test('a bind-type source volume gets independent empty storage without copying its host folder', function () {
    fakeVolumeCloneServer([
        'Name' => 'clone-source',
        'Driver' => 'local',
        'Options' => ['type' => 'none', 'o' => 'bind', 'device' => '/srv/app-data'],
    ]);

    (new VolumeCloneJob('clone-source', 'clone-target', $this->server, $this->server, $this->volume))->handle();

    $commands = volumeCloneCommands();
    expect($commands)->toContain(escapeshellarg("docker volume create 'clone-target'"))
        ->and($this->volume->fresh()->ignores_compose_driver_options)->toBeTrue()
        ->and(composeRenamedVolumeDeclarationFor(['driver' => 'local', 'driver_opts' => ['type' => 'none', 'o' => 'bind', 'device' => '/srv/app-data']], 'clone-target', $this->volume->fresh()))->toBe(['name' => 'clone-target'])
        ->and($commands)->not->toContain('cp -a')
        ->and($commands)->not->toContain('chown -R');
});

test('a remote clone creates the target volume with the source driver options on the target server', function () {
    $targetServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'user' => 'root',
        'private_key_id' => $this->privateKey->id,
    ]);
    fakeVolumeCloneServer([
        'Name' => 'clone-source',
        'Driver' => 'local',
        'Options' => ['type' => 'nfs', 'o' => 'addr=10.0.0.5', 'device' => ':/exports/data'],
    ]);

    (new VolumeCloneJob('clone-source', 'clone-target', $this->server, $targetServer, $this->volume))->handle();

    $commands = volumeCloneCommands();
    expect($commands)->toContain(escapeshellarg("docker volume create --opt 'type=nfs' --opt 'o=addr=10.0.0.5' --opt 'device=:/exports/data' 'clone-target'"))
        ->and($commands)->toContain('tar xzf /clone/volume-data.tar.gz');
});

test('a remote bind-type clone gets independent empty storage without copying the host folder', function () {
    $targetServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'user' => 'root',
        'private_key_id' => $this->privateKey->id,
    ]);
    fakeVolumeCloneServer([
        'Name' => 'clone-source',
        'Driver' => 'local',
        'Options' => ['type' => 'none', 'o' => 'bind,rw', 'device' => '/srv/app-data'],
    ]);

    (new VolumeCloneJob('clone-source', 'clone-target', $this->server, $targetServer, $this->volume))->handle();

    $commands = volumeCloneCommands();
    expect($commands)->toContain(escapeshellarg("docker volume create 'clone-target'"))
        ->and($this->volume->fresh()->ignores_compose_driver_options)->toBeTrue()
        ->and($commands)->not->toContain('tar czf')
        ->and($commands)->not->toContain('tar xzf');
});

test('a non-root server runs the volume create as one sudo shell script', function () {
    $this->server->update(['user' => 'cooluser']);
    Server::flushIdentityMap();
    $server = $this->server->fresh();
    fakeVolumeCloneServer([
        'Name' => 'clone-source',
        'Driver' => 'local',
        'Options' => ['type' => 'cifs', 'o' => 'password=a&&b|c $(x)'],
    ]);

    (new VolumeCloneJob('clone-source', 'clone-target', $server, $server, $this->volume))->handle();

    $inner = "docker volume create --opt 'type=cifs' --opt 'o=password=a&&b|c \$(x)' 'clone-target'";
    expect(volumeCloneCommands())->toContain('sudo sh -c '.escapeshellarg($inner))
        ->and(volumeCloneCommands())->toContain('sudo docker volume inspect');
});
