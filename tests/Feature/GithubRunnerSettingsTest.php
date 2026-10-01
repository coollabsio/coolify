<?php

use App\Enums\GithubRunnerDockerMode;
use App\Enums\GithubRunnerStatus;
use App\Jobs\CleanupGithubRunnerJob;
use App\Jobs\GithubAppPermissionJob;
use App\Jobs\ProvisionGithubRunnerJob;
use App\Livewire\Server\GithubRunnerExecutions;
use App\Livewire\Server\GithubRunners;
use App\Livewire\Server\Show;
use App\Livewire\Source\Github\Change;
use App\Livewire\Source\Github\Create;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->team->members()->attach($this->member->id, ['role' => 'member']);
    session(['currentTeam' => $this->team]);

    $this->server = settingsTestServer($this->team, 'build');
    $this->githubApp = settingsTestGithubApp($this->team, 3333);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function settingsTestServer(Team $team, string $role): Server
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'server_role' => $role,
        'is_build_server' => $role === 'build',
        'is_swarm_worker' => false,
        'force_disabled' => false,
    ]);

    return $server->fresh();
}

function settingsTestGithubApp(Team $team, int $appId, array $attributes = []): GithubApp
{
    $rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rsaKey, $pemKey);
    $privateKey = PrivateKey::create(['name' => 'Runner key', 'private_key' => $pemKey, 'team_id' => $team->id]);

    return GithubApp::create([
        'name' => "settings-app-{$appId}",
        'organization' => 'acme',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => $appId,
        'installation_id' => $appId + 1,
        'webhook_secret' => 'secret',
        'private_key_id' => $privateKey->id,
        'team_id' => $team->id,
        'is_system_wide' => false,
        'organization_self_hosted_runners' => 'write',
        'actions' => 'read',
        'webhook_events' => ['workflow_job'],
        ...$attributes,
    ]);
}

/**
 * Attributes of a GitHub App that is missing every runner permission.
 */
function unreadyRunnerApp(): array
{
    return ['organization_self_hosted_runners' => null, 'actions' => null, 'webhook_events' => ['push']];
}

function fakeRunnerGroupApi(GithubApp $githubApp, int $createStatus = 201): void
{
    Http::fake([
        'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
        "https://api.github.com/app/installations/{$githubApp->installation_id}/access_tokens" => Http::response(['token' => 'installation-token'], 201),
        'https://api.github.com/orgs/acme/actions/runner-groups' => fn ($request) => $request->method() === 'POST'
            ? Http::response($createStatus === 201 ? ['id' => 55, 'default' => false] : ['message' => 'Upgrade to create runner groups'], $createStatus)
            : Http::response(['runner_groups' => [['id' => 1, 'name' => 'Default', 'default' => true]]], 200),
    ]);
}

