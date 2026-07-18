<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('build-secrets-api-test', ['*'])->plainTextToken;
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

function buildSecretsApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function buildSecretsGithubPrivateKey(): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($key, $privateKey);

    return $privateKey;
}

describe('PATCH /api/v1/applications/{uuid} use_build_secrets', function () {
    test('updates the application setting', function () {
        expect($this->application->settings->use_build_secrets)->toBeFalse();

        $this->withHeaders(buildSecretsApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'use_build_secrets' => true,
            ])
            ->assertOk();

        expect($this->application->fresh()->settings->use_build_secrets)->toBeTrue();

        $this->withHeaders(buildSecretsApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'use_build_secrets' => false,
            ])
            ->assertOk();

        expect($this->application->fresh()->settings->use_build_secrets)->toBeFalse();
    });

    test('rejects non boolean values', function () {
        $this->withHeaders(buildSecretsApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'use_build_secrets' => 'not-a-boolean',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('use_build_secrets');
    });

    test('does not change the setting when omitted', function () {
        $this->application->settings->update(['use_build_secrets' => true]);

        $this->withHeaders(buildSecretsApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'name' => 'updated-name',
            ])
            ->assertOk();

        expect($this->application->fresh()->settings->use_build_secrets)->toBeTrue();
    });
});

describe('POST /api/v1/applications/public use_build_secrets', function () {
    test('creates an application with the requested build secrets setting', function (bool $useBuildSecrets) {
        $response = $this->withHeaders(buildSecretsApiHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/public', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'git_repository' => 'https://gitlab.com/coolify/build-secrets-test',
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => '3000',
                'use_build_secrets' => $useBuildSecrets,
                'autogenerate_domain' => false,
            ])
            ->assertCreated();

        $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();

        expect($application->settings->use_build_secrets)->toBe($useBuildSecrets);
    })->with([
        'enabled' => true,
        'disabled' => false,
    ]);

    test('rejects non boolean values', function () {
        $this->withHeaders(buildSecretsApiHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/public', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'git_repository' => 'https://gitlab.com/coolify/build-secrets-test',
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => '3000',
                'use_build_secrets' => 'not-a-boolean',
                'autogenerate_domain' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('use_build_secrets');
    });
});

describe('other application creation endpoints use_build_secrets', function () {
    test('creates a Dockerfile application with build secrets enabled', function () {
        $response = $this->withHeaders(buildSecretsApiHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/dockerfile', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'dockerfile' => base64_encode("FROM nginx:alpine\nEXPOSE 80"),
                'use_build_secrets' => true,
                'autogenerate_domain' => false,
            ])
            ->assertCreated();

        $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();

        expect($application->settings->use_build_secrets)->toBeTrue();
    });

    test('creates a Docker image application with build secrets enabled', function () {
        $response = $this->withHeaders(buildSecretsApiHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/dockerimage', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'docker_registry_image_name' => 'nginx',
                'docker_registry_image_tag' => 'alpine',
                'ports_exposes' => '80',
                'use_build_secrets' => true,
                'autogenerate_domain' => false,
            ])
            ->assertCreated();

        $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();

        expect($application->settings->use_build_secrets)->toBeTrue();
    });

    test('creates a private deploy key application with build secrets enabled', function () {
        $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

        $response = $this->withHeaders(buildSecretsApiHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/private-deploy-key', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'private_key_uuid' => $privateKey->uuid,
                'git_repository' => 'git@gitlab.com:coolify/build-secrets-test.git',
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => '3000',
                'use_build_secrets' => true,
                'autogenerate_domain' => false,
            ])
            ->assertCreated();

        $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();

        expect($application->settings->use_build_secrets)->toBeTrue();
    });

    test('creates a private GitHub App application with build secrets enabled', function () {
        $privateKey = PrivateKey::create([
            'name' => 'GitHub App Key',
            'private_key' => buildSecretsGithubPrivateKey(),
            'team_id' => $this->team->id,
        ]);
        $githubApp = GithubApp::create([
            'name' => 'Build Secrets GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 12345,
            'installation_id' => 67890,
            'client_id' => 'build-secrets-client-id',
            'client_secret' => 'build-secrets-client-secret',
            'webhook_secret' => 'build-secrets-webhook-secret',
            'private_key_id' => $privateKey->id,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
            'is_public' => false,
        ]);

        Http::fake([
            'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, [
                'Date' => now()->toRfc7231String(),
            ]),
            'https://api.github.com/app/installations/67890/access_tokens' => Http::response([
                'token' => 'github-installation-token',
            ], 201),
            'https://api.github.com/repos/coolify/build-secrets-test' => Http::response([
                'id' => 123456,
            ]),
        ]);

        $response = $this->withHeaders(buildSecretsApiHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/private-github-app', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'github_app_uuid' => $githubApp->uuid,
                'git_repository' => 'coolify/build-secrets-test',
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => '3000',
                'use_build_secrets' => true,
                'autogenerate_domain' => false,
            ])
            ->assertCreated();

        $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();

        expect($application->settings->use_build_secrets)->toBeTrue();
    });
});
