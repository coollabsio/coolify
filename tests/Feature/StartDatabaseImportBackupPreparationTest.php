<?php

use App\Actions\Database\StartDatabaseImport;
use App\Jobs\CoolifyTask;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Support\DatabaseImport\DatabaseImportSource;
use App\Support\ResourceStartActivity;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('constants.ssh.mux_enabled', false);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'user' => 'root',
    ]);
    $this->destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17',
        'status' => 'running',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Queue::fake();
});

function importServerBackup(object $test, string $path, string $magicHex): string
{
    Process::fake([
        '*stat -c %s*' => Process::result('2048'),
        '*od -An -tx1*' => Process::result(' '.implode(' ', str_split($magicHex, 2))),
        '*' => Process::result(''),
    ]);

    $activity = app(StartDatabaseImport::class)->handle(
        $test->database,
        new DatabaseImportSource('server', path: $path),
        $test->team->id,
    );

    return (string) $activity->getExtraProperty('command');
}

test('plain and gzip server backups are copied into the database container unchanged', function (string $path, string $magicHex) {
    $command = importServerBackup($this, $path, $magicHex);

    expect($command)
        ->toContain("docker cp '{$path}' '{$this->database->uuid}:/tmp/restore_")
        ->not->toContain('backup-decompress-');
})->with([
    'plain SQL' => ['/srv/backups/app.sql', '2d2d20506f73'],
    'gzip' => ['/srv/backups/app.sql.gz', '1f8b08000000'],
    'custom archive' => ['/srv/backups/app.dmp', '5047444d5001'],
]);

test('bz2, xz, and zip server backups are prepared in the helper image', function (string $path, string $magicHex) {
    $command = importServerBackup($this, $path, $magicHex);

    expect($command)
        ->toContain("docker cp '{$path}' 'backup-decompress-")
        ->toContain('bunzip2 -c')
        ->toContain("cat /tmp/restore.prepared | docker exec -i '{$this->database->uuid}' sh -c")
        ->toContain("docker rm -f 'backup-decompress-")
        ->not->toContain("docker cp '{$path}' '{$this->database->uuid}:");

    Process::assertRan(fn ($process) => str_contains($process->command, "docker run -d --network none --name 'backup-decompress-"));
    Queue::assertPushed(CoolifyTask::class, fn (CoolifyTask $job) => str_starts_with((string) ($job->call_event_data['containerName'] ?? ''), 'backup-decompress-'));
})->with([
    'bz2' => ['/srv/backups/app.sql.bz2', '425a68393141'],
    'xz' => ['/srv/backups/app.sql.xz', 'fd377a585a00'],
    'zip' => ['/srv/backups/app.zip', '504b03041400'],
]);

test('uploaded backups use the same preparation as server backups', function (string $contents, bool $usesHelper) {
    Storage::fake();
    Storage::put("upload/{$this->database->uuid}/restore", $contents);
    Process::fake();

    $activity = app(StartDatabaseImport::class)->handle(
        $this->database,
        new DatabaseImportSource('upload'),
        $this->team->id,
    );
    $command = (string) $activity->getExtraProperty('command');

    expect(str_contains($command, "'backup-decompress-"))->toBe($usesHelper)
        ->and(str_contains($command, "docker cp '/tmp/database-import-"))->toBeTrue();
})->with([
    'plain SQL' => ["-- PostgreSQL database dump\nSELECT 1;\n", false],
    'gzip SQL' => [gzencode("SELECT 1;\n"), false],
    'bz2 SQL' => ["BZh91AY&SY\x00\x00", true],
]);

test('s3 backups are prepared in the s3 helper and streamed into the database container', function () {
    $storage = S3Storage::create([
        'name' => 'Import S3',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://8.8.8.8',
        'is_usable' => true,
        'team_id' => $this->team->id,
    ]);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->with('backups/restore.sql.xz')->andReturn(true);
    $disk->shouldReceive('size')->once()->with('backups/restore.sql.xz')->andReturn(1024);
    $filesystem = Mockery::mock(FilesystemManager::class, [app()])->makePartial();
    $filesystem->shouldReceive('build')->once()->andReturn($disk);
    Storage::swap($filesystem);
    Process::fake();

    $activity = app(StartDatabaseImport::class)->handle(
        $this->database,
        new DatabaseImportSource('s3', path: 'backups/restore.sql.xz', s3StorageUuid: $storage->uuid),
        $this->team->id,
    );
    $command = (string) $activity->getExtraProperty('command');

    expect($command)
        ->toContain('unxz -c')
        ->toContain("cat /tmp/restore.prepared | docker exec -i '{$this->database->uuid}' sh -c")
        ->not->toContain('/tmp/s3-restore-');

    Queue::assertPushed(CoolifyTask::class, fn (CoolifyTask $job) => ! array_key_exists('serverTmpPath', $job->call_event_data)
        && str_starts_with((string) $job->call_event_data['containerName'], 's3-restore-'));
});

test('the import activity is created with its operation, before the CoolifyTask job can load it', function () {
    $operationsAtCreation = [];
    Activity::created(function (Activity $activity) use (&$operationsAtCreation) {
        $operationsAtCreation[] = $activity->getExtraProperty('operation');
    });

    importServerBackup($this, '/srv/backups/app.sql', '2d2d20506f73');

    expect($operationsAtCreation)->toBe([ResourceStartActivity::DATABASE_IMPORT_OPERATION]);
    Queue::assertPushed(CoolifyTask::class);
});
