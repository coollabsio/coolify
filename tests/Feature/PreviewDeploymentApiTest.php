<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
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
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'coolify-'.Str::lower(Str::random(8)),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function createApplication(Environment $environment, StandaloneDocker $destination): Application
{
    return Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'git_repository' => 'https://github.com/example/repo',
        'git_branch' => 'main',
    ]);
}

test('start endpoint auto-creates preview when pull_request_id is provided', function () {
    $application = createApplication($this->environment, $this->destination);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
    ])->postJson("/api/v1/applications/{$application->uuid}/start", [
        'pull_request_id' => 42,
    ]);

    $response->assertSuccessful();

    $preview = ApplicationPreview::query()
        ->where('application_id', $application->id)
        ->where('pull_request_id', 42)
        ->first();

    expect($preview)->not()->toBeNull();
    expect($preview->pull_request_id)->toBe(42);
});

test('start endpoint reuses existing preview', function () {
    $application = createApplication($this->environment, $this->destination);

    $existingPreview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
    ])->postJson("/api/v1/applications/{$application->uuid}/start", [
        'pull_request_id' => 42,
    ]);

    $response->assertSuccessful();

    $previewCount = ApplicationPreview::query()
        ->where('application_id', $application->id)
        ->where('pull_request_id', 42)
        ->count();

    expect($previewCount)->toBe(1);
});

test('restart endpoint auto-creates preview when pull_request_id is provided', function () {
    $application = createApplication($this->environment, $this->destination);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
    ])->postJson("/api/v1/applications/{$application->uuid}/restart", [
        'pull_request_id' => 55,
    ]);

    $response->assertSuccessful();

    $preview = ApplicationPreview::query()
        ->where('application_id', $application->id)
        ->where('pull_request_id', 55)
        ->first();

    expect($preview)->not()->toBeNull();
});

test('deploy endpoint auto-creates preview for non-dockerimage apps', function () {
    $application = createApplication($this->environment, $this->destination);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
    ])->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'pull_request_id' => 77,
    ]);

    $response->assertSuccessful();

    $preview = ApplicationPreview::query()
        ->where('application_id', $application->id)
        ->where('pull_request_id', 77)
        ->first();

    expect($preview)->not()->toBeNull();
});

test('start endpoint without pull_request_id deploys main app', function () {
    $application = createApplication($this->environment, $this->destination);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
    ])->postJson("/api/v1/applications/{$application->uuid}/start");

    $response->assertSuccessful();

    $previewCount = ApplicationPreview::query()
        ->where('application_id', $application->id)
        ->count();

    expect($previewCount)->toBe(0);
});

test('stop endpoint returns 404 for non-existent preview', function () {
    $application = createApplication($this->environment, $this->destination);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
    ])->postJson("/api/v1/applications/{$application->uuid}/stop", [
        'pull_request_id' => 999,
    ]);

    $response->assertStatus(404);
});
