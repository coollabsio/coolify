<?php

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    Queue::fake();
    Bus::fake();
    Process::fake();
    Server::flushIdentityMap();

    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true, 'is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->otherTeam = Team::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->otherTeam->members()->attach($this->otherUser->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = mcpAppCreateApplication($this->environment, $this->destination, 'mine');

    $this->otherServer = Server::factory()->create(['team_id' => $this->otherTeam->id]);
    $this->otherDestination = StandaloneDocker::where('server_id', $this->otherServer->id)->firstOrFail();
    $this->otherProject = Project::factory()->create(['team_id' => $this->otherTeam->id]);
    $this->otherEnvironment = $this->otherProject->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->otherProject->id]);
    $this->otherApplication = mcpAppCreateApplication($this->otherEnvironment, $this->otherDestination, 'theirs');
});

function mcpAppCreateApplication(Environment $environment, StandaloneDocker $destination, string $name): Application
{
    $application = Application::factory()->create([
        'name' => $name,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $application->settings->fill(['is_container_label_readonly_enabled' => false])->save();

    return $application;
}

function mcpAppToken(User $user, array $abilities = ['write']): string
{
    return $user->createToken('mcp-app', $abilities)->plainTextToken;
}

function mcpAppCall(string $token, string $tool, array $arguments = [])
{
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

function mcpAppText($response): string
{
    return (string) ($response->json('result.content.0.text') ?? $response->json('error.message'));
}

function mcpAppIsError($response): bool
{
    return $response->json('result.isError') === true || $response->json('error') !== null;
}

/**
 * @return array<string, mixed>
 */
function mcpAppPublicData(array $overrides = []): array
{
    return array_merge([
        'project_uuid' => test()->project->uuid,
        'environment_uuid' => test()->environment->uuid,
        'server_uuid' => test()->server->uuid,
        'git_repository' => 'https://gitlab.com/coolify/test-static-app',
        'git_branch' => 'main',
        'build_pack' => 'static',
        'ports_exposes' => '80',
        'autogenerate_domain' => false,
        'name' => 'mcp-created',
    ], $overrides);
}

function mcpAppCreatedCount(): int
{
    return Application::where('name', 'mcp-created')->count();
}

// create_application

test('create_application creates a public app in the token team, not the session team', function () {
    $token = mcpAppToken($this->user);
    $sessionTeam = Team::factory()->create();
    $sessionTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $sessionProject = Project::factory()->create(['team_id' => $sessionTeam->id]);
    session(['currentTeam' => $sessionTeam]);

    $response = mcpAppCall($token, 'create_application', ['action' => 'public', 'data' => mcpAppPublicData()]);

    expect(mcpAppIsError($response))->toBeFalse(mcpAppText($response));
    $application = Application::where('name', 'mcp-created')->sole();
    expect($application->environment->project->team_id)->toBe($this->team->id)
        ->and($application->destination_id)->toBe($this->destination->id);

    $intoSessionTeam = mcpAppCall($token, 'create_application', ['action' => 'public', 'data' => mcpAppPublicData([
        'project_uuid' => $sessionProject->uuid,
        'environment_name' => 'production',
        'name' => 'session-team-app',
    ])]);
    expect(mcpAppIsError($intoSessionTeam))->toBeTrue()
        ->and(Application::where('name', 'session-team-app')->exists())->toBeFalse();
});

test('create_application creates a docker image app', function () {
    $response = mcpAppCall(mcpAppToken($this->user), 'create_application', ['action' => 'docker_image', 'data' => [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'server_uuid' => $this->server->uuid,
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => 'latest',
        'ports_exposes' => '80',
        'autogenerate_domain' => false,
        'name' => 'mcp-created',
    ]]);

    expect(mcpAppIsError($response))->toBeFalse(mcpAppText($response))
        ->and(Application::where('name', 'mcp-created')->sole()->build_pack)->toBe('dockerimage');
});

test('create_application rejects a project or server of another team', function (string $field) {
    $data = mcpAppPublicData([$field => $field === 'project_uuid' ? $this->otherProject->uuid : $this->otherServer->uuid]);

    $response = mcpAppCall(mcpAppToken($this->user), 'create_application', ['action' => 'public', 'data' => $data]);

    expect(mcpAppIsError($response))->toBeTrue()
        ->and(mcpAppCreatedCount())->toBe(0);
})->with(['project_uuid', 'server_uuid']);

test('create_application rejects a destination of another team', function () {
    $extra = StandaloneDocker::forceCreate([
        'name' => 'second',
        'network' => 'second-network',
        'server_id' => $this->server->id,
    ]);
    expect($this->server->destinations()->count())->toBeGreaterThan(1);

    $response = mcpAppCall(mcpAppToken($this->user), 'create_application', ['action' => 'public', 'data' => mcpAppPublicData([
        'destination_uuid' => $this->otherDestination->uuid,
    ])]);

    expect(mcpAppIsError($response))->toBeTrue()
        ->and(mcpAppText($response))->toContain('destination_uuid')
        ->and(mcpAppCreatedCount())->toBe(0);

    $ok = mcpAppCall(mcpAppToken($this->user), 'create_application', ['action' => 'public', 'data' => mcpAppPublicData([
        'destination_uuid' => $extra->uuid,
    ])]);
    expect(mcpAppIsError($ok))->toBeFalse(mcpAppText($ok))
        ->and(Application::where('name', 'mcp-created')->sole()->destination_id)->toBe($extra->id);
});

test('create_application rejects a private key of another team', function () {
    $foreignKey = PrivateKey::factory()->create(['team_id' => $this->otherTeam->id]);

    $response = mcpAppCall(mcpAppToken($this->user), 'create_application', ['action' => 'deploy_key', 'data' => mcpAppPublicData([
        'git_repository' => 'git@gitlab.com:coolify/private-app.git',
        'private_key_uuid' => $foreignKey->uuid,
    ])]);

    expect(mcpAppIsError($response))->toBeTrue()
        ->and(mcpAppText($response))->toContain('Private Key not found')
        ->and(mcpAppCreatedCount())->toBe(0)
        ->and(mcpAppText($response))->not->toContain('PRIVATE KEY');
});

test('create_application rejects a GitHub App of another team', function () {
    $foreignApp = GithubApp::forceCreate([
        'name' => 'Their GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'team_id' => $this->otherTeam->id,
        'is_system_wide' => false,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->otherTeam->id])->id,
    ]);

    $response = mcpAppCall(mcpAppToken($this->user), 'create_application', ['action' => 'github_app', 'data' => mcpAppPublicData([
        'git_repository' => 'their-org/their-repo',
        'github_app_uuid' => $foreignApp->uuid,
    ])]);

    expect(mcpAppIsError($response))->toBeTrue()
        ->and(mcpAppText($response))->toContain('Github App not found')
        ->and(mcpAppCreatedCount())->toBe(0);
});

test('create_application rejects read-only and member tokens', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    foreach ([mcpAppToken($this->user, ['read']), mcpAppToken($member, ['read']), mcpAppToken($member, ['write'])] as $token) {
        $response = mcpAppCall($token, 'create_application', ['action' => 'public', 'data' => mcpAppPublicData()]);
        expect(mcpAppIsError($response))->toBeTrue();
    }

    expect(mcpAppCreatedCount())->toBe(0);
});

