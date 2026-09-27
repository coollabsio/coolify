<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartKeydb;
use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Actions\Database\StopDatabase;
use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Events\DatabaseStatusChanged;
use App\Exceptions\DatabaseStartException;
use App\Jobs\DatabaseStartJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $this->destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17',
        'status' => 'exited',
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

/**
 * A functional server that has no CA certificate and cannot generate one.
 */
function serverThatCannotProvideCaCertificate(int $teamId): Server
{
    $server = new class extends Server
    {
        public function isFunctional()
        {
            return true;
        }

        public function ensureCaCertificate(): ?SslCertificate
        {
            return null;
        }
    };

    return $server->forceFill(['id' => 999, 'uuid' => 'server-without-ca', 'team_id' => $teamId]);
}

function queuedDatabaseStartActivity(string $databaseUuid, int $teamId): Activity
{
    return activity()
        ->withProperties([
            'type' => ActivityTypes::INLINE->value,
            'type_uuid' => $databaseUuid,
            'status' => ProcessStatus::QUEUED->value,
            'team_id' => $teamId,
            'operation' => 'database-start',
        ])
        ->event(ActivityTypes::INLINE->value)
        ->log('[]');
}

dataset('ssl database start actions', [
    'postgresql' => [StartPostgresql::class, StandalonePostgresql::class],
    'mysql' => [StartMysql::class, StandaloneMysql::class],
    'mariadb' => [StartMariadb::class, StandaloneMariadb::class],
    'mongodb' => [StartMongodb::class, StandaloneMongodb::class],
    'redis' => [StartRedis::class, StandaloneRedis::class],
    'keydb' => [StartKeydb::class, StandaloneKeydb::class],
    'dragonfly' => [StartDragonfly::class, StandaloneDragonfly::class],
]);

it('throws instead of silently returning when an SSL database has no CA certificate', function (string $action, string $model) {
    Bus::fake();

    $database = (new $model)->forceFill(['id' => 1, 'uuid' => 'ssl-db-uuid', 'enable_ssl' => true]);
    $destination = new StandaloneDocker;
    $destination->setRelation('server', serverThatCannotProvideCaCertificate($this->team->id));
    $database->setRelation('destination', $destination);

    expect(fn () => app($action)->handle($database))
        ->toThrow(DatabaseStartException::class, 'No CA certificate found');

    // The old code queued a broken copy of the action via $this->dispatch('error', ...).
    Bus::assertNothingDispatched();
})->with('ssl database start actions');

it('marks the activity as failed with the start error when the job fails', function () {
    Event::fake([DatabaseStatusChanged::class]);
    $activity = queuedDatabaseStartActivity($this->database->uuid, $this->team->id);

    $job = new DatabaseStartJob(StandalonePostgresql::class, $this->database->id, $this->team->id, $activity->id, null);
    $job->failed(DatabaseStartException::missingCaCertificate());

    $activity->refresh();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($activity, 'properties.error'))->toContain('No CA certificate found')
        ->and(data_get($activity, 'properties.exitCode'))->toBe(1)
        ->and(RunRemoteProcess::decodeOutput($activity))->toContain('No CA certificate found');
});

it('keeps a generic message for unexpected job failures', function () {
    Event::fake([DatabaseStatusChanged::class]);
    $activity = queuedDatabaseStartActivity($this->database->uuid, $this->team->id);

    $job = new DatabaseStartJob(StandalonePostgresql::class, $this->database->id, $this->team->id, $activity->id, null);
    $job->failed(new RuntimeException('SQLSTATE internal details'));

    $activity->refresh();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($activity, 'properties.error'))->toBe('Database start failed.')
        ->and(RunRemoteProcess::decodeOutput($activity))->not->toContain('SQLSTATE');
});

it('fails the queued activity when the start action throws inside the job', function () {
    Event::fake([DatabaseStatusChanged::class]);
    StartPostgresql::shouldRun()->andThrow(DatabaseStartException::missingCaCertificate());
    $activity = queuedDatabaseStartActivity($this->database->uuid, $this->team->id);

    expect(fn () => DatabaseStartJob::dispatchSync(StandalonePostgresql::class, $this->database->id, $this->team->id, $activity->id, null))
        ->toThrow(DatabaseStartException::class);

    $activity->refresh();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($activity, 'properties.error'))->toContain('No CA certificate found');
});

it('fails the activity when the start action returns without running the start commands', function () {
    Event::fake([DatabaseStatusChanged::class]);
    StartPostgresql::shouldRun()->andReturnNull();
    $activity = queuedDatabaseStartActivity($this->database->uuid, $this->team->id);

    expect(fn () => DatabaseStartJob::dispatchSync(StandalonePostgresql::class, $this->database->id, $this->team->id, $activity->id, null))
        ->toThrow(DatabaseStartException::class);

    $activity->refresh();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value);
});

it('marks the activity as failed and rethrows when queueing the start job fails', function () {
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('Queue connection refused'));

    expect(fn () => StartDatabase::run($this->database))
        ->toThrow(RuntimeException::class, 'Queue connection refused');

    $activity = Activity::query()->where('properties->type_uuid', $this->database->uuid)->sole();
    expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($activity, 'properties.error'))->toBe('Database start could not be queued.')
        ->and(data_get($activity, 'properties.exitCode'))->toBe(1);
});

it('returns an immediate error without queueing when SSL is enabled and no CA certificate is available', function () {
    Bus::fake();
    $this->database->forceFill(['enable_ssl' => true])->save();
    $destination = new StandaloneDocker;
    $destination->setRelation('server', serverThatCannotProvideCaCertificate($this->team->id));
    $this->database->setRelation('destination', $destination);

    $result = StartDatabase::run($this->database);

    expect($result)->toBeString()->toContain('No CA certificate found');
    expect(Activity::query()->where('properties->type_uuid', $this->database->uuid)->exists())->toBeFalse();
    Bus::assertNotDispatched(DatabaseStartJob::class);
});

it('does not stop the database on restart when the start prerequisites are missing', function () {
    Bus::fake();
    StopDatabase::shouldRun()->never();
    $this->database->forceFill(['enable_ssl' => true])->save();
    $destination = new StandaloneDocker;
    $destination->setRelation('server', serverThatCannotProvideCaCertificate($this->team->id));
    $this->database->setRelation('destination', $destination);

    $result = RestartDatabase::run($this->database);

    expect($result)->toBeString()->toContain('No CA certificate found');
});

it('queues the start when SSL is enabled and the server already has a CA certificate', function () {
    Bus::fake();
    $this->database->forceFill(['enable_ssl' => true])->save();
    SslCertificate::create([
        'ssl_certificate' => 'ca-cert',
        'ssl_private_key' => 'ca-key',
        'common_name' => 'Coolify CA Certificate',
        'valid_until' => now()->addYear(),
        'is_ca_certificate' => true,
        'server_id' => $this->server->id,
    ]);

    $result = StartDatabase::run($this->database);

    expect($result)->toBeInstanceOf(Activity::class);
    Bus::assertDispatched(DatabaseStartJob::class);
});
