<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All as EnvironmentVariableAll;
use App\Livewire\Project\Shared\EnvironmentVariable\Show as EnvironmentVariableShow;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

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

    // Values hydrate lazily; the edit modal triggers loadValues() on open.
    expect($component->get('value'))->toBeNull();

    $component->call('loadValues');

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
    ])->call('switch');

    expect($component->get('variables'))->toContain('UNLOCKED_VAR=secret-unlocked-value');
});

test('admin dev view hides locked env value', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EnvironmentVariableAll::class, [
        'resource' => $this->application,
    ])->call('switch');

    expect($component->get('variables'))->toContain('LOCKED_VAR=(Locked Secret, delete and add again to change)');
    expect($component->get('variables'))->not->toContain('secret-locked-value');
});

test('member dev view hides all env values', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EnvironmentVariableAll::class, [
        'resource' => $this->application,
    ])->call('switch');

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

test('API hides the value of the automatic preview copy of a locked env', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->admin->createToken('admin-sensitive-preview', ['read', 'read:sensitive', 'write']);
    $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];

    $this->withHeaders($headers)->postJson("/api/v1/applications/{$this->application->uuid}/envs", [
        'key' => 'API_LOCKED_VAR',
        'value' => 'api-secret-value',
        'is_shown_once' => true,
    ])->assertCreated();

    $envs = collect($this->withHeaders($headers)
        ->getJson("/api/v1/applications/{$this->application->uuid}/envs")
        ->assertOk()
        ->json())
        ->where('key', 'API_LOCKED_VAR');

    expect($envs)->toHaveCount(2);
    expect($envs->pluck('is_preview')->sort()->values()->all())->toBe([false, true]);
    $envs->each(function (array $env) {
        expect($env['is_shown_once'])->toBeTruthy();
        expect($env)->not->toHaveKey('value');
        expect($env)->not->toHaveKey('real_value');
    });
});

// --- API: member role hides env values ---

test('API hides env values for member even with read:sensitive token', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->member->createToken('member-sensitive', ['read', 'read:sensitive']);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ])->getJson("/api/v1/applications/{$this->application->uuid}/envs");

    $response->assertForbidden();
});

test('API hides env values for member with read token', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->member->createToken('member-read', ['read']);

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

// --- Session team differs from the resource team ---

test('member dev view hides values after switching to a team they own', function () {
    $ownTeam = Team::factory()->create();
    $this->member->teams()->attach($ownTeam, ['role' => 'owner']);
    $this->actingAs($this->member);
    session(['currentTeam' => $ownTeam]);

    $component = Livewire::test(EnvironmentVariableAll::class, [
        'resource' => $this->application,
    ])->call('switch');

    expect($component->get('variables'))->not->toContain('secret-unlocked-value')
        ->and($component->get('variables'))->toContain('UNLOCKED_VAR=(Hidden');
    $component->assertDontSee('secret-unlocked-value');
});

test('admin dev view shows values while the session is on a team where they are a member', function () {
    $otherTeam = Team::factory()->create();
    $this->admin->teams()->attach($otherTeam, ['role' => 'member']);
    $this->actingAs($this->admin);
    session(['currentTeam' => $otherTeam]);

    $component = Livewire::test(EnvironmentVariableAll::class, [
        'resource' => $this->application,
    ])->call('switch');

    expect($component->get('variables'))->toContain('UNLOCKED_VAR=secret-unlocked-value');
});

test('user outside the resource team cannot open the dev view', function () {
    $outsider = User::factory()->create();
    $outsiderTeam = Team::factory()->create();
    $outsider->teams()->attach($outsiderTeam, ['role' => 'owner']);
    $this->actingAs($outsider);
    session(['currentTeam' => $outsiderTeam]);

    Livewire::test(EnvironmentVariableAll::class, [
        'resource' => $this->application,
    ])->call('switch')->assertForbidden();
});

test('member keeps isValueHidden after switching to a team they own', function () {
    $ownTeam = Team::factory()->create();
    $this->member->teams()->attach($ownTeam, ['role' => 'owner']);
    $this->actingAs($this->member);
    session(['currentTeam' => $ownTeam]);

    $component = Livewire::test(EnvironmentVariableShow::class, [
        'env' => $this->unlockedEnv,
        'type' => 'application',
    ]);

    expect($component->get('isValueHidden'))->toBeTrue();
});

test('admin can limit log redaction to locked values for one application', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(EnvironmentVariableAll::class, ['resource' => $this->application])
        ->assertSet('redact_all_env_values_in_logs', true)
        ->assertSee('Environment values in logs')
        ->set('redact_all_env_values_in_logs', false)
        ->call('saveLogRedactionSetting')
        ->assertHasNoErrors();

    expect($this->application->settings->fresh()->redact_all_env_values_in_logs)->toBeFalse();
});

test('admin can limit log redaction to locked values for one service', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Livewire::test(EnvironmentVariableAll::class, ['resource' => $service])
        ->assertSet('redact_all_env_values_in_logs', true)
        ->set('redact_all_env_values_in_logs', false)
        ->call('saveLogRedactionSetting');

    expect($service->fresh()->redact_all_env_values_in_logs)->toBeFalse();
});

