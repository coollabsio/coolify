<?php

use App\Actions\Service\DeployServiceApplication;
use App\Actions\Service\RestartServiceApplication;
use App\Actions\Service\StopServiceApplication;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\ScheduledTaskJob;
use App\Jobs\VolumeBackupJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('cache.default', 'array');
    Server::flushIdentityMap();
    Process::fake();
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true, 'is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->own = mcpOpsSeedTeam($this->team);

    $this->otherTeam = Team::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->otherTeam->members()->attach($this->otherUser->id, ['role' => 'owner']);
    $this->other = mcpOpsSeedTeam($this->otherTeam);

    session(['currentTeam' => $this->team]);
    Queue::fake();
});

/**
 * @return array<string, mixed>
 */
function mcpOpsSeedTeam(Team $team): array
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destinationAttributes = [
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ];

    $application = Application::factory()->create($destinationAttributes);
    $database = StandalonePostgresql::create([
        'name' => 'ops-postgres-'.$team->id,
        'image' => 'postgres:17-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'db-password-'.$team->id,
        'postgres_db' => 'postgres',
        ...$destinationAttributes,
    ]);
    $service = Service::factory()->create($destinationAttributes);
    $serviceApplication = ServiceApplication::create([
        'uuid' => new_public_id(),
        'name' => 'ops-service-app',
        'image' => 'nginx:alpine',
        'service_id' => $service->id,
    ]);
    $serviceDatabase = ServiceDatabase::create([
        'uuid' => new_public_id(),
        'name' => 'ops-service-db',
        'image' => 'postgres:17-alpine',
        'service_id' => $service->id,
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => $application->uuid.'-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    $s3 = S3Storage::create([
        'name' => 'ops-s3-'.$team->id,
        'region' => 'us-east-1',
        'key' => 'S3KEY'.$team->id,
        'secret' => 'S3SECRET'.$team->id,
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);

    return compact('server', 'destination', 'project', 'environment', 'application', 'database', 'service', 'serviceApplication', 'serviceDatabase', 'volume', 's3');
}

function mcpOpsToken(User $user, array $abilities = ['read', 'write']): string
{
    return $user->createToken('mcp-ops', $abilities)->plainTextToken;
}

function mcpOpsCall(string $token, string $tool, array $arguments = [])
{
    auth()->forgetGuards();

    return test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => (object) $arguments],
    ]);
}

function mcpOpsText($response): string
{
    return (string) ($response->json('result.content.0.text') ?? $response->json('error.message'));
}

function mcpOpsIsError($response): bool
{
    return $response->json('result.isError') === true || $response->json('error') !== null;
}

function mcpOpsMember(Team $team): User
{
    $member = User::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);

    return $member;
}

// ---------------------------------------------------------------- control

test('control starts, restarts, and stops a service application and service database of the token team', function () {
    $token = mcpOpsToken($this->user, ['read', 'deploy']);

    $start = mcpOpsCall($token, 'control', ['resource' => 'service_application', 'action' => 'start', 'service_uuid' => $this->own['service']->uuid, 'uuid' => $this->own['serviceApplication']->uuid]);
    expect(mcpOpsIsError($start))->toBeFalse()
        ->and(mcpOpsText($start))->toContain('deploy request queued');
    DeployServiceApplication::assertPushed();

    $restart = mcpOpsCall($token, 'control', ['resource' => 'service_database', 'action' => 'restart', 'service_uuid' => $this->own['service']->uuid, 'uuid' => $this->own['serviceDatabase']->uuid]);
    expect(mcpOpsIsError($restart))->toBeFalse()
        ->and(mcpOpsText($restart))->toContain('restart request queued');
    RestartServiceApplication::assertPushed();

    $stop = mcpOpsCall($token, 'control', ['resource' => 'service_application', 'action' => 'stop', 'service_uuid' => $this->own['service']->uuid, 'uuid' => $this->own['serviceApplication']->uuid, 'confirm' => true]);
    expect(mcpOpsIsError($stop))->toBeFalse();
    StopServiceApplication::assertPushed();
});

