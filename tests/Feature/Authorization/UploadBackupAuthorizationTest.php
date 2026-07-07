<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Once;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    Once::flush();

    config(['app.maintenance.driver' => 'file']);

    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

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

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running',
    ]);

    $this->service = Service::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'test-service',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => 'version: "3"',
    ]);

    $this->serviceDatabase = ServiceDatabase::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'test-service-db',
        'service_id' => $this->service->id,
    ]);
});

// --- DatabasePolicy::uploadBackup (covers Standalone* databases) ---

test('admin can upload backup for standalone database', function () {
    expect($this->admin->can('uploadBackup', $this->database))->toBeTrue();
});

test('member cannot upload backup for standalone database', function () {
    expect($this->member->can('uploadBackup', $this->database))->toBeFalse();
});

// --- ApplicationPolicy::uploadBackup ---

test('admin can upload backup for application', function () {
    expect($this->admin->can('uploadBackup', $this->application))->toBeTrue();
});

test('member cannot upload backup for application', function () {
    expect($this->member->can('uploadBackup', $this->application))->toBeFalse();
});

// --- ServicePolicy::uploadBackup ---

test('admin can upload backup for service', function () {
    expect($this->admin->can('uploadBackup', $this->service))->toBeTrue();
});

test('member cannot upload backup for service', function () {
    expect($this->member->can('uploadBackup', $this->service))->toBeFalse();
});

// --- ServiceDatabasePolicy::uploadBackup (delegates to ServicePolicy) ---

test('admin can upload backup for service database', function () {
    expect($this->admin->can('uploadBackup', $this->serviceDatabase))->toBeTrue();
});

test('member cannot upload backup for service database', function () {
    expect($this->member->can('uploadBackup', $this->serviceDatabase))->toBeFalse();
});

// --- Cross-team isolation ---

test('user from different team cannot upload backup', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);

    expect($otherUser->can('uploadBackup', $this->database))->toBeFalse();
    expect($otherUser->can('uploadBackup', $this->application))->toBeFalse();
    expect($otherUser->can('uploadBackup', $this->service))->toBeFalse();
    expect($otherUser->can('uploadBackup', $this->serviceDatabase))->toBeFalse();
});

// --- HTTP endpoint: POST /upload/backup/{uuid} ---

test('member gets 403 from POST /upload/backup and no file lands on disk', function () {
    $uploadDir = storage_path('app/upload/'.$this->database->uuid);
    if (File::exists($uploadDir)) {
        File::deleteDirectory($uploadDir);
    }

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $response = $this->withHeader('Accept', 'application/json')
        ->post(route('upload.backup', ['databaseUuid' => $this->database->uuid]));

    $response->assertForbidden();

    expect(File::exists($uploadDir.'/restore'))->toBeFalse();
});

test('user from different team hits null-resource branch with 500', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);

    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    $response = $this->post(route('upload.backup', ['databaseUuid' => $this->database->uuid]));

    $response->assertStatus(500);
});

test('unauthenticated request is redirected to login', function () {
    $response = $this->post(route('upload.backup', ['databaseUuid' => $this->database->uuid]));

    $response->assertRedirect('/login');
});