describe('runner settings page', function () {
    it('saves the configuration and creates a runner group that public repositories can use', function () {
        fakeRunnerGroupApi($this->githubApp);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('githubAppId', $this->githubApp->id)
            ->set('labels', 'Coolify, self-hosted, gpu')
            ->set('memoryLimit', '4G')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('success')
            ->assertSee('Recent runners');

        $config = GithubRunnerConfig::sole();
        expect($config->server_id)->toBe($this->server->id)
            ->and($config->labels)->toBe(['coolify', 'gpu'])
            ->and($config->memory_limit)->toBe('4g')
            ->and($config->docker_mode)->toBe(GithubRunnerDockerMode::None)
            ->and($this->githubApp->fresh()->runner_group_id)->toBe(55);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/orgs/acme/actions/runner-groups')
            && $request['allows_public_repositories'] === true
            && $request['visibility'] === 'all');
        expect($config->allow_pull_requests)->toBeFalse();
    });

    it('opens an existing private runner group to public repositories', function () {
        $this->githubApp->update(['runner_group_id' => 77]);
        Http::fake([
            'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
            "https://api.github.com/app/installations/{$this->githubApp->installation_id}/access_tokens" => Http::response(['token' => 'installation-token'], 201),
            'https://api.github.com/orgs/acme/actions/runner-groups/77' => fn ($request) => $request->method() === 'PATCH'
                ? Http::response(['id' => 77, 'default' => false, 'visibility' => 'all', 'allows_public_repositories' => true])
                : Http::response(['id' => 77, 'default' => false, 'visibility' => 'private', 'allows_public_repositories' => false]),
        ]);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('githubAppId', $this->githubApp->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('success');

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/orgs/acme/actions/runner-groups/77')
            && $request['allows_public_repositories'] === true
            && $request['visibility'] === 'all');
    });

    it('saves whether runners take pull request jobs', function () {
        fakeRunnerGroupApi($this->githubApp);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('githubAppId', $this->githubApp->id)
            ->set('allowPullRequests', true)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('allowPullRequests', true);

        expect(GithubRunnerConfig::sole()->allow_pull_requests)->toBeTrue();
    });

    it('does not fall back to the Default runner group when group creation fails', function (int $status) {
        fakeRunnerGroupApi($this->githubApp, $status);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('githubAppId', $this->githubApp->id)
            ->call('submit')
            ->assertNotDispatched('success');

        expect($this->githubApp->fresh()->runner_group_id)->toBeNull()
            ->and(GithubRunnerConfig::count())->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/orgs/acme/actions/runner-groups'));
    })->with([403, 401, 422, 429, 500]);

    it('requires a custom label with safe characters', function (string $labels) {
        Http::fake();

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('githubAppId', $this->githubApp->id)
            ->set('labels', $labels)
            ->call('submit')
            ->assertHasErrors('labels');

        expect(GithubRunnerConfig::count())->toBe(0);
    })->with([
        'only reserved labels' => ['self-hosted, linux, x64'],
        'shell characters' => ['coolify;rm -rf /'],
    ]);

    it('rejects resource limits that are not plain values', function (string $field, string $value) {
        Http::fake();

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('githubAppId', $this->githubApp->id)
            ->set($field, $value)
            ->call('submit')
            ->assertHasErrors($field);
    })->with([
        'cpu' => ['cpuLimit', '2 --privileged'],
        'memory' => ['memoryLimit', '4g; reboot'],
        'image' => ['runnerImage', 'bad image; reboot'],
    ]);

    it('rejects a GitHub App of another team', function () {
        $otherApp = settingsTestGithubApp(Team::factory()->create(), 4444);
        Http::fake();

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('githubAppId', $otherApp->id)
            ->call('submit')
            ->assertHasErrors('githubAppId');

        expect(GithubRunnerConfig::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('does not let members change the configuration', function () {
        Livewire::actingAs($this->member)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('githubAppId', $this->githubApp->id)
            ->call('submit')
            ->assertForbidden();

        expect(GithubRunnerConfig::count())->toBe(0);
    });

    it('opens on Coolify Cloud because runners run on the user\'s own servers', function () {
        config()->set('constants.coolify.self_hosted', false);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->assertNoRedirect()
            ->assertSee('GitHub Actions runners');
    });

    it('does not open the page for the Coolify host', function () {
        $server = settingsTestServer($this->team, 'build');
        $server->update(['ip' => 'host.docker.internal']);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $server->uuid])
            ->assertRedirect(route('server.show', ['server_uuid' => $server->uuid]));

        $this->actingAs($this->owner)
            ->get(route('server.show', ['server_uuid' => $server->uuid]))
            ->assertDontSee(route('server.github-runners', ['server_uuid' => $server->uuid]));
    });

    it('does not open the page for a server of another team', function () {
        $otherServer = settingsTestServer(Team::factory()->create(), 'build');

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $otherServer->uuid])
            ->assertRedirect(route('server.index'));
    });

    it('refuses to save runners on a server without the build role', function () {
        $server = settingsTestServer($this->team, 'both');
        Http::fake();

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $server->uuid])
            ->assertSee('This server is not enabled for builds')
            ->assertSee(route('server.show', ['server_uuid' => $server->uuid]))
            ->assertSee(route('server.github-runners', ['server_uuid' => $server->uuid]))
            ->assertDontSee('Recent runners')
            ->set('githubAppId', $this->githubApp->id)
            ->call('submit')
            ->assertDispatched('error');

        expect(GithubRunnerConfig::count())->toBe(0);
    });

    it('disables runners and removes only the idle ones', function () {
        Queue::fake();
        $config = GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $this->githubApp->id, 'labels' => ['coolify']]);
        $idle = GithubRunnerExecution::create(['github_app_id' => $this->githubApp->id, 'github_runner_config_id' => $config->id, 'server_id' => $this->server->id, 'trigger_workflow_job_id' => 1, 'status' => GithubRunnerStatus::Idle]);
        $running = GithubRunnerExecution::create(['github_app_id' => $this->githubApp->id, 'github_runner_config_id' => $config->id, 'server_id' => $this->server->id, 'trigger_workflow_job_id' => 2, 'status' => GithubRunnerStatus::Running]);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->call('toggleEnabled')
            ->assertDispatched('success')
            ->assertSee('Runners are disabled')
            ->assertDontSee('Parallel runners')
            ->assertDontSee('Recent runners');

        expect($config->fresh()->is_enabled)->toBeFalse()
            ->and($idle->fresh()->status)->toBe(GithubRunnerStatus::Cancelled)
            ->and($running->fresh()->status)->toBe(GithubRunnerStatus::Running);
        Queue::assertPushed(CleanupGithubRunnerJob::class, 1);
        Queue::assertPushed(CleanupGithubRunnerJob::class, fn ($job) => $job->executionId === $idle->id);
    });

    it('enables disabled runners and starts the queued jobs', function () {
        Queue::fake();
        fakeRunnerGroupApi($this->githubApp);
        $config = GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $this->githubApp->id, 'labels' => ['coolify'], 'is_enabled' => false]);
        GithubRunnerExecution::create(['github_app_id' => $this->githubApp->id, 'trigger_workflow_job_id' => 1, 'status' => GithubRunnerStatus::Queued]);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->call('toggleEnabled')
            ->assertDispatched('success')
            ->assertDontSee('Runners are disabled')
            ->assertSee('Parallel runners');

        expect($config->fresh()->is_enabled)->toBeTrue();
        Queue::assertPushed(ProvisionGithubRunnerJob::class);
    });

    it('shows only the enable button before runners are enabled', function () {
        fakeRunnerGroupApi($this->githubApp);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->assertSee('Runners are disabled')
            ->assertSee('Enable runners')
            ->assertDontSee('Parallel runners')
            ->assertDontSee('Recent runners')
            ->call('toggleEnabled')
            ->assertHasNoErrors()
            ->assertDispatched('success')
            ->assertSee('Parallel runners');

        $config = GithubRunnerConfig::sole();
        expect($config->is_enabled)->toBeTrue()
            ->and($config->github_app_id)->toBe($this->githubApp->id)
            ->and($config->labels)->toBe(['coolify']);
    });

    it('enables runners with an App that is not ready without calling GitHub', function () {
        Http::fake();
        $this->githubApp->update(unreadyRunnerApp());

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->call('toggleEnabled')
            ->assertHasNoErrors()
            ->assertDispatched('success')
            ->assertSee('Parallel runners')
            ->assertSee('The GitHub App is not ready');

        expect(GithubRunnerConfig::sole()->is_enabled)->toBeTrue();
        Http::assertNothingSent();
    });

    it('selects the first App that is ready for runners', function () {
        $this->githubApp->update(['name' => 'a-first', ...unreadyRunnerApp()]);
        $readyApp = settingsTestGithubApp($this->team, 4444, ['name' => 'b-ready']);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->assertSet('githubAppId', $readyApp->id);
    });

    it('saves settings with an App that is not ready without calling GitHub', function () {
        Http::fake();
        $this->githubApp->update(unreadyRunnerApp());
        $config = GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $this->githubApp->id, 'labels' => ['coolify']]);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('maxRunners', 4)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('success');

        expect($config->fresh()->max_runners)->toBe(4);
        Http::assertNothingSent();
    });

    it('keeps runners disabled when saving other settings', function () {
        fakeRunnerGroupApi($this->githubApp);
        $config = GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $this->githubApp->id, 'labels' => ['coolify'], 'is_enabled' => false]);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('maxRunners', 4)
            ->call('submit')
            ->assertDispatched('success');

        expect($config->fresh()->is_enabled)->toBeFalse()
            ->and($config->fresh()->max_runners)->toBe(4);
    });

    it('does not let members disable runners', function () {
        $config = GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $this->githubApp->id, 'labels' => ['coolify']]);

        Livewire::actingAs($this->member)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->call('toggleEnabled')
            ->assertForbidden();

        expect($config->fresh()->is_enabled)->toBeTrue();
    });
});