test('control rejects service children of another team, also under an own service_uuid', function (string $resource, string $childKey, bool $ownParent) {
    $token = mcpOpsToken($this->user, ['read', 'deploy']);
    $parent = $ownParent ? $this->own['service'] : $this->other['service'];

    $response = mcpOpsCall($token, 'control', ['resource' => $resource, 'action' => 'restart', 'service_uuid' => $parent->uuid, 'uuid' => $this->other[$childKey]->uuid]);

    expect(mcpOpsIsError($response))->toBeTrue()
        ->and(mcpOpsText($response))->toContain('not found');
    Queue::assertNothingPushed();
})->with([
    'foreign app, own service' => ['service_application', 'serviceApplication', true],
    'foreign app, foreign service' => ['service_application', 'serviceApplication', false],
    'foreign db, own service' => ['service_database', 'serviceDatabase', true],
    'foreign db, foreign service' => ['service_database', 'serviceDatabase', false],
]);

test('control service child stop needs confirm and service_uuid', function () {
    $token = mcpOpsToken($this->user, ['read', 'deploy']);

    $noConfirm = mcpOpsCall($token, 'control', ['resource' => 'service_application', 'action' => 'stop', 'service_uuid' => $this->own['service']->uuid, 'uuid' => $this->own['serviceApplication']->uuid]);
    expect(mcpOpsIsError($noConfirm))->toBeTrue()
        ->and(mcpOpsText($noConfirm))->toContain('confirm=true');

    $noParent = mcpOpsCall($token, 'control', ['resource' => 'service_database', 'action' => 'start', 'uuid' => $this->own['serviceDatabase']->uuid]);
    expect(mcpOpsIsError($noParent))->toBeTrue()
        ->and(mcpOpsText($noParent))->toContain('service_uuid');

    Queue::assertNothingPushed();
});