test('create_application instant_deploy needs the deploy ability', function () {
    $response = mcpAppCall(mcpAppToken($this->user), 'create_application', ['action' => 'public', 'data' => mcpAppPublicData(['instant_deploy' => true])]);

    expect(mcpAppIsError($response))->toBeTrue()
        ->and(mcpAppText($response))->toContain('deploy')
        ->and(mcpAppCreatedCount())->toBe(0)
        ->and(ApplicationDeploymentQueue::count())->toBe(0);
});

// manage_application

test('manage_application accepts scalar and null unused path parameters', function (mixed $destinationUuid) {
    $response = mcpAppCall(mcpAppToken($this->user), 'manage_application', [
        'action' => 'update',
        'uuid' => $this->application->uuid,
        'destination_uuid' => $destinationUuid,
        'data' => ['name' => 'changed'],
    ]);

    $response->assertOk()->assertJsonMissingPath('error');
    expect(mcpAppIsError($response))->toBeFalse()
        ->and($this->application->fresh()->name)->toBe('changed');
})->with([
    'string' => ['unused'],
    'integer' => [123],
    'float' => [1.5],
    'boolean' => [false],
    'null' => [null],
]);

test('manage_application rejects non-scalar unused path parameters before updating', function (mixed $destinationUuid) {
    $response = mcpAppCall(mcpAppToken($this->user), 'manage_application', [
        'action' => 'update',
        'uuid' => $this->application->uuid,
        'destination_uuid' => $destinationUuid,
        'data' => ['name' => 'changed'],
    ]);

    $response->assertOk()->assertJsonPath('result.isError', true)->assertJsonMissingPath('error');
    expect(mcpAppText($response))->toContain('destination_uuid must be a string.')
        ->and($this->application->fresh()->name)->toBe('mine');
})->with([
    'array' => [['invalid']],
    'object' => [(object) ['uuid' => 'invalid']],
]);

