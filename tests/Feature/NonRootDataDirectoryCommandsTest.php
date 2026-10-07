<?php

use App\Actions\Database\StartPostgresql;
use App\Jobs\DatabaseBackupJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Traits\StagesCloneArchives;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * On the Coolify host, install.sh makes /data/coolify `9999:root 0700`, so a non-root SSH user cannot
 * enter it. `cd`, redirects, globs, and scp run as that user, so they must not touch /data/coolify.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false]);
    Process::fake();
    Queue::fake();

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'user' => 'cooluser',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

/**
 * @return array<int, string>
 */
function nonRootDataDirectoryRemoteLines(): array
{
    $lines = [];
    Process::assertRan(function ($process) use (&$lines) {
        array_push($lines, ...explode("\n", $process->command));

        return true;
    });

    return $lines;
}

function expectNoUnprivilegedDataDirectoryAccess(array $lines): void
{
    foreach ($lines as $line) {
        expect(trim($line))->not->toStartWith('cd ')
            ->and($line)->not->toMatch('/[^2&]>>?\s*\'?\/data\/coolify/')
            ->and($line)->not->toStartWith('scp ');
    }
}

it('starts a database through the queued executor on a non-root server', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination);
    $configurationDirectory = database_configuration_dir().'/'.$database->uuid;

    StartPostgresql::run($database->fresh(), activity()->log('Starting database'));

    $lines = nonRootDataDirectoryRemoteLines();
    expectNoUnprivilegedDataDirectoryAccess($lines);
    expect(implode("\n", $lines))
        ->toContain("| sudo tee {$configurationDirectory}/README.md > /dev/null")
        ->toContain("sudo find {$configurationDirectory}/docker-entrypoint-initdb.d -mindepth 1 -maxdepth 1 -exec rm -rf {} +")
        ->toContain("sudo docker compose -f {$configurationDirectory}/docker-compose.yml up -d");
});

it('writes service compose files without cd or scp on a non-root server', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose' => "services:\n  app:\n    image: nginx\n",
    ]);
    $workdir = $service->workdir();

    $service->saveComposeConfigs();

    $lines = nonRootDataDirectoryRemoteLines();
    expectNoUnprivilegedDataDirectoryAccess($lines);
    expect(implode("\n", $lines))
        ->toContain(escapeshellarg("sudo tee '{$workdir}/docker-compose.yml' > /dev/null"))
        ->toMatch('/^sudo mv '.preg_quote($workdir, '/').'\/\S+\.env\.tmp '.preg_quote($workdir, '/').'\/\.env$/m');
});

it('writes a database dump and its redirect in one root shell', function () {
    $backupLocation = '/data/coolify/backups/databases/team-1/db/pg-dump-db-1.dmp';
    $job = (new ReflectionClass(DatabaseBackupJob::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(DatabaseBackupJob::class, 'backup_location'))->setValue($job, $backupLocation);

    $commands = (new ReflectionMethod(DatabaseBackupJob::class, 'writeBackupFileAsRoot'))->invoke($job, [
        "mkdir -p '/data/coolify/backups/databases/team-1/db'",
        "docker exec 'db' pg_dump --format=custom 'app' > '{$backupLocation}'",
    ]);

    expect(parseCommandsByLineForSudo(collect($commands), $this->server))->toBe([
        "sudo mkdir -p '/data/coolify/backups/databases/team-1/db'",
        "sudo sh -c 'docker exec '\\''db'\\'' pg_dump --format=custom '\\''app'\\'' > '\\''{$backupLocation}'\\'''",
    ]);
});

it('stages clone archives in a random mktemp directory only for a non-root SSH user', function () {
    $stager = new class
    {
        use StagesCloneArchives {
            createCloneArchiveDirectory as public;
        }
    };
    $rootServer = Server::factory()->create(['team_id' => $this->server->team_id, 'user' => 'root', 'private_key_id' => $this->server->private_key_id]);
    Process::fake(fn ($process) => Process::result(
        output: str_contains($process->command, 'mktemp -d') ? '/var/tmp/coolify-clone.Ab3dE6gH9k' : '',
    ));

    $nonRootDirectory = $stager->createCloneArchiveDirectory($this->server, 'volume-a');
    $rootDirectory = $stager->createCloneArchiveDirectory($rootServer, 'volume-a');

    $commands = [];
    Process::assertRan(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return true;
    });
    $commands = implode("\n", $commands);

    expect($nonRootDirectory)->toBe('/var/tmp/coolify-clone.Ab3dE6gH9k')
        ->and($commands)->toContain('sudo mktemp -d /var/tmp/coolify-clone.XXXXXXXXXX')
        ->and($commands)->toContain("sudo chown 'cooluser' '/var/tmp/coolify-clone.Ab3dE6gH9k'")
        ->and($commands)->toContain("sudo chmod 700 '/var/tmp/coolify-clone.Ab3dE6gH9k'")
        ->and($commands)->not->toContain('/var/tmp/coolify-clone/')
        ->and($rootDirectory)->toBe('/data/coolify/clone/volume-a')
        ->and($commands)->toContain("mkdir -p '/data/coolify/clone/volume-a'")
        ->and($commands)->toContain("chmod 777 '/data/coolify/clone/volume-a'");
});

it('rejects a clone archive directory that mktemp did not create', function (string $output) {
    $stager = new class
    {
        use StagesCloneArchives {
            createCloneArchiveDirectory as public;
        }
    };
    Process::fake(fn ($process) => Process::result(
        output: str_contains($process->command, 'mktemp -d') ? $output : '',
    ));

    expect(fn () => $stager->createCloneArchiveDirectory($this->server, 'volume-a'))->toThrow(RuntimeException::class);
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'chown') || str_contains($process->command, 'chmod'));
})->with([
    'empty' => [''],
    'predictable path' => ['/var/tmp/coolify-clone/volume-a'],
    'traversal' => ['/var/tmp/coolify-clone.Ab3dE6gH9k/../../etc'],
    'other directory' => ['/tmp/coolify-clone.Ab3dE6gH9k'],
    'extra line' => ["/var/tmp/coolify-clone.Ab3dE6gH9k\n/etc"],
    'shell characters' => ['/var/tmp/coolify-clone.Ab3d$(id)k'],
]);
