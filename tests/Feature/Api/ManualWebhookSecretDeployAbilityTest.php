<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'manual_webhook_secret_github' => 'existing-secret-0123456789',
    ]);

    Queue::fake();
});

function manualWebhookSecretAbilityHeaders(User $user, int $teamId, array $abilities): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'manual-webhook-secret-ability-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

describe('update', function () {
    test('write-only token cannot set a new manual webhook secret', function (string $field) {
        $this->withHeaders(manualWebhookSecretAbilityHeaders($this->user, $this->team->id, ['write']))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [$field => 'attacker-secret-0123456789'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Missing required permissions: deploy. A manual webhook secret lets anyone who knows it start a deployment, so it needs a token with the deploy permission.');

        $application = $this->application->fresh();
        expect($application->manual_webhook_secret_github)->toBe('existing-secret-0123456789');
        expect($application->{$field})->not->toBe('attacker-secret-0123456789');
    })->with([
        'manual_webhook_secret_github',
        'manual_webhook_secret_gitlab',
        'manual_webhook_secret_bitbucket',
        'manual_webhook_secret_gitea',
    ]);

    test('write-only token can update other fields and send the stored secret back', function () {
        $headers = manualWebhookSecretAbilityHeaders($this->user, $this->team->id, ['write']);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/applications/{$this->application->uuid}", ['description' => 'changed'])
            ->assertOk();
        $this->withHeaders($headers)
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'description' => 'changed again',
                'manual_webhook_secret_github' => 'existing-secret-0123456789',
            ])
            ->assertOk();

        expect($this->application->fresh())
            ->description->toBe('changed again')
            ->manual_webhook_secret_github->toBe('existing-secret-0123456789');
    });

    test('write and deploy token can set a new manual webhook secret', function () {
        $this->withHeaders(manualWebhookSecretAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", ['manual_webhook_secret_gitlab' => 'new-gitlab-secret-0123456789'])
            ->assertOk();

        expect($this->application->fresh()->manual_webhook_secret_gitlab)->toBe('new-gitlab-secret-0123456789');
    });
});

describe('create', function () {
    test('write-only token cannot create an application with a manual webhook secret', function () {
        $this->withHeaders(manualWebhookSecretAbilityHeaders($this->user, $this->team->id, ['write']))
            ->postJson('/api/v1/applications/dockerimage', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'docker_registry_image_name' => 'nginx',
                'ports_exposes' => '80',
                'manual_webhook_secret_github' => 'attacker-secret-0123456789',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'manual webhook secret'));

        expect(Application::count())->toBe(1);
    });
});
