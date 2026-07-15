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
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('application-settings-api-test', ['*'])->plainTextToken;
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

function applicationSettingsApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function recommendedApplicationSettingsPayload(): array
{
    return [
        'is_git_submodules_enabled' => false,
        'is_git_lfs_enabled' => false,
        'is_git_shallow_clone_enabled' => false,
        'disable_build_cache' => true,
        'inject_build_args_to_dockerfile' => false,
        'include_source_commit_in_build' => true,
        'is_env_sorting_enabled' => true,
        'is_pr_deployments_public_enabled' => true,
        'stop_grace_period' => 45,
        'docker_images_to_keep' => 7,
        'is_gzip_enabled' => false,
        'is_stripprefix_enabled' => false,
        'is_raw_compose_deployment_enabled' => true,
    ];
}

test('GET /api/v1/applications/{uuid} includes settings without internal metadata', function () {
    $this->application->settings->update(recommendedApplicationSettingsPayload());

    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->getJson("/api/v1/applications/{$this->application->uuid}")
        ->assertOk()
        ->assertJsonPath('settings.disable_build_cache', true)
        ->assertJsonPath('settings.stop_grace_period', 45)
        ->assertJsonMissingPath('settings.id')
        ->assertJsonMissingPath('settings.application_id')
        ->assertJsonMissingPath('settings.created_at')
        ->assertJsonMissingPath('settings.updated_at');
});

test('PATCH /api/v1/applications/{uuid} updates application settings', function () {
    $this->application->update(['build_pack' => 'dockercompose']);

    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", recommendedApplicationSettingsPayload())
        ->assertOk();

    $settings = $this->application->fresh()->settings;

    foreach (recommendedApplicationSettingsPayload() as $field => $value) {
        expect($settings->{$field})->toBe($value);
    }
});

test('application creation accepts application settings', function () {
    Queue::fake();

    $response = $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->postJson('/api/v1/applications/public', array_merge([
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'git_repository' => 'https://gitlab.com/coolify/application-settings-test',
            'git_branch' => 'main',
            'build_pack' => 'dockercompose',
            'autogenerate_domain' => false,
        ], recommendedApplicationSettingsPayload()))
        ->assertCreated();

    $settings = Application::where('uuid', $response->json('uuid'))->firstOrFail()->settings;

    foreach (recommendedApplicationSettingsPayload() as $field => $value) {
        expect($settings->{$field})->toBe($value);
    }
});

test('proxy settings regenerate managed labels', function () {
    $this->application->settings->update([
        'is_container_label_readonly_enabled' => true,
        'is_gzip_enabled' => true,
        'is_stripprefix_enabled' => true,
    ]);
    $this->application->update(['custom_labels' => base64_encode('sentinel-label=true')]);

    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'is_gzip_enabled' => false,
            'is_stripprefix_enabled' => false,
        ])
        ->assertOk();

    expect(base64_decode($this->application->fresh()->custom_labels))->not->toContain('sentinel-label=true');
});

test('rejects invalid boolean application settings', function () {
    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'disable_build_cache' => 'not-a-boolean',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('disable_build_cache');
});

test('validates stop grace period bounds', function (int $stopGracePeriod) {
    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'stop_grace_period' => $stopGracePeriod,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('stop_grace_period');
})->with([
    'below minimum' => 0,
    'above maximum' => 3601,
]);

test('validates Docker image retention bounds', function (int $dockerImagesToKeep) {
    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'docker_images_to_keep' => $dockerImagesToKeep,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('docker_images_to_keep');
})->with([
    'below minimum' => -1,
    'above maximum' => 101,
]);

test('stop grace period can be reset to null', function () {
    $this->application->settings->update(['stop_grace_period' => 45]);

    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'stop_grace_period' => null,
        ])
        ->assertOk();

    expect($this->application->fresh()->settings->stop_grace_period)->toBeNull();
});

test('raw compose deployment can only be enabled for Docker Compose applications', function () {
    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'is_raw_compose_deployment_enabled' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_raw_compose_deployment_enabled');
});
