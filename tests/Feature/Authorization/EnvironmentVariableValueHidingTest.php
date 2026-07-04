<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All as EnvironmentVariableAll;
use App\Livewire\Project\Shared\EnvironmentVariable\Show as EnvironmentVariableShow;
use App\Models\Application;
use App\Models\EnvironmentVariable;
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
    ]);

    $this->unlockedEnv = EnvironmentVariable::create([
        'key' => 'UNLOCKED_VAR',
        'value' => 'secret-unlocked-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
        'is_shown_once' => false,
        'is_multiline' => false,
        'is_literal' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    $this->lockedEnv = EnvironmentVariable::create([
        'key' => 'LOCKED_VAR',
        'value' => 'secret-locked-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
        'is_shown_once' => true,
        'is_multiline' => false,
        'is_literal' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);
});

// --- Livewire Show component: locked env values ---

test('admin sees unlocked env value in Show component', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EnvironmentVariableShow::class, [
        'env' => $this->unlockedEnv,
        'type' => 'application',
    ]);

    expect($component->get('value'))->toBe('secret-unlocked-value');
});

test('admin cannot see locked env value in Show component', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EnvironmentVariableShow::class, [
        'env' => $this->lockedEnv,
        'type' => 'application',
    ]);

    expect($component->get('value'))->toBeNull();
    expect($component->get('real_value'))->toBeNull();
});

test('member cannot see any env value in Show component', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EnvironmentVariableShow::class, [
        'env' => $this->unlockedEnv,
        'type' => 'application',
    ]);

    expect($component->get('value'))->toBeNull();
    expect($component->get('real_value'))->toBeNull();
});

test('member has isValueHidden flag set to true', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EnvironmentVariableShow::class, [
        'env' => $this->unlockedEnv,
        'type' => 'application',
    ]);

    expect($component->get('isValueHidden'))->toBeTrue();
});

test('admin has isValueHidden flag set to false', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EnvironmentVariableShow::class, [
        'env' => $this->unlockedEnv,
        'type' => 'application',
    ]);

    expect($component->get('isValueHidden'))->toBeFalse();
});

// --- Livewire All component: dev view ---

test('admin dev view shows unlocked env value', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EnvironmentVariableAll::class, [
        'resource' => $this->application,
    ]);

    expect($component->get('variables'))->toContain('UNLOCKED_VAR=secret-unlocked-value');
});

test('admin dev view hides locked env value', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EnvironmentVariableAll::class, [
        'resource' => $this->application,
    ]);

    expect($component->get('variables'))->toContain('LOCKED_VAR=(Locked Secret, delete and add again to change)');
    expect($component->get('variables'))->not->toContain('secret-locked-value');
});

test('member dev view hides all env values', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EnvironmentVariableAll::class, [
        'resource' => $this->application,
    ]);

    expect($component->get('variables'))->not->toContain('secret-unlocked-value');
    expect($component->get('variables'))->not->toContain('secret-locked-value');
    expect($component->get('variables'))->toContain('UNLOCKED_VAR=(Hidden');
});

// --- API: locked env values hidden ---

test('API hides locked env value even with read:sensitive token', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->admin->createToken('admin-sensitive', ['read', 'read:sensitive']);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ])->getJson("/api/v1/applications/{$this->application->uuid}/envs");

    $response->assertOk();

    $envs = collect($response->json());
    $locked = $envs->firstWhere('key', 'LOCKED_VAR');
    $unlocked = $envs->firstWhere('key', 'UNLOCKED_VAR');

    expect($locked)->not->toBeNull();
    expect($locked)->not->toHaveKey('value');
    expect($locked)->not->toHaveKey('real_value');

    expect($unlocked)->not->toBeNull();
    expect($unlocked)->toHaveKey('value');
});

test('API hides locked env value with root token', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->admin->createToken('admin-root', ['root']);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ])->getJson("/api/v1/applications/{$this->application->uuid}/envs");

    $response->assertOk();

    $envs = collect($response->json());
    $locked = $envs->firstWhere('key', 'LOCKED_VAR');

    expect($locked)->not->toBeNull();
    expect($locked)->not->toHaveKey('value');
    expect($locked)->not->toHaveKey('real_value');
});

// --- API: member role hides env values ---

test('API hides env values for member even with read:sensitive token', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->member->createToken('member-sensitive', ['read', 'read:sensitive']);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ])->getJson("/api/v1/applications/{$this->application->uuid}/envs");

    $response->assertOk();

    $envs = collect($response->json());
    $unlocked = $envs->firstWhere('key', 'UNLOCKED_VAR');

    expect($unlocked)->not->toBeNull();
    expect($unlocked)->not->toHaveKey('value');
    expect($unlocked)->not->toHaveKey('real_value');
});

test('API shows env values for admin with read:sensitive token', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->admin->createToken('admin-sensitive-2', ['read', 'read:sensitive']);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ])->getJson("/api/v1/applications/{$this->application->uuid}/envs");

    $response->assertOk();

    $envs = collect($response->json());
    $unlocked = $envs->firstWhere('key', 'UNLOCKED_VAR');

    expect($unlocked)->not->toBeNull();
    expect($unlocked)->toHaveKey('value');
});
