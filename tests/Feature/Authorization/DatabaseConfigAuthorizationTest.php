<?php

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
    InstanceSettings::updateOrCreate(['id' => 0]);

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

    $this->databaseUrl = "/project/{$this->project->uuid}/environment/{$this->environment->uuid}/database/{$this->database->uuid}";
});

// --- Database Policy: view ---

test('admin can view database', function () {
    expect($this->admin->can('view', $this->database))->toBeTrue();
});

test('member can view database', function () {
    expect($this->member->can('view', $this->database))->toBeTrue();
});

// --- Database Policy: update ---

test('admin can update database', function () {
    expect($this->admin->can('update', $this->database))->toBeTrue();
});

test('member cannot update database', function () {
    expect($this->member->can('update', $this->database))->toBeFalse();
});

// --- Database Policy: delete ---

test('admin can delete database', function () {
    expect($this->admin->can('delete', $this->database))->toBeTrue();
});

test('member cannot delete database', function () {
    expect($this->member->can('delete', $this->database))->toBeFalse();
});

// --- Database Policy: manage ---

test('admin can manage database', function () {
    expect($this->admin->can('manage', $this->database))->toBeTrue();
});

test('member cannot manage database', function () {
    expect($this->member->can('manage', $this->database))->toBeFalse();
});

// --- Database Policy: manageBackups ---

test('admin can manage database backups', function () {
    expect($this->admin->can('manageBackups', $this->database))->toBeTrue();
});

test('member cannot manage database backups', function () {
    expect($this->member->can('manageBackups', $this->database))->toBeFalse();
});

// --- Database Policy: manageEnvironment ---

test('admin can manage database environment variables', function () {
    expect($this->admin->can('manageEnvironment', $this->database))->toBeTrue();
});

test('member cannot manage database environment variables', function () {
    expect($this->member->can('manageEnvironment', $this->database))->toBeFalse();
});

// --- Database page access ---

test('admin can access database page', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $this->get($this->databaseUrl)->assertSuccessful();
});

test('member can access database page', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $this->get($this->databaseUrl)->assertSuccessful();
});

// --- Terminal access ---

test('admin can access terminal for database', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('canAccessTerminal'))->toBeTrue();
});

test('member cannot access terminal for database', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('canAccessTerminal'))->toBeFalse();
});

// --- Cross-team isolation ---

test('user from different team cannot view database', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);

    expect($otherUser->can('view', $this->database))->toBeFalse();
});

test('user from different team cannot update database', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);

    expect($otherUser->can('update', $this->database))->toBeFalse();
});

test('user from different team cannot manage database', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);

    expect($otherUser->can('manage', $this->database))->toBeFalse();
});
