<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('auto-deploy-api-test', ['*'])->plainTextToken;
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

describe('application creation endpoints is_auto_deploy_enabled', function () {
    test('stores the requested auto deploy setting', function (string $endpoint, array $payload, bool $isAutoDeployEnabled) {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
            ->postJson("/api/v1/applications/{$endpoint}", [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'is_auto_deploy_enabled' => $isAutoDeployEnabled,
                'instant_deploy' => false,
                'autogenerate_domain' => false,
                ...$payload,
            ])
            ->assertCreated();

        $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();

        expect($application->settings->is_auto_deploy_enabled)->toBe($isAutoDeployEnabled);
    })->with([
        'Docker image' => ['dockerimage', [
            'docker_registry_image_name' => 'nginx',
            'docker_registry_image_tag' => 'alpine',
            'ports_exposes' => '80',
        ]],
        'Dockerfile' => ['dockerfile', [
            'dockerfile' => base64_encode("FROM nginx:alpine\nEXPOSE 80"),
        ]],
        'public repository' => ['public', [
            'git_repository' => 'https://gitlab.com/coolify/auto-deploy-test',
            'git_branch' => 'main',
            'build_pack' => 'dockerfile',
            'ports_exposes' => '3000',
        ]],
    ])->with([
        'disabled' => false,
        'enabled' => true,
    ]);
});