test('admin can limit log redaction to locked values for one database and a member cannot', function () {
    $database = StandalonePostgresql::query()->create([
        'name' => 'log-redaction-database',
        'postgres_password' => 'database-password',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    session(['currentTeam' => $this->team]);

    $this->actingAs($this->member);
    Livewire::test(EnvironmentVariableAll::class, ['resource' => $database])
        ->assertSee('Environment values in logs')
        ->set('redact_all_env_values_in_logs', false)
        ->call('saveLogRedactionSetting')
        ->assertSet('redact_all_env_values_in_logs', true)
        ->assertNotDispatched('success');
    expect($database->fresh()->redact_all_env_values_in_logs)->toBeTrue();

    $this->actingAs($this->admin);
    Livewire::test(EnvironmentVariableAll::class, ['resource' => $database])
        ->assertSet('redact_all_env_values_in_logs', true)
        ->set('redact_all_env_values_in_logs', false)
        ->call('saveLogRedactionSetting')
        ->assertDispatched('success');
    expect($database->fresh()->redact_all_env_values_in_logs)->toBeFalse();
});

test('member cannot change log redaction and the control returns to the stored value', function (string $resourceType, bool $storedValue) {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);
    $resource = $resourceType === 'application' ? $this->application : Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $settings = $resource instanceof Application ? $resource->settings : $resource;
    $settings->update(['redact_all_env_values_in_logs' => $storedValue]);

    Livewire::test(EnvironmentVariableAll::class, ['resource' => $resource])
        ->set('redact_all_env_values_in_logs', ! $storedValue)
        ->call('saveLogRedactionSetting')
        ->assertSet('redact_all_env_values_in_logs', $storedValue)
        ->assertNotDispatched('success');

    expect($settings->fresh()->redact_all_env_values_in_logs)->toBe($storedValue);
})->with(['application', 'service'])->with([true, false]);

test('log redaction control returns to the stored value after a write failure', function (string $resourceType, bool $storedValue) {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $resource = $resourceType === 'application' ? $this->application : Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $settings = $resource instanceof Application ? $resource->settings : $resource;
    $settings->update(['redact_all_env_values_in_logs' => $storedValue]);
    $component = Livewire::test(EnvironmentVariableAll::class, ['resource' => $resource]);
    $event = 'eloquent.saving: '.get_class($settings);
    Event::listen($event, function () {
        throw new RuntimeException('Saving the log redaction setting failed.');
    });

    try {
        $component->set('redact_all_env_values_in_logs', ! $storedValue)
            ->call('saveLogRedactionSetting')
            ->assertSet('redact_all_env_values_in_logs', $storedValue)
            ->assertNotDispatched('success');

        expect($settings->fresh()->redact_all_env_values_in_logs)->toBe($storedValue);
    } finally {
        Event::forget($event);
    }
})->with(['application', 'service'])->with([true, false]);

test('saving the log redaction setting records an audit event for the application', function () {
    $this->withoutDefer();
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(EnvironmentVariableAll::class, ['resource' => $this->application])
        ->set('redact_all_env_values_in_logs', false)
        ->call('saveLogRedactionSetting');

    $event = AuditEvent::query()->where('event', 'ui.application.settings_updated')->sole();

    expect($event->metadata['changed_fields'])->toBe(['redact_all_env_values_in_logs'])
        ->and($event->team_id)->toBe($this->team->id);
});

test('saving the log redaction setting records an audit event for the service', function () {
    $this->withoutDefer();
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Livewire::test(EnvironmentVariableAll::class, ['resource' => $service])
        ->set('redact_all_env_values_in_logs', false)
        ->call('saveLogRedactionSetting');

    expect(AuditEvent::query()->where('event', 'ui.service.updated')->get()->pluck('metadata.changed_fields')->all())
        ->toContain(['redact_all_env_values_in_logs']);
});

test('member of the resource team cannot change log redaction after switching to a team they own', function () {
    $ownTeam = Team::factory()->create();
    $this->member->teams()->attach($ownTeam, ['role' => 'owner']);
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EnvironmentVariableAll::class, ['resource' => $this->application]);

    session(['currentTeam' => $ownTeam]);
    Cache::flush();

    $component->set('redact_all_env_values_in_logs', false)
        ->call('saveLogRedactionSetting');

    expect($this->application->settings->fresh()->redact_all_env_values_in_logs)->toBeTrue();
});

test('admin of the resource team can change log redaction after switching to a team where they are a member', function () {
    $otherTeam = Team::factory()->create();
    $this->admin->teams()->attach($otherTeam, ['role' => 'member']);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EnvironmentVariableAll::class, ['resource' => $this->application]);

    session(['currentTeam' => $otherTeam]);
    Cache::flush();

    $component->set('redact_all_env_values_in_logs', false)
        ->call('saveLogRedactionSetting');

    expect($this->application->settings->fresh()->redact_all_env_values_in_logs)->toBeFalse();
});