test('manage_application updates, clones and moves an application of the token team', function () {
    $token = mcpAppToken($this->user);

    $updated = mcpAppCall($token, 'manage_application', ['action' => 'update', 'uuid' => $this->application->uuid, 'data' => ['name' => 'renamed']]);
    expect(mcpAppIsError($updated))->toBeFalse(mcpAppText($updated))
        ->and($this->application->fresh()->name)->toBe('renamed');

    $cloned = mcpAppCall($token, 'manage_application', ['action' => 'clone', 'uuid' => $this->application->uuid, 'data' => ['destination_uuid' => $this->destination->uuid, 'name' => 'cloned']]);
    expect(mcpAppIsError($cloned))->toBeFalse(mcpAppText($cloned))
        ->and(Application::where('name', 'cloned')->sole()->environment_id)->toBe($this->environment->id);

    $target = Environment::factory()->create(['project_id' => $this->project->id, 'name' => 'staging']);
    $moved = mcpAppCall($token, 'manage_application', ['action' => 'move', 'uuid' => $this->application->uuid, 'confirm' => true, 'data' => ['environment_uuid' => $target->uuid]]);
    expect(mcpAppIsError($moved))->toBeFalse(mcpAppText($moved))
        ->and($this->application->fresh()->environment_id)->toBe($target->id);
});

test('manage_application deletes an application only with confirm=true and a deploy token', function () {
    $writeOnly = mcpAppCall(mcpAppToken($this->user), 'manage_application', ['action' => 'delete', 'uuid' => $this->application->uuid, 'confirm' => true]);
    expect(mcpAppIsError($writeOnly))->toBeTrue()
        ->and(mcpAppText($writeOnly))->toContain('Missing required permissions: deploy')
        ->and(Application::find($this->application->id))->not->toBeNull();

    $token = mcpAppToken($this->user, ['write', 'deploy']);

    $denied = mcpAppCall($token, 'manage_application', ['action' => 'delete', 'uuid' => $this->application->uuid]);
    expect(mcpAppIsError($denied))->toBeTrue()
        ->and(mcpAppText($denied))->toContain('confirm=true')
        ->and(Application::find($this->application->id))->not->toBeNull();

    $deleted = mcpAppCall($token, 'manage_application', ['action' => 'delete', 'uuid' => $this->application->uuid, 'confirm' => true]);
    expect(mcpAppIsError($deleted))->toBeFalse(mcpAppText($deleted))
        ->and(Application::find($this->application->id))->toBeNull();
    Bus::assertDispatched(DeleteResourceJob::class);
});

test('manage_application needs confirm for move and remove_server', function (string $action) {
    $response = mcpAppCall(mcpAppToken($this->user), 'manage_application', [
        'action' => $action,
        'uuid' => $this->application->uuid,
        'destination_uuid' => $this->destination->uuid,
        'data' => [],
    ]);

    expect(mcpAppIsError($response))->toBeTrue()
        ->and(mcpAppText($response))->toContain('confirm=true')
        ->and($this->application->fresh()->environment_id)->toBe($this->environment->id);
})->with(['move', 'remove_server']);

