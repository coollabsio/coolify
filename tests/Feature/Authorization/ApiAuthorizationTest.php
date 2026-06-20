<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0], ['is_api_enabled' => true]);

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
    ]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments()->first();

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running',
    ]);

    $this->database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test DB',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'testdb',
        'image' => 'postgres:15',
        'status' => 'running',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    // Create real tokens with team_id (createToken reads from session)
    session(['currentTeam' => $this->team]);
    $this->adminRootToken = $this->admin->createToken('admin-root', ['root']);
    $this->adminReadToken = $this->admin->createToken('admin-read', ['read']);
    $this->memberRootToken = $this->member->createToken('member-root', ['root']);
});

// --- Unauthenticated Access ---

test('unauthenticated request to api returns 401', function () {
    $this->getJson('/api/v1/projects')->assertStatus(401);
});

// --- Admin with root token ---

test('admin with root token can list projects', function () {
    $this->withToken($this->adminRootToken->plainTextToken)
        ->getJson('/api/v1/projects')
        ->assertSuccessful();
});

test('admin with root token can view project', function () {
    $this->withToken($this->adminRootToken->plainTextToken)
        ->getJson("/api/v1/projects/{$this->project->uuid}")
        ->assertSuccessful();
});

test('admin with root token can view application', function () {
    $this->withToken($this->adminRootToken->plainTextToken)
        ->getJson("/api/v1/applications/{$this->application->uuid}")
        ->assertSuccessful();
});

test('admin with root token can view server', function () {
    $this->withToken($this->adminRootToken->plainTextToken)
        ->getJson("/api/v1/servers/{$this->server->uuid}")
        ->assertSuccessful();
});

test('admin with root token can view database', function () {
    $this->withToken($this->adminRootToken->plainTextToken)
        ->getJson("/api/v1/databases/{$this->database->uuid}")
        ->assertSuccessful();
});

// --- Member with root token (policy should deny mutations) ---

test('member with root token is blocked by middleware', function () {
    $this->withToken($this->memberRootToken->plainTextToken)
        ->getJson("/api/v1/projects/{$this->project->uuid}")
        ->assertStatus(403);
});

test('member with root token cannot delete project', function () {
    $this->withToken($this->memberRootToken->plainTextToken)
        ->deleteJson("/api/v1/projects/{$this->project->uuid}")
        ->assertStatus(403);
});

test('member with root token cannot update application', function () {
    $this->withToken($this->memberRootToken->plainTextToken)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'name' => 'New Name',
        ])
        ->assertStatus(403);
});

test('member with root token cannot delete application', function () {
    $this->withToken($this->memberRootToken->plainTextToken)
        ->deleteJson("/api/v1/applications/{$this->application->uuid}")
        ->assertStatus(403);
});

test('member with root token cannot delete server', function () {
    $this->withToken($this->memberRootToken->plainTextToken)
        ->deleteJson("/api/v1/servers/{$this->server->uuid}")
        ->assertStatus(403);
});

test('member with root token cannot update server', function () {
    $this->withToken($this->memberRootToken->plainTextToken)
        ->patchJson("/api/v1/servers/{$this->server->uuid}", [
            'name' => 'New Server Name',
        ])
        ->assertStatus(403);
});

// --- Token ability checks ---

test('read-only token cannot create project', function () {
    $this->withToken($this->adminReadToken->plainTextToken)
        ->postJson('/api/v1/projects', [
            'name' => 'New Project',
        ])
        ->assertStatus(403);
});

test('read-only token cannot delete application', function () {
    $this->withToken($this->adminReadToken->plainTextToken)
        ->deleteJson("/api/v1/applications/{$this->application->uuid}")
        ->assertStatus(403);
});

// --- Cross-team isolation ---

test('user from different team cannot view project via api', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);
    session(['currentTeam' => $otherTeam]);
    $otherToken = $otherUser->createToken('other-root', ['root']);

    $this->withToken($otherToken->plainTextToken)
        ->getJson("/api/v1/projects/{$this->project->uuid}")
        ->assertStatus(404);
});

test('user from different team cannot view application via api', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);
    session(['currentTeam' => $otherTeam]);
    $otherToken = $otherUser->createToken('other-root', ['root']);

    $this->withToken($otherToken->plainTextToken)
        ->getJson("/api/v1/applications/{$this->application->uuid}")
        ->assertStatus(404);
});

test('user from different team cannot view server via api', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);
    session(['currentTeam' => $otherTeam]);
    $otherToken = $otherUser->createToken('other-root', ['root']);

    $this->withToken($otherToken->plainTextToken)
        ->getJson("/api/v1/servers/{$this->server->uuid}")
        ->assertStatus(404);
});
