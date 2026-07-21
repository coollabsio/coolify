<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
    // Server::created auto-provisions a default StandaloneDocker (network=coolify).
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    // Project::created auto-creates a production environment.
    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://app.example.com',
    ]);
});

function mcpReadToken(): string
{
    return test()->user->createToken('mcp-read', ['read'])->plainTextToken;
}

function mcpReadCall(string $name, array $arguments = [])
{
    return test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.mcpReadToken(),
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => $name,
            'arguments' => (object) $arguments,
        ],
    ]);
}

function mcpReadJson($response): array
{
    return json_decode($response->json('result.content.0.text'), true);
}

test('tools/list includes new read tools and excludes control', function () {
    $token = mcpReadToken();
    $response = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => (object) [],
    ]);

    $response->assertOk();
    $names = collect($response->json('result.tools'))->pluck('name')->all();

    expect($names)->toContain(
        'get_project',
        'get_environment',
        'list_resources',
        'list_deployments',
        'get_deployment',
        'get_logs',
        'list_env_keys',
        'list_storages',
        'list_destinations',
        'get_destination',
        'get_server_domains',
        'get_server_resources',
        'list_tags',
        'list_github_apps',
        'get_current_team',
        'list_team_members',
        'list_database_backups',
        'list_service_applications',
        'list_service_databases',
    );
    expect($names)->not->toContain('control');
});

test('get_project returns environments and counts for team project only', function () {
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);

    $response = mcpReadCall('get_project', ['uuid' => $this->project->uuid]);
    $response->assertOk();
    $body = mcpReadJson($response);

    expect($body['data']['uuid'])->toBe($this->project->uuid);
    expect($body['data']['counts']['applications'])->toBeGreaterThanOrEqual(1);
    expect($body['data']['environments'])->not->toBeEmpty();

    $denied = mcpReadCall('get_project', ['uuid' => $otherProject->uuid]);
    expect($denied->json('result.isError'))->toBeTrue();
});

test('get_environment is team scoped via project', function () {
    $response = mcpReadCall('get_environment', [
        'project_uuid' => $this->project->uuid,
        'environment_name_or_uuid' => $this->environment->name,
    ]);
    $response->assertOk();
    $body = mcpReadJson($response);

    expect($body['data']['uuid'])->toBe($this->environment->uuid);
    expect(collect($body['data']['applications'])->pluck('uuid'))->toContain($this->application->uuid);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = Environment::factory()->create(['project_id' => $otherProject->id]);

    $denied = mcpReadCall('get_environment', [
        'project_uuid' => $otherProject->uuid,
        'environment_name_or_uuid' => $otherEnv->uuid,
    ]);
    expect($denied->json('result.isError'))->toBeTrue();
});

test('list_resources only returns team resources', function () {
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDest = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();
    Application::factory()->create([
        'name' => 'OtherTeamApp',
        'environment_id' => $otherEnv->id,
        'destination_id' => $otherDest->id,
        'destination_type' => $otherDest->getMorphClass(),
    ]);

    $response = mcpReadCall('list_resources');
    $response->assertOk();
    $body = mcpReadJson($response);

    $uuids = collect($body['data'])->pluck('uuid');
    $names = collect($body['data'])->pluck('name');
    expect($uuids)->toContain($this->application->uuid);
    expect($names)->not->toContain('OtherTeamApp');
});

test('list_deployments and get_deployment are team scoped and scrub logs', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep-'.fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $this->server->id,
        'application_name' => $this->application->name,
        'server_name' => $this->server->name,
        'commit' => 'abc123',
        'logs' => json_encode([['name' => 'build', 'output' => 'SECRET_TOKEN=supersecret']]),
    ]);

    $list = mcpReadCall('list_deployments');
    $list->assertOk();
    $listBody = mcpReadJson($list);
    expect(collect($listBody['data'])->pluck('deployment_uuid'))->toContain($deployment->deployment_uuid);
    expect(json_encode($listBody))->not->toContain('supersecret');
    expect(json_encode($listBody))->not->toContain('"logs"');

    $get = mcpReadCall('get_deployment', ['uuid' => $deployment->deployment_uuid]);
    $get->assertOk();
    $getBody = mcpReadJson($get);
    expect($getBody['data']['deployment_uuid'])->toBe($deployment->deployment_uuid);
    expect($getBody['data']['application_uuid'])->toBe($this->application->uuid);
    expect(json_encode($getBody))->not->toContain('supersecret');

    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherDest = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();
    $otherApp = Application::factory()->create([
        'environment_id' => $otherEnv->id,
        'destination_id' => $otherDest->id,
        'destination_type' => $otherDest->getMorphClass(),
    ]);
    $otherDep = ApplicationDeploymentQueue::create([
        'application_id' => $otherApp->id,
        'deployment_uuid' => 'dep-other-'.fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $otherServer->id,
        'application_name' => $otherApp->name,
        'server_name' => $otherServer->name,
    ]);

    $denied = mcpReadCall('get_deployment', ['uuid' => $otherDep->deployment_uuid]);
    expect($denied->json('result.isError'))->toBeTrue();
});

