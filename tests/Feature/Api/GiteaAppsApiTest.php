<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\GiteaApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Http::GitSource() pins DNS before Http::fake() answers, so use a public IP
 * literal that needs no DNS lookup. No request leaves the test.
 */
const GITEA_API_TEST_URL = 'https://1.1.1.1';

function giteaApiTestSource(Team $team, array $attributes = []): GiteaApp
{
    return GiteaApp::create([
        'name' => 'Team Gitea',
        'api_url' => GITEA_API_TEST_URL.'/api/v1',
        'html_url' => GITEA_API_TEST_URL,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'access_token' => 'gitea-access-token',
        'refresh_token' => 'gitea-refresh-token',
        'webhook_secret' => 'gitea-webhook-secret',
        'expires_at' => time() + 3600,
        'team_id' => $team->id,
        'is_system_wide' => false,
        'is_public' => false,
        ...$attributes,
    ]);
}

function fakeGiteaRepository(string $path = 'acme/app', string $owner = 'acme', int $id = 4242): void
{
    Http::fake([
        GITEA_API_TEST_URL."/api/v1/repos/{$path}" => Http::response(['id' => $id, 'full_name' => $path, 'owner' => ['login' => $owner]]),
        '*' => Http::response(['message' => 'Not Found'], 404),
    ]);
}

beforeEach(function () {
    Queue::fake();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->headers = ['Authorization' => 'Bearer '.$this->user->createToken('gitea-api-test', ['*'])->plainTextToken];

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();

    $this->giteaApp = giteaApiTestSource($this->team);
});

function createGiteaApplicationPayload(object $test, array $overrides = []): array
{
    return [
        'project_uuid' => $test->project->uuid,
        'environment_uuid' => $test->environment->uuid,
        'server_uuid' => $test->server->uuid,
        'gitea_app_uuid' => $test->giteaApp->uuid,
        'git_repository' => 'acme/app',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'autogenerate_domain' => false,
        ...$overrides,
    ];
}

describe('GET /api/v1/gitea-apps', function () {
    test('lists team and system-wide sources without secrets for read tokens', function () {
        $otherTeam = Team::factory()->create();
        giteaApiTestSource($otherTeam, ['name' => 'Foreign System Gitea', 'is_system_wide' => true]);
        giteaApiTestSource($otherTeam, ['name' => 'Foreign Private Gitea']);
        giteaApiTestSource($this->team, ['name' => 'Unconnected Gitea', 'access_token' => null, 'refresh_token' => null]);
        $readToken = $this->user->createToken('read-token', ['read'])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$readToken])
            ->getJson('/api/v1/gitea-apps')
            ->assertOk()
            ->assertJsonCount(3);

        $apps = collect($response->json())->keyBy('name');
        expect($apps->keys()->sort()->values()->all())->toBe(['Foreign System Gitea', 'Team Gitea', 'Unconnected Gitea'])
            ->and($apps['Team Gitea']['is_connected'])->toBeTrue()
            ->and($apps['Unconnected Gitea']['is_connected'])->toBeFalse();
        foreach ($apps as $app) {
            expect($app)->not->toHaveKeys(['client_secret', 'access_token', 'refresh_token', 'webhook_secret']);
        }
    });

    test('shows secrets of own sources to root tokens only', function () {
        $otherTeam = Team::factory()->create();
        giteaApiTestSource($otherTeam, ['name' => 'Foreign System Gitea', 'is_system_wide' => true]);

        $apps = collect($this->withHeaders($this->headers)->getJson('/api/v1/gitea-apps')->assertOk()->json())->keyBy('name');

        expect($apps['Team Gitea']['client_secret'])->toBe('client-secret')
            ->and($apps['Foreign System Gitea'])->not->toHaveKey('client_secret');
    });
});

