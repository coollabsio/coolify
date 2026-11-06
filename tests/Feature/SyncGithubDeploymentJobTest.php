<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\GithubAppPermissionJob;
use App\Jobs\SyncGithubDeploymentJob;
use App\Livewire\Source\Github\Change;
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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['fqdn' => 'https://coolify.example.com']));

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pemKey);
    $this->githubApp = GithubApp::create([
        'name' => 'Private GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 12345,
        'installation_id' => 67890,
        'private_key_id' => PrivateKey::create(['name' => 'Key', 'private_key' => $pemKey, 'team_id' => $this->team->id])->id,
        'is_public' => false,
        'deployments' => 'write',
        'team_id' => $this->team->id,
    ]);

    $this->application = Application::factory()->create([
        'name' => 'web',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'source_type' => GithubApp::class,
        'source_id' => $this->githubApp->id,
        'git_repository' => 'acme/shop',
        'git_branch' => 'main',
        'fqdn' => 'https://shop.example.com,https://www.shop.example.com',
    ]);

    // The model hook would run the job on the sync queue; the tests run it explicitly.
    Queue::fake([SyncGithubDeploymentJob::class]);

    Http::fake([
        'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
        'https://api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'token'], 201),
        'https://api.github.com/repos/acme/shop/pulls/7' => Http::response([
            'head' => ['ref' => 'feature/cart', 'sha' => 'aaa111', 'repo' => ['full_name' => 'acme/shop']],
            'base' => ['repo' => ['full_name' => 'acme/shop']],
        ]),
        'https://api.github.com/repos/acme/shop/pulls/8' => Http::response([
            'head' => ['ref' => 'main', 'sha' => 'bbb222', 'repo' => ['full_name' => 'someone/shop']],
            'base' => ['repo' => ['full_name' => 'acme/shop']],
        ]),
        'https://api.github.com/repos/acme/shop/deployments' => fn () => $this->createDeploymentResponse ?? Http::response(['id' => 555], 201),
        'https://api.github.com/repos/acme/shop/deployments/*/statuses' => Http::response(['id' => 1], 201),
    ]);
});

function githubDeployment(Application $application, array $attributes = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'pull_request_id' => 0,
        'commit' => 'HEAD',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'server_id' => $application->destination->server->id,
        ...$attributes,
    ]);
}

function syncGithubDeployment(ApplicationDeploymentQueue $deployment, bool $deactivate = false): void
{
    (new SyncGithubDeploymentJob($deployment->id, $deactivate))->handle();
}

/**
 * @return array<int, array<string, mixed>>
 */
function githubRequests(string $suffix): array
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), $suffix))
        ->map(fn (array $pair): array => $pair[0]->data())
        ->values()
        ->all();
}

it('queues a sync when the status of a deployment changes', function () {
    $deployment = githubDeployment($this->application, ['status' => ApplicationDeploymentStatus::QUEUED->value]);

    $deployment->update(['status' => ApplicationDeploymentStatus::IN_PROGRESS->value]);

    Queue::assertPushed(SyncGithubDeploymentJob::class, fn (SyncGithubDeploymentJob $job): bool => $job->deploymentQueueId === $deployment->id);
});

it('queues the sync on the default queue, not on the deployment queue', function () {
    $deployment = githubDeployment($this->application, ['status' => ApplicationDeploymentStatus::QUEUED->value]);

    $deployment->update(['status' => ApplicationDeploymentStatus::IN_PROGRESS->value]);

    Queue::assertPushedOn('default', SyncGithubDeploymentJob::class);
    expect(deployment_queue())->not->toBe('default');
});

it('gives up on a slow GitHub request after a short timeout', function () {
    $timeouts = [];
    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[$request->url()] = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

        return null;
    });

    syncGithubDeployment(githubDeployment($this->application));

    expect($timeouts)->not->toBeEmpty();
    foreach ($timeouts as $url => [$timeout, $connectTimeout]) {
        expect($timeout)->toBeLessThanOrEqual(SyncGithubDeploymentJob::HTTP_TIMEOUT_SECONDS, $url)
            ->and($connectTimeout)->toBeLessThanOrEqual(SyncGithubDeploymentJob::HTTP_TIMEOUT_SECONDS, $url);
    }
    expect((new SyncGithubDeploymentJob(1))->timeout)->toBeLessThan(120);
});

it('does not queue a sync when the GitHub App has no deployments permission', function () {
    $this->githubApp->update(['deployments' => null]);
    $deployment = githubDeployment($this->application, ['status' => ApplicationDeploymentStatus::QUEUED->value]);

    $deployment->update(['status' => ApplicationDeploymentStatus::IN_PROGRESS->value]);

    Queue::assertNotPushed(SyncGithubDeploymentJob::class);
});

