<?php

use App\Livewire\Project\Database\BackupEdit;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    config()->set('app.maintenance.store', 'array');
    config()->set('cache.default', 'array');

    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    $this->shop = Project::factory()->create(['team_id' => $this->team->id]);
    $this->shopProduction = Environment::factory()->create(['project_id' => $this->shop->id]);
    $this->blog = Project::factory()->create(['team_id' => $this->team->id]);
    $this->blogProduction = Environment::factory()->create(['project_id' => $this->blog->id]);

    // The shop runs on its own server. The blog server runs only blog resources.
    $this->shopServer = Server::factory()->create(['team_id' => $this->team->id]);
    $this->shopDestination = StandaloneDocker::where('server_id', $this->shopServer->id)->firstOrFail();
    $this->blogServer = Server::factory()->create(['team_id' => $this->team->id]);
    $this->blogDestination = StandaloneDocker::where('server_id', $this->blogServer->id)->firstOrFail();

    $this->shopApp = placementTestApplication($this->shopProduction, $this->shopDestination);
    placementTestApplication($this->blogProduction, $this->blogDestination);

    // The developer preset: write and deploy on the shop project only.
    $this->developer = placementTestMember($this->team, [['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['write', 'deploy']]]);
    $this->developerToken = placementTestToken($this->developer, $this->team, ['read', 'write', 'deploy'], [['scope' => 'project', 'uuid' => $this->shop->uuid]]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function placementTestApplication(Environment $environment, StandaloneDocker $destination): Application
{
    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function placementTestMember(Team $team, ?array $access, string $role = 'member'): User
{
    $member = User::factory()->create();
    $team->members()->attach($member->id, ['role' => $role, 'access' => $access === null ? null : json_encode($access)]);

    return $member;
}

function placementTestToken(User $user, Team $team, array $abilities, ?array $scopes = null): string
{
    $token = $user->createToken('placement-test', $abilities, null, $team->id);
    $token->accessToken->forceFill(['scopes' => $scopes])->save();

    return $token->plainTextToken;
}

function placementTestApi(string $token, string $method, string $uri, array $data = [])
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->json($method, $uri, $data);
}

function placementTestCompose(): string
{
    return base64_encode("services:\n  web:\n    image: nginx:alpine\n");
}

describe('project-scoped member', function () {
    test('cannot create a compose service on a server that runs no resource of the member', function () {
        placementTestApi($this->developerToken, 'POST', '/api/v1/services', [
            'project_uuid' => $this->shop->uuid,
            'environment_uuid' => $this->shopProduction->uuid,
            'server_uuid' => $this->blogServer->uuid,
            'docker_compose_raw' => placementTestCompose(),
        ])->assertForbidden();

        expect(Service::count())->toBe(0);
    });

    test('cannot create a database or an application on that server', function () {
        placementTestApi($this->developerToken, 'POST', '/api/v1/databases/postgresql', [
            'project_uuid' => $this->shop->uuid,
            'environment_uuid' => $this->shopProduction->uuid,
            'server_uuid' => $this->blogServer->uuid,
        ])->assertForbidden();

        placementTestApi($this->developerToken, 'POST', '/api/v1/applications/dockerimage', [
            'project_uuid' => $this->shop->uuid,
            'environment_uuid' => $this->shopProduction->uuid,
            'server_uuid' => $this->blogServer->uuid,
            'docker_registry_image_name' => 'nginx',
            'ports_exposes' => '80',
        ])->assertForbidden();

        expect(StandalonePostgresql::count())->toBe(0)
            ->and(Application::query()->whereRelation('environment', 'id', $this->shopProduction->id)->count())->toBe(1);
    });

    test('cannot clone a resource or add a server to an application on that server', function () {
        placementTestApi($this->developerToken, 'POST', "/api/v1/applications/{$this->shopApp->uuid}/clone", [
            'destination_uuid' => $this->blogDestination->uuid,
        ])->assertForbidden();

        placementTestApi($this->developerToken, 'POST', "/api/v1/applications/{$this->shopApp->uuid}/destinations", [
            'destination_uuid' => $this->blogDestination->uuid,
        ])->assertForbidden();

        expect(Application::withoutGlobalScopes()->count())->toBe(2)
            ->and($this->shopApp->additional_networks()->count())->toBe(0);
    });

    test('can still create a database on a server that runs a resource of the project', function () {
        placementTestApi($this->developerToken, 'POST', '/api/v1/databases/postgresql', [
            'project_uuid' => $this->shop->uuid,
            'environment_uuid' => $this->shopProduction->uuid,
            'server_uuid' => $this->shopServer->uuid,
        ])->assertCreated();

        expect(StandalonePostgresql::count())->toBe(1);
    });

    test('the Livewire destination lookup refuses a server that runs no resource of the member', function () {
        $this->actingAs($this->developer);
        session(['currentTeam' => $this->team]);

        expect(find_resource_destination_for_current_team($this->shopDestination->uuid)?->id)->toBe($this->shopDestination->id);
        expect(fn () => find_resource_destination_for_current_team($this->blogDestination->uuid))->toThrow(AuthorizationException::class);
    });
});

test('an admin token scoped to one project cannot place a resource on a server outside the project', function () {
    $token = placementTestToken($this->admin, $this->team, ['read', 'write', 'deploy'], [['scope' => 'project', 'uuid' => $this->shop->uuid]]);

    placementTestApi($token, 'POST', '/api/v1/databases/postgresql', [
        'project_uuid' => $this->shop->uuid,
        'environment_uuid' => $this->shopProduction->uuid,
        'server_uuid' => $this->blogServer->uuid,
    ])->assertForbidden();

    expect(StandalonePostgresql::count())->toBe(0);
});

test('a member with team-wide write can place resources on every server of the team', function () {
    $operator = placementTestMember($this->team, [['scope' => 'team', 'uuid' => null, 'levels' => ['write', 'deploy', 'delete']]]);

    placementTestApi(placementTestToken($operator, $this->team, ['read', 'write', 'deploy']), 'POST', '/api/v1/databases/postgresql', [
        'project_uuid' => $this->shop->uuid,
        'environment_uuid' => $this->shopProduction->uuid,
        'server_uuid' => $this->blogServer->uuid,
    ])->assertCreated();
});

describe('the server that runs Coolify', function () {
    beforeEach(function () {
        $this->rootTeam = Team::factory()->create(['id' => 0]);
        $this->localhost = Server::factory()->create(['id' => 0, 'uuid' => 'localhost', 'team_id' => 0, 'ip' => 'host.docker.internal']);
        $this->rootProject = Project::factory()->create(['team_id' => 0]);
        $this->rootEnvironment = Environment::factory()->create(['project_id' => $this->rootProject->id]);
    });

    test('is closed to a project-scoped member of the root team', function () {
        $developer = placementTestMember($this->rootTeam, [['scope' => 'project', 'uuid' => $this->rootProject->uuid, 'levels' => ['write', 'deploy']]]);

        $token = placementTestToken($developer, $this->rootTeam, ['read', 'write', 'deploy'], [['scope' => 'project', 'uuid' => $this->rootProject->uuid]]);

        placementTestApi($token, 'GET', '/api/v1/servers/localhost')->assertForbidden();
        placementTestApi($token, 'POST', '/api/v1/services', [
            'project_uuid' => $this->rootProject->uuid,
            'environment_uuid' => $this->rootEnvironment->uuid,
            'server_uuid' => 'localhost',
            'docker_compose_raw' => placementTestCompose(),
            'instant_deploy' => true,
        ])->assertForbidden();

        expect(Service::count())->toBe(0);
    });

    test('is closed to a root team member with team-wide write, who is not an instance admin', function () {
        $operator = placementTestMember($this->rootTeam, [['scope' => 'team', 'uuid' => null, 'levels' => ['write', 'deploy', 'delete']]]);

        expect(Gate::forUser($operator)->allows('placeResource', $this->localhost))->toBeFalse();
    });

    test('is open to an instance admin', function () {
        $instanceAdmin = placementTestMember($this->rootTeam, null, 'admin');

        expect(Gate::forUser($instanceAdmin)->allows('placeResource', $this->localhost))->toBeTrue();
    });
});

describe('team credentials in the API', function () {
    test('a project-scoped member cannot create an application with a team private key or git source', function () {
        $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
        $request = [
            'project_uuid' => $this->shop->uuid,
            'environment_uuid' => $this->shopProduction->uuid,
            'server_uuid' => $this->shopServer->uuid,
            'git_repository' => 'git@github.com:acme/private.git',
            'git_branch' => 'main',
            'build_pack' => 'dockerfile',
            'ports_exposes' => '80',
        ];

        placementTestApi($this->developerToken, 'POST', '/api/v1/applications/private-deploy-key', [...$request, 'private_key_uuid' => $privateKey->uuid])
            ->assertForbidden()
            ->assertJsonPath('message', 'You need write access on the whole team to use team credentials, such as private keys, git sources, and S3 storages.');
        placementTestApi($this->developerToken, 'POST', '/api/v1/applications/private-github-app', [...$request, 'github_app_uuid' => 'any-github-app'])
            ->assertForbidden();

        expect(Application::withoutGlobalScopes()->count())->toBe(2);
    });

    test('a member with team-wide write can create an application with a team private key', function () {
        $operator = placementTestMember($this->team, [['scope' => 'team', 'uuid' => null, 'levels' => ['write', 'deploy', 'delete']]]);
        $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

        placementTestApi(placementTestToken($operator, $this->team, ['read', 'write', 'deploy']), 'POST', '/api/v1/applications/private-deploy-key', [
            'project_uuid' => $this->shop->uuid,
            'environment_uuid' => $this->shopProduction->uuid,
            'server_uuid' => $this->shopServer->uuid,
            'git_repository' => 'git@github.com:acme/private.git',
            'git_branch' => 'main',
            'build_pack' => 'dockerfile',
            'ports_exposes' => '80',
            'private_key_uuid' => $privateKey->uuid,
        ])->assertCreated();
    });
});

describe('team S3 storages for backups', function () {
    beforeEach(function () {
        $this->database = create_standalone_postgresql($this->shopProduction->id, $this->shopDestination);
        $this->firstStorage = placementTestS3Storage($this->team, 'first');
        $this->secondStorage = placementTestS3Storage($this->team, 'second');
    });

    test('a project-scoped member cannot send a new backup schedule to a team S3 storage', function () {
        placementTestApi($this->developerToken, 'POST', "/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => '0 0 * * *',
            'save_s3' => true,
            's3_storage_uuid' => $this->firstStorage->uuid,
        ])->assertForbidden();

        expect(ScheduledDatabaseBackup::count())->toBe(0);
    });

    test('a project-scoped member can keep the S3 storages of a schedule but cannot add another one', function () {
        $backup = ScheduledDatabaseBackup::create([
            'frequency' => '0 0 * * *',
            'save_s3' => true,
            'database_type' => $this->database->getMorphClass(),
            'database_id' => $this->database->id,
            'team_id' => $this->team->id,
        ]);
        $backup->syncS3Storages([$this->firstStorage->id]);
        $uri = "/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}";

        placementTestApi($this->developerToken, 'PATCH', $uri, ['s3_storage_uuids' => [$this->firstStorage->uuid]])->assertOk();
        placementTestApi($this->developerToken, 'PATCH', $uri, ['s3_storage_uuids' => [$this->firstStorage->uuid, $this->secondStorage->uuid]])->assertForbidden();

        expect($backup->fresh()->s3Storages()->pluck('s3_storages.id')->all())->toBe([$this->firstStorage->id]);
    });

    test('a project-scoped member cannot add a team S3 storage on the backup page', function () {
        $backup = ScheduledDatabaseBackup::create([
            'frequency' => '0 0 * * *',
            'save_s3' => false,
            'database_type' => $this->database->getMorphClass(),
            'database_id' => $this->database->id,
            'team_id' => $this->team->id,
        ]);
        $this->actingAs($this->developer);
        session(['currentTeam' => $this->team]);

        Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => collect()])
            ->set('saveS3', true)
            ->set('s3StorageIds', [$this->firstStorage->id])
            ->call('submit');

        expect($backup->fresh()->s3Storages()->count())->toBe(0)
            ->and((bool) $backup->fresh()->save_s3)->toBeFalse();
    });

    test('the backup page shows one error when a project-scoped member selects a team S3 storage', function () {
        $backup = ScheduledDatabaseBackup::create([
            'frequency' => '0 0 * * *',
            'save_s3' => false,
            'database_type' => $this->database->getMorphClass(),
            'database_id' => $this->database->id,
            'team_id' => $this->team->id,
        ]);
        $this->actingAs($this->developer);
        session(['currentTeam' => $this->team]);

        $component = Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => collect()])
            ->set('s3StorageIds', [$this->firstStorage->id]);

        $errors = collect($component->effects['dispatches'] ?? [])->where('name', 'error');
        expect($errors)->toHaveCount(1)
            ->and($backup->fresh()->s3Storages()->count())->toBe(0);
        $component->assertSet('s3StorageIds', [])->assertSet('saveS3', false);
    });

    test('a member with team-wide write can send a backup schedule to a team S3 storage', function () {
        $operator = placementTestMember($this->team, [['scope' => 'team', 'uuid' => null, 'levels' => ['write', 'deploy', 'delete']]]);

        placementTestApi(placementTestToken($operator, $this->team, ['read', 'write', 'deploy']), 'POST', "/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => '0 0 * * *',
            'save_s3' => true,
            's3_storage_uuid' => $this->firstStorage->uuid,
        ])->assertCreated();
    });
});

function placementTestS3Storage(Team $team, string $name): S3Storage
{
    return S3Storage::create([
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => $name.'-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
}