describe('POST /api/v1/applications/private-gitea-app', function () {
    test('creates an application from a repository URL on the Gitea instance', function () {
        fakeGiteaRepository();

        $response = $this->withHeaders($this->headers)
            ->postJson('/api/v1/applications/private-gitea-app', createGiteaApplicationPayload($this, [
                'git_repository' => GITEA_API_TEST_URL.'/acme/app.git',
            ]))
            ->assertCreated();

        $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();
        expect($application->source_type)->toBe(GiteaApp::class)
            ->and($application->source_id)->toBe($this->giteaApp->id)
            ->and($application->git_repository)->toBe('acme/app')
            ->and($application->repository_project_id)->toBe(4242);
        Http::assertSent(fn ($request) => $request->url() === GITEA_API_TEST_URL.'/api/v1/repos/acme/app'
            && $request->hasHeader('Authorization', 'Bearer gitea-access-token'));
    });

    test('returns 404 for a Gitea App of another team or one that is not connected', function (string $case) {
        $source = $case === 'other team'
            ? giteaApiTestSource(Team::factory()->create())
            : giteaApiTestSource($this->team, ['access_token' => null, 'refresh_token' => null]);
        fakeGiteaRepository();

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/applications/private-gitea-app', createGiteaApplicationPayload($this, ['gitea_app_uuid' => $source->uuid]))
            ->assertNotFound()
            ->assertJson(['message' => 'Gitea App not found.']);

        expect(Application::count())->toBe(0);
        Http::assertNothingSent();
    })->with(['other team', 'not connected']);

    test('rejects a repository of an owner outside the source organizations', function () {
        $this->giteaApp->update(['organization' => 'acme']);
        fakeGiteaRepository('intruder/app', 'intruder');

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/applications/private-gitea-app', createGiteaApplicationPayload($this, ['git_repository' => 'intruder/app']))
            ->assertNotFound()
            ->assertJson(['message' => 'Repository not found or not accessible by the Gitea App.']);

        expect(Application::count())->toBe(0);
    });

    test('rejects a repository that is not on the Gitea instance of the source', function (string $repository) {
        fakeGiteaRepository();

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/applications/private-gitea-app', createGiteaApplicationPayload($this, ['git_repository' => $repository]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('git_repository');

        Http::assertNothingSent();
    })->with([
        'other host' => 'https://gitea.example.org/acme/app',
        'path traversal' => '../admin',
        'nested path' => 'acme/app/extra',
    ]);

    test('requires gitea_app_uuid', function () {
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/applications/private-gitea-app', createGiteaApplicationPayload($this, ['gitea_app_uuid' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gitea_app_uuid');
    });
});

describe('PATCH /api/v1/applications/{uuid} gitea_app_uuid', function () {
    beforeEach(function () {
        $this->application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => StandaloneDocker::where('server_id', $this->server->id)->firstOrFail()->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => GITEA_API_TEST_URL.'/acme/app',
            'repository_project_id' => null,
        ]);
    });

    test('moves a public repository application to a Gitea App source', function () {
        fakeGiteaRepository();

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/applications/{$this->application->uuid}", ['gitea_app_uuid' => $this->giteaApp->uuid])
            ->assertOk();

        $this->application->refresh();
        expect($this->application->source_type)->toBe(GiteaApp::class)
            ->and($this->application->source_id)->toBe($this->giteaApp->id)
            ->and($this->application->git_repository)->toBe('acme/app')
            ->and($this->application->repository_project_id)->toBe(4242);
    });

    test('does not move the application to a Gitea App of another team', function () {
        $foreignSource = giteaApiTestSource(Team::factory()->create());
        $originalSource = [$this->application->source_type, $this->application->source_id];
        fakeGiteaRepository();

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/applications/{$this->application->uuid}", ['gitea_app_uuid' => $foreignSource->uuid])
            ->assertNotFound();

        $this->application->refresh();
        expect([$this->application->source_type, $this->application->source_id])->toBe($originalSource);
    });

    test('rejects github_app_uuid and gitea_app_uuid together', function () {
        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'gitea_app_uuid' => $this->giteaApp->uuid,
                'github_app_uuid' => 'any-github-app',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gitea_app_uuid');
    });
});
