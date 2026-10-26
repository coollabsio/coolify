<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Security\ApiTokens;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Scopes\VisibleThroughAccessGrants;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $settings = new InstanceSettings(['is_api_enabled' => true, 'is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create(['is_mcp_server_enabled' => true]);
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->firstOrFail();

    $this->shop = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'shop']);
    $this->shopProduction = Environment::factory()->create(['project_id' => $this->shop->id, 'name' => 'prod']);
    $this->blog = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'blog']);
    $this->blogStaging = Environment::factory()->create(['project_id' => $this->blog->id, 'name' => 'staging']);

    $this->shopApi = scopedTokenTestApplication($this->shopProduction, $this->destination, 'shop-api');
    $this->shopWorker = scopedTokenTestApplication($this->shopProduction, $this->destination, 'shop-worker');
    $this->blogWeb = scopedTokenTestApplication($this->blogStaging, $this->destination, 'blog-web');
});

afterEach(function () {
    Server::flushIdentityMap();
});

function scopedTokenTestApplication(Environment $environment, $destination, string $name): Application
{
    return Application::factory()->create([
        'name' => $name,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

/**
 * @param  list<array{scope: string, uuid: string}>|null  $scopes
 */
function scopedTokenTestToken(User $user, Team $team, array $abilities, ?array $scopes): string
{
    $token = $user->createToken('scoped-test', $abilities, null, $team->id);
    $token->accessToken->forceFill(['scopes' => $scopes])->save();

    return $token->plainTextToken;
}

function scopedTokenTestMember(Team $team, ?array $access): User
{
    $member = User::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member', 'access' => $access === null ? null : json_encode($access)]);

    return $member;
}

function scopedTokenTestApi(string $token, string $method, string $uri, array $data = [])
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->json($method, $uri, $data);
}

function scopedTokenTestMcp(string $token, string $method, array $params = [])
{
    app('auth')->forgetGuards();

    return test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
}

describe('admin token scoped to one project', function () {
    beforeEach(function () {
        $this->token = scopedTokenTestToken($this->admin, $this->team, ['read', 'write'], [['scope' => 'project', 'uuid' => $this->shop->uuid]]);
    });

    test('lists only the applications and resources of the project', function () {
        expect(scopedTokenTestApi($this->token, 'GET', '/api/v1/applications')->assertOk()->json('*.name'))
            ->toEqualCanonicalizing(['shop-api', 'shop-worker'])
            ->and(scopedTokenTestApi($this->token, 'GET', '/api/v1/resources')->assertOk()->json('*.name'))
            ->toEqualCanonicalizing(['shop-api', 'shop-worker'])
            ->and(scopedTokenTestApi($this->token, 'GET', '/api/v1/projects')->assertOk()->json('*.name'))
            ->toBe(['shop']);
    });

    test('does not find an application in another project', function () {
        scopedTokenTestApi($this->token, 'GET', "/api/v1/applications/{$this->blogWeb->uuid}")->assertNotFound();
        scopedTokenTestApi($this->token, 'GET', "/api/v1/projects/{$this->blog->uuid}")->assertNotFound();
    });

    test('cannot use team-level endpoints or create projects', function () {
        scopedTokenTestApi($this->token, 'GET', '/api/v1/servers')
            ->assertForbidden()
            ->assertExactJson(['message' => 'This API token is limited to selected projects, environments, or resources, so it cannot use this endpoint.']);
        scopedTokenTestApi($this->token, 'GET', '/api/v1/security/keys')->assertForbidden();
        scopedTokenTestApi($this->token, 'GET', '/api/v1/team')->assertForbidden();
        scopedTokenTestApi($this->token, 'POST', '/api/v1/projects', ['name' => 'new'])->assertForbidden();

        expect(Project::query()->where('name', 'new')->exists())->toBeFalse();
    });

    test('lists only open deployments of the project', function () {
        foreach ([$this->shopApi, $this->blogWeb] as $application) {
            ApplicationDeploymentQueue::create([
                'application_id' => (string) $application->id,
                'deployment_uuid' => 'deploy-'.$application->name,
                'server_id' => $this->server->id,
                'status' => 'queued',
            ]);
        }

        expect(scopedTokenTestApi($this->token, 'GET', '/api/v1/deployments')->assertOk()->json('*.deployment_uuid'))
            ->toBe(['deploy-shop-api']);
        scopedTokenTestApi($this->token, 'GET', '/api/v1/deployments/deploy-blog-web')->assertNotFound();
    });

    test('can create an application in the project', function () {
        scopedTokenTestApi($this->token, 'POST', '/api/v1/applications/dockerimage', [
            'project_uuid' => $this->shop->uuid,
            'environment_uuid' => $this->shopProduction->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_registry_image_name' => 'nginx',
            'ports_exposes' => '80',
        ])->assertCreated();

        expect($this->shopProduction->applications()->count())->toBe(3);
    });
});

describe('token scoped to one resource', function () {
    test('deploys the resource with the deploy ability', function () {
        $token = scopedTokenTestToken($this->admin, $this->team, ['deploy'], [['scope' => 'resource', 'uuid' => $this->shopApi->uuid]]);

        scopedTokenTestApi($token, 'POST', "/api/v1/deploy?uuid={$this->shopApi->uuid}")
            ->assertOk()
            ->assertJsonPath('deployments.0.resource_uuid', $this->shopApi->uuid);
        scopedTokenTestApi($token, 'POST', "/api/v1/deploy?uuid={$this->shopWorker->uuid}")->assertNotFound();

        expect(ApplicationDeploymentQueue::query()->pluck('application_id')->all())->toBe([(string) $this->shopApi->id]);
        Queue::assertPushed(ApplicationDeploymentJob::class);
    });

    test('cannot create an application in the environment of the resource', function () {
        $token = scopedTokenTestToken($this->admin, $this->team, ['read', 'write'], [['scope' => 'resource', 'uuid' => $this->shopApi->uuid]]);

        scopedTokenTestApi($token, 'POST', '/api/v1/applications/dockerimage', [
            'project_uuid' => $this->shop->uuid,
            'environment_uuid' => $this->shopProduction->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_registry_image_name' => 'nginx',
            'ports_exposes' => '80',
        ])->assertForbidden();

        expect(Application::withoutGlobalScope(VisibleThroughAccessGrants::class)->where('environment_id', $this->shopProduction->id)->count())->toBe(2);
    });
});

describe('delete ability', function () {
    test('a write token without delete cannot delete an application', function () {
        $token = scopedTokenTestToken($this->admin, $this->team, ['read', 'write'], null);

        scopedTokenTestApi($token, 'DELETE', "/api/v1/applications/{$this->shopApi->uuid}")
            ->assertForbidden()
            ->assertExactJson(['message' => 'Missing required permissions: delete']);

        expect(Application::query()->whereKey($this->shopApi->id)->exists())->toBeTrue();
    });

    test('a token with delete can delete an application', function () {
        $token = scopedTokenTestToken($this->admin, $this->team, ['read', 'write', 'delete'], null);

        scopedTokenTestApi($token, 'DELETE', "/api/v1/applications/{$this->shopApi->uuid}")->assertOk();

        expect(Application::query()->whereKey($this->shopApi->id)->exists())->toBeFalse();
    });
});

describe('member tokens', function () {
    test('a legacy member can use a read token but not a write token', function () {
        $member = scopedTokenTestMember($this->team, null);

        expect(scopedTokenTestApi(scopedTokenTestToken($member, $this->team, ['read'], null), 'GET', '/api/v1/applications')->assertOk()->json('*.name'))
            ->toEqualCanonicalizing(['shop-api', 'shop-worker', 'blog-web']);
        scopedTokenTestApi(scopedTokenTestToken($member, $this->team, ['read', 'write'], null), 'GET', '/api/v1/applications')
            ->assertForbidden()
            ->assertExactJson(['message' => 'This API token has more access than you have in this team. Revoke it and create a new token.']);
    });

    test('a member with a project grant can use a write token limited to that project', function () {
        $member = scopedTokenTestMember($this->team, [['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['write', 'deploy']]]);
        $token = scopedTokenTestToken($member, $this->team, ['read', 'write'], [['scope' => 'project', 'uuid' => $this->shop->uuid]]);

        scopedTokenTestApi($token, 'PATCH', "/api/v1/applications/{$this->shopApi->uuid}", ['name' => 'renamed'])->assertOk();
        scopedTokenTestApi($token, 'PATCH', "/api/v1/applications/{$this->blogWeb->uuid}", ['name' => 'renamed'])->assertNotFound();

        expect($this->shopApi->fresh()->name)->toBe('renamed')
            ->and($this->blogWeb->fresh()->name)->toBe('blog-web');
    });

    test('a member with a project grant cannot use a team-wide write token', function () {
        $member = scopedTokenTestMember($this->team, [['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['write', 'deploy']]]);

        scopedTokenTestApi(scopedTokenTestToken($member, $this->team, ['read', 'write'], null), 'GET', '/api/v1/applications')->assertForbidden();
        scopedTokenTestApi(scopedTokenTestToken($member, $this->team, ['delete'], [['scope' => 'project', 'uuid' => $this->shop->uuid]]), 'GET', '/api/v1/applications')->assertForbidden();
    });

    test('a member with a write grant can create project and environment shared variables', function () {
        $member = scopedTokenTestMember($this->team, [['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['write']]]);
        $token = scopedTokenTestToken($member, $this->team, ['read', 'write'], [['scope' => 'project', 'uuid' => $this->shop->uuid]]);

        scopedTokenTestApi($token, 'POST', "/api/v1/projects/{$this->shop->uuid}/envs", ['key' => 'PROJECT_KEY', 'value' => 'v'])->assertCreated();
        scopedTokenTestApi($token, 'POST', "/api/v1/projects/{$this->shop->uuid}/environments/{$this->shopProduction->uuid}/envs", ['key' => 'ENVIRONMENT_KEY', 'value' => 'v'])->assertCreated();
        scopedTokenTestApi($token, 'POST', "/api/v1/projects/{$this->blog->uuid}/envs", ['key' => 'OTHER_KEY', 'value' => 'v'])->assertNotFound();

        expect(SharedEnvironmentVariable::query()->whereIn('key', ['PROJECT_KEY', 'ENVIRONMENT_KEY', 'OTHER_KEY'])->pluck('key')->all())->toEqualCanonicalizing(['PROJECT_KEY', 'ENVIRONMENT_KEY']);
    });

    test('a member with a read grant cannot create project or environment shared variables', function () {
        $member = scopedTokenTestMember($this->team, [['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']]]);
        $token = scopedTokenTestToken($member, $this->team, ['read'], [['scope' => 'project', 'uuid' => $this->shop->uuid]]);
        $writeToken = scopedTokenTestToken($member, $this->team, ['read', 'write'], [['scope' => 'project', 'uuid' => $this->shop->uuid]]);

        foreach ([$token, $writeToken] as $candidate) {
            scopedTokenTestApi($candidate, 'POST', "/api/v1/projects/{$this->shop->uuid}/envs", ['key' => 'PROJECT_KEY', 'value' => 'v'])->assertForbidden();
            scopedTokenTestApi($candidate, 'POST', "/api/v1/projects/{$this->shop->uuid}/environments/{$this->shopProduction->uuid}/envs", ['key' => 'ENVIRONMENT_KEY', 'value' => 'v'])->assertForbidden();
        }

        expect(SharedEnvironmentVariable::query()->whereIn('key', ['PROJECT_KEY', 'ENVIRONMENT_KEY'])->exists())->toBeFalse();
    });
});

describe('mcp', function () {
    test('a scoped token lists only scoped applications and does not get team-level tools', function () {
        $token = scopedTokenTestToken($this->admin, $this->team, ['read'], [['scope' => 'project', 'uuid' => $this->shop->uuid]]);

        $listed = json_decode((string) scopedTokenTestMcp($token, 'tools/call', ['name' => 'list_applications', 'arguments' => (object) []])->json('result.content.0.text'), true);
        $tools = scopedTokenTestMcp($token, 'tools/list')->json('result.tools.*.name');

        expect(collect($listed['data'])->pluck('name')->all())->toEqualCanonicalizing(['shop-api', 'shop-worker'])
            ->and($tools)->toContain('list_applications')
            ->and($tools)->not->toContain('list_servers')
            ->and($tools)->not->toContain('manage_private_key');
        expect(scopedTokenTestMcp($token, 'tools/call', ['name' => 'list_servers', 'arguments' => (object) []])->json('error'))->not->toBeNull();
    });

    test('a team-wide token still gets team-level tools', function () {
        $tools = scopedTokenTestMcp(scopedTokenTestToken($this->admin, $this->team, ['read'], null), 'tools/list')->json('result.tools.*.name');

        expect($tools)->toContain('list_servers');
    });
});

describe('token form', function () {
    beforeEach(function () {
        session(['currentTeam' => $this->team]);
    });

    test('creates a token limited to the selected project', function () {
        $this->actingAs($this->admin);

        Livewire::test(ApiTokens::class)
            ->set('description', 'shop deploys')
            ->set('permissions', ['deploy'])
            ->set('scopeMode', 'selected')
            ->set('selectedScopes', ["project:{$this->shop->uuid}"])
            ->call('addNewToken')
            ->assertHasNoErrors()
            ->assertNotDispatched('error');

        $token = PersonalAccessToken::query()->sole();
        expect($token->abilities)->toBe(['deploy'])
            ->and($token->scopes)->toBe([['scope' => 'project', 'uuid' => $this->shop->uuid]])
            ->and((int) $token->team_id)->toBe($this->team->id);
    });

    test('rejects a scope from another team', function () {
        $otherTeam = Team::factory()->create();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
        $this->actingAs($this->admin);

        Livewire::test(ApiTokens::class)
            ->set('description', 'foreign')
            ->set('permissions', ['read'])
            ->set('scopeMode', 'selected')
            ->set('selectedScopes', ["project:{$otherProject->uuid}"])
            ->call('addNewToken')
            ->assertDispatched('error');

        expect(PersonalAccessToken::query()->count())->toBe(0);
    });

    test('a member can create a token only within their grants', function () {
        $member = scopedTokenTestMember($this->team, [['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['write']]]);
        $this->actingAs($member);

        $form = Livewire::test(ApiTokens::class)->assertSet('scopeMode', 'selected');
        expect(collect($form->instance()->scopeOptions())->pluck('value')->filter(fn ($value) => str_starts_with($value, 'project:'))->values()->all())
            ->toBe(["project:{$this->shop->uuid}"]);

        $form->set('description', 'too wide')
            ->set('permissions', ['write'])
            ->set('scopeMode', 'team')
            ->call('addNewToken')
            ->assertDispatched('error');
        $form->set('description', 'blog')
            ->set('scopeMode', 'selected')
            ->set('selectedScopes', ["project:{$this->blog->uuid}"])
            ->call('addNewToken')
            ->assertDispatched('error');
        $form->set('description', 'shop')
            ->set('selectedScopes', ["project:{$this->shop->uuid}"])
            ->call('addNewToken');

        expect($member->tokens()->pluck('name')->all())->toBe(['shop']);
    });
});
