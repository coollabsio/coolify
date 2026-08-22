<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->bearerToken = createTeamApiToken($this->user, $this->team, ['*']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    CleanupPreviewDeployment::shouldRun()->andReturn([
        'cancelled_deployments' => 0,
        'killed_containers' => 0,
        'status' => 'success',
    ]);
});

function previewAuthHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function createTeamApiToken(User $user, Team $team, array $abilities): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'test-token-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

function createPreview(Application $application, int $pullRequestId): ApplicationPreview
{
    return ApplicationPreview::create([
        'uuid' => (string) new Cuid2,
        'application_id' => $application->id,
        'pull_request_id' => $pullRequestId,
        'pull_request_html_url' => "https://github.com/example/repo/pull/{$pullRequestId}",
        'fqdn' => "pr-{$pullRequestId}.example.com",
    ]);
}

describe('DELETE /api/v1/applications/{uuid}/previews/{pull_request_id}', function () {
    test('returns 401 when no bearer token provided', function () {
        $response = $this->deleteJson("/api/v1/applications/{$this->application->uuid}/previews/42");

        $response->assertUnauthorized();
    });

    test('returns 404 when application uuid does not exist', function () {
        $response = $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->deleteJson('/api/v1/applications/nonexistent-uuid/previews/42');

        $response->assertNotFound()
            ->assertJson(['message' => 'Application not found.']);
    });

    test('returns 404 when preview does not exist for the application', function () {
        $response = $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/previews/9999");

        $response->assertNotFound()
            ->assertJson(['message' => 'Preview not found.']);
    });

    test('returns 422 when pull_request_id is not a positive integer', function () {
        $response = $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/previews/0");

        $response->assertStatus(422)
            ->assertJson(['message' => 'Invalid pull_request_id.']);
    });

    test('soft-deletes the preview and returns 200 on success', function () {
        $preview = createPreview($this->application, 42);

        $response = $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/previews/42");

        $response->assertOk()
            ->assertJson(['message' => 'Preview deletion request queued.']);

        expect($preview->fresh()->trashed())->toBeTrue();
    });

    test('returns 403 when token lacks write ability', function () {
        $readOnlyToken = createTeamApiToken($this->user, $this->team, ['read']);
        createPreview($this->application, 7);

        $response = $this->withHeaders(previewAuthHeaders($readOnlyToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/previews/7");

        $response->assertForbidden();
    });
});