describe('isolated Docker with Sysbox', function () {
    it('does not save Isolated Docker while Sysbox is missing', function () {
        fakeRunnerGroupApi($this->githubApp);
        Process::fake(['*' => Process::result(output: 'io.containerd.runc.v2 runc')]);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('githubAppId', $this->githubApp->id)
            ->set('dockerMode', 'sysbox')
            ->call('submit')
            ->assertHasErrors('dockerMode')
            ->assertSet('isSysboxInstalled', false);

        expect(GithubRunnerConfig::count())->toBe(0);
    });

    it('saves Isolated Docker when Sysbox is installed', function () {
        fakeRunnerGroupApi($this->githubApp);
        Process::fake(['*' => Process::result(output: 'io.containerd.runc.v2 runc sysbox-runc ')]);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('githubAppId', $this->githubApp->id)
            ->set('dockerMode', 'sysbox')
            ->call('submit')
            ->assertHasNoErrors();

        expect(GithubRunnerConfig::sole()->docker_mode->value)->toBe('sysbox');
    });

    it('checks that Sysbox can mount its FUSE file system', function () {
        Process::fake(['*' => Process::result(output: '')]);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->call('checkSysbox');

        Process::assertRan(fn ($process) => str_contains($process->command, 'test -x /usr/bin/fusermount3'));
    });

    it('shows the install button when Sysbox is missing', function () {
        Process::fake(['*' => Process::result(output: '')]);
        GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $this->githubApp->id, 'labels' => ['coolify']]);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->call('checkSysbox')
            ->assertSet('isSysboxInstalled', false)
            ->assertSee('Install Sysbox');
    });

    it('stops before the installation on servers without apt', function () {
        Process::fake(['*' => Process::result(output: '')]);

        Livewire::actingAs($this->owner)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->call('installSysbox')
            ->assertNotDispatched('activityMonitor');

        Process::assertRanTimes(fn ($process) => str_contains($process->command, 'apt-get'), 1);
    });

    it('does not let members install Sysbox', function () {
        Process::fake();

        Livewire::actingAs($this->member)
            ->test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->call('installSysbox')
            ->assertForbidden();

        Process::assertNothingRan();
    });
});

