<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Notifications\Application\DeploymentFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;

uses(RefreshDatabase::class);

/**
 * Fake the queue, but throw when a deployment job matches $shouldFail, as a job that cannot be
 * serialized or a queue connection that is down does.
 *
 * @param  Closure(ApplicationDeploymentQueue): bool  $shouldFail
 */
function fakeQueueFailingDeploymentDispatch(Closure $shouldFail): QueueFake
{
    $fake = new class(app(), [], app('queue')) extends QueueFake
    {
        public ?Closure $shouldFail = null;

        public function push($job, $data = '', $queue = null)
        {
            if ($job instanceof ApplicationDeploymentJob
                && ($this->shouldFail)(ApplicationDeploymentQueue::query()->findOrFail($job->application_deployment_queue_id))) {
                throw new RuntimeException('queue connection refused');
            }

            return parent::push($job, $data, $queue);
        }
    };
    $fake->shouldFail = $shouldFail;
    Queue::swap($fake);

    return $fake;
}

beforeEach(function () {
    Server::flushIdentityMap();
    Notification::fake();
    config()->set('cache.default', 'array');
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->team->emailNotificationSettings->update([
        'smtp_enabled' => true,
        'deployment_failure_email_notifications' => true,
    ]);
    $makeServer = function (string $name): Server {
        $server = Server::factory()->create(['team_id' => $this->team->id, 'name' => $name]);
        $server->settings->update(['concurrent_builds' => 1]);

        return $server;
    };
    $this->mainServer = $makeServer('main-server');
    $this->mainDestination = StandaloneDocker::where('server_id', $this->mainServer->id)->firstOrFail();
    $this->firstExtraServer = $makeServer('first-extra-server');
    $this->secondExtraServer = $makeServer('second-extra-server');

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $this->mainDestination->id,
        'destination_type' => $this->mainDestination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);

    $this->queueDeployment = fn (string $uuid, string $status = 'queued'): ApplicationDeploymentQueue => ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => $uuid,
        'status' => $status,
        'server_id' => $this->mainServer->id,
        'destination_id' => $this->mainDestination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
        'only_this_server' => true,
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

test('an additional server whose deployment cannot be dispatched does not stop the other additional servers', function () {
    foreach ([$this->firstExtraServer, $this->secondExtraServer] as $server) {
        $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
        $this->application->additional_networks()->attach($destination->id, ['server_id' => $server->id]);
    }
    $this->application->refresh();
    $parent = ($this->queueDeployment)('dispatch-fail-parent', ApplicationDeploymentStatus::FINISHED->value);
    fakeQueueFailingDeploymentDispatch(fn (ApplicationDeploymentQueue $deployment) => $deployment->server_id === $this->firstExtraServer->id);

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();
    foreach ([
        'application' => $this->application,
        'application_deployment_queue' => $parent,
        'deployment_uuid' => $parent->deployment_uuid,
        'server' => $this->mainServer,
        'mainServer' => $this->mainServer,
        'destination' => $this->mainDestination,
        'pull_request_id' => 0,
        'preview' => null,
        'only_this_server' => false,
        'commit' => 'HEAD',
        'rollback' => false,
    ] as $name => $value) {
        $reflection->getProperty($name)->setValue($job, $value);
    }

    $reflection->getMethod('handleSuccessfulDeployment')->invoke($job);

    $failed = ApplicationDeploymentQueue::where('server_id', $this->firstExtraServer->id)->sole();
    $started = ApplicationDeploymentQueue::where('server_id', $this->secondExtraServer->id)->sole();
    expect($failed->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($failed->logs)->toContain('queue connection refused')
        ->and($started->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($started->parent_deployment_uuid)->toBe($parent->deployment_uuid)
        ->and($parent->fresh()->logs)->toContain('queue connection refused')
        ->and(Cache::has("multi-server-deployment-queued:{$parent->deployment_uuid}"))->toBeTrue();
    Queue::assertPushed(ApplicationDeploymentJob::class, 1);
    Queue::assertPushed(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job) => $job->application_deployment_queue_id === $started->id);
    // The summary waits for the second server, then reports the failed first server.
    Notification::assertNothingSent();
});

test('a deployment that cannot be dispatched starts the next queued deployment on its server', function () {
    $waiting = ($this->queueDeployment)('dispatch-fail-waiting');
    $this->travel(1)->seconds();
    fakeQueueFailingDeploymentDispatch(fn (ApplicationDeploymentQueue $deployment) => $deployment->deployment_uuid === 'dispatch-fail-broken');

    expect(fn () => queue_application_deployment($this->application, 'dispatch-fail-broken', no_questions_asked: true))
        ->toThrow(RuntimeException::class, 'queue connection refused');

    expect(ApplicationDeploymentQueue::where('deployment_uuid', 'dispatch-fail-broken')->value('status'))->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($waiting->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Queue::assertPushed(ApplicationDeploymentJob::class, 1);
    Queue::assertPushed(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job) => $job->application_deployment_queue_id === $waiting->id);
    Notification::assertSentTo($this->team, DeploymentFailed::class, fn (DeploymentFailed $notification) => $notification->deployment_uuid === 'dispatch-fail-broken');
});

test('when no deployment can be dispatched, each queued deployment fails once and the queue stops', function () {
    $waiting = collect(range(1, 3))->map(function (int $number) {
        $this->travel(1)->seconds();

        return ($this->queueDeployment)("dispatch-fail-waiting-{$number}");
    });
    $this->travel(1)->seconds();
    fakeQueueFailingDeploymentDispatch(fn () => true);

    expect(fn () => queue_application_deployment($this->application, 'dispatch-fail-broken', no_questions_asked: true))
        ->toThrow(RuntimeException::class, 'queue connection refused');

    expect(ApplicationDeploymentQueue::where('application_id', $this->application->id)->pluck('status')->unique()->all())
        ->toBe([ApplicationDeploymentStatus::FAILED->value]);
    foreach ($waiting as $deployment) {
        expect(substr_count($deployment->fresh()->logs, 'Deployment could not be started'))->toBe(1);
    }
    Queue::assertNothingPushed();
    Notification::assertSentToTimes($this->team, DeploymentFailed::class, 4);
});
