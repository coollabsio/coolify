<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();

    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->destinationA = StandaloneDocker::where('server_id', $this->serverA->id)->first();
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->envA = Environment::factory()->create(['project_id' => $this->projectA->id]);
});

test('queryDatabaseByUuidWithinTeam returns database when team owns it', function () {
    $database = StandalonePostgresql::create([
        'name' => 'pg-team-a',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $this->envA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => $this->destinationA->getMorphClass(),
    ]);

    $found = queryDatabaseByUuidWithinTeam($database->uuid, (string) $this->teamA->id);

    expect($found)->not->toBeNull();
    expect($found->uuid)->toBe($database->uuid);
    expect($found)->toBeInstanceOf(StandalonePostgresql::class);
});

test('queryDatabaseByUuidWithinTeam returns null when team does not own the database', function () {
    $database = StandalonePostgresql::create([
        'name' => 'pg-team-a',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $this->envA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => $this->destinationA->getMorphClass(),
    ]);

    $found = queryDatabaseByUuidWithinTeam($database->uuid, (string) $this->teamB->id);

    expect($found)->toBeNull();
});

test('queryDatabaseByUuidWithinTeam returns null for unknown uuid', function () {
    $found = queryDatabaseByUuidWithinTeam('does-not-exist', (string) $this->teamA->id);

    expect($found)->toBeNull();
});

test('queryDatabaseByUuidWithinTeam can query every registered standalone database type without error', function () {
    foreach (STANDALONE_DATABASE_MODELS as $slug => $modelClass) {
        $count = $modelClass::query()->whereUuid('non-existent-uuid')->count();
        expect($count)->toBe(0, "{$modelClass} ({$slug}) failed whereUuid() smoke query");
    }
});