it('does not queue a sync for the deployment to an additional server', function () {
    $deployment = githubDeployment($this->application, [
        'status' => ApplicationDeploymentStatus::QUEUED->value,
        'parent_deployment_uuid' => 'main-deployment',
    ]);

    $deployment->update(['status' => ApplicationDeploymentStatus::IN_PROGRESS->value]);

    Queue::assertNotPushed(SyncGithubDeploymentJob::class);
});

it('queues a sync for a deployment that fails because its job cannot be queued', function () {
    Notification::fake();
    $deployment = githubDeployment($this->application);

    fail_undispatchable_deployment($deployment, new RuntimeException('queue connection refused'));

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
    Queue::assertPushed(SyncGithubDeploymentJob::class, fn (SyncGithubDeploymentJob $job): bool => $job->deploymentQueueId === $deployment->id);
});

it('queues a sync for the deployments that a newer webhook deployment cancels', function () {
    Queue::fake();
    $this->application->settings->update(['is_auto_cancel_building_deployments_enabled' => true]);
    $building = githubDeployment($this->application, ['is_webhook' => true]);
    $queued = githubDeployment($this->application, ['is_webhook' => true, 'status' => ApplicationDeploymentStatus::QUEUED->value]);

    queue_application_deployment($this->application, 'newer-webhook-deployment', commit: 'ddd444', is_webhook: true);

    expect($building->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($queued->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
    foreach ([$building, $queued] as $cancelled) {
        Queue::assertPushed(SyncGithubDeploymentJob::class, fn (SyncGithubDeploymentJob $job): bool => $job->deploymentQueueId === $cancelled->id);
    }
});

it('creates one GitHub deployment for the branch and sends each status', function () {
    $deployment = githubDeployment($this->application);

    syncGithubDeployment($deployment);
    syncGithubDeployment($deployment);
    $deployment->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);
    syncGithubDeployment($deployment);

    expect(githubRequests('/deployments'))->toBe([[
        'ref' => 'main',
        'environment' => 'web',
        'description' => 'Coolify deployment',
        'auto_merge' => false,
        'required_contexts' => [],
        'transient_environment' => false,
        'production_environment' => true,
    ]]);

    $statuses = githubRequests('/deployments/555/statuses');
    $logUrl = "https://coolify.example.com/project/{$this->application->environment->project->uuid}/environment/{$this->application->environment->uuid}/application/{$this->application->uuid}/deployment/{$deployment->deployment_uuid}";
    expect($statuses)->toBe([
        ['state' => 'in_progress', 'description' => 'Deploying with Coolify.', 'log_url' => $logUrl],
        ['state' => 'success', 'description' => 'Deployed with Coolify.', 'log_url' => $logUrl, 'environment_url' => 'https://shop.example.com'],
    ])->and($deployment->fresh()->only(['github_deployment_id', 'github_deployment_state']))
        ->toBe(['github_deployment_id' => 555, 'github_deployment_state' => 'success']);
});

it('uses the head branch of a pull request from the same repository', function () {
    ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 7, 'pull_request_html_url' => 'https://github.com/acme/shop/pull/7', 'fqdn' => 'https://pr-7.shop.example.com']);
    $deployment = githubDeployment($this->application, [
        'pull_request_id' => 7,
        'commit' => 'aaa111',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);

    syncGithubDeployment($deployment);

    expect(githubRequests('/deployments')[0])->toMatchArray([
        'ref' => 'feature/cart',
        'environment' => 'web (preview)',
        'transient_environment' => true,
        'production_environment' => false,
    ])->and(githubRequests('/statuses')[0]['environment_url'])->toBe('https://pr-7.shop.example.com');
});

it('uses the deployed commit when the head branch of a pull request moved', function () {
    $deployment = githubDeployment($this->application, ['pull_request_id' => 7, 'commit' => 'old999']);

    syncGithubDeployment($deployment);

    expect(githubRequests('/deployments')[0]['ref'])->toBe('old999');
});

it('uses the deployed commit for a pull request from a fork', function () {
    $deployment = githubDeployment($this->application, ['pull_request_id' => 8, 'commit' => 'bbb222']);

    syncGithubDeployment($deployment);

    expect(githubRequests('/deployments')[0]['ref'])->toBe('bbb222');
});

