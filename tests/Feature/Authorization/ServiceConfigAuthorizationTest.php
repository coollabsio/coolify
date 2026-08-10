<?php

use App\Livewire\Project\Service\Heading as ServiceHeading;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
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
});

// --- Service Policy: view ---

test('admin can view service', function () {
    expect($this->admin->can('view', $this->service))->toBeTrue();
});

test('member can view service', function () {
    expect($this->member->can('view', $this->service))->toBeTrue();
});

// --- Service Policy: update ---

test('admin can update service', function () {
    expect($this->admin->can('update', $this->service))->toBeTrue();
});

test('member cannot update service', function () {
    expect($this->member->can('update', $this->service))->toBeFalse();
});

// --- Service Policy: delete ---

test('admin can delete service', function () {
    expect($this->admin->can('delete', $this->service))->toBeTrue();
});

test('member cannot delete service', function () {
    expect($this->member->can('delete', $this->service))->toBeFalse();
});

// --- Service Policy: deploy ---

test('admin can deploy service', function () {
    expect($this->admin->can('deploy', $this->service))->toBeTrue();
});

test('member cannot deploy service', function () {
    expect($this->member->can('deploy', $this->service))->toBeFalse();
});

// --- Service Policy: stop ---

test('admin can stop service', function () {
    expect($this->admin->can('stop', $this->service))->toBeTrue();
});

test('member cannot stop service', function () {
    expect($this->member->can('stop', $this->service))->toBeFalse();
});

// --- Service Policy: manageEnvironment ---

test('admin can manage service environment variables', function () {
    expect($this->admin->can('manageEnvironment', $this->service))->toBeTrue();
});

test('member cannot manage service environment variables', function () {
    expect($this->member->can('manageEnvironment', $this->service))->toBeFalse();
});

// --- Service Heading Livewire actions ---

test('member cannot call start on service heading', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServiceHeading::class, [
        'service' => $this->service,
        'parameters' => $this->serviceParams,
        'query' => [],
    ])
        ->call('start')
        ->assertDispatched('error');
});

test('member cannot call stop on service heading', function () {
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

test('member cannot call restart on service heading', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServiceHeading::class, [
        'service' => $this->service,
        'parameters' => $this->serviceParams,
        'query' => [],
    ])
        ->call('restart')
        ->assertDispatched('error');
});

test('member cannot call force deploy on service heading', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServiceHeading::class, [
        'service' => $this->service,
        'parameters' => $this->serviceParams,
        'query' => [],
    ])
        ->call('forceDeploy')
        ->assertDispatched('error');
});

// --- Service Heading visibility ---

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

test('admin sees deploy button for service', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServiceHeading::class, [
        'service' => $this->service,
        'parameters' => $this->serviceParams,
        'query' => [],
    ])
        ->assertSee('Deploy');
});

// --- Cross-team isolation ---

test('user from different team cannot view service', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);

    expect($otherUser->can('view', $this->service))->toBeFalse();
});

test('user from different team cannot update service', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);

    expect($otherUser->can('update', $this->service))->toBeFalse();
});
