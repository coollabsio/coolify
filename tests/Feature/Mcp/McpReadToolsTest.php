<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

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

function mcpSensitiveReadCall(string $name, array $arguments = [])
{
    $token = test()->user->createToken('mcp-sensitive-read', ['read', 'read:sensitive'])->plainTextToken;

    return test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
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

test('tools/list includes new read tools and lifecycle tools', function () {
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
        'search_resources',
        'list_unhealthy_resources',
        'list_application_previews',
        'list_shared_env_keys',
        'coolify_help',
        'control',
        'deploy',
        'cancel_deployment',
    );
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

    $response = mcpSensitiveReadCall('get_logs', [
        'resource' => 'application',
        'uuid' => $otherApp->uuid,
    ]);
    expect($response->json('result.isError'))->toBeTrue();
});

test('search_resources finds team app by name and domain and excludes other team', function () {
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDest = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();
    Application::factory()->create([
        'name' => 'SecretOtherApp',
        'fqdn' => 'https://app.example.com',
        'environment_id' => $otherEnv->id,
        'destination_id' => $otherDest->id,
        'destination_type' => $otherDest->getMorphClass(),
    ]);

    $byName = mcpReadCall('search_resources', ['query' => $this->application->name]);
    $byName->assertOk();
    $names = collect(mcpReadJson($byName)['data']['results'])->pluck('name');
    expect($names)->toContain($this->application->name);
    expect($names)->not->toContain('SecretOtherApp');

    $byDomain = mcpReadCall('search_resources', ['query' => 'app.example.com', 'types' => 'application']);
    $byDomain->assertOk();
    $uuids = collect(mcpReadJson($byDomain)['data']['results'])->pluck('uuid');
    expect($uuids)->toContain($this->application->uuid);
});

test('list_unhealthy_resources includes non-running apps and is team scoped', function () {
    $this->application->update(['status' => 'exited:unhealthy']);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDest = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();
    Application::factory()->create([
        'name' => 'OtherDown',
        'status' => 'exited:unhealthy',
        'environment_id' => $otherEnv->id,
        'destination_id' => $otherDest->id,
        'destination_type' => $otherDest->getMorphClass(),
    ]);

    $response = mcpReadCall('list_unhealthy_resources');
    $response->assertOk();
    $body = mcpReadJson($response);
    $names = collect($body['data']['unhealthy'])->pluck('name');
    expect($names)->toContain($this->application->name);
    expect($names)->not->toContain('OtherDown');
});

test('list_application_previews is team scoped', function () {
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/org/repo/pull/42',
        'fqdn' => 'https://pr-42.example.com',
        'status' => 'running:healthy',
    ]);

    $response = mcpReadCall('list_application_previews', ['uuid' => $this->application->uuid]);
    $response->assertOk();
    $body = mcpReadJson($response);
    expect(collect($body['data']['previews'])->pluck('uuid'))->toContain($preview->uuid);
    expect(collect($body['data']['previews'])->pluck('pull_request_id'))->toContain(42);

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

    expect(mcpReadCall('list_application_previews', ['uuid' => $otherApp->uuid])->json('result.isError'))->toBeTrue();
});

test('list_shared_env_keys returns names without values and is team scoped', function () {
    SharedEnvironmentVariable::create([
        'key' => 'SHARED_API_URL',
        'value' => 'https://secret.example.com',
        'type' => 'project',
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
    ]);

    $response = mcpReadCall('list_shared_env_keys', [
        'scope' => 'project',
        'uuid' => $this->project->uuid,
    ]);
    $response->assertOk();
    $body = mcpReadJson($response);
    $raw = json_encode($body);
    expect(collect($body['data']['keys'])->pluck('key'))->toContain('SHARED_API_URL');
    expect($raw)->not->toContain('secret.example.com');
    expect($raw)->not->toContain('"value"');

    $otherProject = Project::factory()->create(['team_id' => Team::factory()->create()->id]);
    expect(mcpReadCall('list_shared_env_keys', [
        'scope' => 'project',
        'uuid' => $otherProject->uuid,
    ])->json('result.isError'))->toBeTrue();
});

test('get_deployment include_log_summary returns capped redacted text', function () {
    $logs = json_encode([
        ['output' => 'step 1 ok', 'type' => 'stdout', 'hidden' => false],
        ['output' => 'password=supersecretvalue', 'type' => 'stderr', 'hidden' => false],
        ['output' => 'done', 'type' => 'stdout', 'hidden' => false],
    ]);

    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep-log-'.fake()->uuid(),
        'status' => 'failed',
        'server_id' => $this->server->id,
        'application_name' => $this->application->name,
        'server_name' => $this->server->name,
        'commit' => 'deadbeef',
        'logs' => $logs,
    ]);

    $response = mcpReadCall('get_deployment', [
        'uuid' => $deployment->deployment_uuid,
        'include_log_summary' => true,
        'log_lines' => 10,
    ]);
    $response->assertOk();
    $body = mcpReadJson($response);

    expect($body['data']['log_summary']['available'])->toBeTrue();
    expect($body['data']['log_summary']['text'])->toContain('step 1 ok');
    expect($body['data']['log_summary']['text'])->not->toContain('supersecretvalue');
    expect($body['data']['log_summary']['text'])->toContain('password=');
    // Full logs field still scrubbed from root payload
    expect(json_encode($body))->not->toContain('"logs":');
});

