<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Mcp\Concerns\BuildsResponse;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\SwarmDocker;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'app.maintenance.driver' => 'file',
    ]);

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
    // Ensure each call resolves the Bearer token freshly (no guard bleed between tokens).
    auth()->forgetGuards();

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
    // Ensure each call resolves the Bearer token freshly (no guard bleed between tokens).
    auth()->forgetGuards();

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

test('database backup tools scope schedules by database type and id', function () {
    $postgres = StandalonePostgresql::create([
        'name' => 'postgres',
        'postgres_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $mysql = StandaloneMysql::create([
        'name' => 'mysql',
        'mysql_root_password' => 'password',
        'mysql_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    expect($mysql->id)->toBe($postgres->id);

    $postgresBackup = ScheduledDatabaseBackup::create([
        'team_id' => $this->team->id,
        'frequency' => '0 0 * * *',
        'database_id' => $postgres->id,
        'database_type' => $postgres->getMorphClass(),
    ]);
    $mysqlBackup = ScheduledDatabaseBackup::create([
        'team_id' => $this->team->id,
        'frequency' => '0 0 * * *',
        'database_id' => $mysql->id,
        'database_type' => $mysql->getMorphClass(),
    ]);

    $response = mcpReadCall('list_database_backups', ['uuid' => $postgres->uuid]);
    $response->assertOk();

    $backupUuids = collect(mcpReadJson($response)['data']['backups'])->pluck('uuid');
    expect($backupUuids)
        ->toContain($postgresBackup->uuid)
        ->not->toContain($mysqlBackup->uuid);

    $response = mcpReadCall('list_backup_executions', [
        'database_uuid' => $postgres->uuid,
        'scheduled_backup_uuid' => $mysqlBackup->uuid,
    ]);
    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
});

test('list_backup_executions omits messages without sensitive read and redacts when included', function () {
    $postgres = StandalonePostgresql::create([
        'name' => 'backup-msg-db',
        'postgres_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->team->id,
        'frequency' => '0 0 * * *',
        'database_id' => $postgres->id,
        'database_type' => $postgres->getMorphClass(),
    ]);

    $older = ScheduledDatabaseBackupExecution::create([
        'uuid' => (string) Str::uuid(),
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'message' => 'older backup ok',
        'size' => 100,
        'filename' => 'old.sql.gz',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    $newer = ScheduledDatabaseBackupExecution::create([
        'uuid' => (string) Str::uuid(),
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'failed',
        'message' => "backup failed password=redactme01\n",
        'size' => 0,
        'filename' => 'new.sql.gz',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $readOnly = mcpReadCall('list_backup_executions', [
        'database_uuid' => $postgres->uuid,
        'scheduled_backup_uuid' => $backup->uuid,
        'page' => 1,
        'per_page' => 1,
    ]);
    $readOnly->assertOk();
    $readBody = mcpReadJson($readOnly);
    expect($readBody['data']['message_included'])->toBeFalse()
        ->and($readBody['data']['executions'])->toHaveCount(1)
        ->and($readBody['data']['executions'][0]['status'])->toBe('failed')
        ->and($readBody['data']['executions'][0]['filename'])->toBe('new.sql.gz')
        ->and($readBody['data']['executions'][0])->not->toHaveKey('message')
        ->and($readBody['_pagination']['total'])->toBe(2);

    $page2 = mcpReadCall('list_backup_executions', [
        'database_uuid' => $postgres->uuid,
        'scheduled_backup_uuid' => $backup->uuid,
        'page' => 2,
        'per_page' => 1,
    ]);
    $page2->assertOk();
    expect(mcpReadJson($page2)['data']['executions'][0]['status'])->toBe('success');

    $sensitive = mcpSensitiveReadCall('list_backup_executions', [
        'database_uuid' => $postgres->uuid,
        'scheduled_backup_uuid' => $backup->uuid,
        'page' => 1,
        'per_page' => 1,
    ]);
    $sensitive->assertOk();
    $sensitiveBody = mcpReadJson($sensitive);
    $message = $sensitiveBody['data']['executions'][0]['message'] ?? '';
    expect($sensitiveBody['data']['message_included'])->toBeTrue()
        ->and($message)->not->toContain('redactme01')
        ->and($message)->toContain('password=')
        ->and($message)->toContain(REDACTED);

    expect($older->id)->not->toBe($newer->id);
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
    expect($body['data']['counts']['applications'])->toBeGreaterThanOrEqual(1);
    expect($body['data']['truncated']['applications'])->toBeFalse();

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = Environment::factory()->create(['project_id' => $otherProject->id]);

    $denied = mcpReadCall('get_environment', [
        'project_uuid' => $otherProject->uuid,
        'environment_name_or_uuid' => $otherEnv->uuid,
    ]);
    expect($denied->json('result.isError'))->toBeTrue();
});

test('get_environment caps resource samples and points to list tools when truncated', function () {
    foreach (['env-app-a', 'env-app-b', 'env-app-c'] as $name) {
        Application::factory()->create([
            'name' => $name,
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
    }

    $response = mcpReadCall('get_environment', [
        'project_uuid' => $this->project->uuid,
        'environment_name_or_uuid' => $this->environment->uuid,
        'sample_per_type' => 2,
    ]);
    $response->assertOk();
    $body = mcpReadJson($response);

    // beforeEach already has one application in this environment.
    expect($body['data']['counts']['applications'])->toBeGreaterThanOrEqual(4)
        ->and($body['data']['applications'])->toHaveCount(2)
        ->and($body['data']['truncated']['applications'])->toBeTrue()
        ->and(collect($body['data']['next_tools'])->pluck('tool')->all())->toContain('list_applications');
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

test('list_resources paginates sorts and filters at the query layer', function () {
    $this->application->update(['name' => 'Charlie App']);

    $alphaApp = Application::factory()->create([
        'name' => 'Alpha App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $bravoService = Service::factory()->create([
        'name' => 'Bravo Service',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $deltaDb = StandalonePostgresql::create([
        'name' => 'Delta DB',
        'postgres_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $tag = Tag::create([
        'name' => 'mcp-listed',
        'team_id' => $this->team->id,
    ]);
    $alphaApp->tags()->attach($tag->id);
    $bravoService->tags()->attach($tag->id);

    $otherProject = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Other Project']);
    $otherEnv = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    Application::factory()->create([
        'name' => 'Zed Other Project App',
        'environment_id' => $otherEnv->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $page1 = mcpReadCall('list_resources', ['page' => 1, 'per_page' => 2]);
    $page1->assertOk();
    $body1 = mcpReadJson($page1);
    expect($body1['_pagination']['total'])->toBe(5)
        ->and($body1['_pagination']['per_page'])->toBe(2)
        ->and($body1['_pagination']['page'])->toBe(1)
        ->and(collect($body1['data'])->pluck('name')->all())->toBe(['Alpha App', 'Bravo Service']);

    $page2 = mcpReadCall('list_resources', ['page' => 2, 'per_page' => 2]);
    $page2->assertOk();
    $body2 = mcpReadJson($page2);
    expect(collect($body2['data'])->pluck('name')->all())->toBe(['Charlie App', 'Delta DB']);

    $appsOnly = mcpReadCall('list_resources', ['type' => 'application']);
    $appsOnly->assertOk();
    $appsBody = mcpReadJson($appsOnly);
    expect(collect($appsBody['data'])->pluck('type')->unique()->values()->all())->toBe(['application'])
        ->and($appsBody['_pagination']['total'])->toBe(3);

    $dbsOnly = mcpReadCall('list_resources', ['type' => 'database']);
    $dbsOnly->assertOk();
    $dbsBody = mcpReadJson($dbsOnly);
    expect($dbsBody['_pagination']['total'])->toBe(1)
        ->and($dbsBody['data'][0]['uuid'])->toBe($deltaDb->uuid)
        ->and($dbsBody['data'][0]['type'])->toBe('standalone-postgresql');

    $tagged = mcpReadCall('list_resources', ['tag' => 'mcp-listed']);
    $tagged->assertOk();
    $taggedBody = mcpReadJson($tagged);
    expect(collect($taggedBody['data'])->pluck('uuid')->sort()->values()->all())
        ->toBe(collect([$alphaApp->uuid, $bravoService->uuid])->sort()->values()->all());

    $byProject = mcpReadCall('list_resources', ['project_uuid' => $otherProject->uuid]);
    $byProject->assertOk();
    $projectBody = mcpReadJson($byProject);
    expect($projectBody['_pagination']['total'])->toBe(1)
        ->and($projectBody['data'][0]['name'])->toBe('Zed Other Project App')
        ->and($projectBody['data'][0]['project_uuid'])->toBe($otherProject->uuid);
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
        'logs' => json_encode([['name' => 'build', 'output' => 'SECRET_TOKEN=redactme01']]),
    ]);

    $list = mcpReadCall('list_deployments');
    $list->assertOk();
    $listBody = mcpReadJson($list);
    expect(collect($listBody['data'])->pluck('deployment_uuid'))->toContain($deployment->deployment_uuid);
    expect(json_encode($listBody))->not->toContain('redactme01');
    expect(json_encode($listBody))->not->toContain('"logs"');

    $get = mcpReadCall('get_deployment', ['uuid' => $deployment->deployment_uuid]);
    $get->assertOk();
    $getBody = mcpReadJson($get);
    expect($getBody['data']['deployment_uuid'])->toBe($deployment->deployment_uuid);
    expect($getBody['data']['application_uuid'])->toBe($this->application->uuid);
    expect(json_encode($getBody))->not->toContain('redactme01');

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

test('deployment listings and overview scope shared server deployments by application team', function () {
    $teamDeployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep-team-'.fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $this->server->id,
        'application_name' => $this->application->name,
        'server_name' => $this->server->name,
    ]);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherApplication = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $otherDeployment = ApplicationDeploymentQueue::create([
        'application_id' => $otherApplication->id,
        'deployment_uuid' => 'dep-other-shared-server-'.fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $this->server->id,
        'application_name' => $otherApplication->name,
        'server_name' => $this->server->name,
    ]);

    $listBody = mcpReadJson(mcpReadCall('list_deployments'));
    expect(collect($listBody['data'])->pluck('deployment_uuid'))
        ->toContain($teamDeployment->deployment_uuid)
        ->not->toContain($otherDeployment->deployment_uuid);

    $overviewBody = mcpReadJson(mcpReadCall('get_infrastructure_overview'));
    expect($overviewBody['data']['counts']['open_deployments'])->toBe(1);
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

test('get_application omits free-form configuration that can contain secrets', function () {
    $this->application->update([
        'git_full_url' => 'https://oauth2:application-secret@example.com/repository.git',
        'build_command' => 'API_TOKEN=application-secret npm run build',
        'custom_docker_run_options' => '--env API_TOKEN=application-secret',
        'custom_nginx_configuration' => base64_encode('proxy_set_header Authorization "Bearer application-secret";'),
    ]);

    $response = mcpReadCall('get_application', ['uuid' => $this->application->uuid]);
    $response->assertOk();

    $body = mcpReadJson($response);
    $raw = json_encode($body);

    expect($body['data']['uuid'])->toBe($this->application->uuid)
        ->and($raw)->not->toContain('application-secret')
        ->and($raw)->not->toContain('git_full_url')
        ->and($raw)->not->toContain('build_command')
        ->and($raw)->not->toContain('custom_docker_run_options')
        ->and($raw)->not->toContain('custom_nginx_configuration');
});

test('get_database omits configuration blobs that can contain secrets', function () {
    $database = StandalonePostgresql::create([
        'name' => 'Secret-bearing config',
        'postgres_password' => 'database-password',
        'postgres_conf' => "primary_conninfo = 'password=database-config-secret'",
        'custom_docker_run_options' => '--env API_TOKEN=database-config-secret',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $response = mcpReadCall('get_database', ['uuid' => $database->uuid]);
    $response->assertOk();

    $body = mcpReadJson($response);
    $raw = json_encode($body);

    expect($body['data']['uuid'])->toBe($database->uuid)
        ->and($raw)->not->toContain('database-config-secret')
        ->and($raw)->not->toContain('postgres_conf')
        ->and($raw)->not->toContain('custom_docker_run_options');
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
    expect($domainBody['data']['domains'])->toHaveCount(1);
    expect($domainBody['data']['domains'][0]['resource_uuid'])->toBe($this->application->uuid);
    expect($domainBody['data']['domains'][0]['domains'])->toContain('app.example.com');

    $resources = mcpReadCall('get_server_resources', ['uuid' => $this->server->uuid]);
    $resources->assertOk();

    $otherServer = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    expect(mcpReadCall('get_server_domains', ['uuid' => $otherServer->uuid])->json('result.isError'))->toBeTrue();
    expect(mcpReadCall('get_server_resources', ['uuid' => $otherServer->uuid])->json('result.isError'))->toBeTrue();
});

test('get_server_domains filters polymorphic destinations by type and id', function () {
    // Other server gets the next standalone_dockers id (typically 2).
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    $otherStandalone = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();

    // Swarm on this server with the same numeric id as the other server's
    // standalone docker. Untyped whereIn(destination_id) would merge that id
    // and wrongly attribute the other server's app to this server.
    DB::table('swarm_dockers')->insert([
        'id' => $otherStandalone->id,
        'uuid' => (string) Str::uuid(),
        'server_id' => $this->server->id,
        'name' => 'swarm-network',
        'network' => 'swarm-network',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $swarmOnThisServer = SwarmDocker::query()->findOrFail($otherStandalone->id);

    expect($swarmOnThisServer->id)->toBe($otherStandalone->id);
    expect($this->destination->id)->not->toBe($otherStandalone->id);

    $swarmApp = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $swarmOnThisServer->id,
        'destination_type' => SwarmDocker::class,
        'fqdn' => 'https://swarm.example.com',
        'name' => 'swarm-app',
    ]);

    $otherServerApp = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $otherStandalone->id,
        'destination_type' => StandaloneDocker::class,
        'fqdn' => 'https://other-server.example.com',
        'name' => 'other-server-app',
    ]);

    $domains = mcpReadCall('get_server_domains', ['uuid' => $this->server->uuid]);
    $domains->assertOk();
    $domainBody = mcpReadJson($domains);
    $resourceUuids = collect($domainBody['data']['domains'])->pluck('resource_uuid');

    expect($resourceUuids)->toContain($this->application->uuid);
    expect($resourceUuids)->toContain($swarmApp->uuid);
    expect($resourceUuids)->not->toContain($otherServerApp->uuid);
});

test('list_applications list_databases list_services server_uuid filters use destination type', function () {
    // Other server gets the next standalone_dockers id (typically 2).
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    $otherStandalone = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();

    // Swarm on this server with the same numeric id as the other server's standalone docker.
    DB::table('swarm_dockers')->insert([
        'id' => $otherStandalone->id,
        'uuid' => (string) Str::uuid(),
        'server_id' => $this->server->id,
        'name' => 'swarm-network-list-filter',
        'network' => 'swarm-network-list-filter',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $swarmOnThisServer = SwarmDocker::query()->findOrFail($otherStandalone->id);
    expect($swarmOnThisServer->id)->toBe($otherStandalone->id);

    $swarmApp = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $swarmOnThisServer->id,
        'destination_type' => SwarmDocker::class,
        'name' => 'swarm-list-app',
    ]);
    $otherServerApp = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $otherStandalone->id,
        'destination_type' => StandaloneDocker::class,
        'name' => 'other-server-list-app',
    ]);

    $swarmDb = StandalonePostgresql::create([
        'name' => 'swarm-list-db',
        'status' => 'running:healthy',
        'postgres_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $swarmOnThisServer->id,
        'destination_type' => SwarmDocker::class,
    ]);
    $otherServerDb = StandalonePostgresql::create([
        'name' => 'other-server-list-db',
        'status' => 'running:healthy',
        'postgres_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $otherStandalone->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $swarmService = Service::factory()->create([
        'name' => 'swarm-list-svc',
        'environment_id' => $this->environment->id,
        'server_id' => null,
        'destination_id' => $swarmOnThisServer->id,
        'destination_type' => SwarmDocker::class,
    ]);
    $otherServerService = Service::factory()->create([
        'name' => 'other-server-list-svc',
        'environment_id' => $this->environment->id,
        'server_id' => null,
        'destination_id' => $otherStandalone->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $apps = mcpReadCall('list_applications', ['server_uuid' => $this->server->uuid]);
    $apps->assertOk();
    $appUuids = collect(mcpReadJson($apps)['data'])->pluck('uuid');
    expect($appUuids)->toContain($this->application->uuid, $swarmApp->uuid)
        ->not->toContain($otherServerApp->uuid);

    $dbs = mcpReadCall('list_databases', ['server_uuid' => $this->server->uuid]);
    $dbs->assertOk();
    $dbUuids = collect(mcpReadJson($dbs)['data'])->pluck('uuid');
    expect($dbUuids)->toContain($swarmDb->uuid)
        ->not->toContain($otherServerDb->uuid);

    $services = mcpReadCall('list_services', ['server_uuid' => $this->server->uuid]);
    $services->assertOk();
    $serviceUuids = collect(mcpReadJson($services)['data'])->pluck('uuid');
    expect($serviceUuids)->toContain($swarmService->uuid)
        ->not->toContain($otherServerService->uuid);
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

test('list_github_repositories rejects public github sources cleanly', function () {
    $publicApp = GithubApp::create([
        'name' => 'Public Source',
        'uuid' => 'github-public-test',
        'team_id' => $this->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'is_public' => true,
        'is_system_wide' => false,
    ]);

    $response = mcpReadCall('list_github_repositories', [
        'github_app_uuid' => $publicApp->uuid,
    ]);
    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))
        ->toContain('public or missing app installation credentials')
        ->not->toContain('private_key');
});

test('list_github_branches uses anonymous github api for public sources', function () {
    $publicApp = GithubApp::create([
        'name' => 'Public Source',
        'uuid' => 'github-public-branches',
        'team_id' => $this->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'is_public' => true,
        'is_system_wide' => false,
    ]);

    Http::fake([
        'https://api.github.com/repos/coollabsio/coolify/branches*' => Http::response([
            ['name' => 'v4.x', 'protected' => true, 'commit' => ['sha' => 'abc123']],
            ['name' => 'next', 'protected' => false, 'commit' => ['sha' => 'def456']],
        ], 200),
    ]);

    $response = mcpReadCall('list_github_branches', [
        'github_app_uuid' => $publicApp->uuid,
        'owner' => 'coollabsio',
        'repo' => 'coolify',
    ]);
    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse();
    $body = mcpReadJson($response);
    expect(collect($body['data']['branches'])->pluck('name')->all())->toContain('v4.x', 'next');
    expect($body['data']['branches'][0]['commit_sha'])->toBe('abc123');
});

test('list_github_branches rejects private apps missing installation credentials', function () {
    $privateApp = GithubApp::create([
        'name' => 'Incomplete Private App',
        'uuid' => 'github-private-incomplete',
        'team_id' => $this->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => null,
        'installation_id' => null,
        'private_key_id' => null,
        'is_public' => false,
        'is_system_wide' => false,
    ]);

    Http::fake();

    $response = mcpReadCall('list_github_branches', [
        'github_app_uuid' => $privateApp->uuid,
        'owner' => 'coollabsio',
        'repo' => 'coolify',
    ]);
    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))
        ->toContain('missing installation credentials')
        ->not->toContain('private_key');
    Http::assertNothingSent();
});

test('list_github_branches rejects owner or repo path segment injection', function () {
    $publicApp = GithubApp::create([
        'name' => 'Public Source Path Check',
        'uuid' => 'github-public-path-check',
        'team_id' => $this->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'is_public' => true,
        'is_system_wide' => false,
    ]);

    Http::fake();

    $badOwner = mcpReadCall('list_github_branches', [
        'github_app_uuid' => $publicApp->uuid,
        'owner' => 'cool/../labs',
        'repo' => 'coolify',
    ]);
    $badOwner->assertOk();
    expect($badOwner->json('result.isError'))->toBeTrue();
    expect($badOwner->json('result.content.0.text'))->toContain('valid GitHub login');

    $badRepo = mcpReadCall('list_github_branches', [
        'github_app_uuid' => $publicApp->uuid,
        'owner' => 'coollabsio',
        'repo' => 'coolify/extra',
    ]);
    $badRepo->assertOk();
    expect($badRepo->json('result.isError'))->toBeTrue();
    expect($badRepo->json('result.content.0.text'))->toContain('valid GitHub repository');

    Http::assertNothingSent();
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

test('get_infrastructure_overview health_hints and project counts stay accurate', function () {
    $this->application->update(['status' => 'exited:unhealthy']);
    Application::factory()->create([
        'name' => 'HealthyApp',
        'status' => 'running:healthy',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    StandalonePostgresql::create([
        'name' => 'DownDb',
        'postgres_password' => 'password',
        'status' => 'exited:unhealthy',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    StandalonePostgresql::create([
        'name' => 'UpDb',
        'postgres_password' => 'password',
        'status' => 'running:healthy',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Service::factory()->create([
        'name' => 'EmptyService',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDest = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();
    Application::factory()->create([
        'name' => 'OtherTeamDown',
        'status' => 'exited:unhealthy',
        'environment_id' => $otherEnv->id,
        'destination_id' => $otherDest->id,
        'destination_type' => $otherDest->getMorphClass(),
    ]);
    StandalonePostgresql::create([
        'name' => 'OtherTeamDb',
        'postgres_password' => 'password',
        'status' => 'exited:unhealthy',
        'environment_id' => $otherEnv->id,
        'destination_id' => $otherDest->id,
        'destination_type' => $otherDest->getMorphClass(),
    ]);

    $response = mcpReadCall('get_infrastructure_overview');
    $response->assertOk();
    $body = mcpReadJson($response);
    $data = $body['data'];

    expect($data['counts']['applications'])->toBe(2)
        ->and($data['counts']['services'])->toBe(1)
        ->and($data['counts']['databases'])->toBe(2)
        ->and($data['projects'][0]['counts']['applications'])->toBe(2)
        ->and($data['projects'][0]['counts']['services'])->toBe(1)
        ->and($data['projects'][0]['counts']['databases'])->toBe(2)
        ->and($data['health_hints']['applications_not_running'])->toBe(1)
        ->and($data['health_hints']['databases_not_running'])->toBe(1)
        // Empty service has no containers → aggregated status is not healthy.
        ->and($data['health_hints']['services_not_running'])->toBe(1);
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

test('get_deployment include_log_summary requires sensitive read ability', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep-log-'.fake()->uuid(),
        'status' => 'failed',
        'server_id' => $this->server->id,
        'application_name' => $this->application->name,
        'server_name' => $this->server->name,
        'commit' => 'deadbeef',
        'logs' => json_encode([
            ['output' => 'unstructured-sensitive-build-output', 'type' => 'stdout', 'hidden' => false],
        ]),
    ]);

    $response = mcpReadCall('get_deployment', [
        'uuid' => $deployment->deployment_uuid,
        'include_log_summary' => true,
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('read:sensitive')
        ->and($response->json('result.content.0.text'))->not->toContain('unstructured-sensitive-build-output');
});

test('get_deployment include_log_summary returns capped redacted text with sensitive read ability', function () {
    $logs = json_encode([
        ['output' => 'step 1 ok', 'type' => 'stdout', 'hidden' => false],
        ['output' => 'password=redactme01', 'type' => 'stderr', 'hidden' => false],
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

    $response = mcpSensitiveReadCall('get_deployment', [
        'uuid' => $deployment->deployment_uuid,
        'include_log_summary' => true,
        'log_lines' => 10,
    ]);
    $response->assertOk();
    $body = mcpReadJson($response);

    expect($body['data']['log_summary']['available'])->toBeTrue();
    expect($body['data']['log_summary']['text'])->toContain('step 1 ok');
    expect($body['data']['log_summary']['text'])->not->toContain('redactme01');
    expect($body['data']['log_summary']['text'])->toContain('password=');
    // Full logs field still scrubbed from root payload
    expect(json_encode($body))->not->toContain('"logs":');
});

test('get_deployment plain-text log summary respects the requested line limit', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep-log-'.fake()->uuid(),
        'status' => 'failed',
        'server_id' => $this->server->id,
        'application_name' => $this->application->name,
        'server_name' => $this->server->name,
        'commit' => 'deadbeef',
        'logs' => "first line\nsecond line token=redactme01\nlast line",
    ]);

    $response = mcpSensitiveReadCall('get_deployment', [
        'uuid' => $deployment->deployment_uuid,
        'include_log_summary' => true,
        'log_lines' => 1,
    ]);
    $response->assertOk();
    $summary = mcpReadJson($response)['data']['log_summary'];

    expect($summary['lines'])->toBe(1)
        ->and($summary['truncated'])->toBeTrue()
        ->and($summary['text'])->toBe('last line')
        ->and($summary['text'])->not->toContain('redactme01');
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

test('list_applications paginates with stable name order and disjoint pages', function () {
    $this->application->update(['name' => 'app-z-original']);

    foreach (['app-a', 'app-b', 'app-c'] as $name) {
        Application::factory()->create([
            'name' => $name,
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
    }

    $page1 = mcpReadCall('list_applications', ['page' => 1, 'per_page' => 2]);
    $page1->assertOk();
    $page1Body = mcpReadJson($page1);
    $page1Names = collect($page1Body['data'])->pluck('name')->all();
    $page1Uuids = collect($page1Body['data'])->pluck('uuid')->all();

    expect($page1Names)->toBe(collect($page1Names)->sort()->values()->all())
        ->and($page1Body['_pagination']['total'])->toBeGreaterThanOrEqual(4)
        ->and($page1Body['_pagination']['next']['args']['page'] ?? null)->toBe(2);

    $page2 = mcpReadCall('list_applications', ['page' => 2, 'per_page' => 2]);
    $page2->assertOk();
    $page2Uuids = collect(mcpReadJson($page2)['data'])->pluck('uuid')->all();

    expect(array_intersect($page1Uuids, $page2Uuids))->toBe([]);
});

test('list_databases filters by project, name, status, and server and is team scoped', function () {
    $matching = StandalonePostgresql::create([
        'name' => 'prod-postgres',
        'status' => 'running:healthy',
        'postgres_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $otherName = StandalonePostgresql::create([
        'name' => 'dev-redis-like',
        'status' => 'exited:unhealthy',
        'postgres_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    $otherEnv = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    StandalonePostgresql::create([
        'name' => 'other-project-db',
        'status' => 'running:healthy',
        'postgres_password' => 'password',
        'environment_id' => $otherEnv->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $otherTeam = Team::factory()->create();
    $otherTeamProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherTeamEnv = $otherTeamProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherTeamProject->id]);
    $otherTeamServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherTeamDest = StandaloneDocker::query()->where('server_id', $otherTeamServer->id)->firstOrFail();
    StandalonePostgresql::create([
        'name' => 'foreign-db',
        'status' => 'running:healthy',
        'postgres_password' => 'password',
        'environment_id' => $otherTeamEnv->id,
        'destination_id' => $otherTeamDest->id,
        'destination_type' => $otherTeamDest->getMorphClass(),
    ]);

    $all = mcpReadCall('list_databases');
    $all->assertOk();
    $allUuids = collect(mcpReadJson($all)['data'])->pluck('uuid');
    expect($allUuids)
        ->toContain($matching->uuid, $otherName->uuid)
        ->not->toContain(StandalonePostgresql::where('name', 'foreign-db')->value('uuid'));

    $byProject = mcpReadCall('list_databases', ['project_uuid' => $this->project->uuid]);
    $byProject->assertOk();
    $projectUuids = collect(mcpReadJson($byProject)['data'])->pluck('uuid');
    expect($projectUuids)
        ->toContain($matching->uuid)
        ->not->toContain(StandalonePostgresql::where('name', 'other-project-db')->value('uuid'));

    $byName = mcpReadCall('list_databases', ['name' => 'prod-']);
    $byName->assertOk();
    expect(collect(mcpReadJson($byName)['data'])->pluck('uuid'))
        ->toContain($matching->uuid)
        ->not->toContain($otherName->uuid);

    $byStatus = mcpReadCall('list_databases', ['status' => 'exited']);
    $byStatus->assertOk();
    expect(collect(mcpReadJson($byStatus)['data'])->pluck('uuid'))
        ->toContain($otherName->uuid)
        ->not->toContain($matching->uuid);

    $byServer = mcpReadCall('list_databases', ['server_uuid' => $this->server->uuid]);
    $byServer->assertOk();
    expect(collect(mcpReadJson($byServer)['data'])->pluck('uuid'))->toContain($matching->uuid);

    $missingServer = mcpReadCall('list_databases', ['server_uuid' => 'no-such-server']);
    $missingServer->assertOk();
    expect(mcpReadJson($missingServer)['data'])->toBe([]);

    $row = collect(mcpReadJson($byProject)['data'])->firstWhere('uuid', $matching->uuid);
    expect($row)
        ->toHaveKeys(['uuid', 'name', 'status', 'type', 'project_uuid', 'project_name'])
        ->and($row['project_uuid'])->toBe($this->project->uuid)
        ->and($row['type'])->toBe('standalone-postgresql');
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

test('get_service_application returns a field whitelist and is team scoped', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);
    $app = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'web',
        'human_name' => 'Web',
        'description' => 'Frontend container',
        'service_id' => $service->id,
        'image' => 'nginx:alpine',
        'fqdn' => 'https://web.example.com',
        'status' => 'running:healthy',
    ]);

    $response = mcpReadCall('get_service_application', [
        'service_uuid' => $service->uuid,
        'uuid' => $app->uuid,
    ]);
    $response->assertOk();
    $body = mcpReadJson($response);
    $data = $body['data'];

    expect($data['uuid'])->toBe($app->uuid)
        ->and($data['service_uuid'])->toBe($service->uuid)
        ->and($data['name'])->toBe('web')
        ->and($data['human_name'])->toBe('Web')
        ->and($data['status'])->toBe('running:healthy')
        ->and($data['fqdn'])->toBe('https://web.example.com')
        ->and($data['image'])->toBe('nginx:alpine')
        ->and($data)->toHaveKeys([
            'uuid',
            'service_uuid',
            'name',
            'human_name',
            'description',
            'status',
            'fqdn',
            'ports',
            'exposes',
            'image',
            'exclude_from_status',
            'required_fqdn',
            'is_log_drain_enabled',
            'is_include_timestamps',
            'is_gzip_enabled',
            'is_stripprefix_enabled',
            'last_online_at',
            'created_at',
            'updated_at',
        ])
        ->and($data)->not->toHaveKey('id')
        ->and($data)->not->toHaveKey('service_id')
        ->and($data)->not->toHaveKey('is_migrated');

    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherDest = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();
    $otherService = Service::factory()->create([
        'environment_id' => $otherEnv->id,
        'server_id' => $otherServer->id,
        'destination_id' => $otherDest->id,
        'destination_type' => $otherDest->getMorphClass(),
    ]);
    $otherApp = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'theirs',
        'service_id' => $otherService->id,
        'image' => 'nginx:alpine',
    ]);

    $denied = mcpReadCall('get_service_application', [
        'service_uuid' => $otherService->uuid,
        'uuid' => $otherApp->uuid,
    ]);
    expect($denied->json('result.isError'))->toBeTrue();
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

    // Elevated member tokens are rejected as JSON-RPC errors (HTTP 200) so MCP clients can parse them.
    $response->assertOk();
    expect($response->json('error.message') ?? $response->json('result.content.0.text') ?? '')
        ->toMatch('/team role|Missing required/i');
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

test('get_logs returns structured choices when service has multiple containers', function () {
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    $service = Service::factory()->create([
        'name' => 'multi-container-svc',
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $childApp = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'web',
        'service_id' => $service->id,
        'status' => 'running:healthy',
        'image' => 'nginx:latest',
    ]);
    $childDb = ServiceDatabase::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'service_id' => $service->id,
        'status' => 'running:healthy',
        'image' => 'postgres:16',
    ]);

    $response = mcpSensitiveReadCall('get_logs', [
        'resource' => 'service',
        'uuid' => $service->uuid,
    ]);
    $response->assertOk();
    $body = mcpReadJson($response);

    expect($body['data']['ok'])->toBeFalse()
        ->and($body['data']['reason'])->toBe('multiple_containers')
        ->and($body['data']['choices'])->toBeArray()
        ->and(collect($body['data']['choices'])->pluck('uuid')->all())
        ->toContain($childApp->uuid, $childDb->uuid)
        ->and(json_encode($body['data']['message'] ?? ''))->not->toContain('"uuid"');
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

    // Middleware returns a JSON-RPC error envelope (HTTP 200) for MCP clients.
    $response->assertOk();
    expect($response->json('jsonrpc'))->toBe('2.0');
    expect($response->json('error.message') ?? $response->json('result.content.0.text') ?? '')
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
    // Avoid real SSH via instant_remote_process during cancellation cleanup.
    Process::fake([
        '*' => Process::result(output: ''),
    ]);
    Queue::fake();

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
    $nextDeployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep-next-'.fake()->uuid(),
        'status' => 'queued',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'application_name' => $this->application->name,
        'server_name' => $this->server->name,
        'commit' => 'def',
        'pull_request_id' => 0,
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
    expect($nextDeployment->fresh()->status)->toBe('in_progress');
    Queue::assertPushed(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job) => $job->application_deployment_queue_id === $nextDeployment->id);

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

test('cancel_deployment rejects other team deployment even on owned server', function () {
    // Shared-server case: caller's team owns the host server, but the application belongs to another team.
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherApp = Application::factory()->create([
        'environment_id' => $otherEnv->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $sharedServerDep = ApplicationDeploymentQueue::create([
        'application_id' => $otherApp->id,
        'deployment_uuid' => 'dep-shared-server-cancel-'.fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $this->server->id,
        'application_name' => $otherApp->name,
        'server_name' => $this->server->name,
    ]);

    $token = $this->user->createToken('mcp-shared-server-cancel', ['read', 'deploy'])->plainTextToken;
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
            'arguments' => (object) ['uuid' => $sharedServerDep->deployment_uuid],
        ],
    ]);

    expect($denied->json('result.isError'))->toBeTrue();
    expect($sharedServerDep->fresh()->status)->toBe('in_progress');
});

test('cancel_deployment updates only a still cancellable deployment', function () {
    // Avoid real SSH via instant_remote_process during cancellation cleanup.
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

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

test('MCP overview resource returns batched project resource counts', function () {
    StandalonePostgresql::create([
        'name' => 'overview-postgres',
        'postgres_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    Service::create([
        'name' => 'overview-service',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => 'services: {}',
    ]);

    $token = mcpReadToken();
    $response = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'resources/read',
        'params' => [
            'uri' => 'coolify://overview',
        ],
    ]);

    $response->assertOk();

    $text = collect($response->json('result.contents'))->pluck('text')->first();
    expect($text)->not->toBeNull();

    $body = json_decode($text, true);
    expect($body)->toHaveKeys(['coolify_version', 'servers', 'projects', 'counts']);
    expect($body['counts']['projects'])->toBe(1);

    $project = collect($body['projects'])->firstWhere('uuid', $this->project->uuid);
    expect($project)->not->toBeNull();
    expect($project['counts'])->toMatchArray([
        'applications' => 1,
        'services' => 1,
        'databases' => 1,
    ]);
});

test('get_deployment and cancel_deployment work for soft-deleted applications', function () {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep-soft-delete-'.fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $this->server->id,
        'application_name' => $this->application->name,
        'server_name' => $this->server->name,
        'commit' => 'abc123',
    ]);

    $deployToken = $this->user->createToken('mcp-soft-cancel', ['read', 'deploy'])->plainTextToken;

    $this->application->delete();
    expect(Application::withTrashed()->find($this->application->id))->not->toBeNull();
    expect(Application::find($this->application->id))->toBeNull();

    // get_deployment still resolves soft-deleted applications for the team.
    $get = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$deployToken,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'get_deployment',
            'arguments' => (object) ['uuid' => $deployment->deployment_uuid],
        ],
    ]);
    $get->assertOk();
    expect($get->json('result.isError'))->toBeFalse();
    $getBody = mcpReadJson($get);
    expect($getBody['data']['deployment_uuid'])->toBe($deployment->deployment_uuid)
        ->and($getBody['data']['application_uuid'])->toBe($this->application->uuid);

    $cancel = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$deployToken,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'cancel_deployment',
            'arguments' => (object) ['uuid' => $deployment->deployment_uuid],
        ],
    ]);
    $cancel->assertOk();
    expect($cancel->json('result.isError'))->toBeFalse();
    expect($deployment->fresh()->status)->toBe('cancelled-by-user');
});

test('list_databases paginates at the query layer', function () {
    foreach (['alpha-db', 'beta-db', 'gamma-db'] as $name) {
        StandalonePostgresql::create([
            'name' => $name,
            'status' => 'running:healthy',
            'postgres_password' => 'password',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
    }

    $page1 = mcpReadCall('list_databases', ['per_page' => 2, 'page' => 1]);
    $page1->assertOk();
    $body1 = mcpReadJson($page1);
    expect($body1['_pagination']['total'])->toBe(3)
        ->and($body1['data'])->toHaveCount(2)
        ->and($body1['data'][0]['name'])->toBe('alpha-db')
        ->and($body1['data'][1]['name'])->toBe('beta-db');

    $page2 = mcpReadCall('list_databases', ['per_page' => 2, 'page' => 2]);
    $page2->assertOk();
    $body2 = mcpReadJson($page2);
    expect($body2['data'])->toHaveCount(1)
        ->and($body2['data'][0]['name'])->toBe('gamma-db');

    $page1Uuids = collect($body1['data'])->pluck('uuid')->all();
    $page2Uuids = collect($body2['data'])->pluck('uuid')->all();
    expect(array_intersect($page1Uuids, $page2Uuids))->toBe([]);
});

test('list_unhealthy_resources full mode paginates without dropping summary totals', function () {
    $this->application->update(['name' => 'AppA', 'status' => 'exited:unhealthy']);
    Application::factory()->create([
        'name' => 'AppB',
        'status' => 'exited:unhealthy',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    Application::factory()->create([
        'name' => 'AppC',
        'status' => 'exited:unhealthy',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    // Ensure the default server is not counted as unhealthy (factory settings vary).
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    $page1 = mcpReadCall('list_unhealthy_resources', [
        'sample_only' => false,
        'per_page' => 2,
        'page' => 1,
    ]);
    $page1->assertOk();
    $body1 = mcpReadJson($page1);
    expect($body1['data']['summary']['applications'])->toBe(3)
        ->and($body1['data']['summary']['servers'])->toBe(0)
        ->and($body1['_pagination']['total'])->toBe(3)
        ->and($body1['data']['unhealthy'])->toHaveCount(2);

    $page1Names = collect($body1['data']['unhealthy'])->pluck('name')->all();
    expect($page1Names)->toBe(['AppA', 'AppB']);

    $page2 = mcpReadCall('list_unhealthy_resources', [
        'sample_only' => false,
        'per_page' => 2,
        'page' => 2,
    ]);
    $page2->assertOk();
    $body2 = mcpReadJson($page2);
    expect($body2['data']['unhealthy'])->toHaveCount(1)
        ->and($body2['data']['unhealthy'][0]['name'])->toBe('AppC');
});

test('list_unhealthy_resources full mode paginates services without dropping summary totals', function () {
    // Keep apps/servers healthy so the page window is pure services.
    $this->application->update(['status' => 'running:healthy']);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    // Empty services have no running status → treated as unhealthy by the status scan.
    foreach (['SvcA', 'SvcB', 'SvcC', 'SvcD', 'SvcE'] as $name) {
        Service::factory()->create([
            'name' => $name,
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
    }

    $page1 = mcpReadCall('list_unhealthy_resources', [
        'sample_only' => false,
        'per_page' => 2,
        'page' => 1,
    ]);
    $page1->assertOk();
    $body1 = mcpReadJson($page1);
    expect($body1['data']['summary']['services'])->toBe(5)
        ->and($body1['data']['summary']['total'])->toBe(5)
        ->and($body1['_pagination']['total'])->toBe(5)
        ->and($body1['data']['unhealthy'])->toHaveCount(2)
        ->and(collect($body1['data']['unhealthy'])->pluck('name')->all())->toBe(['SvcA', 'SvcB'])
        ->and(collect($body1['data']['unhealthy'])->pluck('type')->unique()->all())->toBe(['service']);

    $page3 = mcpReadCall('list_unhealthy_resources', [
        'sample_only' => false,
        'per_page' => 2,
        'page' => 3,
    ]);
    $page3->assertOk();
    $body3 = mcpReadJson($page3);
    expect($body3['data']['summary']['services'])->toBe(5)
        ->and($body3['data']['unhealthy'])->toHaveCount(1)
        ->and($body3['data']['unhealthy'][0]['name'])->toBe('SvcE');
});

test('get_service_database returns a field whitelist and is team scoped', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  db:\n    image: postgres:16\n",
    ]);
    $db = ServiceDatabase::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'human_name' => 'Database',
        'description' => 'Primary DB',
        'service_id' => $service->id,
        'image' => 'postgres:16',
        'status' => 'running:healthy',
    ]);

    $response = mcpReadCall('get_service_database', [
        'service_uuid' => $service->uuid,
        'uuid' => $db->uuid,
    ]);
    $response->assertOk();
    $data = mcpReadJson($response)['data'];

    expect($data['uuid'])->toBe($db->uuid)
        ->and($data['service_uuid'])->toBe($service->uuid)
        ->and($data['name'])->toBe('db')
        ->and($data)->toHaveKeys([
            'uuid',
            'service_uuid',
            'name',
            'human_name',
            'description',
            'status',
            'image',
            'created_at',
            'updated_at',
        ])
        ->and($data)->not->toHaveKey('id')
        ->and($data)->not->toHaveKey('service_id')
        ->and($data)->not->toHaveKey('is_migrated');

    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnv = $otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherDest = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();
    $otherService = Service::factory()->create([
        'environment_id' => $otherEnv->id,
        'server_id' => $otherServer->id,
        'destination_id' => $otherDest->id,
        'destination_type' => $otherDest->getMorphClass(),
    ]);
    $otherDb = ServiceDatabase::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'theirs',
        'service_id' => $otherService->id,
        'image' => 'postgres:16',
    ]);

    $denied = mcpReadCall('get_service_database', [
        'service_uuid' => $otherService->uuid,
        'uuid' => $otherDb->uuid,
    ]);
    expect($denied->json('result.isError'))->toBeTrue();
});

test('list_scheduled_tasks omits command without sensitive read ability', function () {
    ScheduledTask::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'nightly-backup',
        'command' => 'pg_dump --flag=redactme01',
        'frequency' => '0 2 * * *',
        'enabled' => true,
        'timeout' => 3600,
        'team_id' => $this->team->id,
        'application_id' => $this->application->id,
    ]);

    $readOnly = mcpReadCall('list_scheduled_tasks', [
        'resource' => 'application',
        'uuid' => $this->application->uuid,
    ]);
    $readOnly->assertOk();
    $tasks = mcpReadJson($readOnly)['data']['tasks'];
    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]['name'])->toBe('nightly-backup')
        ->and($tasks[0]['command_included'])->toBeFalse()
        ->and($tasks[0])->not->toHaveKey('command');
    expect(json_encode($tasks))->not->toContain('redactme01');

    $sensitive = mcpSensitiveReadCall('list_scheduled_tasks', [
        'resource' => 'application',
        'uuid' => $this->application->uuid,
    ]);
    $sensitive->assertOk();
    expect($sensitive->json('result.isError'))->toBeFalse();
    $sensitiveBody = mcpReadJson($sensitive);
    expect($sensitiveBody['data']['command_included'])->toBeTrue();
    $sensitiveTasks = $sensitiveBody['data']['tasks'];
    expect($sensitiveTasks[0]['command_included'])->toBeTrue()
        ->and($sensitiveTasks[0]['command'])->toContain('pg_dump');
});

test('list_scheduled_task_executions returns newest first across pages', function () {
    $task = ScheduledTask::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'exec-history',
        'command' => 'echo ok',
        'frequency' => '0 1 * * *',
        'enabled' => true,
        'timeout' => 60,
        'team_id' => $this->team->id,
        'application_id' => $this->application->id,
    ]);

    $older = ScheduledTaskExecution::create([
        'scheduled_task_id' => $task->id,
        'status' => 'success',
        'message' => 'older-run',
        'started_at' => now()->subHours(2),
        'finished_at' => now()->subHours(2)->addMinute(),
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    $newer = ScheduledTaskExecution::create([
        'scheduled_task_id' => $task->id,
        'status' => 'failed',
        'message' => 'newer-run',
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour()->addMinute(),
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $page1 = mcpReadCall('list_scheduled_task_executions', [
        'resource' => 'application',
        'uuid' => $this->application->uuid,
        'task_uuid' => $task->uuid,
        'page' => 1,
        'per_page' => 1,
    ]);
    $page1->assertOk();
    $page1Body = mcpReadJson($page1);
    expect($page1Body['data']['executions'])->toHaveCount(1)
        ->and($page1Body['data']['message_included'])->toBeFalse()
        ->and($page1Body['data']['executions'][0])->not->toHaveKey('message')
        ->and($page1Body['data']['executions'][0]['status'])->toBe('failed')
        ->and($page1Body['_pagination']['total'])->toBe(2);

    $page2 = mcpReadCall('list_scheduled_task_executions', [
        'resource' => 'application',
        'uuid' => $this->application->uuid,
        'task_uuid' => $task->uuid,
        'page' => 2,
        'per_page' => 1,
    ]);
    $page2->assertOk();
    $page2Body = mcpReadJson($page2);
    expect($page2Body['data']['executions'][0]['status'])->toBe('success')
        ->and($page2Body['data']['executions'][0])->not->toHaveKey('message');

    $sensitive = mcpSensitiveReadCall('list_scheduled_task_executions', [
        'resource' => 'application',
        'uuid' => $this->application->uuid,
        'task_uuid' => $task->uuid,
        'page' => 1,
        'per_page' => 1,
    ]);
    $sensitive->assertOk();
    $sensitiveBody = mcpReadJson($sensitive);
    expect($sensitiveBody['data']['message_included'])->toBeTrue()
        ->and($sensitiveBody['data']['executions'][0]['message'])->toBe('newer-run');

    // Silence unused variable analysis when timestamps are forced via create attributes.
    expect($older->id)->not->toBe($newer->id);
});

test('list_scheduled_task_executions redacts secret-like values in messages with sensitive read', function () {
    $task = ScheduledTask::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'exec-redact',
        'command' => 'echo ok',
        'frequency' => '0 1 * * *',
        'enabled' => true,
        'timeout' => 60,
        'team_id' => $this->team->id,
        'application_id' => $this->application->id,
    ]);
    ScheduledTaskExecution::create([
        'scheduled_task_id' => $task->id,
        'status' => 'failed',
        'message' => "backup failed password=redactme01\n",
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $response = mcpSensitiveReadCall('list_scheduled_task_executions', [
        'resource' => 'application',
        'uuid' => $this->application->uuid,
        'task_uuid' => $task->uuid,
    ]);
    $response->assertOk();
    $body = mcpReadJson($response);
    $message = $body['data']['executions'][0]['message'] ?? '';

    expect($body['data']['message_included'])->toBeTrue()
        ->and($message)->not->toContain('redactme01')
        ->and($message)->toContain('password=')
        ->and($message)->toContain(REDACTED);
});

test('get_logs redacts secret-like values in container output', function () {
    // Preflight fails for non-running apps, so exercise redaction via the shared helper path
    // through get_deployment (already covered) and unit-level BuildsResponse redaction.
    // Use low-entropy test markers so secret scanners do not flag fixtures.
    $trait = new class
    {
        use BuildsResponse;

        public function redact(string $text): string
        {
            return $this->redactLogText($text);
        }
    };

    $redacted = $trait->redact("boot ok\npassword=redactme01\nAPI_TOKEN=redactme02\n");
    expect($redacted)->toContain('boot ok')
        ->and($redacted)->not->toContain('redactme01')
        ->and($redacted)->not->toContain('redactme02')
        ->and($redacted)->toContain('password=')
        ->and($redacted)->toContain(REDACTED);
});

test('redactLogText redacts JSON secret fields in log lines', function () {
    $trait = new class
    {
        use BuildsResponse;

        public function redact(string $text): string
        {
            return $this->redactLogText($text);
        }
    };

    $jsonLine = '{"token":"redactme01","API_KEY":"redactme02","status":"ok"}';
    $redacted = $trait->redact("request failed: {$jsonLine}");

    expect($redacted)->toContain('status')
        ->and($redacted)->toContain('ok')
        ->and($redacted)->not->toContain('redactme01')
        ->and($redacted)->not->toContain('redactme02')
        ->and($redacted)->toContain('token=')
        ->and($redacted)->toContain('API_KEY=')
        ->and($redacted)->toContain(REDACTED);

    // Shell-style still works alongside JSON
    $mixed = $trait->redact('password=redactme01 {"client_secret":"redactme02"} export DB_PASSWORD=redactme03');
    expect($mixed)->not->toContain('redactme01')
        ->and($mixed)->not->toContain('redactme02')
        ->and($mixed)->not->toContain('redactme03')
        ->and($mixed)->toContain(REDACTED);
});