test('list_env_keys never returns values and is team scoped', function () {
    EnvironmentVariable::create([
        'key' => 'DATABASE_URL',
        'value' => 'postgres://secret@localhost/db',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
    ]);

    $response = mcpReadCall('list_env_keys', [
        'resource' => 'application',
        'uuid' => $this->application->uuid,
    ]);
    $response->assertOk();
    $body = mcpReadJson($response);
    $raw = json_encode($body);

    expect(collect($body['data']['keys'])->pluck('key'))->toContain('DATABASE_URL');
    expect($raw)->not->toContain('postgres://secret');
    expect($raw)->not->toContain('"value"');
    expect($raw)->not->toContain('real_value');

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDest = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();
    $otherApp = Application::factory()->create([
        'environment_id' => $otherEnv->id,
        'destination_id' => $otherDest->id,
        'destination_type' => $otherDest->getMorphClass(),
    ]);

    $denied = mcpReadCall('list_env_keys', [
        'resource' => 'application',
        'uuid' => $otherApp->uuid,
    ]);
    expect($denied->json('result.isError'))->toBeTrue();
});

test('list_destinations and get_destination are team scoped', function () {
    $response = mcpReadCall('list_destinations');
    $response->assertOk();
    $body = mcpReadJson($response);
    expect(collect($body['data'])->pluck('uuid'))->toContain($this->destination->uuid);

    $get = mcpReadCall('get_destination', ['uuid' => $this->destination->uuid]);
    $get->assertOk();
    expect(mcpReadJson($get)['data']['uuid'])->toBe($this->destination->uuid);

    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDest = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();
    $denied = mcpReadCall('get_destination', ['uuid' => $otherDest->uuid]);
    expect($denied->json('result.isError'))->toBeTrue();
});

test('get_server_domains and get_server_resources are team scoped', function () {
    $domains = mcpReadCall('get_server_domains', ['uuid' => $this->server->uuid]);
    $domains->assertOk();
    $domainBody = mcpReadJson($domains);
    expect($domainBody['data']['server_uuid'])->toBe($this->server->uuid);

    $resources = mcpReadCall('get_server_resources', ['uuid' => $this->server->uuid]);
    $resources->assertOk();

    $otherServer = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    expect(mcpReadCall('get_server_domains', ['uuid' => $otherServer->uuid])->json('result.isError'))->toBeTrue();
    expect(mcpReadCall('get_server_resources', ['uuid' => $otherServer->uuid])->json('result.isError'))->toBeTrue();
});

test('list_tags and get_current_team and list_team_members are team scoped', function () {
    Tag::create(['name' => 'prod', 'team_id' => $this->team->id]);
    Tag::create(['name' => 'theirs', 'team_id' => Team::factory()->create()->id]);

    $tags = mcpReadCall('list_tags');
    $tags->assertOk();
    $tagNames = collect(mcpReadJson($tags)['data'])->pluck('name');
    expect($tagNames)->toContain('prod');
    expect($tagNames)->not->toContain('theirs');

    $team = mcpReadCall('get_current_team');
    $team->assertOk();
    expect(mcpReadJson($team)['data']['name'])->toBe($this->team->name);

    $members = mcpReadCall('list_team_members');
    $members->assertOk();
    expect(collect(mcpReadJson($members)['data'])->pluck('email'))->toContain($this->user->email);
});

test('list_github_apps is team scoped and scrubs secrets', function () {
    $app = GithubApp::create([
        'name' => 'Mine',
        'team_id' => $this->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 1,
        'installation_id' => 1,
        'client_id' => 'client-id',
        'client_secret' => 'super-client-secret',
        'webhook_secret' => 'super-webhook-secret',
        'is_public' => false,
        'is_system_wide' => false,
    ]);

    GithubApp::create([
        'name' => 'Theirs',
        'team_id' => Team::factory()->create()->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 2,
        'installation_id' => 2,
        'client_id' => 'other-client',
        'client_secret' => 'other-secret',
        'webhook_secret' => 'other-webhook',
        'is_public' => false,
        'is_system_wide' => false,
    ]);

    $response = mcpReadCall('list_github_apps');
    $response->assertOk();
    $body = mcpReadJson($response);
    $names = collect($body['data'])->pluck('name');
    $raw = json_encode($body);

    expect($names)->toContain('Mine');
    expect($names)->not->toContain('Theirs');
    expect($raw)->not->toContain('super-client-secret');
    expect($raw)->not->toContain('super-webhook-secret');
    expect(collect($body['data'])->pluck('uuid'))->toContain($app->uuid);
});

test('list_applications project_uuid filter is team scoped', function () {
    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    $otherEnv = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    Application::factory()->create([
        'name' => 'OtherProjectApp',
        'environment_id' => $otherEnv->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $response = mcpReadCall('list_applications', ['project_uuid' => $this->project->uuid]);
    $response->assertOk();
    $body = mcpReadJson($response);
    $names = collect($body['data'])->pluck('name');
    expect($names)->toContain($this->application->name);
    expect($names)->not->toContain('OtherProjectApp');
});

test('get_logs rejects other team application uuid', function () {
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDest = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();
    $otherApp = Application::factory()->create([
        'environment_id' => $otherEnv->id,
        'destination_id' => $otherDest->id,
        'destination_type' => $otherDest->getMorphClass(),
    ]);

    $response = mcpReadCall('get_logs', [
        'resource' => 'application',
        'uuid' => $otherApp->uuid,
    ]);
    expect($response->json('result.isError'))->toBeTrue();
});