test('manage_application cannot touch an application of another team', function (string $action, array $data) {
    $before = $this->otherApplication->fresh()->toArray();

    $response = mcpAppCall(mcpAppToken($this->user, ['write', 'deploy']), 'manage_application', [
        'action' => $action,
        'uuid' => $this->otherApplication->uuid,
        'destination_uuid' => $this->otherDestination->uuid,
        'confirm' => true,
        'data' => $data,
    ]);

    expect(mcpAppIsError($response))->toBeTrue()
        ->and(Application::find($this->otherApplication->id))->not->toBeNull()
        ->and($this->otherApplication->fresh()->name)->toBe($before['name'])
        ->and($this->otherApplication->fresh()->environment_id)->toBe($before['environment_id'])
        ->and(Application::where('name', 'hijacked')->exists())->toBeFalse();
})->with([
    'update' => ['update', ['name' => 'hijacked']],
    'delete' => ['delete', []],
    'clone' => ['clone', ['destination_uuid' => 'placeholder', 'name' => 'hijacked']],
    'move' => ['move', ['environment_uuid' => 'placeholder']],
    'add_server' => ['add_server', ['destination_uuid' => 'placeholder']],
    'remove_server' => ['remove_server', []],
]);

test('manage_application rejects a destination or environment of another team', function () {
    $token = mcpAppToken($this->user);

    $clone = mcpAppCall($token, 'manage_application', ['action' => 'clone', 'uuid' => $this->application->uuid, 'data' => ['destination_uuid' => $this->otherDestination->uuid, 'name' => 'hijacked']]);
    expect(mcpAppIsError($clone))->toBeTrue()
        ->and(Application::where('name', 'hijacked')->exists())->toBeFalse();

    $move = mcpAppCall($token, 'manage_application', ['action' => 'move', 'uuid' => $this->application->uuid, 'confirm' => true, 'data' => ['environment_uuid' => $this->otherEnvironment->uuid]]);
    expect(mcpAppIsError($move))->toBeTrue()
        ->and($this->application->fresh()->environment_id)->toBe($this->environment->id);

    $addServer = mcpAppCall($token, 'manage_application', ['action' => 'add_server', 'uuid' => $this->application->uuid, 'data' => ['destination_uuid' => $this->otherDestination->uuid]]);
    expect(mcpAppIsError($addServer))->toBeTrue()
        ->and($this->application->fresh()->additional_networks)->toHaveCount(0);
});

test('manage_application adds and removes an additional server of the token team', function () {
    $token = mcpAppToken($this->user);
    $secondServer = Server::factory()->create(['team_id' => $this->team->id]);
    $secondDestination = StandaloneDocker::where('server_id', $secondServer->id)->firstOrFail();

    $added = mcpAppCall($token, 'manage_application', ['action' => 'add_server', 'uuid' => $this->application->uuid, 'data' => ['destination_uuid' => $secondDestination->uuid]]);
    expect(mcpAppIsError($added))->toBeFalse(mcpAppText($added))
        ->and($this->application->fresh()->additional_networks)->toHaveCount(1);

    $removed = mcpAppCall($token, 'manage_application', ['action' => 'remove_server', 'uuid' => $this->application->uuid, 'destination_uuid' => $secondDestination->uuid, 'confirm' => true]);
    expect(mcpAppIsError($removed))->toBeFalse(mcpAppText($removed))
        ->and($this->application->fresh()->additional_networks)->toHaveCount(0);
});

test('manage_application rejects read-only and member tokens', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    foreach ([mcpAppToken($this->user, ['read']), mcpAppToken($member, ['read']), mcpAppToken($member, ['write'])] as $token) {
        $update = mcpAppCall($token, 'manage_application', ['action' => 'update', 'uuid' => $this->application->uuid, 'data' => ['name' => 'nope']]);
        $delete = mcpAppCall($token, 'manage_application', ['action' => 'delete', 'uuid' => $this->application->uuid, 'confirm' => true]);
        expect(mcpAppIsError($update))->toBeTrue()
            ->and(mcpAppIsError($delete))->toBeTrue();
    }

    expect($this->application->fresh()->name)->toBe('mine');
});

// manage_preview and get_application_preview

