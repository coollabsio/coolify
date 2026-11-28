<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\EvaluatePush;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\DeployTriggers\AffectedRunnerException;
use App\Services\DeployTriggers\ClosedPullRequests;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\EvaluationRequestBuilder;
use App\Services\DeployTriggers\EvaluationSlots;
use App\Services\DeployTriggers\PullRequestContext;
use App\Services\DeployTriggers\PushContext;
use App\Services\DeployTriggers\PushTriggerRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAffectedRunner;

uses(RefreshDatabase::class);

const EP_BASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const EP_AFTER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function () {
    Queue::fake([ApplicationDeploymentJob::class]);
    app()->instance(EvaluationSlots::class, new class extends EvaluationSlots
    {
        public bool $busy = false;

        public function run(Closure $callback, Closure $whenBusy): void
        {
            $this->busy ? $whenBusy() : $callback();
        }
    });
    $this->fixture = deployTriggerFixture();
    $this->web = epApplication($this->fixture, '/apps/web');
    $this->api = epApplication($this->fixture, '/apps/api');
});

function epApplication(array $fixture, string $baseDirectory): Application
{
    $application = deployTriggerApplication($fixture, ['base_directory' => $baseDirectory, 'git_repository' => 'https://github.com/acme/mono']);
    $application->forceFill(['trigger_base_commit' => EP_BASE])->save();

    return $application->refresh();
}

function epContext(array $overrides = []): PushContext
{
    return new PushContext(...array_merge([
        'provider' => 'gitlab', 'teamId' => 1, 'repositoryUrl' => 'https://github.com/acme/mono', 'ref' => 'refs/heads/main',
        'after' => EP_AFTER, 'before' => EP_BASE, 'forced' => null, 'commitMessage' => 'feat: change',
    ], $overrides));
}

/**
 * @param  array<string, array{0: string, 1: string}>  $decisions  application id => [decision, reason_code]
 */
function epRespond(array $decisions): Closure
{
    return function (EvaluateRequest $request) use ($decisions): string {
        return json_encode([
            'after' => $request->after ?? EP_AFTER,
            'applications' => array_map(fn (string $id) => [
                'id' => $id, 'decision' => $decisions[$id][0] ?? 'deploy', 'reason_code' => $decisions[$id][1] ?? 'matched',
                'reason' => 'because', 'base' => EP_BASE, 'matches' => [['file' => 'apps/x/a.ts', 'rule' => 'workspace', 'via' => ['apps/x']]],
                'trigger_set' => ['display' => ['apps/x/**'], 'compiled' => ['version' => 1, 'all_of' => [], 'lockfiles' => []], 'input_patterns' => ['version' => 1, 'all_of' => [], 'lockfiles' => []]],
            ], $request->applicationIds()),
        ]);
    };
}

function epRun(PushContext $context, array $applications, bool $dryRun = false): void
{
    (new EvaluatePush($context, array_map(fn (Application $a) => $a->id, $applications), $dryRun))->handle();
}

it('queues deployments for deploy decisions with the trigger reason', function () {
    FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['deploy', 'matched'], $this->api->uuid => ['skip', 'no_match']]));

    epRun(epContext(), [$this->web, $this->api]);

    $deployment = ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->sole();
    expect($deployment->status)->not->toBe(ApplicationDeploymentStatus::SKIPPED->value)
        ->and($deployment->commit)->toBe(EP_AFTER)
        ->and($deployment->trigger_reason['reason_code'])->toBe('matched')
        ->and($deployment->trigger_reason['matches'][0]['file'])->toBe('apps/x/a.ts');
});

it('records skips and moves the base', function () {
    FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['skip', 'no_match'], $this->api->uuid => ['skip', 'no_match']]));

    epRun(epContext(), [$this->web, $this->api]);

    expect(ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->sole()->status)->toBe(ApplicationDeploymentStatus::SKIPPED->value)
        ->and($this->web->refresh()->trigger_base_commit)->toBe(EP_AFTER)
        ->and($this->web->trigger_set['evaluated_commit'])->toBe(EP_AFTER)
        ->and($this->web->trigger_set['display'])->toBe(['apps/x/**']);
});

it('deploys every candidate when the runner fails and stores the last error', function () {
    FakeAffectedRunner::failing(new AffectedRunnerException('server unreachable'));

    epRun(epContext(), [$this->web, $this->api]);

    expect(ApplicationDeploymentQueue::query()->where('status', '!=', ApplicationDeploymentStatus::SKIPPED->value)->count())->toBe(2)
        ->and(ApplicationDeploymentQueue::query()->first()->trigger_reason['reason_code'])->toBe('evaluation_failed')
        ->and($this->web->refresh()->trigger_set['last_error'])->toBe('server unreachable');
});

