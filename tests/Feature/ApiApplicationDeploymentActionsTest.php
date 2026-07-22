<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\Fluent\AssertableJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('deployment-actions-test', ['deploy'])->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function deploymentActionHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ];
}

function makeDeploymentActionApplication(Environment $environment, StandaloneDocker $destination): Application
{
    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
    ]);
}

test('application start API returns queued deployment uuid as a string', function () {
    $application = makeDeploymentActionApplication($this->environment, $this->destination);

    $response = $this->withHeaders(deploymentActionHeaders($this->token))
        ->postJson("/api/v1/applications/{$application->uuid}/start");

    $response->assertSuccessful()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('message', 'Deployment request queued.')
            ->whereType('deployment_uuid', 'string')
        );

    expect(ApplicationDeploymentQueue::query()->where('deployment_uuid', $response->json('deployment_uuid'))->exists())->toBeTrue();
});

test('application restart API returns queued deployment uuid as a string', function () {
    $application = makeDeploymentActionApplication($this->environment, $this->destination);

    $response = $this->withHeaders(deploymentActionHeaders($this->token))
        ->postJson("/api/v1/applications/{$application->uuid}/restart");

    $response->assertSuccessful()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('message', 'Restart request queued.')
            ->whereType('deployment_uuid', 'string')
        );

    $deployment = ApplicationDeploymentQueue::query()
        ->where('deployment_uuid', $response->json('deployment_uuid'))
        ->first();

    expect($deployment)->not->toBeNull()
        ->and($deployment->restart_only)->toBeTruthy();
});

test('deployment uuid strings are not converted as objects in API and webhook controllers', function () {
    $files = [
        app_path('Http/Controllers/Api/DeployController.php'),
        app_path('Http/Controllers/Webhook/Bitbucket.php'),
        app_path('Http/Controllers/Webhook/Gitea.php'),
        app_path('Http/Controllers/Webhook/Gitlab.php'),
    ];

    foreach ($files as $file) {
        expect(file_get_contents($file))
            ->not->toContain('$deployment_uuid->toString()')
            ->not->toContain('$deployment_uuid?->toString()');
    }
});