test('manage_preview creates, updates and deletes a preview of the token team', function () {
    $writeToken = mcpAppToken($this->user, ['write']);
    $deployToken = mcpAppToken($this->user, ['deploy']);

    $writeOnly = mcpAppCall($writeToken, 'manage_preview', ['action' => 'create', 'uuid' => $this->application->uuid, 'data' => ['pull_request_id' => 7, 'git_type' => 'github', 'instant_deploy' => false]]);
    expect(mcpAppIsError($writeOnly))->toBeTrue()
        ->and(mcpAppText($writeOnly))->toContain('deploy');

    $created = mcpAppCall($deployToken, 'manage_preview', ['action' => 'create', 'uuid' => $this->application->uuid, 'data' => ['pull_request_id' => 7, 'git_type' => 'github', 'instant_deploy' => false]]);
    expect(mcpAppIsError($created))->toBeFalse(mcpAppText($created))
        ->and(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 7)->exists())->toBeTrue();

    $updated = mcpAppCall($writeToken, 'manage_preview', ['action' => 'update', 'uuid' => $this->application->uuid, 'pull_request_id' => '7', 'data' => ['domains' => 'https://pr7.example.com']]);
    expect(mcpAppIsError($updated))->toBeFalse(mcpAppText($updated))
        ->and(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 7)->sole()->fqdn)->toBe('https://pr7.example.com');

    $writeOnlyDelete = mcpAppCall($writeToken, 'manage_preview', ['action' => 'delete', 'uuid' => $this->application->uuid, 'pull_request_id' => '7', 'confirm' => true]);
    expect(mcpAppIsError($writeOnlyDelete))->toBeTrue()
        ->and(mcpAppText($writeOnlyDelete))->toContain('Missing required permissions: deploy');

    $writeDeployToken = mcpAppToken($this->user, ['write', 'deploy']);
    $denied = mcpAppCall($writeDeployToken, 'manage_preview', ['action' => 'delete', 'uuid' => $this->application->uuid, 'pull_request_id' => '7']);
    expect(mcpAppIsError($denied))->toBeTrue()
        ->and(mcpAppText($denied))->toContain('confirm=true');

    $deleted = mcpAppCall($writeDeployToken, 'manage_preview', ['action' => 'delete', 'uuid' => $this->application->uuid, 'pull_request_id' => '7', 'confirm' => true]);
    expect(mcpAppIsError($deleted))->toBeFalse(mcpAppText($deleted))
        ->and(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 7)->exists())->toBeFalse();
});

test('manage_preview cannot touch previews of another team', function () {
    $foreignPreview = ApplicationPreview::create(['application_id' => $this->otherApplication->id, 'pull_request_id' => 3, 'pull_request_html_url' => '', 'fqdn' => 'https://theirs.example.com']);
    $token = mcpAppToken($this->user, ['write', 'deploy']);

    $create = mcpAppCall($token, 'manage_preview', ['action' => 'create', 'uuid' => $this->otherApplication->uuid, 'data' => ['pull_request_id' => 9, 'git_type' => 'github', 'instant_deploy' => false]]);
    $update = mcpAppCall($token, 'manage_preview', ['action' => 'update', 'uuid' => $this->otherApplication->uuid, 'pull_request_id' => '3', 'data' => ['domains' => 'https://hijacked.example.com']]);
    $delete = mcpAppCall($token, 'manage_preview', ['action' => 'delete', 'uuid' => $this->otherApplication->uuid, 'pull_request_id' => '3', 'confirm' => true]);

    expect(mcpAppIsError($create))->toBeTrue()
        ->and(mcpAppIsError($update))->toBeTrue()
        ->and(mcpAppIsError($delete))->toBeTrue()
        ->and(ApplicationPreview::where('application_id', $this->otherApplication->id)->where('pull_request_id', 9)->exists())->toBeFalse()
        ->and($foreignPreview->fresh())->not->toBeNull()
        ->and($foreignPreview->fresh()->fqdn)->toBe('https://theirs.example.com');
});

test('manage_preview rejects read-only tokens', function () {
    ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 4, 'pull_request_html_url' => '']);
    $token = mcpAppToken($this->user, ['read']);

    expect(mcpAppIsError(mcpAppCall($token, 'manage_preview', ['action' => 'create', 'uuid' => $this->application->uuid, 'data' => ['pull_request_id' => 5, 'git_type' => 'github']])))->toBeTrue()
        ->and(mcpAppIsError(mcpAppCall($token, 'manage_preview', ['action' => 'delete', 'uuid' => $this->application->uuid, 'pull_request_id' => '4', 'confirm' => true])))->toBeTrue()
        ->and(ApplicationPreview::where('application_id', $this->application->id)->count())->toBe(1);
});

