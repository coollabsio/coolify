<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

if (! function_exists('mcpPost')) {
    function mcpPost(array $payload, ?string $token = null)
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ];
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return test()->withHeaders($headers)->postJson('/mcp', $payload);
    }
}

if (! function_exists('mcpCallTool')) {
    function mcpCallTool(string $token, string $name, array $arguments = [])
    {
        return mcpPost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => (object) $arguments,
            ],
        ], $token);
    }
}

if (! function_exists('mcpToolJson')) {
    function mcpToolJson($response): ?array
    {
        return json_decode($response->json('result.content.0.text'), true);
    }
}

beforeEach(function () {
    InstanceSettings::query()->where('id', 0)->delete();
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $this->otherApplication = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
    ]);
});

function seedDeployment(Application $application, array $overrides = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create(array_merge([
        'application_id' => $application->id,
        'deployment_uuid' => (string) \Illuminate\Support\Str::uuid(),
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'commit' => 'abc123',
        'commit_message' => 'Initial commit',
        'is_webhook' => false,
        'logs' => json_encode([
            ['command' => null, 'output' => 'Building image', 'type' => 'stdout', 'timestamp' => now()->toIso8601String(), 'hidden' => false, 'batch' => 1, 'order' => 1],
            ['command' => null, 'output' => 'Build finished', 'type' => 'stdout', 'timestamp' => now()->toIso8601String(), 'hidden' => false, 'batch' => 1, 'order' => 2],
        ]),
    ], $overrides));
}

test('list_deployments returns deployment history scoped to the token team', function () {
    seedDeployment($this->application);
    seedDeployment($this->otherApplication);

    $response = mcpCallTool($this->token, 'list_deployments', ['application_uuid' => $this->application->uuid]);
    $response->assertOk();

    $body = mcpToolJson($response);
    expect($body['data'])->toHaveCount(1);
    expect($body['data'][0])->toHaveKeys(['uuid', 'status', 'commit', 'commit_message', 'created_at', 'finished_at']);
});

test('list_deployments returns error for an application owned by another team', function () {
    $response = mcpCallTool($this->token, 'list_deployments', ['application_uuid' => $this->otherApplication->uuid]);

    $body = mcpToolJson($response);
    expect($body)->toBeNull();
    expect($response->json('result.content.0.text'))->toContain('not found');
});

test('get_deployment_logs decodes and windows the logs blob', function () {
    $deployment = seedDeployment($this->application);

    $response = mcpCallTool($this->token, 'get_deployment_logs', [
        'application_uuid' => $this->application->uuid,
        'deployment_uuid' => $deployment->deployment_uuid,
    ]);
    $response->assertOk();

    $body = mcpToolJson($response);
    expect($body['data']['deployment']['uuid'])->toBe($deployment->deployment_uuid);
    expect($body['data']['lines'])->toHaveCount(2);
    expect($body['data']['lines'][0]['line'])->toBe('Building image');
});

test('get_deployment_logs returns error for a deployment belonging to another team application', function () {
    $deployment = seedDeployment($this->otherApplication);

    $response = mcpCallTool($this->token, 'get_deployment_logs', [
        'application_uuid' => $this->application->uuid,
        'deployment_uuid' => $deployment->deployment_uuid,
    ]);

    expect($response->json('result.content.0.text'))->toContain('not found');
});

test('list_service_containers enumerates applications and databases with derived container names', function () {
    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);
    ServiceApplication::create(['service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:alpine']);
    ServiceDatabase::create(['service_id' => $service->id, 'name' => 'db', 'image' => 'postgres:16-alpine', 'custom_type' => 'postgresql']);

    $response = mcpCallTool($this->token, 'list_service_containers', ['uuid' => $service->uuid]);
    $response->assertOk();

    $body = mcpToolJson($response);
    $containerNames = collect($body['data'])->pluck('container_name')->all();
    expect($containerNames)->toContain("web-{$service->uuid}", "db-{$service->uuid}");
});

test('get_service_container_logs rejects a container name that does not belong to the service', function () {
    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);
    ServiceApplication::create(['service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:alpine']);

    $response = mcpCallTool($this->token, 'get_service_container_logs', [
        'uuid' => $service->uuid,
        'container' => 'not-a-real-container;whoami',
    ]);

    expect($response->json('result.content.0.text'))->toContain('does not belong to service');
});

test('get_service_container_logs tails a valid container over SSH', function () {
    Process::fake([
        '*docker logs*' => Process::result(output: "line one\nline two"),
    ]);

    $this->server->settings->fill(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false])->save();

    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);
    ServiceApplication::create(['service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:alpine']);

    $response = mcpCallTool($this->token, 'get_service_container_logs', [
        'uuid' => $service->uuid,
        'container' => "web-{$service->uuid}",
    ]);
    $response->assertOk();

    $body = mcpToolJson($response);
    expect($body['data']['lines'])->toBe(['line one', 'line two']);
});

test('get_application_logs falls back to the crash-log snapshot when no live container exists', function () {
    Process::fake();

    $this->server->settings->fill(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false])->save();

    $this->application->update([
        'last_crash_logs' => ['app-container' => "boom\nexit 1"],
        'last_crash_logs_captured_at' => now(),
        'status' => 'exited:unhealthy',
        'restart_count' => 10,
        'max_restart_count' => 10,
        'last_restart_type' => 'crash',
    ]);

    $response = mcpCallTool($this->token, 'get_application_logs', ['application_uuid' => $this->application->uuid]);
    $response->assertOk();

    $body = mcpToolJson($response);
    expect($body['data']['source'])->toBe('crash_snapshot');
    expect($body['data']['stopped_after_restart_limit'])->toBeTrue();
    expect($body['data']['containers'][0]['container'])->toBe('app-container');
});

test('get_application_health correlates a failed deployment with restart info', function () {
    $deployment = seedDeployment($this->application, ['status' => ApplicationDeploymentStatus::FAILED->value]);
    $this->application->update([
        'status' => 'exited:unhealthy',
        'restart_count' => 10,
        'max_restart_count' => 10,
        'last_restart_type' => 'crash',
    ]);

    $response = mcpCallTool($this->token, 'get_application_health', ['application_uuid' => $this->application->uuid]);
    $response->assertOk();

    $body = mcpToolJson($response);
    expect($body['data']['last_deployment']['uuid'])->toBe($deployment->deployment_uuid);
    expect($body['data']['last_deployment']['status'])->toBe('failed');
    expect($body['data']['stopped_after_restart_limit'])->toBeTrue();
    $actionTools = collect($body['_actions'])->pluck('tool')->all();
    expect($actionTools)->toContain('get_deployment_logs', 'get_application_logs');
});
