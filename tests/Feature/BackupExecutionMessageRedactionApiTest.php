<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const BACKUP_MESSAGE_DB_PASSWORD = 'supersecretdbpw';

beforeEach(function () {
    Queue::fake();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);

    $this->database = StandalonePostgresql::query()->create([
        'name' => 'backup-message-database',
        'postgres_password' => BACKUP_MESSAGE_DB_PASSWORD,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->backup = ScheduledDatabaseBackup::query()->create([
        'team_id' => $this->team->id,
        'frequency' => '0 0 * * *',
        'database_type' => $this->database->getMorphClass(),
        'database_id' => $this->database->id,
    ]);
    ScheduledDatabaseBackupExecution::query()->create([
        'scheduled_database_backup_id' => $this->backup->id,
        'status' => 'failed',
        'message' => 'pg_dump failed: password '.BACKUP_MESSAGE_DB_PASSWORD.' rejected',
    ]);

    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['read', 'read:sensitive'],
        'team_id' => $this->team->id,
    ]);
    $this->bearer = $token->getKey().'|'.$plainTextToken;
});

it('redacts the database password from backup execution messages', function (Closure $path, string $messageKey) {
    $response = $this->withToken($this->bearer)->getJson($path())->assertOk();

    expect($response->json($messageKey))->toBe('pg_dump failed: password '.REDACTED.' rejected');
})->with([
    'database list' => [fn () => '/api/v1/databases', '0.backup_configs.0.latest_log.message'],
    'database backups' => [fn () => '/api/v1/databases/'.test()->database->uuid.'/backups', '0.executions.0.message'],
    'backup executions' => [fn () => '/api/v1/databases/'.test()->database->uuid.'/backups/'.test()->backup->uuid.'/executions', 'executions.0.message'],
]);
