<?php

use App\Livewire\Project\Application\Heading as ApplicationHeading;
use App\Livewire\Project\Service\Heading as ServiceHeading;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

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

    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
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

    $this->service = Service::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Service',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => 'version: "3"',
    ]);

    $this->serviceParams = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'service_uuid' => $this->service->uuid,
    ];

    $this->databaseUrl = "/project/{$this->project->uuid}/environment/{$this->environment->uuid}/database/{$this->database->uuid}";
});

// --- Application Heading ---

test('admin sees deploy controls for application', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationHeading::class, ['application' => $this->application])
        ->assertSee('Redeploy')
        ->assertSee('Restart')
        ->assertSee('Stop');
});

test('member cannot call deploy on application', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationHeading::class, ['application' => $this->application])
        ->call('deploy')
        ->assertDispatched('error');
});

test('member cannot call restart on application', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationHeading::class, ['application' => $this->application])
        ->call('restart')
        ->assertDispatched('error');
});

test('member cannot call stop on application', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationHeading::class, ['application' => $this->application])
        ->call('stop')
        ->assertDispatched('error');
});

test('member does not see terminal link for application', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationHeading::class, ['application' => $this->application])
        ->assertDontSee('Terminal');
});

test('admin sees terminal link for application', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationHeading::class, ['application' => $this->application])
        ->assertSee('Terminal');
});

// --- Database Heading (via page route for rendering, policy checks for actions) ---

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

test('admin can manage database', function () {
    expect($this->admin->can('manage', $this->database))->toBeTrue();
});

test('member cannot manage database', function () {
    expect($this->member->can('manage', $this->database))->toBeFalse();
});

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

// --- Service Heading ---

test('admin sees service deploy button', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServiceHeading::class, [
        'service' => $this->service,
        'parameters' => $this->serviceParams,
        'query' => [],
    ])
        ->assertSee('Deploy');
});

test('member cannot call stop on service', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServiceHeading::class, [
        'service' => $this->service,
        'parameters' => $this->serviceParams,
        'query' => [],
    ])
        ->call('stop')
        ->assertDispatched('error');
});

test('member does not see terminal link for service', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServiceHeading::class, [
        'service' => $this->service,
        'parameters' => $this->serviceParams,
        'query' => [],
    ])
        ->assertDontSee('Terminal');
});

test('admin sees terminal link for service', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServiceHeading::class, [
        'service' => $this->service,
        'parameters' => $this->serviceParams,
        'query' => [],
    ])
        ->assertSee('Terminal');
});
