<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true, 'is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();
    Server::flushIdentityMap();
    Queue::fake();
    Process::fake();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->otherTeam = Team::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->otherTeam->members()->attach($this->otherUser->id, ['role' => 'owner']);

    $this->mine = mcpDataSeedTeam($this->team);
    $this->theirs = mcpDataSeedTeam($this->otherTeam);

    session(['currentTeam' => $this->team]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

/**
 * @return array{server: Server, destination: StandaloneDocker, project: Project, environment: Environment, database: StandalonePostgresql, service: Service, service_app: ServiceApplication, service_db: ServiceDatabase, application: Application}
 */
function mcpDataSeedTeam(Team $team): array
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db-'.$team->id,
        'postgres_user' => 'postgres',
        'postgres_password' => 'pg-secret-'.$team->id,
        'postgres_db' => 'app',
        'image' => 'postgres:17',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $service = Service::factory()->create([
        'name' => 'svc-'.$team->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);
    $serviceApp = ServiceApplication::create(['uuid' => (string) Str::uuid(), 'name' => 'web', 'service_id' => $service->id, 'image' => 'nginx:alpine']);
    $serviceDb = ServiceDatabase::create(['uuid' => (string) Str::uuid(), 'name' => 'postgres', 'service_id' => $service->id, 'image' => 'postgres:17']);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    return [
        'server' => $server,
        'destination' => $destination,
        'project' => $project,
        'environment' => $environment,
        'database' => $database,
        'service' => $service,
        'service_app' => $serviceApp,
        'service_db' => $serviceDb,
        'application' => $application,
    ];
}

function mcpDataToken(User $user, array $abilities = ['write']): string
{
    return $user->createToken('mcp-data', $abilities)->plainTextToken;
}

function mcpDataCall(string $token, string $tool, array $arguments = [])
{
    // Each call authenticates its own token; the guard would otherwise keep the user of the previous call.
    app('auth')->forgetGuards();

    return test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => (object) $arguments],
    ]);
}

function mcpDataText($response): string
{
    return (string) ($response->json('result.content.0.text') ?? $response->json('error.message'));
}

/**
 * A tool error (result.isError) or a JSON-RPC error, for example the MCP endpoint rejecting a member token with write abilities.
 */
function mcpDataIsError($response): bool
{
    return $response->json('result.isError') === true || $response->json('error') !== null;
}

function mcpDataJson($response): array
{
    return json_decode(mcpDataText($response), true) ?? [];
}

function mcpDataMember(Team $team): User
{
    $member = User::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);

    return $member;
}

function mcpDataDbEnv(StandalonePostgresql $database, string $key, string $value, bool $shownOnce = false): EnvironmentVariable
{
    return EnvironmentVariable::create([
        'key' => $key,
        'value' => $value,
        'is_shown_once' => $shownOnce,
        'resourceable_type' => $database->getMorphClass(),
        'resourceable_id' => $database->id,
    ]);
}

