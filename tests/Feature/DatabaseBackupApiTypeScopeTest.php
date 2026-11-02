<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMysql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $common = [
        'id' => 7,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ];

    $this->mysql = StandaloneMysql::forceCreate([...$common, 'name' => 'mysql', 'mysql_root_password' => 'root', 'mysql_password' => 'pw']);
    $this->mariadb = StandaloneMariadb::forceCreate([...$common, 'name' => 'mariadb', 'mariadb_root_password' => 'root', 'mariadb_password' => 'pw']);

    $this->mariadbBackup = ScheduledDatabaseBackup::query()->create([
        'team_id' => $this->team->id,
        'frequency' => '0 0 * * *',
        'database_type' => $this->mariadb->getMorphClass(),
        'database_id' => $this->mariadb->id,
    ]);
    ScheduledDatabaseBackupExecution::query()->create([
        'scheduled_database_backup_id' => $this->mariadbBackup->id,
        'status' => 'success',
    ]);

    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['root'],
        'team_id' => $this->team->id,
    ]);
    $this->bearer = $token->getKey().'|'.$plainTextToken;
});

it('lists only the backups of the requested database type', function () {
    expect($this->mysql->id)->toBe($this->mariadb->id);

    $this->withToken($this->bearer)->getJson("/api/v1/databases/{$this->mysql->uuid}/backups")
        ->assertOk()
        ->assertExactJson([]);

    $this->withToken($this->bearer)->getJson("/api/v1/databases/{$this->mariadb->uuid}/backups")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.uuid', $this->mariadbBackup->uuid);
});

it('does not expose a backup of another database type with the same id', function (string $method, Closure $path, array $payload = []) {
    $this->withToken($this->bearer)->json($method, $path(), $payload)->assertNotFound();

    expect($this->mariadbBackup->fresh())->not->toBeNull()
        ->and($this->mariadbBackup->fresh()->frequency)->toBe('0 0 * * *')
        ->and($this->mariadbBackup->executions()->count())->toBe(1);
})->with([
    'list executions' => ['GET', fn () => '/api/v1/databases/'.test()->mysql->uuid.'/backups/'.test()->mariadbBackup->uuid.'/executions'],
    'update backup' => ['PATCH', fn () => '/api/v1/databases/'.test()->mysql->uuid.'/backups/'.test()->mariadbBackup->uuid, ['frequency' => '0 1 * * *']],
    'delete backup' => ['DELETE', fn () => '/api/v1/databases/'.test()->mysql->uuid.'/backups/'.test()->mariadbBackup->uuid],
    'delete execution' => ['DELETE', fn () => '/api/v1/databases/'.test()->mysql->uuid.'/backups/'.test()->mariadbBackup->uuid.'/executions/'.test()->mariadbBackup->executions()->first()->uuid],
]);