describe('runner executions list', function () {
    beforeEach(function () {
        Queue::fake();
        $config = GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $this->githubApp->id, 'labels' => ['coolify']]);
        $this->running = GithubRunnerExecution::create(['github_app_id' => $this->githubApp->id, 'github_runner_config_id' => $config->id, 'server_id' => $this->server->id, 'trigger_workflow_job_id' => 1, 'status' => GithubRunnerStatus::Running, 'repository_full_name' => 'acme/api']);
        $this->queued = GithubRunnerExecution::create(['github_app_id' => $this->githubApp->id, 'trigger_workflow_job_id' => 2, 'status' => GithubRunnerStatus::Queued, 'repository_full_name' => 'acme/web']);
    });

    it('shows runners of the server and queued jobs of its GitHub App', function () {
        $otherServer = settingsTestServer($this->team, 'build');
        GithubRunnerExecution::create(['github_app_id' => $this->githubApp->id, 'server_id' => $otherServer->id, 'trigger_workflow_job_id' => 3, 'status' => GithubRunnerStatus::Running, 'repository_full_name' => 'acme/other']);

        Livewire::actingAs($this->owner)
            ->test(GithubRunnerExecutions::class, ['server' => $this->server])
            ->assertSee('acme/api')
            ->assertSee('acme/web')
            ->assertDontSee('acme/other');
    });

    it('cancels a running runner and queues its cleanup', function () {
        Livewire::actingAs($this->owner)
            ->test(GithubRunnerExecutions::class, ['server' => $this->server])
            ->call('cancel', $this->running->id)
            ->assertDispatched('success');

        expect($this->running->fresh()->status)->toBe(GithubRunnerStatus::Cancelled);
        Queue::assertPushed(CleanupGithubRunnerJob::class, fn ($job) => $job->executionId === $this->running->id);
    });

    it('does not let members cancel runners', function () {
        Livewire::actingAs($this->member)
            ->test(GithubRunnerExecutions::class, ['server' => $this->server])
            ->call('cancel', $this->running->id)
            ->assertForbidden();

        expect($this->running->fresh()->status)->toBe(GithubRunnerStatus::Running);
    });

    it('does not cancel runners of another server', function () {
        $otherServer = settingsTestServer($this->team, 'build');
        $other = GithubRunnerExecution::create(['github_app_id' => $this->githubApp->id, 'server_id' => $otherServer->id, 'trigger_workflow_job_id' => 4, 'status' => GithubRunnerStatus::Running]);

        Livewire::actingAs($this->owner)
            ->test(GithubRunnerExecutions::class, ['server' => $this->server])
            ->call('cancel', $other->id);

        expect($other->fresh()->status)->toBe(GithubRunnerStatus::Running);
    });
});

