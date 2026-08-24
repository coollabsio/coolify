<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->tokens()->create(['name' => 'imports', 'token' => hash('sha256', 'secret'), 'abilities' => ['deploy', 'read'], 'team_id' => $this->team->id]);
    $this->headers = ['Authorization' => 'Bearer '.$this->token->id.'|secret'];
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::firstOrCreate(['server_id' => $this->server->id, 'network' => 'coolify'], ['uuid' => (string) Str::uuid(), 'name' => 'docker']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

test('validates standalone import source and hides foreign databases', function () {
    $database = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);

    $this->withHeaders($this->headers)->postJson("/api/v1/databases/{$database->uuid}/imports", ['source' => 'upload', 'path' => '../bad'])
        ->assertUnprocessable()->assertJsonValidationErrors(['upload_id', 'path']);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $foreign = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'foreign', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $otherEnvironment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);

    $this->withHeaders($this->headers)->postJson("/api/v1/databases/{$foreign->uuid}/imports", ['source' => 'server', 'path' => '/tmp/a.sql'])->assertNotFound();
});

test('requires deploy ability to start standalone import', function () {
    $database = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);
    $read = $this->user->createToken('read', ['read']);

    $this->withToken($read->plainTextToken)->postJson("/api/v1/databases/{$database->uuid}/imports", ['source' => 'server', 'path' => '/tmp/a.sql'])->assertForbidden();
});

test('returns only a team and resource scoped import activity', function () {
    $database = StandalonePostgresql::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db', 'image' => 'postgres:17', 'status' => 'running', 'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass()]);
    $activity = Activity::create(['log_name' => 'default', 'description' => json_encode([['order' => 1, 'output' => 'restored', 'type' => 'stdout']]), 'properties' => ['team_id' => $this->team->id, 'type_uuid' => $database->uuid, 'operation' => 'database_import', 'status' => 'finished', 'exitCode' => 0]]);

    $this->withHeaders($this->headers)->getJson("/api/v1/databases/{$database->uuid}/imports/{$activity->id}")
        ->assertOk()->assertJson(['id' => $activity->id, 'status' => 'finished', 'exit_code' => 0, 'output' => 'restored'])
        ->assertJsonMissingPath('command');

    $activity->properties = $activity->properties->merge(['team_id' => $this->team->id + 1]);
    $activity->save();
    $this->withHeaders($this->headers)->getJson("/api/v1/databases/{$database->uuid}/imports/{$activity->id}")->assertNotFound();
});
