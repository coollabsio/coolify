<?php

use App\Actions\Database\StartDatabaseImport;
use App\Enums\ProcessStatus;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Support\DatabaseImport\DatabaseImportException;
use App\Support\DatabaseImport\DatabaseImportSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
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

function startImport(object $database, int $teamId, string $path = 'backup.sql'): Activity
{
    return app(StartDatabaseImport::class)->handle(
        $database,
        new DatabaseImportSource('server', path: $path),
        $teamId,
    );
}

test('a held import lock rejects a second start before restore commands run', function () {
    $lock = Cache::lock(StartDatabaseImport::lockKey($this->database->uuid), StartDatabaseImport::LOCK_SECONDS);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => startImport($this->database, $this->team->id))
            ->toThrow(DatabaseImportException::class, 'A database import is already running.');
    } finally {
        $lock->release();
    }
});

test('an already queued import is still rejected after the lock is acquired', function () {
    Activity::create([
        'log_name' => 'default',
        'description' => 'queued',
        'properties' => [
            'team_id' => $this->team->id,
            'type_uuid' => $this->database->uuid,
            'operation' => 'database_import',
            'status' => ProcessStatus::QUEUED->value,
        ],
    ]);

    expect(fn () => startImport($this->database, $this->team->id))
        ->toThrow(DatabaseImportException::class, 'A database import is already running.');
});

test('an in-progress import is rejected the same way as a queued import', function () {
    Activity::create([
        'log_name' => 'default',
        'description' => 'in progress',
        'properties' => [
            'team_id' => $this->team->id,
            'type_uuid' => $this->database->uuid,
            'operation' => 'database_import',
            'status' => ProcessStatus::IN_PROGRESS->value,
        ],
    ]);

    expect(fn () => startImport($this->database, $this->team->id))
        ->toThrow(DatabaseImportException::class, 'A database import is already running.');
});

test('a finished import does not hold the active-import guard', function () {
    Activity::create([
        'log_name' => 'default',
        'description' => 'finished',
        'properties' => [
            'team_id' => $this->team->id,
            'type_uuid' => $this->database->uuid,
            'operation' => 'database_import',
            'status' => ProcessStatus::FINISHED->value,
        ],
    ]);

    expect(fn () => startImport($this->database, $this->team->id))
        ->toThrow(DatabaseImportException::class, 'The server path is invalid.');
});

test('a failed start releases the import lock', function () {
    expect(fn () => startImport($this->database, $this->team->id))
        ->toThrow(DatabaseImportException::class, 'The server path is invalid.');

    $lock = Cache::lock(StartDatabaseImport::lockKey($this->database->uuid), StartDatabaseImport::LOCK_SECONDS);
    try {
        expect($lock->get())->toBeTrue();
    } finally {
        $lock->release();
    }
});