test('list_servers reachable filter works', function () {
    $this->server->settings->forceFill(['is_reachable' => true])->saveQuietly();

    $response = mcpReadCall('list_servers', ['reachable' => true]);
    $response->assertOk();
    $uuids = collect(mcpReadJson($response)['data'])->pluck('uuid');
    expect($uuids)->toContain($this->server->uuid);

    $none = mcpReadCall('list_servers', ['reachable' => false]);
    $none->assertOk();
    expect(collect(mcpReadJson($none)['data'])->pluck('uuid'))->not->toContain($this->server->uuid);
});

test('list_applications status and server_uuid filters work', function () {
    $this->application->update(['status' => 'running:healthy']);

    $byStatus = mcpReadCall('list_applications', ['status' => 'running']);
    $byStatus->assertOk();
    expect(collect(mcpReadJson($byStatus)['data'])->pluck('uuid'))->toContain($this->application->uuid);

    $byServer = mcpReadCall('list_applications', ['server_uuid' => $this->server->uuid]);
    $byServer->assertOk();
    expect(collect(mcpReadJson($byServer)['data'])->pluck('uuid'))->toContain($this->application->uuid);

    $missingServer = mcpReadCall('list_applications', ['server_uuid' => 'no-such-server']);
    $missingServer->assertOk();
    expect(mcpReadJson($missingServer)['data'])->toBe([]);
});

test('list_services filters by its computed status before pagination', function () {
    Service::factory()->create([
        'name' => 'Matching service',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    Service::factory()->create([
        'name' => 'Another service',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $response = mcpReadCall('list_services', [
        'status' => 'unknown',
        'per_page' => 1,
    ]);

    $response->assertOk();
    $body = mcpReadJson($response);

    expect($body['_pagination']['total'])->toBe(2)
        ->and($body['data'])->toHaveCount(1)
        ->and($body['data'][0]['status'])->toContain('unknown');
});

test('MCP lists prompts for troubleshooting workflows', function () {
    $token = mcpReadToken();
    $response = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'prompts/list',
        'params' => (object) [],
    ]);

    $response->assertOk();
    $names = collect($response->json('result.prompts'))->pluck('name')->all();
    expect($names)->toContain('troubleshoot_application', 'explain_failed_deploy');
});

test('get_logs requires sensitive read ability', function () {
    $this->application->update(['status' => 'running:healthy']);

    $response = mcpReadCall('get_logs', [
        'resource' => 'application',
        'uuid' => $this->application->uuid,
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('read:sensitive');
});

test('team members cannot retrieve logs with sensitive read ability', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    $response = mcpSensitiveReadCall('get_logs', [
        'resource' => 'application',
        'uuid' => $this->application->uuid,
    ]);

    expect($response->status())->toBeIn([403]);
});

test('get_logs returns structured next_tools when application is not running', function () {
    $this->application->update(['status' => 'exited:unhealthy']);

    $response = mcpSensitiveReadCall('get_logs', [
        'resource' => 'application',
        'uuid' => $this->application->uuid,
    ]);
    $response->assertOk();
    $body = mcpReadJson($response);

    expect($body['data']['ok'])->toBeFalse();
    expect($body['data']['reason'])->toBe('not_running');
    expect($body['data']['next_tools'])->not->toBeEmpty();
    expect(collect($body['data']['next_tools'])->pluck('tool'))->toContain('list_deployments', 'list_unhealthy_resources');
});

test('coolify_help returns catalog intents', function () {
    $response = mcpReadCall('coolify_help', ['intent' => 'essentials']);
    $response->assertOk();
    $body = mcpReadJson($response);
    expect($body['data']['catalog']['essentials']['tools'])->toContain('search_resources', 'control');
});

test('list_unhealthy_resources sample_only returns summary and samples', function () {
    $this->application->update(['status' => 'exited:unhealthy']);

    $response = mcpReadCall('list_unhealthy_resources', ['sample_only' => true, 'sample_per_type' => 3]);
    $response->assertOk();
    $body = mcpReadJson($response);
    expect($body['data']['sample_only'])->toBeTrue();
    expect($body['data']['summary'])->toHaveKeys(['total', 'applications', 'servers']);
    expect($body['data']['samples'])->toHaveKeys(['applications', 'servers', 'services', 'databases']);
});

test('control and deploy require deploy ability', function () {
    $denied = mcpReadCall('control', [
        'resource' => 'application',
        'action' => 'start',
        'uuid' => $this->application->uuid,
    ]);
    $denied->assertOk();
    expect($denied->json('result.isError'))->toBeTrue();
    expect($denied->json('result.content.0.text'))->toContain('Missing required permissions');

    $deployDenied = mcpReadCall('deploy', ['uuid' => $this->application->uuid]);
    expect($deployDenied->json('result.isError'))->toBeTrue();
});

test('control stop requires confirm', function () {
    $token = test()->user->createToken('mcp-deploy', ['read', 'deploy'])->plainTextToken;
    $response = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'control',
            'arguments' => (object) [
                'resource' => 'application',
                'action' => 'stop',
                'uuid' => $this->application->uuid,
            ],
        ],
    ]);
    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('confirm=true');
});