describe('build server role', function () {
    beforeEach(function () {
        GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $this->githubApp->id, 'labels' => ['coolify']]);
    });

    it('blocks a role change in the UI while runners are enabled', function () {
        Livewire::actingAs($this->owner)
            ->test(Show::class, ['server_uuid' => $this->server->uuid])
            ->set('serverRole', 'both')
            ->call('requestServerRoleChange')
            ->assertSet('serverRole', 'build')
            ->assertDispatched('error');

        expect($this->server->settings->fresh()->server_role->value)->toBe('build');
    });

    it('blocks a role change through the API while runners are enabled', function () {
        $token = $this->owner->createToken('runner-test', ['*']);
        $token->accessToken->forceFill(['team_id' => $this->team->id])->save();

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
            ->patchJson('/api/v1/servers/'.$this->server->uuid, ['server_role' => 'both'])
            ->assertStatus(422)
            ->assertJsonPath('errors.server_role.0', 'Disable the GitHub runners before you change the role of this server.');
    });

    it('leaves dedicated runner servers out of application builds', function () {
        expect(Server::buildServers($this->team->id)->pluck('id'))->toContain($this->server->id);

        $this->server->githubRunnerConfig->update(['is_dedicated' => true]);
        expect(Server::buildServers($this->team->id)->pluck('id'))->not->toContain($this->server->id);

        $this->server->githubRunnerConfig->update(['is_enabled' => false]);
        expect(Server::buildServers($this->team->id)->pluck('id'))->toContain($this->server->id);
    });
});

describe('GitHub App runner permissions', function () {
    it('stores runner permissions and webhook events from GitHub', function () {
        Http::fake([
            'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
            'https://api.github.com/app' => Http::response([
                'permissions' => ['contents' => 'read', 'metadata' => 'read', 'organization_self_hosted_runners' => 'write', 'actions' => 'read'],
                'events' => ['push', 'workflow_job'],
            ]),
        ]);

        GithubAppPermissionJob::dispatchSync($this->githubApp);

        $githubApp = $this->githubApp->fresh();
        expect($githubApp->organization_self_hosted_runners)->toBe('write')
            ->and($githubApp->actions)->toBe('read')
            ->and($githubApp->webhook_events)->toBe(['push', 'workflow_job'])
            ->and($githubApp->missingRunnerRequirements())->toBe([]);
    });

    it('lists what an App needs before it can run jobs', function () {
        $githubApp = settingsTestGithubApp($this->team, 5555, [...unreadyRunnerApp(), 'organization' => null]);

        expect($githubApp->missingRunnerRequirements())->toHaveCount(4);
    });

    it('shows the runner permissions on the GitHub App permissions tab', function () {
        $this->githubApp->update(['organization_self_hosted_runners' => 'write', 'webhook_events' => ['push']]);

        Livewire::withQueryParams(['github_app_uuid' => $this->githubApp->uuid])
            ->actingAs($this->owner)
            ->test(Change::class)
            ->set('activeTab', 'permissions')
            ->assertSee('GitHub Actions runners')
            ->assertSee('Not ready for runners')
            ->assertSee('Webhook event "Workflow job"')
            ->assertDontSee('Organization permission "Self-hosted runners": write');
    });

    it('shows the runner option disabled with a reason when the App has no organization', function () {
        $personalApp = GithubApp::create(['name' => 'personal', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'custom_user' => 'git', 'custom_port' => 22, 'team_id' => $this->team->id, 'is_system_wide' => false]);

        Livewire::withQueryParams(['github_app_uuid' => $personalApp->uuid])
            ->actingAs($this->owner)
            ->test(Change::class)
            ->assertSee('GitHub Actions runners')
            ->assertSee('Only GitHub Apps that belong to an organization can run workflow jobs.');
    });

    it('survives the confirmation modal refresh after deleting an App', function () {
        $unregistered = GithubApp::create(['name' => 'to-delete', 'organization' => 'acme', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'custom_user' => 'git', 'custom_port' => 22, 'team_id' => $this->team->id, 'is_system_wide' => false]);

        Livewire::withQueryParams(['github_app_uuid' => $unregistered->uuid])
            ->actingAs($this->owner)
            ->test(Change::class)
            ->call('delete')
            ->assertRedirect(route('source.all'))
            ->call('$refresh')
            ->assertOk();

        expect(GithubApp::find($unregistered->id))->toBeNull();
    });

    it('blocks deleting an App while servers have enabled runners', function () {
        GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $this->githubApp->id, 'labels' => ['coolify']]);

        expect(fn () => $this->githubApp->delete())->toThrow(Exception::class, 'GitHub Actions runners');
        expect(GithubApp::find($this->githubApp->id))->not->toBeNull()
            ->and(GithubRunnerConfig::count())->toBe(1);
    });

    it('blocks deleting an App while disabled runners still run jobs', function () {
        $config = GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $this->githubApp->id, 'labels' => ['coolify'], 'is_enabled' => false]);
        GithubRunnerExecution::create(['github_app_id' => $this->githubApp->id, 'github_runner_config_id' => $config->id, 'server_id' => $this->server->id, 'trigger_workflow_job_id' => 1, 'status' => GithubRunnerStatus::Running]);

        expect(fn () => $this->githubApp->delete())->toThrow(Exception::class, 'GitHub Actions runners');
        expect(GithubApp::find($this->githubApp->id))->not->toBeNull();
    });

    it('deletes an App after its runners are disabled and finished', function () {
        $config = GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $this->githubApp->id, 'labels' => ['coolify'], 'is_enabled' => false]);
        GithubRunnerExecution::create(['github_app_id' => $this->githubApp->id, 'github_runner_config_id' => $config->id, 'server_id' => $this->server->id, 'trigger_workflow_job_id' => 1, 'status' => GithubRunnerStatus::Completed]);

        $this->githubApp->delete();

        expect(GithubApp::find($this->githubApp->id))->toBeNull()
            ->and(GithubRunnerConfig::count())->toBe(0);
    });
});

