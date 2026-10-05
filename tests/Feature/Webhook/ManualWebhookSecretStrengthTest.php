<?php

use App\Livewire\Project\Shared\Webhooks;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member->id, ['role' => 'member']);

    $plainTextToken = Str::random(40);
    $token = $this->admin->tokens()->create([
        'name' => 'manual-webhook-secret-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'git_repository' => 'https://github.com/coollabsio/coolify-examples',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'manual_webhook_secret_github' => 'short-legacy',
        'manual_webhook_secret_gitlab' => 'gitlab-legacy',
        'manual_webhook_secret_bitbucket' => null,
        'manual_webhook_secret_gitea' => Str::random(40),
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function manualWebhookSecretApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function manualWebhookSecretComponent(): Testable
{
    test()->actingAs(test()->admin);
    session(['currentTeam' => test()->team]);

    return Livewire::test(Webhooks::class, ['resource' => test()->application->fresh()]);
}

describe('Webhooks component', function () {
    test('rejects a new secret shorter than 16 characters', function () {
        manualWebhookSecretComponent()
            ->set('githubManualWebhookSecret', 'too-short-12')
            ->call('submit')
            ->assertHasErrors(['githubManualWebhookSecret'])
            ->assertNotDispatched('success');

        expect($this->application->fresh()->manual_webhook_secret_github)->toBe('short-legacy');
    });

    test('saves when existing short secrets are left unchanged', function () {
        $newSecret = Str::random(16);

        manualWebhookSecretComponent()
            ->set('bitbucketManualWebhookSecret', $newSecret)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('success');

        $application = $this->application->fresh();
        expect($application->manual_webhook_secret_github)->toBe('short-legacy')
            ->and($application->manual_webhook_secret_gitlab)->toBe('gitlab-legacy')
            ->and($application->manual_webhook_secret_bitbucket)->toBe($newSecret);
    });

    test('allows clearing a secret', function () {
        manualWebhookSecretComponent()
            ->set('githubManualWebhookSecret', '')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('success');

        expect($this->application->fresh()->manual_webhook_secret_github)->toBeEmpty();
    });

    test('generate returns a random secret of at least 32 characters that saves with the form', function () {
        $generated = null;

        $component = manualWebhookSecretComponent()
            ->call('generateSecret', 'gitlab')
            ->assertHasNoErrors()
            ->assertReturned(function ($secret) use (&$generated) {
                $generated = $secret;

                return is_string($secret) && strlen($secret) >= 32;
            })
            ->assertSet('gitlabManualWebhookSecret', 'gitlab-legacy');

        expect($this->application->fresh()->manual_webhook_secret_gitlab)->toBe('gitlab-legacy');

        $component->set('gitlabManualWebhookSecret', $generated)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('success');

        expect($this->application->fresh()->manual_webhook_secret_gitlab)->toBe($generated);
    });

    test('generate rejects an unknown provider', function () {
        manualWebhookSecretComponent()
            ->call('generateSecret', 'deploywebhook')
            ->assertDispatched('error')
            ->assertReturned(null);
    });

    test('shows generate buttons only to users who can edit secrets', function () {
        manualWebhookSecretComponent()
            ->assertSeeHtml('aria-label="Generate GitHub webhook secret"')
            ->assertSeeHtml('aria-label="Generate Gitea webhook secret"');

        $this->actingAs($this->member);

        Livewire::test(Webhooks::class, ['resource' => $this->application->fresh()])
            ->assertDontSeeHtml('aria-label="Generate GitHub webhook secret"');
    });

    test('members cannot generate a secret', function () {
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        Livewire::test(Webhooks::class, ['resource' => $this->application->fresh()])
            ->call('generateSecret', 'github')
            ->assertDispatched('error')
            ->assertReturned(null);
    });
});

describe('Applications API', function () {
    test('update rejects a new secret shorter than 16 characters', function () {
        $this->withHeaders(manualWebhookSecretApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'manual_webhook_secret_github' => 'too-short-12',
            ])
            ->assertUnprocessable()
            ->assertInvalid(['manual_webhook_secret_github']);

        expect($this->application->fresh()->manual_webhook_secret_github)->toBe('short-legacy');
    });

    test('update accepts an unchanged short secret, a 16 character secret, and null', function () {
        $newSecret = Str::random(16);

        $this->withHeaders(manualWebhookSecretApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'manual_webhook_secret_github' => 'short-legacy',
                'manual_webhook_secret_gitlab' => $newSecret,
                'manual_webhook_secret_bitbucket' => null,
            ])
            ->assertOk();

        $application = $this->application->fresh();
        expect($application->manual_webhook_secret_github)->toBe('short-legacy')
            ->and($application->manual_webhook_secret_gitlab)->toBe($newSecret)
            ->and($application->manual_webhook_secret_bitbucket)->toBeNull();
    });

    test('create rejects a secret shorter than 16 characters', function () {
        $this->withHeaders(manualWebhookSecretApiHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/dockerimage', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'docker_registry_image_name' => 'nginx',
                'docker_registry_image_tag' => 'latest',
                'ports_exposes' => '80',
                'instant_deploy' => false,
                'manual_webhook_secret_gitea' => 'too-short-12',
            ])
            ->assertUnprocessable()
            ->assertInvalid(['manual_webhook_secret_gitea']);
    });

    test('create accepts a secret of 16 characters', function () {
        $secret = Str::random(16);

        $response = $this->withHeaders(manualWebhookSecretApiHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/dockerimage', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'docker_registry_image_name' => 'nginx',
                'docker_registry_image_tag' => 'latest',
                'ports_exposes' => '80',
                'instant_deploy' => false,
                'manual_webhook_secret_gitea' => $secret,
            ])
            ->assertCreated();

        expect(Application::query()->where('uuid', $response->json('uuid'))->first()->manual_webhook_secret_gitea)
            ->toBe($secret);
    });
});