test('team member with deploy ability cannot call lifecycle tools', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    $token = $this->user->createToken('mcp-member-deploy', ['read', 'deploy'])->plainTextToken;

    $response = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'control',
            'arguments' => (object) [
                'resource' => 'application',
                'action' => 'start',
                'uuid' => $this->application->uuid,
            ],
        ],
    ]);

    // Middleware returns 403; ensureAbility would also deny if middleware were bypassed.
    expect($response->status())->toBeIn([403]);
    expect($response->json('message') ?? $response->json('result.content.0.text') ?? '')
        ->toMatch('/team role|Missing required/i');
});

test('control start with deploy ability queues application deployment', function () {
    Bus::fake();

    $token = $this->user->createToken('mcp-deploy-start', ['read', 'deploy'])->plainTextToken;
    $response = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'control',
            'arguments' => (object) [
                'resource' => 'application',
                'action' => 'start',
                'uuid' => $this->application->uuid,
            ],
        ],
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse();
    $body = mcpReadJson($response);
    expect($body['data']['ok'])->toBeTrue()
        ->and($body['data']['action'])->toBe('start')
        ->and($body['data']['deployment_uuid'])->not->toBeEmpty();
});

test('deploy tool queues application deployment', function () {
    Bus::fake();

    $token = $this->user->createToken('mcp-deploy-tool', ['read', 'deploy'])->plainTextToken;
    $response = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'deploy',
            'arguments' => (object) [
                'uuid' => $this->application->uuid,
                'force' => false,
            ],
        ],
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse();
    $body = mcpReadJson($response);
    expect($body['data']['ok'])->toBeTrue()
        ->and($body['data']['deployment_uuid'])->not->toBeEmpty();
    expect(ApplicationDeploymentQueue::where('deployment_uuid', $body['data']['deployment_uuid'])->exists())->toBeTrue();
});

test('cancel_deployment cancels team deployment and rejects other team', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep-cancel-'.fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $this->server->id,
        'application_name' => $this->application->name,
        'server_name' => $this->server->name,
        'commit' => 'abc',
        'current_process_id' => '12345',
    ]);

    $token = $this->user->createToken('mcp-cancel', ['read', 'deploy'])->plainTextToken;
    $ok = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'cancel_deployment',
            'arguments' => (object) ['uuid' => $deployment->deployment_uuid],
        ],
    ]);

    $ok->assertOk();
    expect($ok->json('result.isError'))->toBeFalse();
    $body = mcpReadJson($ok);
    expect($body['data']['ok'])->toBeTrue()
        ->and($body['data']['status'])->toBe('cancelled-by-user');
    expect($deployment->fresh()->status)->toBe('cancelled-by-user');

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
        'deployment_uuid' => 'dep-other-cancel-'.fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $otherServer->id,
        'application_name' => $otherApp->name,
        'server_name' => $otherServer->name,
    ]);

    $denied = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'cancel_deployment',
            'arguments' => (object) ['uuid' => $otherDep->deployment_uuid],
        ],
    ]);
    expect($denied->json('result.isError'))->toBeTrue();
    expect($otherDep->fresh()->status)->toBe('in_progress');
});

test('cancel_deployment updates only a still cancellable deployment', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep-atomic-cancel-'.fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $this->server->id,
        'application_name' => $this->application->name,
        'server_name' => $this->server->name,
    ]);

    $updates = [];
    DB::listen(function ($query) use (&$updates) {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
            $updates[] = strtolower($query->sql);
        }
    });

    $token = $this->user->createToken('mcp-atomic-cancel', ['read', 'deploy'])->plainTextToken;
    $response = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'cancel_deployment',
            'arguments' => (object) ['uuid' => $deployment->deployment_uuid],
        ],
    ]);

    $response->assertOk();
    expect(collect($updates)->contains(
        fn (string $sql) => str_contains($sql, 'application_deployment_queues')
            && str_contains($sql, 'status')
            && str_contains($sql, ' in '),
    ))->toBeTrue();
});

test('MCP resources list includes overview and application template', function () {
    $token = mcpReadToken();
    $response = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'resources/list',
        'params' => (object) [],
    ]);

    $response->assertOk();
    $uris = collect($response->json('result.resources'))->pluck('uri')->filter()->all();
    $templates = collect($response->json('result.resources'))->pluck('uriTemplate')->filter()->all();

    // Static resource may appear under resources; templates under list or templates/list depending on server.
    $all = collect($uris)->merge($templates)->implode(' ');
    expect($all)->toContain('coolify://');
});
