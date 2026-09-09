<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);

    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = $this->server->standaloneDockers()->firstOrCreate(
            ['network' => 'coolify'],
            ['uuid' => (string) new Cuid2, 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) new Cuid2,
        'name' => 'test-project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments()->first();

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function previewDeploymentsAuthHeaders($bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('PATCH /api/v1/applications/{uuid} is_preview_deployments_enabled', function () {
    test('can enable preview deployments', function () {
        $this->application->settings->update(['is_preview_deployments_enabled' => false]);

        $response = $this->withHeaders(previewDeploymentsAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'is_preview_deployments_enabled' => true,
            ]);

        $response->assertOk();

        $this->application->refresh();
        expect($this->application->settings->is_preview_deployments_enabled)->toBeTrue();
    });

    test('can disable preview deployments', function () {
        $this->application->settings->update(['is_preview_deployments_enabled' => true]);

        $response = $this->withHeaders(previewDeploymentsAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'is_preview_deployments_enabled' => false,
            ]);

        $response->assertOk();

        $this->application->refresh();
        expect($this->application->settings->is_preview_deployments_enabled)->toBeFalse();
    });

    test('is_preview_deployments_enabled is not changed when not in request', function () {
        $this->application->settings->update(['is_preview_deployments_enabled' => true]);

        $response = $this->withHeaders(previewDeploymentsAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'name' => 'updated-name',
            ]);

        $response->assertOk();

        $this->application->refresh();
        expect($this->application->settings->is_preview_deployments_enabled)->toBeTrue();
    });

    test('rejects non-boolean value', function () {
        $response = $this->withHeaders(previewDeploymentsAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'is_preview_deployments_enabled' => 'yes',
            ]);

        $response->assertStatus(422);
    });
});

describe('POST /api/v1/applications/public is_preview_deployments_enabled', function () {
    test('can enable preview deployments on create', function () {
        $response = $this->withHeaders(previewDeploymentsAuthHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/public', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'git_repository' => 'https://gitlab.com/coolify/test-preview-app',
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => '3000',
                'is_preview_deployments_enabled' => true,
                'autogenerate_domain' => false,
            ]);

        $response->assertCreated();

        $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();
        expect($application->settings->is_preview_deployments_enabled)->toBeTrue();
    });

    test('rejects non-boolean value on create', function () {
        $response = $this->withHeaders(previewDeploymentsAuthHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/public', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'git_repository' => 'https://gitlab.com/coolify/test-preview-app',
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => '3000',
                'is_preview_deployments_enabled' => 'yes',
                'autogenerate_domain' => false,
            ]);

        $response->assertUnprocessable();
    });
});
