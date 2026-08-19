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
    config(['app.maintenance.driver' => 'file']);

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
        'is_log_drain_enabled' => true,
        'is_gpu_enabled' => true,
        'gpu_driver' => 'nvidia',
        'gpu_count' => '1',
        'gpu_device_ids' => '0',
        'gpu_options' => null,
        'is_consistent_container_name_enabled' => true,
        'custom_internal_name' => 'my-app-internal',
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

test('http basic auth updates regenerate managed labels', function () {
    $this->application->settings->update(['is_container_label_readonly_enabled' => true]);
    $this->application->update([
        'fqdn' => 'https://app.example.com',
        'is_http_basic_auth_enabled' => false,
        'http_basic_auth_username' => null,
        'http_basic_auth_password' => null,
        'custom_labels' => base64_encode('sentinel-label=true'),
    ]);

    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'is_http_basic_auth_enabled' => true,
            'http_basic_auth_username' => 'api-user',
            'http_basic_auth_password' => 'api-password',
        ])
        ->assertOk();

    $application = $this->application->fresh();
    $labels = $application->parseContainerLabels();

    expect((bool) $application->is_http_basic_auth_enabled)->toBeTrue()
        ->and($application->http_basic_auth_username)->toBe('api-user')
        ->and($application->http_basic_auth_password)->toBe('api-password')
        ->and($labels)->toContain('basicauth')
        ->and($labels)->toContain('api-user')
        ->and($labels)->not->toContain('sentinel-label=true');
});

test('http basic auth updates preserve user-managed labels', function () {
    $this->application->settings->update(['is_container_label_readonly_enabled' => false]);
    $this->application->update([
        'fqdn' => 'https://app.example.com',
        'custom_labels' => base64_encode('sentinel-label=true'),
    ]);

    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'is_http_basic_auth_enabled' => true,
            'http_basic_auth_username' => 'api-user',
            'http_basic_auth_password' => 'api-password',
        ])
        ->assertOk();

    expect(base64_decode($this->application->fresh()->custom_labels))->toBe('sentinel-label=true');
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

function advancedApplicationSettingsPayload(): array
{
    return [
        'is_log_drain_enabled' => true,
        'is_gpu_enabled' => true,
        'gpu_driver' => 'nvidia',
        'gpu_count' => '1',
        'gpu_device_ids' => '0',
        'gpu_options' => 'capabilities=compute,utility',
        'is_consistent_container_name_enabled' => true,
        'custom_internal_name' => 'my-app-container',
    ];
}

test('PATCH /api/v1/applications/{uuid} updates advanced application settings', function () {
    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", advancedApplicationSettingsPayload())
        ->assertOk();

    $settings = $this->application->fresh()->settings;

    foreach (advancedApplicationSettingsPayload() as $field => $value) {
        expect($settings->{$field})->toBe($value);
    }
});

test('PATCH /api/v1/applications/{uuid} updates preview_url_template and max_restart_count', function () {
    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'preview_url_template' => '{{pr_id}}.preview.example.com',
            'max_restart_count' => 5,
        ])
        ->assertOk();

    $application = $this->application->fresh();

    expect($application->preview_url_template)->toBe('{{pr_id}}.preview.example.com')
        ->and($application->max_restart_count)->toBe(5);
});

test('GET /api/v1/applications/{uuid} includes advanced settings', function () {
    $this->application->settings->update(advancedApplicationSettingsPayload());
    $this->application->update([
        'preview_url_template' => '{{pr_id}}.preview.example.com',
        'max_restart_count' => 3,
    ]);

    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->getJson("/api/v1/applications/{$this->application->uuid}")
        ->assertOk()
        ->assertJsonPath('settings.is_log_drain_enabled', true)
        ->assertJsonPath('settings.is_gpu_enabled', true)
        ->assertJsonPath('settings.gpu_driver', 'nvidia')
        ->assertJsonPath('settings.custom_internal_name', 'my-app-container')
        ->assertJsonPath('settings.is_consistent_container_name_enabled', true)
        ->assertJsonPath('preview_url_template', '{{pr_id}}.preview.example.com')
        ->assertJsonPath('max_restart_count', 3);
});

test('application creation accepts advanced application settings', function () {
    Queue::fake();

    $response = $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->postJson('/api/v1/applications/public', array_merge([
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'git_repository' => 'https://gitlab.com/coolify/advanced-settings-test',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'autogenerate_domain' => false,
            'preview_url_template' => '{{pr_id}}.create.example.com',
            'max_restart_count' => 7,
        ], advancedApplicationSettingsPayload()))
        ->assertCreated();

    $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();
    $settings = $application->settings;

    foreach (advancedApplicationSettingsPayload() as $field => $value) {
        expect($settings->{$field})->toBe($value);
    }

    expect($application->preview_url_template)->toBe('{{pr_id}}.create.example.com')
        ->and($application->max_restart_count)->toBe(7);
});

test('rejects invalid max_restart_count', function () {
    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'max_restart_count' => -1,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_restart_count');
});

test('rejects invalid gpu boolean settings', function () {
    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'is_gpu_enabled' => 'not-a-boolean',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_gpu_enabled');
});

test('rejects swarm fields on application update', function (string $field, mixed $value) {
    $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            $field => $value,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'swarm_replicas' => ['swarm_replicas', 3],
    'swarm_placement_constraints' => ['swarm_placement_constraints', 'node.role==worker'],
    'is_swarm_only_worker_nodes' => ['is_swarm_only_worker_nodes', true],
]);