test('get_application_preview returns a preview of the token team only', function () {
    ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 12, 'pull_request_html_url' => '', 'fqdn' => 'https://pr12.example.com']);
    ApplicationPreview::create(['application_id' => $this->otherApplication->id, 'pull_request_id' => 12, 'pull_request_html_url' => '', 'fqdn' => 'https://theirs.example.com']);
    $token = mcpAppToken($this->user, ['read']);

    $mine = mcpAppCall($token, 'get_application_preview', ['action' => 'get', 'uuid' => $this->application->uuid, 'pull_request_id' => '12']);
    expect(mcpAppIsError($mine))->toBeFalse(mcpAppText($mine))
        ->and(mcpAppText($mine))->toContain('pr12.example.com');

    $theirs = mcpAppCall($token, 'get_application_preview', ['action' => 'get', 'uuid' => $this->otherApplication->uuid, 'pull_request_id' => '12']);
    expect(mcpAppIsError($theirs))->toBeTrue()
        ->and(mcpAppText($theirs))->not->toContain('theirs.example.com');
});

test('get_application_preview logs need read:sensitive and stay inside the token team', function () {
    ApplicationPreview::create(['application_id' => $this->otherApplication->id, 'pull_request_id' => 12, 'pull_request_html_url' => '']);

    $readOnly = mcpAppCall(mcpAppToken($this->user, ['read']), 'get_application_preview', ['action' => 'logs', 'uuid' => $this->application->uuid, 'pull_request_id' => '12']);
    expect(mcpAppIsError($readOnly))->toBeTrue()
        ->and(mcpAppText($readOnly))->toContain('read:sensitive');

    $foreign = mcpAppCall(mcpAppToken($this->user, ['read', 'read:sensitive']), 'get_application_preview', ['action' => 'logs', 'uuid' => $this->otherApplication->uuid, 'pull_request_id' => '12']);
    expect(mcpAppIsError($foreign))->toBeTrue()
        ->and(mcpAppText($foreign))->toContain('not found');
    Process::assertNothingRan();
});

// rollback_application and list_rollback_images

test('rollback_application needs confirm and the deploy ability, then queues a rollback', function () {
    $writeOnly = mcpAppCall(mcpAppToken($this->user, ['write']), 'rollback_application', ['action' => 'rollback', 'uuid' => $this->application->uuid, 'confirm' => true, 'data' => ['commit' => 'abc1234']]);
    expect(mcpAppIsError($writeOnly))->toBeTrue()
        ->and(mcpAppText($writeOnly))->toContain('Tool [rollback_application] not found');

    $deployToken = mcpAppToken($this->user, ['deploy']);
    $unconfirmed = mcpAppCall($deployToken, 'rollback_application', ['action' => 'rollback', 'uuid' => $this->application->uuid, 'data' => ['commit' => 'abc1234']]);
    expect(mcpAppIsError($unconfirmed))->toBeTrue()
        ->and(mcpAppText($unconfirmed))->toContain('confirm=true')
        ->and(ApplicationDeploymentQueue::count())->toBe(0);

    $queued = mcpAppCall($deployToken, 'rollback_application', ['action' => 'rollback', 'uuid' => $this->application->uuid, 'confirm' => true, 'data' => ['commit' => 'abc1234']]);
    expect(mcpAppIsError($queued))->toBeFalse(mcpAppText($queued))
        ->and(ApplicationDeploymentQueue::where('application_id', $this->application->id)->where('commit', 'abc1234')->where('rollback', true)->exists())->toBeTrue();
});

test('rollback_application cannot roll back an application of another team', function () {
    $response = mcpAppCall(mcpAppToken($this->user, ['deploy']), 'rollback_application', ['action' => 'rollback', 'uuid' => $this->otherApplication->uuid, 'confirm' => true, 'data' => ['commit' => 'abc1234']]);

    expect(mcpAppIsError($response))->toBeTrue()
        ->and(ApplicationDeploymentQueue::count())->toBe(0);
});

test('list_rollback_images lists images for the token team only', function () {
    $token = mcpAppToken($this->user, ['read']);

    $mine = mcpAppCall($token, 'list_rollback_images', ['action' => 'list', 'uuid' => $this->application->uuid]);
    expect(mcpAppIsError($mine))->toBeFalse(mcpAppText($mine))
        ->and(mcpAppText($mine))->toContain('images');

    $theirs = mcpAppCall($token, 'list_rollback_images', ['action' => 'list', 'uuid' => $this->otherApplication->uuid]);
    expect(mcpAppIsError($theirs))->toBeTrue()
        ->and(mcpAppText($theirs))->toContain('not found');
});
