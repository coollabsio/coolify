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
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
});

test('s3 import activity command does not contain storage key or secret', function () {
    $accessKey = 'AKIA_TEST_ACCESS_KEY_LEAK';
    $secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYTESTSECRET';
    $storage = S3Storage::create([
        'name' => 'Import S3',
        'region' => 'us-east-1',
        'key' => $accessKey,
        'secret' => $secret,
        'bucket' => 'test-bucket',
        'endpoint' => 'https://8.8.8.8',
        'is_usable' => true,
        'team_id' => $this->team->id,
    ]);

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->with('backups/restore.sql')->andReturn(true);
    $disk->shouldReceive('size')->once()->with('backups/restore.sql')->andReturn(1024);
    $filesystem = Mockery::mock(FilesystemManager::class, [app()])->makePartial();
    $filesystem->shouldReceive('build')->once()->andReturn($disk);
    Storage::swap($filesystem);

    Process::fake();
    Queue::fake();

    $activity = app(StartDatabaseImport::class)->handle(
        $this->database,
        new DatabaseImportSource('s3', path: 'backups/restore.sql', s3StorageUuid: $storage->uuid),
        $this->team->id,
    );

    $command = (string) $activity->getExtraProperty('command');

    expect($command)
        ->not->toContain($accessKey)
        ->not->toContain($secret)
        ->not->toContain('.env')
        ->not->toContain('S3_ACCESS_KEY=')
        ->not->toContain('S3_SECRET_KEY=')
        ->toContain('mc alias set s3temp "$S3_ENDPOINT" "$S3_ACCESS_KEY" "$S3_SECRET_KEY"');

    Queue::assertPushed(CoolifyTask::class, function (CoolifyTask $job) {
        $cleanup = $job->call_event_data;

        return is_array($cleanup)
            && ! array_key_exists('credentialTmpPath', $cleanup)
            && filled($cleanup['containerName'] ?? null);
    });
});