describe('new GitHub App purpose', function () {
    it('requires an organization when the App is for GitHub Actions runners', function () {
        Livewire::actingAs($this->owner)
            ->test(Create::class)
            ->set('use_for_github_runners', true)
            ->set('organization', '')
            ->call('createGitHubApp')
            ->assertHasErrors(['organization' => 'required']);

        expect(GithubApp::where('team_id', $this->team->id)->count())->toBe(1);
    });

    it('marks the organization as required when GitHub Actions runners are selected', function () {
        Livewire::actingAs($this->owner)
            ->test(Create::class)
            ->assertSee('Personal account when empty')
            ->set('use_for_github_runners', true)
            ->assertSee('GitHub organization, for example coollabsio')
            ->assertSeeHtml('required');
    });

    it('passes the selected purposes to the registration page', function () {
        Livewire::actingAs($this->owner)
            ->test(Create::class)
            ->set('name', 'runner-purpose-app')
            ->set('organization', 'acme')
            ->set('use_for_pull_request_previews', false)
            ->set('use_for_github_runners', true)
            ->call('createGitHubApp')
            ->assertHasNoErrors()
            ->assertRedirectContains('previews=0&runners=1');

        $githubApp = GithubApp::where('name', 'runner-purpose-app')->sole();
        expect($githubApp->organization)->toBe('acme');
    });

    it('prefills the registration options from the selected purposes', function (array $query, bool $previews, bool $runners, ?string $organization) {
        $unregistered = GithubApp::create(['name' => 'prefill', 'organization' => $organization, 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'custom_user' => 'git', 'custom_port' => 22, 'team_id' => $this->team->id, 'is_system_wide' => false]);

        Livewire::withQueryParams(['github_app_uuid' => $unregistered->uuid, ...$query])
            ->actingAs($this->owner)
            ->test(Change::class)
            ->assertSet('preview_deployment_permissions', $previews)
            ->assertSet('github_runners', $runners);
    })->with([
        'defaults' => [[], true, false, 'acme'],
        'runners only' => [['previews' => '0', 'runners' => '1'], false, true, 'acme'],
        'runners without organization' => [['runners' => '1'], true, false, null],
    ]);
});