it('deploys smart apps and uses legacy watch paths when no server can evaluate', function () {
    FakeAffectedRunner::unsupported();
    $this->api->update(['watch_paths' => 'apps/api/**']);

    epRun(epContext(['payloadChangedFiles' => ['apps/web/a.ts']]), [$this->web, $this->api]);

    expect(ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->sole()->trigger_reason['reason_code'])->toBe('no_server')
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $this->api->id)->sole()->status)->toBe(ApplicationDeploymentStatus::SKIPPED->value);
});

it('deploys legacy watch path apps when the payload has no file list', function () {
    FakeAffectedRunner::unsupported();
    $this->api->update(['watch_paths' => 'apps/api/**']);

    epRun(epContext(['payloadChangedFiles' => null]), [$this->api]);

    expect(ApplicationDeploymentQueue::query()->where('application_id', $this->api->id)->sole()->status)->not->toBe(ApplicationDeploymentStatus::SKIPPED->value);
});

it('deploys fork pull requests without calling the runner', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([]));
    $this->web->settings->update(['is_preview_deployments_enabled' => true]);

    epRun(epContext(['ref' => 'refs/pull/3/head', 'pullRequest' => new PullRequestContext(3, 'main', true, 'github', 'https://github.com/acme/mono/pull/3')]), [$this->web]);

    $deployment = ApplicationDeploymentQueue::query()->sole();
    expect($deployment->pull_request_id)->toBe(3)
        ->and($deployment->trigger_reason['reason_code'])->toBe('fork_no_narrowing')
        ->and($runner->requests)->toBe([]);
});

it('drops applications deleted, auto-deploy-disabled, or branch-changed since dispatch', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([]));
    $this->web->settings->update(['is_auto_deploy_enabled' => false]);
    $this->api->update(['git_branch' => 'staging']);
    $deletedId = 999999;

    (new EvaluatePush(epContext(), [$this->web->id, $this->api->id, $deletedId]))->handle();

    expect(ApplicationDeploymentQueue::query()->count())->toBe(0)
        ->and($runner->requests)->toBe([]);
});

it('is unique per team, repository, ref and commit', function () {
    $first = new EvaluatePush(epContext(), [$this->web->id]);

    expect($first->uniqueId())->toBe((new EvaluatePush(epContext(), [$this->web->id]))->uniqueId())
        ->and($first->uniqueId())->not->toBe((new EvaluatePush(epContext(['after' => str_repeat('c', 40)]), [$this->web->id]))->uniqueId())
        ->and($first->uniqueId())->not->toBe((new EvaluatePush(epContext(), [$this->web->id], dryRun: true))->uniqueId());
});

it('re-dispatches the remaining applications with a delay when the queue is full', function () {
    Queue::fake();
    FakeAffectedRunner::respondWith(epRespond([]));
    $this->fixture['server']->settings->update(['deployment_queue_limit' => 1]);
    $other = deployTriggerApplication($this->fixture, ['base_directory' => '/apps/other']);
    ApplicationDeploymentQueue::create(['application_id' => $other->id, 'deployment_uuid' => 'busy', 'server_id' => $this->fixture['server']->id, 'status' => ApplicationDeploymentStatus::QUEUED->value, 'commit' => EP_BASE]);

    epRun(epContext(), [$this->web, $this->api]);

    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->applicationIds === [$this->web->id, $this->api->id] && $job->delay !== null);
    expect(ApplicationDeploymentQueue::query()->whereIn('application_id', [$this->web->id, $this->api->id])->count())->toBe(0);
});

it('releases itself when no evaluation slot is free', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([]));
    app(EvaluationSlots::class)->busy = true;
    $job = (new EvaluatePush(epContext(), [$this->web->id]))->withFakeQueueInteractions();

    $job->handle();

    $job->assertReleased(15);
    expect($runner->requests)->toBe([]);
});

