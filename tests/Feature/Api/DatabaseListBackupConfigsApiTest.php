<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $token = $this->user->createToken('database-list-backups', ['read']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->bearerToken = $token->plainTextToken;

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $placement = [
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ];

    $this->postgres = StandalonePostgresql::create([
        'name' => 'shared-id-postgres',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'app',
        'image' => 'postgres:16-alpine',
        ...$placement,
    ]);
    $this->mysql = StandaloneMysql::forceCreate([
        'id' => $this->postgres->id,
        'name' => 'shared-id-mysql',
        'mysql_root_password' => 'password',
        'mysql_password' => 'password',
        'mysql_user' => 'mysql',
        'mysql_database' => 'app',
        'image' => 'mysql:8',
        ...$placement,
    ]);
});

test('database list matches backup configs by database type and id', function () {
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'database_type' => $this->postgres->getMorphClass(),
        'database_id' => $this->postgres->id,
        'team_id' => $this->team->id,
    ]);

    $databases = collect(
        $this->withToken($this->bearerToken)->getJson('/api/v1/databases')->assertOk()->json()
    )->keyBy('uuid');

    expect($this->mysql->id)->toBe($this->postgres->id)
        ->and($databases[$this->postgres->uuid]['backup_configs'])->toHaveCount(1)
        ->and($databases[$this->postgres->uuid]['backup_configs'][0]['uuid'])->toBe($backup->uuid)
        ->and($databases[$this->mysql->uuid]['backup_configs'])->toBe([]);
});
