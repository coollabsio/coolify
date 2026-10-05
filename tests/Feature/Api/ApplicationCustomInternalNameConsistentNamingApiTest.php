<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config(['app.maintenance.store' => 'array']);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('custom-internal-name-test', ['*'])->plainTextToken;
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function customInternalNameCreatePayload(array $overrides): array
{
    return array_merge([
        'project_uuid' => test()->project->uuid,
        'environment_uuid' => test()->environment->uuid,
        'server_uuid' => test()->server->uuid,
        'git_repository' => 'https://gitlab.com/coolify/custom-internal-name',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'autogenerate_domain' => false,
    ], $overrides);
}

const CUSTOM_INTERNAL_NAME_NEEDS_CONSISTENT_NAMING = 'Set is_consistent_container_name_enabled to true to use custom_internal_name. Coolify ignores the custom internal name while consistent container naming is turned off.';

test('updating only custom_internal_name is rejected while consistent container naming is off', function () {
    $this->withToken($this->bearerToken)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", ['custom_internal_name' => 'my-app'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.custom_internal_name.0', CUSTOM_INTERNAL_NAME_NEEDS_CONSISTENT_NAMING);

    expect($this->application->fresh()->settings->custom_internal_name)->toBeNull();
});

test('updating custom_internal_name while turning consistent container naming off is rejected', function () {
    $this->application->settings->update(['is_consistent_container_name_enabled' => true]);

    $this->withToken($this->bearerToken)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'custom_internal_name' => 'my-app',
            'is_consistent_container_name_enabled' => false,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.custom_internal_name.0', CUSTOM_INTERNAL_NAME_NEEDS_CONSISTENT_NAMING);
});

test('updating custom_internal_name together with consistent container naming succeeds', function () {
    $this->withToken($this->bearerToken)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'custom_internal_name' => 'my-app',
            'is_consistent_container_name_enabled' => true,
        ])
        ->assertOk();

    $settings = $this->application->fresh()->settings;
    expect($settings->custom_internal_name)->toBe('my-app')
        ->and($settings->is_consistent_container_name_enabled)->toBeTrue();
});

test('updating only custom_internal_name succeeds when consistent container naming is already on', function () {
    $this->application->settings->update(['is_consistent_container_name_enabled' => true]);

    $this->withToken($this->bearerToken)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", ['custom_internal_name' => 'my-app'])
        ->assertOk();

    expect($this->application->fresh()->settings->custom_internal_name)->toBe('my-app');
});

test('clearing custom_internal_name does not need consistent container naming', function (?string $value) {
    $this->withToken($this->bearerToken)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", ['custom_internal_name' => $value])
        ->assertOk();
})->with(['null' => [null], 'empty string' => ['']]);

test('creating an application with custom_internal_name requires consistent container naming', function () {
    Queue::fake();

    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/applications/public', customInternalNameCreatePayload(['custom_internal_name' => 'my-app']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.custom_internal_name.0', CUSTOM_INTERNAL_NAME_NEEDS_CONSISTENT_NAMING);

    expect(Application::query()->count())->toBe(1);
});

test('creating an application with custom_internal_name and consistent container naming succeeds', function () {
    Queue::fake();

    $response = $this->withToken($this->bearerToken)
        ->postJson('/api/v1/applications/public', customInternalNameCreatePayload([
            'custom_internal_name' => 'my-app',
            'is_consistent_container_name_enabled' => true,
        ]))
        ->assertCreated();

    expect(Application::query()->where('uuid', $response->json('uuid'))->sole()->settings->custom_internal_name)->toBe('my-app');
});
