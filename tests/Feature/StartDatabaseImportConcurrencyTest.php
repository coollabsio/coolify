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
use App\Support\DatabaseOperationReservation;
use App\Support\ResourceStartActivity;
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

function importActivity(string $databaseUuid, int $teamId, ProcessStatus $status, int $minutesSinceLastUpdate = 0): Activity
{
    $activity = Activity::create([
        'log_name' => 'default',
        'description' => '[]',
        'properties' => [
            'team_id' => $teamId,
            'type_uuid' => $databaseUuid,
            'operation' => 'database_import',
            'status' => $status->value,
        ],
    ]);

    Activity::query()->whereKey($activity->id)->update([
        'created_at' => now()->subMinutes($minutesSinceLastUpdate),
        'updated_at' => now()->subMinutes($minutesSinceLastUpdate),
    ]);

    return $activity->refresh();
}

test('an in-progress import that is silent for a long time but within the limit still blocks', function () {
    $minutes = intdiv(ResourceStartActivity::importStaleAfterSeconds(), 60) - 5;
    $activity = importActivity($this->database->uuid, $this->team->id, ProcessStatus::IN_PROGRESS, $minutes);

    expect(fn () => startImport($this->database, $this->team->id))
        ->toThrow(DatabaseImportException::class, 'A database import is already running.');

    expect(data_get($activity->refresh(), 'properties.status'))->toBe(ProcessStatus::IN_PROGRESS->value);
});

test('a stale import does not block a new import and is marked as failed', function (ProcessStatus $status) {
    $minutes = intdiv(ResourceStartActivity::importStaleAfterSeconds(), 60) + 5;
    $stale = importActivity($this->database->uuid, $this->team->id, $status, $minutes);

    // The guard lets the import through, so it fails later on the invalid path instead of with 409.
    expect(fn () => startImport($this->database, $this->team->id))
        ->toThrow(DatabaseImportException::class, 'The server path is invalid.');

    $stale->refresh();
    expect(data_get($stale, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($stale, 'properties.error'))->toBe(ResourceStartActivity::STALE_MESSAGE.' '.ResourceStartActivity::IMPORT_PARTLY_RESTORED_MESSAGE)
        ->and(data_get($stale, 'properties.exitCode'))->toBe(1);
})->with([
    'queued' => ProcessStatus::QUEUED,
    'in progress' => ProcessStatus::IN_PROGRESS,
]);

test('the stale import limit follows a raised SSH command timeout', function () {
    config()->set('constants.ssh.command_timeout', 5 * 3600);

    expect(ResourceStartActivity::importStaleAfterSeconds())->toBeGreaterThan(5 * 3600);
});

test('an import interrupted by a Coolify restart is failed at boot and no longer blocks a new import', function () {
    $interrupted = importActivity($this->database->uuid, $this->team->id, ProcessStatus::IN_PROGRESS);

    expect(ResourceStartActivity::failInterrupted())->toBe(1);

    $interrupted->refresh();
    expect(data_get($interrupted, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($interrupted, 'properties.error'))->toBe(ResourceStartActivity::INTERRUPTED_MESSAGE.' '.ResourceStartActivity::IMPORT_PARTLY_RESTORED_MESSAGE);

    expect(fn () => startImport($this->database, $this->team->id))
        ->toThrow(DatabaseImportException::class, 'The server path is invalid.');
});

test('an import is rejected while a start or restart of the database waits in the queue', function () {
    $token = DatabaseOperationReservation::acquire($this->database->uuid);

    try {
        expect(fn () => startImport($this->database, $this->team->id))
            ->toThrow(DatabaseImportException::class, ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE);
    } finally {
        DatabaseOperationReservation::release($this->database->uuid, $token);
    }
});

test('the 409 status is used when a pending start blocks an import', function () {
    $token = DatabaseOperationReservation::acquire($this->database->uuid);

    try {
        startImport($this->database, $this->team->id);
        $this->fail('The import was not rejected.');
    } catch (DatabaseImportException $exception) {
        expect($exception->status)->toBe(409);
    } finally {
        DatabaseOperationReservation::release($this->database->uuid, $token);
    }
});

test('an import is rejected while a start of the database is running', function (ProcessStatus $status) {
    Activity::create([
        'log_name' => 'default',
        'description' => '[]',
        'properties' => [
            'team_id' => $this->team->id,
            'type_uuid' => $this->database->uuid,
            'operation' => ResourceStartActivity::DATABASE_START_OPERATION,
            'status' => $status->value,
        ],
    ]);

    expect(fn () => startImport($this->database, $this->team->id))
        ->toThrow(DatabaseImportException::class, ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE);
})->with([
    'queued' => ProcessStatus::QUEUED,
    'in progress' => ProcessStatus::IN_PROGRESS,
]);

test('an import holds the operation reservation and releases it afterwards', function () {
    expect(fn () => startImport($this->database, $this->team->id))
        ->toThrow(DatabaseImportException::class, 'The server path is invalid.');

    expect(Cache::has(DatabaseOperationReservation::key($this->database->uuid)))->toBeFalse();
});