test('control service child rejects read-only and member tokens', function () {
    $args = ['resource' => 'service_application', 'action' => 'restart', 'service_uuid' => $this->own['service']->uuid, 'uuid' => $this->own['serviceApplication']->uuid];

    expect(mcpOpsIsError(mcpOpsCall(mcpOpsToken($this->user, ['read', 'write']), 'control', $args)))->toBeTrue();
    expect(mcpOpsIsError(mcpOpsCall(mcpOpsToken(mcpOpsMember($this->team), ['read', 'deploy']), 'control', $args)))->toBeTrue();

    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------- deploy by tag

test('deploy by tag deploys only the token team resources with that tag', function () {
    $ownTag = Tag::create(['name' => 'prod', 'team_id' => $this->team->id]);
    $this->own['application']->tags()->attach($ownTag->id);
    $foreignTag = Tag::create(['name' => 'prod', 'team_id' => $this->otherTeam->id]);
    $this->other['application']->tags()->attach($foreignTag->id);

    $response = mcpOpsCall(mcpOpsToken($this->user, ['read', 'deploy']), 'deploy', ['tag' => 'prod', 'confirm' => true]);

    expect(mcpOpsIsError($response))->toBeFalse()
        ->and(mcpOpsText($response))->toContain($this->own['application']->uuid)
        ->and(ApplicationDeploymentQueue::where('application_id', $this->own['application']->id)->count())->toBe(1)
        ->and(ApplicationDeploymentQueue::where('application_id', $this->other['application']->id)->count())->toBe(0);
});

test('deploy by a tag that only another team has deploys nothing', function () {
    $foreignTag = Tag::create(['name' => 'theirs', 'team_id' => $this->otherTeam->id]);
    $this->other['application']->tags()->attach($foreignTag->id);

    $response = mcpOpsCall(mcpOpsToken($this->user, ['read', 'deploy']), 'deploy', ['tag' => 'theirs', 'confirm' => true]);

    expect(mcpOpsIsError($response))->toBeTrue()
        ->and(ApplicationDeploymentQueue::count())->toBe(0);
});

test('deploy by tag needs confirm and exactly one of uuid or tag', function (array $args, string $message) {
    $tag = Tag::create(['name' => 'prod', 'team_id' => $this->team->id]);
    $this->own['application']->tags()->attach($tag->id);
    $args = array_map(fn ($value) => $value === 'APP' ? $this->own['application']->uuid : $value, $args);

    $response = mcpOpsCall(mcpOpsToken($this->user, ['read', 'deploy']), 'deploy', $args);

    expect(mcpOpsIsError($response))->toBeTrue()
        ->and(mcpOpsText($response))->toContain($message)
        ->and(ApplicationDeploymentQueue::count())->toBe(0);
})->with([
    'no confirm' => [['tag' => 'prod'], 'confirm=true'],
    'both' => [['tag' => 'prod', 'uuid' => 'APP', 'confirm' => true], 'exactly one'],
    'neither' => [[], 'exactly one'],
    'tag with pull request' => [['tag' => 'prod', 'pull_request_id' => 3, 'confirm' => true], 'pull_request_id'],
]);

test('deploy by tag rejects read-only and member tokens', function () {
    $tag = Tag::create(['name' => 'prod', 'team_id' => $this->team->id]);
    $this->own['application']->tags()->attach($tag->id);

    expect(mcpOpsIsError(mcpOpsCall(mcpOpsToken($this->user, ['read', 'write']), 'deploy', ['tag' => 'prod', 'confirm' => true])))->toBeTrue();
    expect(mcpOpsIsError(mcpOpsCall(mcpOpsToken(mcpOpsMember($this->team), ['read', 'deploy']), 'deploy', ['tag' => 'prod', 'confirm' => true])))->toBeTrue();
    expect(ApplicationDeploymentQueue::count())->toBe(0);
});

// ---------------------------------------------------------------- manage_scheduled_task

test('manage_scheduled_task creates, updates, executes, and deletes tasks of the token team', function (string $resource, string $key) {
    $token = mcpOpsToken($this->user, ['read', 'write', 'deploy']);
    $owner = $this->own[$key];

    $created = mcpOpsCall($token, 'manage_scheduled_task', ['action' => 'create', 'resource' => $resource, 'uuid' => $owner->uuid, 'data' => ['name' => 'cleanup', 'command' => 'echo SECRETCMD', 'frequency' => 'daily']]);
    expect(mcpOpsIsError($created))->toBeFalse()
        ->and(mcpOpsText($created))->not->toContain('SECRETCMD');
    $task = ScheduledTask::where('name', 'cleanup')->sole();
    expect($task->team_id)->toBe($this->team->id)
        ->and($task->{$resource.'_id'})->toBe($owner->id);

    $updated = mcpOpsCall($token, 'manage_scheduled_task', ['action' => 'update', 'resource' => $resource, 'uuid' => $owner->uuid, 'task_uuid' => $task->uuid, 'data' => ['frequency' => 'hourly']]);
    expect(mcpOpsIsError($updated))->toBeFalse()
        ->and($task->fresh()->frequency)->toBe('hourly');

    $executed = mcpOpsCall($token, 'manage_scheduled_task', ['action' => 'execute', 'resource' => $resource, 'uuid' => $owner->uuid, 'task_uuid' => $task->uuid, 'confirm' => true]);
    expect(mcpOpsIsError($executed))->toBeFalse();
    Queue::assertPushed(ScheduledTaskJob::class);

    $deleted = mcpOpsCall($token, 'manage_scheduled_task', ['action' => 'delete', 'resource' => $resource, 'uuid' => $owner->uuid, 'task_uuid' => $task->uuid, 'confirm' => true]);
    expect(mcpOpsIsError($deleted))->toBeFalse()
        ->and($task->fresh())->toBeNull();
})->with([
    'application' => ['application', 'application'],
    'service' => ['service', 'service'],
]);

test('manage_scheduled_task cannot reach tasks or resources of another team', function (string $action, bool $ownParent) {
    $foreignTask = ScheduledTask::factory()->create(['team_id' => $this->otherTeam->id, 'application_id' => $this->other['application']->id, 'name' => 'theirs']);
    $parent = $ownParent ? $this->own['application'] : $this->other['application'];

    $response = mcpOpsCall(mcpOpsToken($this->user, ['read', 'write', 'deploy']), 'manage_scheduled_task', [
        'action' => $action, 'resource' => 'application', 'uuid' => $parent->uuid, 'task_uuid' => $foreignTask->uuid,
        'confirm' => true, 'data' => $action === 'create' ? ['name' => 'evil', 'command' => 'id', 'frequency' => 'daily'] : ($action === 'update' ? ['name' => 'hijacked'] : []),
    ]);

    expect(mcpOpsIsError($response))->toBeTrue()
        ->and($foreignTask->fresh()?->name)->toBe('theirs')
        ->and(ScheduledTask::where('name', 'evil')->exists())->toBeFalse();
    Queue::assertNotPushed(ScheduledTaskJob::class);
})->with([
    'create on foreign app' => ['create', false],
    'update via own app' => ['update', true],
    'update via foreign app' => ['update', false],
    'delete via own app' => ['delete', true],
    'execute via own app' => ['execute', true],
    'execute via foreign app' => ['execute', false],
]);

test('manage_scheduled_task needs confirm for delete and execute and rejects read-only tokens', function () {
    $task = ScheduledTask::factory()->create(['team_id' => $this->team->id, 'application_id' => $this->own['application']->id]);
    $token = mcpOpsToken($this->user, ['read', 'write', 'deploy']);

    foreach (['delete', 'execute'] as $action) {
        $response = mcpOpsCall($token, 'manage_scheduled_task', ['action' => $action, 'resource' => 'application', 'uuid' => $this->own['application']->uuid, 'task_uuid' => $task->uuid]);
        expect(mcpOpsIsError($response))->toBeTrue()
            ->and(mcpOpsText($response))->toContain('confirm=true');
    }

    $readOnly = mcpOpsCall(mcpOpsToken($this->user, ['read']), 'manage_scheduled_task', ['action' => 'execute', 'resource' => 'application', 'uuid' => $this->own['application']->uuid, 'task_uuid' => $task->uuid, 'confirm' => true]);
    expect(mcpOpsIsError($readOnly))->toBeTrue();

    $member = mcpOpsCall(mcpOpsToken(mcpOpsMember($this->team), ['read']), 'manage_scheduled_task', ['action' => 'delete', 'resource' => 'application', 'uuid' => $this->own['application']->uuid, 'task_uuid' => $task->uuid, 'confirm' => true]);
    expect(mcpOpsIsError($member))->toBeTrue();

    expect($task->fresh())->not->toBeNull();
    Queue::assertNotPushed(ScheduledTaskJob::class);
});

// ---------------------------------------------------------------- manage_backup

test('manage_backup creates, updates, runs, and deletes a backup schedule of the token team', function () {
    $token = mcpOpsToken($this->user, ['read', 'write', 'deploy']);
    $database = $this->own['database'];

    $created = mcpOpsCall($token, 'manage_backup', ['action' => 'create', 'uuid' => $database->uuid, 'data' => ['frequency' => 'daily', 'save_s3' => true, 's3_storage_uuid' => $this->own['s3']->uuid]]);
    expect(mcpOpsIsError($created))->toBeFalse()
        ->and(mcpOpsText($created))->not->toContain('S3SECRET')
        ->and(mcpOpsText($created))->not->toContain('S3KEY');
    $backup = ScheduledDatabaseBackup::where('database_id', $database->id)->sole();
    expect($backup->team_id)->toBe($this->team->id)
        ->and($backup->s3_storage_id)->toBe($this->own['s3']->id);

    $updated = mcpOpsCall($token, 'manage_backup', ['action' => 'update', 'uuid' => $database->uuid, 'scheduled_backup_uuid' => $backup->uuid, 'data' => ['frequency' => 'hourly', 'backup_now' => true]]);
    expect(mcpOpsIsError($updated))->toBeFalse()
        ->and($backup->fresh()->frequency)->toBe('hourly');
    Queue::assertPushed(DatabaseBackupJob::class);

    $execution = ScheduledDatabaseBackupExecution::create(['scheduled_database_backup_id' => $backup->id, 'database_name' => 'postgres', 'status' => 'success']);
    $deletedExecution = mcpOpsCall($token, 'manage_backup', ['action' => 'delete_execution', 'uuid' => $database->uuid, 'scheduled_backup_uuid' => $backup->uuid, 'execution_uuid' => $execution->uuid, 'confirm' => true]);
    expect(mcpOpsIsError($deletedExecution))->toBeFalse()
        ->and($execution->fresh())->toBeNull();

    $deleted = mcpOpsCall($token, 'manage_backup', ['action' => 'delete', 'uuid' => $database->uuid, 'scheduled_backup_uuid' => $backup->uuid, 'confirm' => true]);
    expect(mcpOpsIsError($deleted))->toBeFalse()
        ->and($backup->fresh())->toBeNull();
});

test('manage_backup rejects an S3 storage of another team', function () {
    $token = mcpOpsToken($this->user);
    $database = $this->own['database'];

    $created = mcpOpsCall($token, 'manage_backup', ['action' => 'create', 'uuid' => $database->uuid, 'data' => ['frequency' => 'daily', 'save_s3' => true, 's3_storage_uuid' => $this->other['s3']->uuid]]);
    expect(mcpOpsIsError($created))->toBeTrue()
        ->and(ScheduledDatabaseBackup::where('database_id', $database->id)->exists())->toBeFalse();

    $backup = ScheduledDatabaseBackup::create(['team_id' => $this->team->id, 'frequency' => 'daily', 'database_id' => $database->id, 'database_type' => $database->getMorphClass()]);
    $updated = mcpOpsCall($token, 'manage_backup', ['action' => 'update', 'uuid' => $database->uuid, 'scheduled_backup_uuid' => $backup->uuid, 'data' => ['save_s3' => true, 's3_storage_uuids' => [$this->other['s3']->uuid], 'frequency' => 'hourly']]);
    expect(mcpOpsIsError($updated))->toBeTrue()
        ->and($backup->fresh()->s3_storage_id)->toBeNull()
        ->and($backup->fresh()->frequency)->toBe('daily')
        ->and($backup->fresh()->s3Storages()->exists())->toBeFalse();
});

test('manage_backup cannot reach backups of another team', function (string $action, bool $ownParent) {
    $foreignDatabase = $this->other['database'];
    $foreignBackup = ScheduledDatabaseBackup::create(['team_id' => $this->otherTeam->id, 'frequency' => 'daily', 'database_id' => $foreignDatabase->id, 'database_type' => $foreignDatabase->getMorphClass()]);
    $foreignExecution = ScheduledDatabaseBackupExecution::create(['scheduled_database_backup_id' => $foreignBackup->id, 'database_name' => 'postgres', 'status' => 'success']);
    $parent = $ownParent ? $this->own['database'] : $foreignDatabase;

    $response = mcpOpsCall(mcpOpsToken($this->user), 'manage_backup', [
        'action' => $action, 'uuid' => $parent->uuid, 'scheduled_backup_uuid' => $foreignBackup->uuid, 'execution_uuid' => $foreignExecution->uuid,
        'confirm' => true, 'data' => $action === 'delete' || $action === 'delete_execution' ? [] : ['frequency' => 'hourly', 'backup_now' => true],
    ]);

    expect(mcpOpsIsError($response))->toBeTrue()
        ->and($foreignBackup->fresh()?->frequency)->toBe('daily')
        ->and($foreignExecution->fresh())->not->toBeNull()
        ->and(ScheduledDatabaseBackup::where('team_id', $this->team->id)->exists())->toBeFalse();
    Queue::assertNotPushed(DatabaseBackupJob::class);
})->with([
    'create on foreign db' => ['create', false],
    'update via own db' => ['update', true],
    'update via foreign db' => ['update', false],
    'delete via own db' => ['delete', true],
    'delete execution via own db' => ['delete_execution', true],
    'delete execution via foreign db' => ['delete_execution', false],
]);

test('manage_backup needs confirm for deletes and rejects read-only tokens', function () {
    $database = $this->own['database'];
    $backup = ScheduledDatabaseBackup::create(['team_id' => $this->team->id, 'frequency' => 'daily', 'database_id' => $database->id, 'database_type' => $database->getMorphClass()]);
    $execution = ScheduledDatabaseBackupExecution::create(['scheduled_database_backup_id' => $backup->id, 'database_name' => 'postgres', 'status' => 'success']);
    $token = mcpOpsToken($this->user);

    foreach (['delete', 'delete_execution'] as $action) {
        $response = mcpOpsCall($token, 'manage_backup', ['action' => $action, 'uuid' => $database->uuid, 'scheduled_backup_uuid' => $backup->uuid, 'execution_uuid' => $execution->uuid]);
        expect(mcpOpsIsError($response))->toBeTrue()
            ->and(mcpOpsText($response))->toContain('confirm=true');
    }

    $readOnly = mcpOpsCall(mcpOpsToken($this->user, ['read']), 'manage_backup', ['action' => 'create', 'uuid' => $database->uuid, 'data' => ['frequency' => 'daily']]);
    expect(mcpOpsIsError($readOnly))->toBeTrue()
        ->and($backup->fresh())->not->toBeNull()
        ->and($execution->fresh())->not->toBeNull()
        ->and(ScheduledDatabaseBackup::count())->toBe(1);
});

// ---------------------------------------------------------------- manage_storage

test('manage_storage creates, updates, and deletes storages of the token team', function () {
    $token = mcpOpsToken($this->user);
    $application = $this->own['application'];

    $created = mcpOpsCall($token, 'manage_storage', ['action' => 'create', 'resource' => 'application', 'uuid' => $application->uuid, 'data' => ['type' => 'persistent', 'name' => 'cache', 'mount_path' => '/cache']]);
    expect(mcpOpsIsError($created))->toBeFalse();
    $volume = LocalPersistentVolume::where('name', $application->uuid.'-cache')->sole();

    $updated = mcpOpsCall($token, 'manage_storage', ['action' => 'update', 'resource' => 'application', 'uuid' => $application->uuid, 'data' => ['type' => 'persistent', 'uuid' => $volume->uuid, 'mount_path' => '/cache2']]);
    expect(mcpOpsIsError($updated))->toBeFalse()
        ->and($volume->fresh()->mount_path)->toBe('/cache2');

    $dbCreated = mcpOpsCall($token, 'manage_storage', ['action' => 'create', 'resource' => 'database', 'uuid' => $this->own['database']->uuid, 'data' => ['type' => 'persistent', 'name' => 'extra', 'mount_path' => '/extra']]);
    expect(mcpOpsIsError($dbCreated))->toBeFalse();

    $serviceCreated = mcpOpsCall($token, 'manage_storage', ['action' => 'create', 'resource' => 'service', 'uuid' => $this->own['service']->uuid, 'data' => ['type' => 'persistent', 'name' => 'svc', 'mount_path' => '/svc', 'resource_uuid' => $this->own['serviceApplication']->uuid]]);
    expect(mcpOpsIsError($serviceCreated))->toBeFalse()
        ->and(LocalPersistentVolume::where('resource_id', $this->own['serviceApplication']->id)->where('mount_path', '/svc')->exists())->toBeTrue();

    $deleted = mcpOpsCall($token, 'manage_storage', ['action' => 'delete', 'resource' => 'application', 'uuid' => $application->uuid, 'storage_uuid' => $volume->uuid, 'confirm' => true]);
    expect(mcpOpsIsError($deleted))->toBeFalse()
        ->and($volume->fresh())->toBeNull();
});

test('manage_storage sets, runs, and removes a volume backup without returning S3 secrets', function () {
    $token = mcpOpsToken($this->user, ['read', 'write', 'deploy']);
    $args = ['resource' => 'application', 'uuid' => $this->own['application']->uuid, 'storage_uuid' => $this->own['volume']->uuid];

    $set = mcpOpsCall($token, 'manage_storage', [...$args, 'action' => 'set_backup', 'data' => ['frequency' => 'daily', 'save_s3' => true, 's3_storage_uuid' => $this->own['s3']->uuid]]);
    expect(mcpOpsIsError($set))->toBeFalse()
        ->and(mcpOpsText($set))->not->toContain('S3SECRET')
        ->and(mcpOpsText($set))->not->toContain('S3KEY');
    $backup = ScheduledVolumeBackup::query()->sole();
    expect($backup->s3_storage_id)->toBe($this->own['s3']->id);

    $run = mcpOpsCall($token, 'manage_storage', [...$args, 'action' => 'run_backup', 'confirm' => true]);
    expect(mcpOpsIsError($run))->toBeFalse();
    Queue::assertPushed(VolumeBackupJob::class);

    $removed = mcpOpsCall($token, 'manage_storage', [...$args, 'action' => 'remove_backup', 'confirm' => true]);
    expect(mcpOpsIsError($removed))->toBeFalse()
        ->and($backup->fresh())->toBeNull();
});

test('manage_storage rejects an S3 storage and a service child of another team', function () {
    $token = mcpOpsToken($this->user);

    $set = mcpOpsCall($token, 'manage_storage', ['action' => 'set_backup', 'resource' => 'application', 'uuid' => $this->own['application']->uuid, 'storage_uuid' => $this->own['volume']->uuid, 'data' => ['frequency' => 'daily', 'save_s3' => true, 's3_storage_uuid' => $this->other['s3']->uuid]]);
    expect(mcpOpsIsError($set))->toBeTrue()
        ->and(ScheduledVolumeBackup::query()->exists())->toBeFalse();

    $create = mcpOpsCall($token, 'manage_storage', ['action' => 'create', 'resource' => 'service', 'uuid' => $this->own['service']->uuid, 'data' => ['type' => 'persistent', 'name' => 'evil', 'mount_path' => '/evil', 'resource_uuid' => $this->other['serviceApplication']->uuid]]);
    expect(mcpOpsIsError($create))->toBeTrue()
        ->and(LocalPersistentVolume::where('mount_path', '/evil')->exists())->toBeFalse();
});

test('manage_storage cannot reach storages of another team', function (string $action, bool $ownParent) {
    $foreignVolume = $this->other['volume'];
    $foreignVolume->scheduledBackups()->create(['team_id' => $this->otherTeam->id, 'frequency' => 'daily']);
    $parent = $ownParent ? $this->own['application'] : $this->other['application'];

    $response = mcpOpsCall(mcpOpsToken($this->user, ['read', 'write', 'deploy']), 'manage_storage', [
        'action' => $action, 'resource' => 'application', 'uuid' => $parent->uuid, 'storage_uuid' => $foreignVolume->uuid, 'confirm' => true,
        'data' => match ($action) {
            'create' => ['type' => 'persistent', 'name' => 'evil', 'mount_path' => '/evil'],
            'update' => ['type' => 'persistent', 'uuid' => $foreignVolume->uuid, 'mount_path' => '/hijacked'],
            'set_backup' => ['frequency' => 'hourly'],
            default => [],
        },
    ]);

    expect(mcpOpsIsError($response))->toBeTrue()
        ->and($foreignVolume->fresh()?->mount_path)->toBe('/data')
        ->and($foreignVolume->scheduledBackups()->sole()->frequency)->toBe('daily')
        ->and(LocalPersistentVolume::where('mount_path', '/evil')->exists())->toBeFalse();
    Queue::assertNotPushed(VolumeBackupJob::class);
})->with([
    'create on foreign app' => ['create', false],
    'update via own app' => ['update', true],
    'update via foreign app' => ['update', false],
    'delete via own app' => ['delete', true],
    'set backup via own app' => ['set_backup', true],
    'run backup via own app' => ['run_backup', true],
    'run backup via foreign app' => ['run_backup', false],
    'remove backup via own app' => ['remove_backup', true],
]);

test('manage_storage needs confirm for delete, remove_backup, and run_backup and rejects read-only tokens', function () {
    $this->own['volume']->scheduledBackups()->create(['team_id' => $this->team->id, 'frequency' => 'daily']);
    $args = ['resource' => 'application', 'uuid' => $this->own['application']->uuid, 'storage_uuid' => $this->own['volume']->uuid];
    $token = mcpOpsToken($this->user, ['read', 'write', 'deploy']);

    foreach (['delete', 'remove_backup', 'run_backup'] as $action) {
        $response = mcpOpsCall($token, 'manage_storage', [...$args, 'action' => $action]);
        expect(mcpOpsIsError($response))->toBeTrue()
            ->and(mcpOpsText($response))->toContain('confirm=true');
    }

    $readOnly = mcpOpsCall(mcpOpsToken($this->user, ['read']), 'manage_storage', [...$args, 'action' => 'delete', 'confirm' => true]);
    expect(mcpOpsIsError($readOnly))->toBeTrue()
        ->and($this->own['volume']->fresh())->not->toBeNull()
        ->and(ScheduledVolumeBackup::count())->toBe(1);
    Queue::assertNotPushed(VolumeBackupJob::class);
});

// ---------------------------------------------------------------- manage_tags

test('manage_tags creates, renames, attaches, detaches, and deletes tags of the token team', function () {
    $token = mcpOpsToken($this->user);

    $created = mcpOpsCall($token, 'manage_tags', ['action' => 'create', 'data' => ['name' => 'Web']]);
    expect(mcpOpsIsError($created))->toBeFalse();
    $tag = Tag::where('team_id', $this->team->id)->where('name', 'web')->sole();

    $renamed = mcpOpsCall($token, 'manage_tags', ['action' => 'update', 'tag_uuid' => $tag->uuid, 'data' => ['name' => 'frontend']]);
    expect(mcpOpsIsError($renamed))->toBeFalse()
        ->and($tag->fresh()->name)->toBe('frontend');

    foreach (['application', 'database', 'service'] as $resource) {
        $attached = mcpOpsCall($token, 'manage_tags', ['action' => 'attach', 'resource' => $resource, 'uuid' => $this->own[$resource]->uuid, 'data' => ['tag_name' => 'frontend']]);
        expect(mcpOpsIsError($attached))->toBeFalse()
            ->and($this->own[$resource]->tags()->whereKey($tag->id)->exists())->toBeTrue();
    }

    $detached = mcpOpsCall($token, 'manage_tags', ['action' => 'detach', 'resource' => 'application', 'uuid' => $this->own['application']->uuid, 'tag_uuid' => $tag->uuid]);
    expect(mcpOpsIsError($detached))->toBeFalse()
        ->and($this->own['application']->tags()->whereKey($tag->id)->exists())->toBeFalse();

    $deleted = mcpOpsCall($token, 'manage_tags', ['action' => 'delete', 'tag_uuid' => $tag->uuid, 'confirm' => true]);
    expect(mcpOpsIsError($deleted))->toBeFalse()
        ->and($tag->fresh())->toBeNull();
});

test('manage_tags attach never links a tag of another team', function () {
    $foreignTag = Tag::create(['name' => 'shared', 'team_id' => $this->otherTeam->id]);

    $response = mcpOpsCall(mcpOpsToken($this->user), 'manage_tags', ['action' => 'attach', 'resource' => 'application', 'uuid' => $this->own['application']->uuid, 'data' => ['tag_name' => 'shared']]);

    expect(mcpOpsIsError($response))->toBeFalse()
        ->and(mcpOpsText($response))->not->toContain($foreignTag->uuid)
        ->and($this->own['application']->tags()->whereKey($foreignTag->id)->exists())->toBeFalse()
        ->and($this->own['application']->tags()->sole()->team_id)->toBe($this->team->id);
});

test('manage_tags cannot change tags or resources of another team', function (string $action) {
    $foreignTag = Tag::create(['name' => 'theirs', 'team_id' => $this->otherTeam->id]);
    $this->other['application']->tags()->attach($foreignTag->id);
    $ownTag = Tag::create(['name' => 'mine', 'team_id' => $this->team->id]);
    $this->own['application']->tags()->attach($ownTag->id);

    $args = match ($action) {
        'update' => ['tag_uuid' => $foreignTag->uuid, 'data' => ['name' => 'hijacked']],
        'delete' => ['tag_uuid' => $foreignTag->uuid],
        'attach to foreign app' => ['resource' => 'application', 'uuid' => $this->other['application']->uuid, 'data' => ['tag_name' => 'evil']],
        'detach foreign tag from own app' => ['resource' => 'application', 'uuid' => $this->own['application']->uuid, 'tag_uuid' => $foreignTag->uuid],
        'detach from foreign app' => ['resource' => 'application', 'uuid' => $this->other['application']->uuid, 'tag_uuid' => $foreignTag->uuid],
    };
    $toolAction = str_starts_with($action, 'attach') ? 'attach' : (str_starts_with($action, 'detach') ? 'detach' : $action);

    $response = mcpOpsCall(mcpOpsToken($this->user), 'manage_tags', ['action' => $toolAction, 'confirm' => true, ...$args]);

    expect(mcpOpsIsError($response))->toBeTrue()
        ->and($foreignTag->fresh()?->name)->toBe('theirs')
        ->and($this->other['application']->tags()->whereKey($foreignTag->id)->exists())->toBeTrue()
        ->and(Tag::where('name', 'evil')->exists())->toBeFalse();
})->with([
    'update',
    'delete',
    'attach to foreign app',
    'detach foreign tag from own app',
    'detach from foreign app',
]);

test('manage_tags delete needs confirm and write actions reject read-only and member tokens', function () {
    $tag = Tag::create(['name' => 'mine', 'team_id' => $this->team->id]);

    $noConfirm = mcpOpsCall(mcpOpsToken($this->user), 'manage_tags', ['action' => 'delete', 'tag_uuid' => $tag->uuid]);
    expect(mcpOpsIsError($noConfirm))->toBeTrue()
        ->and(mcpOpsText($noConfirm))->toContain('confirm=true');

    $readOnly = mcpOpsCall(mcpOpsToken($this->user, ['read']), 'manage_tags', ['action' => 'create', 'data' => ['name' => 'nope']]);
    expect(mcpOpsIsError($readOnly))->toBeTrue();

    $member = mcpOpsCall(mcpOpsToken(mcpOpsMember($this->team), ['read', 'write']), 'manage_tags', ['action' => 'create', 'data' => ['name' => 'nope']]);
    expect(mcpOpsIsError($member))->toBeTrue();

    expect($tag->fresh())->not->toBeNull()
        ->and(Tag::where('name', 'nope')->exists())->toBeFalse();
});