it('creates the preview only when a pull request deploys', function () {
    FakeAffectedRunner::respondWith(epRespond([$this->web->uuid.':pr-5' => ['deploy', 'matched'], $this->api->uuid.':pr-5' => ['skip', 'no_match']]));
    $this->web->settings->update(['is_preview_deployments_enabled' => true]);
    $this->api->settings->update(['is_preview_deployments_enabled' => true]);

    epRun(epContext(['ref' => 'refs/pull/5/head', 'pullRequest' => new PullRequestContext(5, 'main', false, 'github', 'https://github.com/acme/mono/pull/5')]), [$this->web, $this->api]);

    expect(ApplicationPreview::query()->where('application_id', $this->web->id)->where('pull_request_id', 5)->exists())->toBeTrue()
        ->and(ApplicationPreview::query()->where('application_id', $this->api->id)->exists())->toBeFalse()
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->sole()->pull_request_id)->toBe(5);
});

it('stores trigger sets on a dry run without deploying or moving the base', function () {
    FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['skip', 'no_match']]));

    epRun(epContext(['after' => null]), [$this->web], dryRun: true);

    expect(ApplicationDeploymentQueue::query()->count())->toBe(0)
        ->and($this->web->refresh()->trigger_base_commit)->toBe(EP_BASE)
        ->and($this->web->trigger_set['display'])->toBe(['apps/x/**']);
});

it('sends managed config paths and merge_base_ref for a new preview', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([]));
    $this->web->settings->update(['is_preview_deployments_enabled' => true]);

    epRun(epContext(['ref' => 'refs/pull/9/head', 'pullRequest' => new PullRequestContext(9, 'main', false, 'github', 'https://github.com/acme/mono/pull/9')]), [$this->web]);

    $input = $runner->requests[0]->applications[0];
    expect($input->id)->toBe($this->web->uuid.':pr-9')
        ->and($input->baseCommit)->toBeNull()
        ->and($input->mergeBaseRef)->toBe('refs/heads/main')
        ->and($runner->requests[0]->fetchRefs)->toBe(['refs/pull/9/head', 'refs/heads/main'])
        ->and($runner->requests[0]->mirrorKey)->toBe(EvaluateRequest::mirrorKeyFor(1, 'https://github.com/acme/mono'));
});

it('chooses the lowest-id functional destination server', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([]));

    epRun(epContext(), [$this->web, $this->api]);

    $other = Server::factory()->create(['team_id' => $this->fixture['team']->id]);
    $other->settings->update(['is_reachable' => false, 'is_usable' => false]);

    expect($runner->requests)->toHaveCount(1)
        ->and($runner->servers[0]->id)->toBe($this->fixture['server']->id)
        ->and($runner->requests[0]->applicationIds())->toEqualCanonicalizing([$this->web->uuid, $this->api->uuid]);
});

it('filters watch path apps with the webhook file list while the base is unknown', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([]));
    $this->api->update(['watch_paths' => 'apps/api/**']);
    $this->api->forceFill(['trigger_base_commit' => null])->save();

    epRun(epContext(['payloadChangedFiles' => ['apps/web/a.ts']]), [$this->api]);

    expect(ApplicationDeploymentQueue::query()->where('application_id', $this->api->id)->sole()->status)->toBe(ApplicationDeploymentStatus::SKIPPED->value)
        ->and($runner->requests)->toBe([])
        ->and($this->api->refresh()->trigger_base_commit)->toBeNull();
});

it('keeps retrying after releases for half an hour', function () {
    $job = new EvaluatePush(epContext(), [$this->web->id]);

    expect($job->retryUntil()->greaterThan(now()->addMinutes(29)))->toBeTrue();
});

it('gives jobs for different applications of one push different unique ids', function () {
    expect((new EvaluatePush(epContext(), [$this->web->id]))->uniqueId())
        ->not->toBe((new EvaluatePush(epContext(), [$this->api->id]))->uniqueId())
        ->and((new EvaluatePush(epContext(), [$this->web->id, $this->api->id]))->uniqueId())
        ->toBe((new EvaluatePush(epContext(), [$this->api->id, $this->web->id]))->uniqueId())
        ->and((new EvaluatePush(epContext(['provider' => 'github']), [$this->web->id]))->uniqueId())
        ->not->toBe((new EvaluatePush(epContext(['provider' => 'github_manual']), [$this->web->id]))->uniqueId());
});

it('re-dispatches the remaining applications under a new unique id', function () {
    Queue::fake();
    FakeAffectedRunner::respondWith(epRespond([]));
    $this->fixture['server']->settings->update(['deployment_queue_limit' => 1]);
    $other = deployTriggerApplication($this->fixture, ['base_directory' => '/apps/other']);
    ApplicationDeploymentQueue::create(['application_id' => $other->id, 'deployment_uuid' => 'busy', 'server_id' => $this->fixture['server']->id, 'status' => ApplicationDeploymentStatus::QUEUED->value, 'commit' => EP_BASE]);
    $job = new EvaluatePush(epContext(), [$this->web->id, $this->api->id]);

    $job->handle();

    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $retry) => $retry->uniqueId() !== $job->uniqueId());
});

