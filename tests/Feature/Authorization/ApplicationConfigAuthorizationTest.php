<?php

use App\Livewire\Project\Application\Advanced as ApplicationAdvanced;
use App\Livewire\Project\Application\Heading as ApplicationHeading;
use App\Livewire\Project\Application\Rollback as ApplicationRollback;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
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
});

// --- Application Policy: view ---

test('admin can view application', function () {
    expect($this->admin->can('view', $this->application))->toBeTrue();
});

test('member can view application', function () {
    expect($this->member->can('view', $this->application))->toBeTrue();
});

// --- Application Policy: update ---

test('admin can update application', function () {
    expect($this->admin->can('update', $this->application))->toBeTrue();
});

test('member cannot update application', function () {
    expect($this->member->can('update', $this->application))->toBeFalse();
});

// --- Application Policy: deploy ---

test('admin can deploy application', function () {
    expect($this->admin->can('deploy', $this->application))->toBeTrue();
});

test('member cannot deploy application', function () {
    expect($this->member->can('deploy', $this->application))->toBeFalse();
});

// --- Application Policy: delete ---

test('admin can delete application', function () {
    expect($this->admin->can('delete', $this->application))->toBeTrue();
});

test('member cannot delete application', function () {
    expect($this->member->can('delete', $this->application))->toBeFalse();
});

// --- Application Policy: manageEnvironment ---

test('admin can manage application environment', function () {
    expect($this->admin->can('manageEnvironment', $this->application))->toBeTrue();
});

test('member cannot manage application environment', function () {
    expect($this->member->can('manageEnvironment', $this->application))->toBeFalse();
});

// --- Application Heading Livewire actions ---

test('member cannot call deploy on application heading', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationHeading::class, ['application' => $this->application])
        ->call('deploy')
        ->assertDispatched('error');
});

test('member cannot call restart on application heading', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationHeading::class, ['application' => $this->application])
        ->call('restart')
        ->assertDispatched('error');
});

test('member cannot call stop on application heading', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationHeading::class, ['application' => $this->application])
        ->call('stop')
        ->assertDispatched('error');
});

test('member cannot call force deploy on application heading', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationHeading::class, ['application' => $this->application])
        ->call('force_deploy_without_cache')
        ->assertDispatched('error');
});

// --- Application General policy (Livewire mount requires full app data) ---

test('member cannot update application general settings', function () {
    expect($this->member->can('update', $this->application))->toBeFalse();
});

// --- Application Advanced Livewire actions ---

test('member cannot save application advanced settings', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationAdvanced::class, ['application' => $this->application])
        ->call('instantSave')
        ->assertDispatched('error');
});

test('member cannot submit application advanced settings', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationAdvanced::class, ['application' => $this->application])
        ->call('submit')
        ->assertDispatched('error');
});

// --- Application Rollback Livewire actions ---

test('member cannot save rollback settings', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationRollback::class, ['application' => $this->application])
        ->call('saveSettings')
        ->assertDispatched('error');
});

test('member cannot rollback image', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApplicationRollback::class, ['application' => $this->application])
        ->call('rollbackImage', 'test-image:latest')
        ->assertForbidden();
});

// --- Application Heading visibility ---

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

// --- Cross-team isolation ---

test('user from different team cannot view application', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);

    expect($otherUser->can('view', $this->application))->toBeFalse();
});

test('user from different team cannot update application', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);

    expect($otherUser->can('update', $this->application))->toBeFalse();
});