function mcpDataS3(Team $team): S3Storage
{
    return S3Storage::create([
        'name' => 'backups-'.$team->id,
        'region' => 'us-east-1',
        'key' => 'AKIADATATEST',
        'secret' => 's3-secret-'.$team->id,
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
}

// create_database

test('create_database creates a database in the token team and never returns connection URLs', function (array $abilities) {
    $response = mcpDataCall(mcpDataToken($this->user, $abilities), 'create_database', ['action' => 'postgresql', 'data' => [
        'project_uuid' => $this->mine['project']->uuid,
        'environment_uuid' => $this->mine['environment']->uuid,
        'server_uuid' => $this->mine['server']->uuid,
        'name' => 'from-mcp',
        'postgres_password' => 'VeryS3cretPass',
        'is_public' => true,
        'public_port' => 15432,
    ]]);

    expect(mcpDataIsError($response))->toBeFalse();
    $uuid = mcpDataJson($response)['data']['uuid'];
    $database = StandalonePostgresql::whereUuid($uuid)->sole();
    expect($database->name)->toBe('from-mcp')
        ->and($database->environment_id)->toBe($this->mine['environment']->id)
        ->and(mcpDataText($response))->not->toContain('VeryS3cretPass')
        ->and(mcpDataText($response))->not->toContain('internal_db_url')
        ->and(mcpDataText($response))->not->toContain('external_db_url')
        ->and(mcpDataText($response))->not->toContain('postgres_password');
})->with([
    'write' => [['write']],
    'root' => [['root']],
    'write and read:sensitive' => [['write', 'read:sensitive']],
]);

test('create_database rejects a project, server, or destination of another team', function (string $field) {
    $own = $this->mine;
    StandaloneDocker::create(['name' => 'second', 'network' => 'second-net', 'server_id' => $own['server']->id]);
    $data = [
        'project_uuid' => $own['project']->uuid,
        'environment_uuid' => $own['environment']->uuid,
        'server_uuid' => $own['server']->uuid,
        'destination_uuid' => $own['destination']->uuid,
        'name' => 'cross-team',
    ];
    $data[$field] = match ($field) {
        'project_uuid' => $this->theirs['project']->uuid,
        'environment_uuid' => $this->theirs['environment']->uuid,
        'server_uuid' => $this->theirs['server']->uuid,
        'destination_uuid' => $this->theirs['destination']->uuid,
    };

    $response = mcpDataCall(mcpDataToken($this->user), 'create_database', ['action' => 'postgresql', 'data' => $data]);

    expect(mcpDataIsError($response))->toBeTrue()
        ->and(StandalonePostgresql::where('name', 'cross-team')->exists())->toBeFalse();
})->with(['project_uuid', 'environment_uuid', 'server_uuid', 'destination_uuid']);

test('create_database rejects read-only and member tokens, and instant_deploy without deploy', function () {
    $data = [
        'project_uuid' => $this->mine['project']->uuid,
        'environment_uuid' => $this->mine['environment']->uuid,
        'server_uuid' => $this->mine['server']->uuid,
        'name' => 'denied',
    ];
    $member = mcpDataMember($this->team);

    $read = mcpDataCall(mcpDataToken($this->user, ['read']), 'create_database', ['action' => 'redis', 'data' => $data]);
    $memberResponse = mcpDataCall(mcpDataToken($member, ['write']), 'create_database', ['action' => 'redis', 'data' => $data]);
    $instant = mcpDataCall(mcpDataToken($this->user), 'create_database', ['action' => 'redis', 'data' => $data + ['instant_deploy' => true]]);

    expect(mcpDataIsError($read))->toBeTrue()
        ->and(mcpDataText($read))->toContain('Tool [create_database] not found')
        ->and(mcpDataIsError($memberResponse))->toBeTrue()
        ->and(mcpDataIsError($instant))->toBeTrue()
        ->and(mcpDataText($instant))->toContain('deploy');
    expect(StandalonePostgresql::where('name', 'denied')->exists())->toBeFalse();
});

// manage_database

test('manage_database updates a database of the token team', function () {
    $response = mcpDataCall(mcpDataToken($this->user), 'manage_database', ['action' => 'update', 'uuid' => $this->mine['database']->uuid, 'data' => ['name' => 'renamed']]);

    expect(mcpDataIsError($response))->toBeFalse()
        ->and($this->mine['database']->fresh()->name)->toBe('renamed');
});

test('manage_database cannot touch a database of another team', function (string $action, array $data) {
    $foreign = $this->theirs['database'];

    $response = mcpDataCall(mcpDataToken($this->user, ['write', 'deploy']), 'manage_database', ['action' => $action, 'uuid' => $foreign->uuid, 'confirm' => true, 'data' => $data]);

    expect(mcpDataIsError($response))->toBeTrue();
    $fresh = StandalonePostgresql::withTrashed()->find($foreign->id);
    expect($fresh->trashed())->toBeFalse()
        ->and($fresh->name)->toBe($foreign->name)
        ->and($fresh->environment_id)->toBe($foreign->environment_id)
        ->and(StandalonePostgresql::count())->toBe(2);
})->with([
    'update' => ['update', ['name' => 'hijacked']],
    'delete' => ['delete', []],
    'clone' => ['clone', ['destination_uuid' => 'placeholder']],
    'move' => ['move', ['environment_uuid' => 'placeholder']],
    'import' => ['import', ['source' => 's3', 's3_storage_uuid' => 'x', 'path' => '/a.sql']],
]);

test('manage_database rejects a destination, environment, or S3 storage of another team', function () {
    $own = $this->mine['database'];
    $token = mcpDataToken($this->user, ['write', 'deploy']);
    $foreignS3 = mcpDataS3($this->otherTeam);

    $clone = mcpDataCall($token, 'manage_database', ['action' => 'clone', 'uuid' => $own->uuid, 'confirm' => true, 'data' => ['destination_uuid' => $this->theirs['destination']->uuid]]);
    $move = mcpDataCall($token, 'manage_database', ['action' => 'move', 'uuid' => $own->uuid, 'confirm' => true, 'data' => ['environment_uuid' => $this->theirs['environment']->uuid]]);
    $import = mcpDataCall($token, 'manage_database', ['action' => 'import', 'uuid' => $own->uuid, 'confirm' => true, 'data' => ['source' => 's3', 's3_storage_uuid' => $foreignS3->uuid, 'path' => '/backup.sql']]);

    expect(mcpDataIsError($clone))->toBeTrue()
        ->and(mcpDataIsError($move))->toBeTrue()
        ->and(mcpDataIsError($import))->toBeTrue()
        ->and(StandalonePostgresql::count())->toBe(2)
        ->and($own->fresh()->environment_id)->toBe($this->mine['environment']->id)
        ->and(mcpDataText($import))->not->toContain('s3-secret');
});

test('manage_database clones to an own destination and moves to an own environment', function () {
    $own = $this->mine['database'];
    $token = mcpDataToken($this->user);
    $target = Environment::factory()->create(['project_id' => $this->mine['project']->id]);

    $clone = mcpDataCall($token, 'manage_database', ['action' => 'clone', 'uuid' => $own->uuid, 'confirm' => true, 'data' => ['destination_uuid' => $this->mine['destination']->uuid, 'name' => 'the-clone']]);
    $move = mcpDataCall($token, 'manage_database', ['action' => 'move', 'uuid' => $own->uuid, 'confirm' => true, 'data' => ['environment_uuid' => $target->uuid]]);

    expect(mcpDataIsError($clone))->toBeFalse()
        ->and(StandalonePostgresql::where('name', 'the-clone')->sole()->environment_id)->toBe($this->mine['environment']->id)
        ->and(mcpDataIsError($move))->toBeFalse()
        ->and($own->fresh()->environment_id)->toBe($target->id);
});

test('manage_database destructive actions need confirm=true', function (string $action, array $data) {
    $own = $this->mine['database'];

    $denied = mcpDataCall(mcpDataToken($this->user, ['write', 'deploy']), 'manage_database', ['action' => $action, 'uuid' => $own->uuid, 'data' => $data]);

    expect(mcpDataIsError($denied))->toBeTrue()
        ->and(mcpDataText($denied))->toContain('confirm=true')
        ->and(StandalonePostgresql::count())->toBe(2)
        ->and($own->fresh())->not->toBeNull();
})->with([
    'delete' => ['delete', []],
    'clone' => ['clone', ['destination_uuid' => 'x']],
    'move' => ['move', ['environment_uuid' => 'x']],
    'import' => ['import', ['source' => 'server', 'path' => '/tmp/a.sql']],
]);

test('manage_database delete with confirm=true deletes an own database', function () {
    $own = $this->mine['database'];

    $writeOnly = mcpDataCall(mcpDataToken($this->user), 'manage_database', ['action' => 'delete', 'uuid' => $own->uuid, 'confirm' => true]);
    expect(mcpDataIsError($writeOnly))->toBeTrue()
        ->and(mcpDataText($writeOnly))->toContain('Missing required permissions: deploy')
        ->and(StandalonePostgresql::find($own->id))->not->toBeNull();

    $response = mcpDataCall(mcpDataToken($this->user, ['write', 'deploy']), 'manage_database', ['action' => 'delete', 'uuid' => $own->uuid, 'confirm' => true]);

    expect(mcpDataIsError($response))->toBeFalse()
        ->and(StandalonePostgresql::find($own->id))->toBeNull();
});

test('manage_database import needs deploy and runs REST validation', function () {
    $own = $this->mine['database'];
    $writeOnly = mcpDataToken($this->user);

    foreach ([['source' => 's3', 's3_storage_uuid' => 'x', 'path' => '/a.sql'], ['source' => 'server', 'path' => '/etc/passwd']] as $data) {
        $denied = mcpDataCall($writeOnly, 'manage_database', ['action' => 'import', 'uuid' => $own->uuid, 'confirm' => true, 'data' => $data]);
        expect(mcpDataIsError($denied))->toBeTrue()
            ->and(mcpDataText($denied))->toContain('Missing required permissions: deploy');
    }

    $invalid = mcpDataCall(mcpDataToken($this->user, ['write', 'deploy']), 'manage_database', ['action' => 'import', 'uuid' => $own->uuid, 'confirm' => true, 'data' => ['source' => 's3']]);
    expect(mcpDataIsError($invalid))->toBeTrue()
        ->and(mcpDataText($invalid))->toContain('s3_storage_uuid');
});

test('manage_database rejects read-only and member tokens', function () {
    $own = $this->mine['database'];
    $member = mcpDataMember($this->team);

    $read = mcpDataCall(mcpDataToken($this->user, ['read']), 'manage_database', ['action' => 'update', 'uuid' => $own->uuid, 'data' => ['name' => 'nope']]);
    $memberResponse = mcpDataCall(mcpDataToken($member, ['read']), 'manage_database', ['action' => 'update', 'uuid' => $own->uuid, 'data' => ['name' => 'nope']]);

    expect(mcpDataIsError($read))->toBeTrue()
        ->and(mcpDataIsError($memberResponse))->toBeTrue()
        ->and($own->fresh()->name)->not->toBe('nope');
});

// get_database_import

test('get_database_import returns an own import and hides imports of other teams', function () {
    $own = $this->mine['database'];
    $foreign = $this->theirs['database'];
    $makeActivity = fn (Team $team, StandalonePostgresql $database): Activity => Activity::create([
        'log_name' => 'default',
        'description' => 'import',
        'properties' => ['team_id' => $team->id, 'type_uuid' => $database->uuid, 'operation' => 'database_import', 'status' => 'finished'],
    ]);
    $ownActivity = $makeActivity($this->team, $own);
    $foreignActivity = $makeActivity($this->otherTeam, $foreign);
    $token = mcpDataToken($this->user, ['read']);

    $ok = mcpDataCall($token, 'get_database_import', ['action' => 'get', 'resource' => 'database', 'uuid' => $own->uuid, 'activity_id' => (string) $ownActivity->id]);
    $foreignUuid = mcpDataCall($token, 'get_database_import', ['action' => 'get', 'resource' => 'database', 'uuid' => $foreign->uuid, 'activity_id' => (string) $foreignActivity->id]);
    $foreignId = mcpDataCall($token, 'get_database_import', ['action' => 'get', 'resource' => 'database', 'uuid' => $own->uuid, 'activity_id' => (string) $foreignActivity->id]);

    expect(mcpDataIsError($ok))->toBeFalse()
        ->and(mcpDataJson($ok)['data']['status'])->toBe('finished')
        ->and(mcpDataJson($ok)['data']['output'])->toBeNull()
        ->and(mcpDataIsError($foreignUuid))->toBeTrue()
        ->and(mcpDataIsError($foreignId))->toBeTrue();
});

test('get_database_import rejects a service database of another team', function () {
    $response = mcpDataCall(mcpDataToken($this->user, ['read']), 'get_database_import', [
        'action' => 'get',
        'resource' => 'service_database',
        'uuid' => $this->mine['service']->uuid,
        'database_uuid' => $this->theirs['service_db']->uuid,
        'activity_id' => '1',
    ]);

    expect(mcpDataIsError($response))->toBeTrue()
        ->and(mcpDataText($response))->toContain('not found');
});

// list_service_templates

test('list_service_templates lists template metadata without Compose content', function () {
    $templates = get_service_templates();
    $type = (string) $templates->keys()->first();

    $response = mcpDataCall(mcpDataToken($this->user, ['read']), 'list_service_templates', ['search' => $type, 'per_page' => 100]);

    expect(mcpDataIsError($response))->toBeFalse();
    $items = collect(mcpDataJson($response)['data']);
    expect($items->pluck('type'))->toContain($type)
        ->and(array_keys($items->first()))->toEqualCanonicalizing(['type', 'slogan', 'category', 'tags', 'documentation', 'port'])
        ->and(mcpDataText($response))->not->toContain((string) data_get($templates->get($type), 'compose'));
});

test('list_service_templates paginates and requires a valid token', function () {
    $response = mcpDataCall(mcpDataToken($this->user, ['read']), 'list_service_templates', ['per_page' => 2]);
    $payload = mcpDataJson($response);

    expect($payload['data'])->toHaveCount(2)
        ->and($payload['_pagination']['total'])->toBe(get_service_templates()->count())
        ->and($payload['_pagination']['next']['args']['page'])->toBe(2);

    $none = mcpDataCall(mcpDataToken($this->user, []), 'list_service_templates');
    expect(mcpDataIsError($none))->toBeTrue();
});

// create_service

test('create_service creates a Compose service in the token team', function () {
    $response = mcpDataCall(mcpDataToken($this->user), 'create_service', ['action' => 'compose', 'data' => [
        'project_uuid' => $this->mine['project']->uuid,
        'environment_uuid' => $this->mine['environment']->uuid,
        'server_uuid' => $this->mine['server']->uuid,
        'name' => 'mcp-service',
        'docker_compose_raw' => base64_encode("services:\n  app:\n    image: nginx:alpine\n"),
    ]]);

    expect(mcpDataIsError($response))->toBeFalse();
    $service = Service::whereUuid(mcpDataJson($response)['data']['uuid'])->sole();
    expect($service->name)->toBe('mcp-service')
        ->and($service->environment_id)->toBe($this->mine['environment']->id)
        ->and($service->server_id)->toBe($this->mine['server']->id);
});

test('create_service rejects a project, server, or destination of another team', function (string $field) {
    $own = $this->mine;
    StandaloneDocker::create(['name' => 'second', 'network' => 'second-net', 'server_id' => $own['server']->id]);
    $data = [
        'project_uuid' => $own['project']->uuid,
        'environment_uuid' => $own['environment']->uuid,
        'server_uuid' => $own['server']->uuid,
        'destination_uuid' => $own['destination']->uuid,
        'name' => 'cross-team-service',
        'docker_compose_raw' => base64_encode("services:\n  app:\n    image: nginx:alpine\n"),
    ];
    $data[$field] = match ($field) {
        'project_uuid' => $this->theirs['project']->uuid,
        'environment_uuid' => $this->theirs['environment']->uuid,
        'server_uuid' => $this->theirs['server']->uuid,
        'destination_uuid' => $this->theirs['destination']->uuid,
    };

    $response = mcpDataCall(mcpDataToken($this->user), 'create_service', ['action' => 'compose', 'data' => $data]);

    expect(mcpDataIsError($response))->toBeTrue()
        ->and(Service::where('name', 'cross-team-service')->exists())->toBeFalse();
})->with(['project_uuid', 'environment_uuid', 'server_uuid', 'destination_uuid']);

test('create_service rejects read-only and member tokens', function () {
    $data = [
        'project_uuid' => $this->mine['project']->uuid,
        'environment_uuid' => $this->mine['environment']->uuid,
        'server_uuid' => $this->mine['server']->uuid,
        'name' => 'denied-service',
        'docker_compose_raw' => base64_encode("services:\n  app:\n    image: nginx:alpine\n"),
    ];

    $read = mcpDataCall(mcpDataToken($this->user, ['read']), 'create_service', ['action' => 'compose', 'data' => $data]);
    $member = mcpDataCall(mcpDataToken(mcpDataMember($this->team), ['write']), 'create_service', ['action' => 'compose', 'data' => $data]);

    expect(mcpDataIsError($read))->toBeTrue()
        ->and(mcpDataIsError($member))->toBeTrue()
        ->and(Service::where('name', 'denied-service')->exists())->toBeFalse();
});

// manage_service

test('manage_service updates a service, its application, and its database', function () {
    $own = $this->mine;
    $token = mcpDataToken($this->user);

    $service = mcpDataCall($token, 'manage_service', ['action' => 'update', 'uuid' => $own['service']->uuid, 'data' => ['name' => 'renamed-service']]);
    $app = mcpDataCall($token, 'manage_service', ['action' => 'update_application', 'uuid' => $own['service']->uuid, 'app_uuid' => $own['service_app']->uuid, 'data' => ['human_name' => 'Web']]);
    $db = mcpDataCall($token, 'manage_service', ['action' => 'update_database', 'uuid' => $own['service']->uuid, 'database_uuid' => $own['service_db']->uuid, 'data' => ['human_name' => 'DB']]);

    expect(mcpDataIsError($service))->toBeFalse()
        ->and($own['service']->fresh()->name)->toBe('renamed-service')
        ->and(mcpDataIsError($app))->toBeFalse()
        ->and($own['service_app']->fresh()->human_name)->toBe('Web')
        ->and(mcpDataIsError($db))->toBeFalse()
        ->and($own['service_db']->fresh()->human_name)->toBe('DB');
});

test('manage_service cannot touch a service of another team', function (string $action, array $data) {
    $foreign = $this->theirs;

    $response = mcpDataCall(mcpDataToken($this->user, ['write', 'deploy']), 'manage_service', [
        'action' => $action,
        'uuid' => $foreign['service']->uuid,
        'app_uuid' => $foreign['service_app']->uuid,
        'database_uuid' => $foreign['service_db']->uuid,
        'confirm' => true,
        'data' => $data,
    ]);

    expect(mcpDataIsError($response))->toBeTrue();
    $fresh = Service::withTrashed()->find($foreign['service']->id);
    expect($fresh->trashed())->toBeFalse()
        ->and($fresh->name)->toBe($foreign['service']->name)
        ->and($fresh->environment_id)->toBe($foreign['environment']->id)
        ->and($foreign['service_app']->fresh()->human_name)->toBeNull()
        ->and($foreign['service_db']->fresh()->human_name)->toBeNull()
        ->and(Service::count())->toBe(2);
})->with([
    'update' => ['update', ['name' => 'hijacked']],
    'delete' => ['delete', []],
    'clone' => ['clone', ['destination_uuid' => 'placeholder']],
    'move' => ['move', ['environment_uuid' => 'placeholder']],
    'update_application' => ['update_application', ['human_name' => 'hijacked']],
    'update_database' => ['update_database', ['human_name' => 'hijacked']],
    'import_database' => ['import_database', ['source' => 's3', 's3_storage_uuid' => 'x', 'path' => '/a.sql']],
]);

test('manage_service cannot reach an application or database of another team through an own service', function () {
    $token = mcpDataToken($this->user);

    $app = mcpDataCall($token, 'manage_service', ['action' => 'update_application', 'uuid' => $this->mine['service']->uuid, 'app_uuid' => $this->theirs['service_app']->uuid, 'data' => ['human_name' => 'hijacked']]);
    $db = mcpDataCall($token, 'manage_service', ['action' => 'update_database', 'uuid' => $this->mine['service']->uuid, 'database_uuid' => $this->theirs['service_db']->uuid, 'data' => ['human_name' => 'hijacked']]);

    expect(mcpDataIsError($app))->toBeTrue()
        ->and(mcpDataIsError($db))->toBeTrue()
        ->and($this->theirs['service_app']->fresh()->human_name)->toBeNull()
        ->and($this->theirs['service_db']->fresh()->human_name)->toBeNull();
});

test('manage_service rejects a destination, environment, or S3 storage of another team', function () {
    $own = $this->mine;
    $token = mcpDataToken($this->user, ['write', 'deploy']);
    $foreignS3 = mcpDataS3($this->otherTeam);

    $clone = mcpDataCall($token, 'manage_service', ['action' => 'clone', 'uuid' => $own['service']->uuid, 'confirm' => true, 'data' => ['destination_uuid' => $this->theirs['destination']->uuid]]);
    $move = mcpDataCall($token, 'manage_service', ['action' => 'move', 'uuid' => $own['service']->uuid, 'confirm' => true, 'data' => ['environment_uuid' => $this->theirs['environment']->uuid]]);
    $import = mcpDataCall($token, 'manage_service', ['action' => 'import_database', 'uuid' => $own['service']->uuid, 'database_uuid' => $own['service_db']->uuid, 'confirm' => true, 'data' => ['source' => 's3', 's3_storage_uuid' => $foreignS3->uuid, 'path' => '/a.sql']]);

    expect(mcpDataIsError($clone))->toBeTrue()
        ->and(mcpDataIsError($move))->toBeTrue()
        ->and(mcpDataIsError($import))->toBeTrue()
        ->and(Service::count())->toBe(2)
        ->and($own['service']->fresh()->environment_id)->toBe($own['environment']->id);
});

test('manage_service destructive actions need confirm=true', function (string $action) {
    $own = $this->mine;

    $denied = mcpDataCall(mcpDataToken($this->user, ['write', 'deploy']), 'manage_service', ['action' => $action, 'uuid' => $own['service']->uuid, 'database_uuid' => $own['service_db']->uuid, 'data' => ['destination_uuid' => 'x']]);

    expect(mcpDataIsError($denied))->toBeTrue()
        ->and(mcpDataText($denied))->toContain('confirm=true')
        ->and($own['service']->fresh())->not->toBeNull();
})->with(['delete', 'clone', 'move', 'import_database']);

test('manage_service delete with confirm=true deletes an own service', function () {
    $writeOnly = mcpDataCall(mcpDataToken($this->user), 'manage_service', ['action' => 'delete', 'uuid' => $this->mine['service']->uuid, 'confirm' => true]);
    expect(mcpDataIsError($writeOnly))->toBeTrue()
        ->and(mcpDataText($writeOnly))->toContain('Missing required permissions: deploy')
        ->and(Service::find($this->mine['service']->id))->not->toBeNull();

    $response = mcpDataCall(mcpDataToken($this->user, ['write', 'deploy']), 'manage_service', ['action' => 'delete', 'uuid' => $this->mine['service']->uuid, 'confirm' => true]);

    expect(mcpDataIsError($response))->toBeFalse()
        ->and(Service::find($this->mine['service']->id))->toBeNull();
});

test('manage_service rejects read-only tokens', function () {
    $response = mcpDataCall(mcpDataToken($this->user, ['read']), 'manage_service', ['action' => 'update', 'uuid' => $this->mine['service']->uuid, 'data' => ['name' => 'nope']]);

    expect(mcpDataIsError($response))->toBeTrue()
        ->and($this->mine['service']->fresh()->name)->not->toBe('nope');
});

// manage_env

test('manage_env creates, updates, and deletes variables without returning values', function (string $resource) {
    $target = match ($resource) {
        'application' => $this->mine['application'],
        'database' => $this->mine['database'],
        'service' => $this->mine['service'],
    };
    $token = mcpDataToken($this->user);
    $env = fn (string $key) => EnvironmentVariable::where('resourceable_type', $target->getMorphClass())->where('resourceable_id', $target->id)->where('key', $key)->where('is_preview', false)->first();

    $create = mcpDataCall($token, 'manage_env', ['action' => 'create', 'resource' => $resource, 'uuid' => $target->uuid, 'data' => ['key' => 'MCP_KEY', 'value' => 'first-secret-value']]);
    $update = mcpDataCall($token, 'manage_env', ['action' => 'update', 'resource' => $resource, 'uuid' => $target->uuid, 'data' => ['key' => 'MCP_KEY', 'value' => 'second-secret-value']]);

    expect(mcpDataIsError($create))->toBeFalse()
        ->and(mcpDataIsError($update))->toBeFalse()
        ->and($env('MCP_KEY')->value)->toBe('second-secret-value');
    foreach ([$create, $update] as $response) {
        expect(mcpDataText($response))->not->toContain('secret-value');
    }

    $uuid = $env('MCP_KEY')->uuid;
    $denied = mcpDataCall($token, 'manage_env', ['action' => 'delete', 'resource' => $resource, 'uuid' => $target->uuid, 'env_uuid' => $uuid]);
    expect(mcpDataIsError($denied))->toBeTrue()
        ->and(mcpDataText($denied))->toContain('confirm=true')
        ->and($env('MCP_KEY'))->not->toBeNull();

    $deleted = mcpDataCall($token, 'manage_env', ['action' => 'delete', 'resource' => $resource, 'uuid' => $target->uuid, 'env_uuid' => $uuid, 'confirm' => true]);
    expect(mcpDataIsError($deleted))->toBeFalse()
        ->and($env('MCP_KEY'))->toBeNull();
})->with(['application', 'database', 'service']);

test('manage_env bulk updates variables without returning values', function (string $resource) {
    $target = match ($resource) {
        'application' => $this->mine['application'],
        'database' => $this->mine['database'],
        'service' => $this->mine['service'],
    };

    $bulk = mcpDataCall(mcpDataToken($this->user), 'manage_env', ['action' => 'bulk_update', 'resource' => $resource, 'uuid' => $target->uuid, 'data' => ['data' => [['key' => 'MCP_BULK', 'value' => 'bulk-secret-value']]]]);

    expect(mcpDataIsError($bulk))->toBeFalse()
        ->and(mcpDataText($bulk))->not->toContain('bulk-secret-value')
        ->and(EnvironmentVariable::where('resourceable_type', $target->getMorphClass())->where('resourceable_id', $target->id)->where('key', 'MCP_BULK')->where('is_preview', false)->sole()->value)->toBe('bulk-secret-value');
})->with(['application', 'database', 'service']);

test('manage_env cannot change variables of another team', function (string $action) {
    $foreign = $this->theirs['database'];
    $foreignEnv = mcpDataDbEnv($foreign, 'THEIR_KEY', 'their-value');
    $token = mcpDataToken($this->user);

    $viaForeignUuid = mcpDataCall($token, 'manage_env', ['action' => $action, 'resource' => 'database', 'uuid' => $foreign->uuid, 'env_uuid' => $foreignEnv->uuid, 'confirm' => true, 'data' => ['key' => 'THEIR_KEY', 'value' => 'hijacked', 'data' => [['key' => 'THEIR_KEY', 'value' => 'hijacked']]]]);
    $viaOwnUuid = mcpDataCall($token, 'manage_env', ['action' => 'delete', 'resource' => 'database', 'uuid' => $this->mine['database']->uuid, 'env_uuid' => $foreignEnv->uuid, 'confirm' => true]);

    expect(mcpDataIsError($viaForeignUuid))->toBeTrue()
        ->and(mcpDataIsError($viaOwnUuid))->toBeTrue()
        ->and($foreignEnv->fresh()->value)->toBe('their-value')
        ->and(EnvironmentVariable::where('resourceable_id', $foreign->id)->where('resourceable_type', $foreign->getMorphClass())->count())->toBe(1);
})->with(['create', 'update', 'bulk_update', 'delete']);

test('manage_env rejects read-only and member tokens', function () {
    $own = $this->mine['database'];
    $data = ['action' => 'create', 'resource' => 'database', 'uuid' => $own->uuid, 'data' => ['key' => 'DENIED', 'value' => 'x']];

    $read = mcpDataCall(mcpDataToken($this->user, ['read']), 'manage_env', $data);
    $member = mcpDataCall(mcpDataToken(mcpDataMember($this->team), ['write']), 'manage_env', $data);

    expect(mcpDataIsError($read))->toBeTrue()
        ->and(mcpDataIsError($member))->toBeTrue()
        ->and(EnvironmentVariable::where('key', 'DENIED')->exists())->toBeFalse();
});

// manage_shared_env

test('manage_shared_env creates, updates, and deletes shared variables in the token team', function (string $resource) {
    $args = match ($resource) {
        'team' => [],
        'project' => ['uuid' => $this->mine['project']->uuid],
        'environment' => ['uuid' => $this->mine['project']->uuid, 'environment_name_or_uuid' => $this->mine['environment']->uuid],
        'server' => ['uuid' => $this->mine['server']->uuid],
    };
    $token = mcpDataToken($this->user);

    $create = mcpDataCall($token, 'manage_shared_env', ['action' => 'create', 'resource' => $resource, 'data' => ['key' => 'SHARED_MCP', 'value' => 'shared-value']] + $args);
    expect(mcpDataIsError($create))->toBeFalse();
    $envId = mcpDataJson($create)['data']['id'];
    expect($envId)->not->toBeNull();
    $env = SharedEnvironmentVariable::where('key', 'SHARED_MCP')->sole();
    expect($env->team_id)->toBe($this->team->id)
        ->and($env->type)->toBe($resource);

    $update = mcpDataCall($token, 'manage_shared_env', ['action' => 'update', 'resource' => $resource, 'env_id' => (string) $envId, 'data' => ['value' => 'updated-value']] + $args);
    expect(mcpDataIsError($update))->toBeFalse()
        ->and($env->fresh()->value)->toBe('updated-value')
        ->and(mcpDataText($update))->not->toContain('updated-value');

    $denied = mcpDataCall($token, 'manage_shared_env', ['action' => 'delete', 'resource' => $resource, 'env_id' => (string) $envId] + $args);
    expect(mcpDataIsError($denied))->toBeTrue()
        ->and(mcpDataText($denied))->toContain('confirm=true')
        ->and($env->fresh())->not->toBeNull();

    $deleted = mcpDataCall($token, 'manage_shared_env', ['action' => 'delete', 'resource' => $resource, 'env_id' => (string) $envId, 'confirm' => true] + $args);
    expect(mcpDataIsError($deleted))->toBeFalse()
        ->and(SharedEnvironmentVariable::find($env->id))->toBeNull();
})->with(['team', 'project', 'environment', 'server']);

test('manage_shared_env cannot create in or change shared variables of another team', function (string $resource) {
    $foreignArgs = match ($resource) {
        'team' => [],
        'project' => ['uuid' => $this->theirs['project']->uuid],
        'environment' => ['uuid' => $this->theirs['project']->uuid, 'environment_name_or_uuid' => $this->theirs['environment']->uuid],
        'server' => ['uuid' => $this->theirs['server']->uuid],
    };
    $foreignEnv = SharedEnvironmentVariable::create([
        'key' => 'THEIR_SHARED',
        'value' => 'their-shared-value',
        'type' => $resource,
        'team_id' => $this->otherTeam->id,
        'project_id' => in_array($resource, ['project', 'environment'], true) ? $this->theirs['project']->id : null,
        'environment_id' => $resource === 'environment' ? $this->theirs['environment']->id : null,
        'server_id' => $resource === 'server' ? $this->theirs['server']->id : null,
    ]);
    $token = mcpDataToken($this->user);

    $update = mcpDataCall($token, 'manage_shared_env', ['action' => 'update', 'resource' => $resource, 'env_id' => (string) $foreignEnv->id, 'data' => ['value' => 'hijacked']] + $foreignArgs);
    $delete = mcpDataCall($token, 'manage_shared_env', ['action' => 'delete', 'resource' => $resource, 'env_id' => (string) $foreignEnv->id, 'confirm' => true] + $foreignArgs);

    expect(mcpDataIsError($update))->toBeTrue()
        ->and(mcpDataIsError($delete))->toBeTrue()
        ->and($foreignEnv->fresh()->value)->toBe('their-shared-value');

    if ($resource !== 'team') {
        $create = mcpDataCall($token, 'manage_shared_env', ['action' => 'create', 'resource' => $resource, 'data' => ['key' => 'INJECTED', 'value' => 'x']] + $foreignArgs);
        expect(mcpDataIsError($create))->toBeTrue()
            ->and(SharedEnvironmentVariable::where('key', 'INJECTED')->exists())->toBeFalse();
    }
})->with(['team', 'project', 'environment', 'server']);

test('manage_shared_env rejects read-only tokens', function () {
    $response = mcpDataCall(mcpDataToken($this->user, ['read']), 'manage_shared_env', ['action' => 'create', 'resource' => 'team', 'data' => ['key' => 'DENIED_SHARED', 'value' => 'x']]);

    expect(mcpDataIsError($response))->toBeTrue()
        ->and(SharedEnvironmentVariable::where('key', 'DENIED_SHARED')->exists())->toBeFalse();
});

// get_env_values

test('get_env_values returns values for an owner token with read:sensitive', function () {
    $own = $this->mine['database'];
    mcpDataDbEnv($own, 'DB_VISIBLE', 'visible-value');
    mcpDataDbEnv($own, 'DB_ONCE', 'shown-once-value', shownOnce: true);
    SharedEnvironmentVariable::create(['key' => 'TEAM_VISIBLE', 'value' => 'team-value', 'type' => 'team', 'team_id' => $this->team->id]);
    $token = mcpDataToken($this->user, ['read', 'read:sensitive']);

    $database = mcpDataCall($token, 'get_env_values', ['action' => 'list', 'resource' => 'database', 'uuid' => $own->uuid]);
    $team = mcpDataCall($token, 'get_env_values', ['action' => 'list', 'resource' => 'team']);

    expect(mcpDataIsError($database))->toBeFalse()
        ->and(collect(mcpDataJson($database)['data'])->firstWhere('key', 'DB_VISIBLE')['value'])->toBe('visible-value')
        ->and(mcpDataText($database))->not->toContain('shown-once-value')
        ->and(mcpDataIsError($team))->toBeFalse()
        ->and(collect(mcpDataJson($team)['data'])->firstWhere('key', 'TEAM_VISIBLE')['value'])->toBe('team-value');
});

test('get_env_values never returns values without read:sensitive or to members', function (array $abilities, bool $asMember) {
    $own = $this->mine['database'];
    mcpDataDbEnv($own, 'DB_HIDDEN', 'hidden-value');
    $user = $asMember ? mcpDataMember($this->team) : $this->user;

    $response = mcpDataCall(mcpDataToken($user, $abilities), 'get_env_values', ['action' => 'list', 'resource' => 'database', 'uuid' => $own->uuid]);

    expect(mcpDataIsError($response))->toBeTrue()
        ->and(mcpDataText($response))->not->toContain('hidden-value');
})->with([
    'read token' => [['read'], false],
    'write token' => [['read', 'write'], false],
    'member with read:sensitive' => [['read', 'read:sensitive'], true],
]);

test('get_env_values cannot read variables of another team', function (string $resource) {
    $foreign = $this->theirs;
    mcpDataDbEnv($foreign['database'], 'THEIR_DB', 'their-db-value');
    SharedEnvironmentVariable::create(['key' => 'THEIR_PROJECT', 'value' => 'their-project-value', 'type' => 'project', 'team_id' => $this->otherTeam->id, 'project_id' => $foreign['project']->id]);
    $args = match ($resource) {
        'application' => ['uuid' => $foreign['application']->uuid],
        'database' => ['uuid' => $foreign['database']->uuid],
        'service' => ['uuid' => $foreign['service']->uuid],
        'project' => ['uuid' => $foreign['project']->uuid],
        'environment' => ['uuid' => $foreign['project']->uuid, 'environment_name_or_uuid' => $foreign['environment']->uuid],
        'server' => ['uuid' => $foreign['server']->uuid],
    };

    $response = mcpDataCall(mcpDataToken($this->user, ['read', 'read:sensitive']), 'get_env_values', ['action' => 'list', 'resource' => $resource] + $args);

    expect(mcpDataIsError($response))->toBeTrue()
        ->and(mcpDataText($response))->not->toContain('their-');
})->with(['application', 'database', 'service', 'project', 'environment', 'server']);

test('get_env_values for the team returns only the token team variables', function () {
    SharedEnvironmentVariable::create(['key' => 'THEIR_TEAM', 'value' => 'their-team-value', 'type' => 'team', 'team_id' => $this->otherTeam->id]);

    $response = mcpDataCall(mcpDataToken($this->user, ['read', 'read:sensitive']), 'get_env_values', ['action' => 'list', 'resource' => 'team']);

    expect(mcpDataIsError($response))->toBeFalse()
        ->and(mcpDataText($response))->not->toContain('their-team-value');
});