it('deploys every candidate when building the request throws', function () {
    FakeAffectedRunner::respondWith(epRespond([]));
    $this->mock(EvaluationRequestBuilder::class, fn ($mock) => $mock->shouldReceive('build')->andThrow(new RuntimeException('Private key not found')));

    epRun(epContext(), [$this->web, $this->api]);

    expect(ApplicationDeploymentQueue::query()->where('status', '!=', ApplicationDeploymentStatus::SKIPPED->value)->count())->toBe(2)
        ->and(ApplicationDeploymentQueue::query()->first()->trigger_reason['reason_code'])->toBe('evaluation_failed')
        ->and($this->web->refresh()->trigger_set['last_error'])->toContain('Private key not found');
});

it('deploys every candidate when the job fails', function () {
    $job = new EvaluatePush(epContext(), [$this->web->id, $this->api->id]);

    $job->failed(new RuntimeException('worker timeout'));

    expect(ApplicationDeploymentQueue::query()->where('status', '!=', ApplicationDeploymentStatus::SKIPPED->value)->count())->toBe(2)
        ->and(ApplicationDeploymentQueue::query()->first()->trigger_reason['reason_code'])->toBe('evaluation_failed');
});

it('drops a redelivered push that was already evaluated', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['skip', 'no_match']]));
    epRun(epContext(), [$this->web]);

    epRun(epContext(), [$this->web->refresh()]);

    expect($runner->requests)->toHaveCount(1)
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->sole()->skipped_count)->toBe(1);
});

it('evaluates a dry run without a stored base', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['skip', 'no_match']]));
    $this->web->forceFill(['trigger_base_commit' => null])->save();

    epRun(epContext(['after' => EP_AFTER]), [$this->web->refresh()], dryRun: true);

    expect($runner->requests)->toHaveCount(1)
        ->and($runner->requests[0]->applications[0]->baseCommit)->toBe(EP_AFTER)
        ->and($this->web->refresh()->trigger_set['display'])->toBe(['apps/x/**']);
});

it('leaves an already queued deployment of the same commit alone', function () {
    FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['deploy', 'matched']]));
    $existing = ApplicationDeploymentQueue::create([
        'application_id' => $this->web->id, 'deployment_uuid' => 'manual-one', 'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::QUEUED->value, 'commit' => EP_AFTER, 'server_id' => $this->fixture['server']->id,
    ]);

    epRun(epContext(), [$this->web]);

    expect(ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->count())->toBe(1)
        ->and($existing->refresh()->trigger_reason)->toBeNull();
});

it('deploys the application when the queue-full retry runs', function () {
    Queue::fake();
    FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['deploy', 'matched']]));
    $this->fixture['server']->settings->update(['deployment_queue_limit' => 1]);
    $other = deployTriggerApplication($this->fixture, ['base_directory' => '/apps/other']);
    $busy = ApplicationDeploymentQueue::create(['application_id' => $other->id, 'deployment_uuid' => 'busy', 'server_id' => $this->fixture['server']->id, 'status' => ApplicationDeploymentStatus::QUEUED->value, 'commit' => EP_BASE]);
    epRun(epContext(), [$this->web]);
    $retry = Queue::pushed(EvaluatePush::class)->first();
    $busy->delete();

    $retry->handle();

    expect(ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->count())->toBe(1);
});

it('records a new preview skip on the pull request, not on the application', function () {
    FakeAffectedRunner::respondWith(epRespond([$this->web->uuid.':pr-5' => ['skip', 'no_match']]));
    $this->web->settings->update(['is_preview_deployments_enabled' => true]);
    $setBefore = $this->web->trigger_set;

    epRun(epContext(['ref' => 'refs/pull/5/head', 'pullRequest' => new PullRequestContext(5, 'main', false, 'github', 'https://github.com/acme/mono/pull/5')]), [$this->web]);

    $row = ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->sole();
    expect($row->pull_request_id)->toBe(5)
        ->and($row->git_type)->toBe('github')
        ->and($row->trigger_reason['pull_request_html_url'])->toBe('https://github.com/acme/mono/pull/5')
        ->and($this->web->refresh()->trigger_set)->toBe($setBefore)
        ->and($this->web->trigger_base_commit)->toBe(EP_BASE);
});

