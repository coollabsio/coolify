<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\EvaluatePush;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Services\DeployTriggers\EvaluationSlots;
use App\Services\DeployTriggers\PushContext;
use App\Services\DeployTriggers\PushTriggerRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAffectedRunner;

uses(RefreshDatabase::class);

const LANE_BASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const LANE_ONE = '1111111111111111111111111111111111111111';
const LANE_TWO = '2222222222222222222222222222222222222222';

beforeEach(function () {
    Queue::fake([ApplicationDeploymentJob::class, EvaluatePush::class]);
    app()->instance(EvaluationSlots::class, new class extends EvaluationSlots
    {
        public function run(Closure $callback, Closure $whenBusy): void
        {
            $callback();
        }
    });
    $this->fixture = deployTriggerFixture();
    $this->web = laneApplication($this->fixture, '/apps/web');
    $this->api = laneApplication($this->fixture, '/apps/api');
});

function laneApplication(array $fixture, string $baseDirectory): Application
{
    $application = deployTriggerApplication($fixture, ['base_directory' => $baseDirectory, 'git_repository' => 'https://github.com/acme/mono']);
    $application->forceFill(['trigger_base_commit' => LANE_BASE])->save();

    return $application->refresh();
}

/**
 * Routes one webhook delivery of a push to evaluation and returns its queued job.
 *
 * @param  list<Application>  $applications
 * @param  list<string>|null  $files
 */
function laneDeliver(array $applications, string $before, string $after, ?array $files = null): EvaluatePush
{
    $router = PushTriggerRouter::forPush(new PushContext(
        provider: 'gitlab', teamId: 0, repositoryUrl: 'https://github.com/acme/mono', ref: 'refs/heads/main',
        after: $after, before: $before, commitMessage: 'change', payloadChangedFiles: $files,
    ));
    foreach ($applications as $application) {
        $router->defer($application);
    }
    $router->dispatch();

    return Queue::pushed(EvaluatePush::class)->last();
}

function laneDeployRespond(): Closure
{
    return fn ($request): string => json_encode([
        'after' => $request->after,
        'applications' => array_map(fn (string $id) => ['id' => $id, 'decision' => 'deploy', 'reason_code' => 'matched', 'reason' => 'because', 'base' => LANE_BASE], $request->applicationIds()),
    ]);
}

/**
 * @return list<string>
 */
function laneDeployedCommits(Application $application): array
{
    return ApplicationDeploymentQueue::query()->where('application_id', $application->id)
        ->where('status', '!=', ApplicationDeploymentStatus::SKIPPED->value)->orderBy('id')->pluck('commit')->all();
}

it('deploys a watch path push from the webhook file list when an earlier push was dropped', function (?string $base, bool $evaluatedFirst) {
    FakeAffectedRunner::unsupported();
    $this->api->forceFill(['watch_paths' => 'apps/api/**', 'trigger_base_commit' => $base])->save();

    $older = laneDeliver([$this->api->refresh()], LANE_BASE, LANE_ONE, ['apps/api/index.ts']);
    $newer = laneDeliver([$this->api], LANE_ONE, LANE_TWO, ['README.md']);
    foreach ($evaluatedFirst ? [$newer, $older] : [$older, $newer] as $job) {
        $job->handle();
    }

    expect(laneDeployedCommits($this->api))->toBe([LANE_TWO]);
})->with([
    'no base, newer evaluated first' => [null, true],
    'no base, older evaluated first' => [null, false],
    'no server, older evaluated first' => [LANE_BASE, false],
]);

it('still skips a watch path push from the file list when the earlier push was evaluated', function () {
    FakeAffectedRunner::unsupported();
    $this->api->forceFill(['watch_paths' => 'apps/api/**'])->save();

    laneDeliver([$this->api->refresh()], LANE_BASE, LANE_ONE, ['apps/api/index.ts'])->handle();
    laneDeliver([$this->api], LANE_ONE, LANE_TWO, ['README.md'])->handle();

    expect(laneDeployedCommits($this->api))->toBe([LANE_ONE]);
});

it('does not drop a push of one application for a newer push that only another application received', function () {
    FakeAffectedRunner::respondWith(laneDeployRespond());

    $webDelivery = laneDeliver([$this->web], LANE_BASE, LANE_ONE);
    laneDeliver([$this->api], LANE_BASE, LANE_ONE);
    laneDeliver([$this->api], LANE_ONE, LANE_TWO);
    $webDelivery->handle();

    expect(laneDeployedCommits($this->web))->toBe([LANE_ONE]);
});

it('does not drop a pending push for a redelivery of an older push', function () {
    FakeAffectedRunner::respondWith(laneDeployRespond());

    laneDeliver([$this->web], LANE_BASE, LANE_ONE)->handle();
    $newer = laneDeliver([$this->web], LANE_ONE, LANE_TWO);
    laneDeliver([$this->web], LANE_BASE, LANE_ONE);
    $newer->handle();

    expect(laneDeployedCommits($this->web))->toBe([LANE_ONE, LANE_TWO]);
});

it('drops a pending push once a webhook deployed a newer push directly', function () {
    FakeAffectedRunner::respondWith(laneDeployRespond());

    $older = laneDeliver([$this->web], LANE_BASE, LANE_ONE);
    $this->web->settings->update(['is_smart_triggers_enabled' => false]);
    queue_application_deployment(application: $this->web->refresh(), deployment_uuid: new_public_id(), commit: LANE_TWO, is_webhook: true);
    $older->handle();

    expect(laneDeployedCommits($this->web))->toBe([LANE_TWO]);
});