it('uses the deployed commit of a branch deployment', function () {
    $deployment = githubDeployment($this->application, ['commit' => 'ccc333']);

    syncGithubDeployment($deployment);

    expect(githubRequests('/deployments')[0]['ref'])->toBe('ccc333');
});

it('does not create a GitHub deployment for a deployment that was cancelled before the sync', function () {
    $deployment = githubDeployment($this->application, ['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);

    syncGithubDeployment($deployment);

    expect(githubRequests('/deployments'))->toBe([])
        ->and($deployment->fresh()->github_deployment_id)->toBeNull();
});

it('marks the GitHub deployments of a closed pull request inactive and keeps them inactive', function () {
    $deployment = githubDeployment($this->application, ['pull_request_id' => 7]);
    syncGithubDeployment($deployment);
    $other = githubDeployment($this->application, ['pull_request_id' => 9]);

    SyncGithubDeploymentJob::deactivatePullRequest($this->application, 7);

    Queue::assertPushed(SyncGithubDeploymentJob::class, 1);
    Queue::assertPushed(SyncGithubDeploymentJob::class, fn (SyncGithubDeploymentJob $job): bool => $job->deploymentQueueId === $deployment->id && $job->deactivate);
    Queue::assertNotPushed(SyncGithubDeploymentJob::class, fn (SyncGithubDeploymentJob $job): bool => $job->deploymentQueueId === $other->id);

    syncGithubDeployment($deployment, deactivate: true);
    $deployment->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);
    syncGithubDeployment($deployment);

    expect(collect(githubRequests('/deployments/555/statuses'))->pluck('state')->all())->toBe(['in_progress', 'inactive']);
});

it('does not create a GitHub deployment when the pull request closed before the first sync', function () {
    $deployment = githubDeployment($this->application, ['pull_request_id' => 7]);

    SyncGithubDeploymentJob::deactivatePullRequest($this->application, 7);

    Queue::assertPushed(SyncGithubDeploymentJob::class, fn (SyncGithubDeploymentJob $job): bool => $job->deploymentQueueId === $deployment->id && $job->deactivate);

    syncGithubDeployment($deployment, deactivate: true);
    $deployment->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);
    syncGithubDeployment($deployment);

    expect(githubRequests('/deployments'))->toBe([])
        ->and(githubRequests('/statuses'))->toBe([])
        ->and($deployment->fresh()->github_deployment_state)->toBe('inactive');
});

it('fails the sync job, not the deployment, when GitHub rejects the request', function () {
    $this->createDeploymentResponse = Http::response(['message' => 'Resource not accessible by integration'], 403);
    $deployment = githubDeployment($this->application);

    expect(fn () => syncGithubDeployment($deployment))->toThrow(Exception::class, 'Resource not accessible by integration')
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($deployment->fresh()->github_deployment_id)->toBeNull();
});

it('shows an existing App how to add the deployments permission, and hides it after Refetch', function () {
    $this->withoutVite();
    $this->githubApp->update(['deployments' => null]);
    $owner = User::factory()->create();
    $this->team->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);

    $page = fn () => Livewire::withQueryParams(['github_app_uuid' => $this->githubApp->uuid])
        ->test(Change::class)
        ->set('activeTab', 'permissions');

    $page()->assertSee('Not ready for GitHub deployments')
        ->assertSee('Repository permission &quot;Deployments&quot;: write', false)
        ->assertSee(getPermissionsPath($this->githubApp));

    Http::fake([
        'https://api.github.com/app' => Http::response(['permissions' => ['contents' => 'read', 'deployments' => 'write'], 'events' => ['push']]),
        'https://api.github.com/app/installations/67890' => Http::response(['permissions' => ['contents' => 'read', 'deployments' => 'write']]),
    ]);
    GithubAppPermissionJob::dispatchSync($this->githubApp);

    expect($this->githubApp->fresh()->canSyncDeployments())->toBeTrue();
    $page()->assertSee('GitHub deployments')->assertDontSee('Not ready for GitHub deployments');
});

it('stays not ready until the installation approves the deployments permission', function () {
    $this->githubApp->update(['deployments' => null]);
    Http::fake([
        'https://api.github.com/app' => Http::response(['permissions' => ['contents' => 'read', 'deployments' => 'write']]),
        'https://api.github.com/app/installations/67890' => Http::response(['permissions' => ['contents' => 'read']]),
    ]);

    GithubAppPermissionJob::dispatchSync($this->githubApp);

    expect($this->githubApp->fresh()->deployments)->toBeNull()
        ->and($this->githubApp->fresh()->canSyncDeployments())->toBeFalse();
});