it('still deploys a production push of a commit that a preview skipped', function () {
    FakeAffectedRunner::respondWith(epRespond([$this->web->uuid.':pr-5' => ['skip', 'no_match']]));
    $this->web->settings->update(['is_preview_deployments_enabled' => true]);
    epRun(epContext(['ref' => 'refs/pull/5/head', 'pullRequest' => new PullRequestContext(5, 'main', false, 'github', null)]), [$this->web]);

    FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['deploy', 'matched']]));
    epRun(epContext(), [$this->web->refresh()]);

    expect(ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->where('pull_request_id', 0)->where('status', '!=', 'skipped')->count())->toBe(1);
});

it('re-evaluates a redelivered push whose deployment failed', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['deploy', 'matched']]));
    ApplicationDeploymentQueue::create(['application_id' => $this->web->id, 'deployment_uuid' => 'failed-one', 'pull_request_id' => 0, 'status' => ApplicationDeploymentStatus::FAILED->value, 'commit' => EP_AFTER]);
    $this->web->forceFill(['trigger_set' => ['display' => [], 'evaluated_commit' => EP_AFTER, 'last_error' => null]])->save();

    epRun(epContext(), [$this->web->refresh()]);

    expect($runner->requests)->toHaveCount(1);
});

it('does not drop a push of the commit that evaluate now looked at', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['deploy', 'matched']]));
    $this->web->forceFill(['trigger_set' => ['display' => [], 'evaluated_commit' => EP_AFTER, 'last_error' => null]])->save();

    epRun(epContext(), [$this->web->refresh()]);

    expect($runner->requests)->toHaveCount(1);
});

it('evaluates a push back to an older deployed commit after a newer deployment', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['deploy', 'matched']]));
    ApplicationDeploymentQueue::create(['application_id' => $this->web->id, 'deployment_uuid' => 'old', 'pull_request_id' => 0, 'status' => ApplicationDeploymentStatus::FINISHED->value, 'commit' => EP_AFTER]);
    ApplicationDeploymentQueue::create(['application_id' => $this->web->id, 'deployment_uuid' => 'newer', 'pull_request_id' => 0, 'status' => ApplicationDeploymentStatus::FINISHED->value, 'commit' => str_repeat('e', 40)]);

    epRun(epContext(), [$this->web]);

    expect($runner->requests)->toHaveCount(1);
});

it('recreates the preview when a closed pull request is reopened at the same head', function () {
    FakeAffectedRunner::unsupported();
    $fixture = deployTriggerFixture();
    $application = deployTriggerApplication($fixture, ['base_directory' => '/apps/web', 'git_repository' => 'https://github.com/acme/mono']);
    $application->settings->update(['is_preview_deployments_enabled' => true]);
    $head = str_repeat('b', 40);
    // The preview deployed at the head, then the pull request closed: the preview is gone, its queue row stays.
    ApplicationDeploymentQueue::create(['application_id' => $application->id, 'deployment_uuid' => 'pr-old', 'pull_request_id' => 7, 'status' => ApplicationDeploymentStatus::FINISHED->value, 'commit' => $head]);

    (new EvaluatePush(new PushContext(provider: 'github', teamId: $fixture['team']->id, repositoryUrl: 'https://github.com/acme/mono', ref: 'refs/pull/7/head', after: $head,
        pullRequest: new PullRequestContext(7, 'main', false, 'github', 'https://github.com/acme/mono/pull/7')), [$application->id]))->handle();

    expect(ApplicationPreview::query()->where('application_id', $application->id)->where('pull_request_id', 7)->exists())->toBeTrue();
});

it('marks the last error of a failed dry run as coming from evaluate now', function () {
    FakeAffectedRunner::failing(new AffectedRunnerException('server unreachable'));

    epRun(epContext(), [$this->web], dryRun: true);

    expect(ApplicationDeploymentQueue::query()->count())->toBe(0)
        ->and($this->web->refresh()->trigger_set['last_error'])->toBe('server unreachable')
        ->and($this->web->trigger_set['last_error_dry_run'])->toBeTrue();
});

it('sends the git credential of an application only to a server of that application', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([]));
    $key = PrivateKey::factory()->create(['team_id' => $this->fixture['team']->id]);
    // The credential comes from web, which runs on a server with a higher id than the server of api.
    $webServer = Server::factory()->create(['team_id' => $this->fixture['team']->id]);
    $webServer->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $webDestination = StandaloneDocker::query()->where('server_id', $webServer->id)->firstOrFail();
    $this->web->forceFill(['private_key_id' => $key->id, 'git_repository' => 'git@github.com:acme/mono.git', 'destination_id' => $webDestination->id])->save();
    $this->api->forceFill(['git_repository' => 'git@github.com:acme/mono.git'])->save();
    foreach ([$this->web, $this->api] as $application) {
        $application->forceFill(['trigger_base_commit' => EP_BASE])->save();
    }

    epRun(epContext(['repositoryUrl' => 'git@github.com:acme/mono.git']), [$this->web->refresh(), $this->api->refresh()]);

    expect($runner->accesses[0]->files['id_key'] ?? null)->toBe(rtrim($key->private_key, "\n")."\n")
        ->and($runner->servers[0]->id)->toBe($webServer->id);
});

it('drops a pending pull request evaluation once the pull request closed', function () {
    FakeAffectedRunner::unsupported();
    $this->web->settings->update(['is_preview_deployments_enabled' => true]);
    $context = epContext(['provider' => 'github', 'ref' => 'refs/pull/7/head', 'pullRequest' => new PullRequestContext(7, 'main', false, 'github', 'https://github.com/acme/mono/pull/7')]);
    $context = $context->recordArrival([$this->web->id]);
    ClosedPullRequests::markClosed($this->web, 7);

    epRun($context, [$this->web]);

    expect(ApplicationPreview::query()->where('application_id', $this->web->id)->exists())->toBeFalse()
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->exists())->toBeFalse();
});

it('keeps a pull request closed when the router dispatches one of its updates', function () {
    $this->web->settings->update(['is_preview_deployments_enabled' => true]);
    ClosedPullRequests::markClosed($this->web, 7);
    Queue::fake([EvaluatePush::class, ApplicationDeploymentJob::class]);
    $router = PushTriggerRouter::forPush(epContext(['provider' => 'github', 'ref' => 'refs/pull/7/head', 'pullRequest' => new PullRequestContext(7, 'main', false, 'github', null)]));
    $router->defer($this->web);
    $router->dispatch();

    // Only an open or reopen event clears it (PullRequestClosureTest).
    expect(ClosedPullRequests::isClosed($this->web->id, 7))->toBeTrue();
});

it('deploys a push when the application changed configuration that was not deployed yet', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['skip', 'no_match']]));
    $this->web->forceFill(['build_command' => 'pnpm build'])->save();
    ApplicationDeploymentQueue::create(['application_id' => $this->web->id, 'deployment_uuid' => 'deployed', 'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FINISHED->value, 'commit' => EP_BASE,
        'configuration_snapshot' => $this->web->deploymentConfigurationSnapshot()]);
    $this->web->forceFill(['build_command' => 'pnpm build:web'])->save();

    epRun(epContext(), [$this->web->refresh()]);

    $row = ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->latest('id')->first();
    expect($row->status)->not->toBe(ApplicationDeploymentStatus::SKIPPED->value)
        ->and($row->trigger_reason['reason_code'])->toBe('config_changed')
        ->and($runner->requests)->toBe([]);
});

it('deploys a pending push when deploy triggers were turned off meanwhile', function () {
    $runner = FakeAffectedRunner::respondWith(epRespond([$this->web->uuid => ['skip', 'no_match']]));
    $this->web->settings->update(['is_smart_triggers_enabled' => false]);

    epRun(epContext(), [$this->web->refresh()]);

    $row = ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->sole();
    expect($row->status)->not->toBe(ApplicationDeploymentStatus::SKIPPED->value)
        ->and($row->trigger_reason['reason_code'])->toBe('triggers_off')
        ->and($runner->requests)->toBe([]);
});

it('neither deploys nor records a skip when the pull request closes during the evaluation', function (string $decision) {
    $this->web->settings->update(['is_preview_deployments_enabled' => true]);
    FakeAffectedRunner::respondWith(function (EvaluateRequest $request) use ($decision): string {
        ClosedPullRequests::markClosed($this->web, 5);

        return epRespond([$this->web->uuid.':pr-5' => [$decision, 'matched']])($request);
    });

    epRun(epContext(['ref' => 'refs/pull/5/head', 'pullRequest' => new PullRequestContext(5, 'main', false, 'github', 'https://github.com/acme/mono/pull/5')]), [$this->web]);

    expect(ApplicationPreview::query()->where('application_id', $this->web->id)->exists())->toBeFalse()
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->exists())->toBeFalse();
})->with(['deploy', 'skip']);
