<?php

use App\Actions\Server\CleanupDocker;
use App\Events\DockerCleanupDone;
use App\Jobs\DockerCleanupJob;
use App\Models\Application;
use App\Models\DockerCleanupExecution;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledJobDelivery;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Server\DockerCleanupFailed;
use App\Services\ScheduledJobDeliveryService;
use App\Support\Actions\UniqueUntilProcessingJobDecorator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
});

afterEach(function () {
    Server::flushIdentityMap();
    Carbon::setTestNow();
});

function dockerCleanupReachableServer(): Server
{
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    return $server->fresh();
}

/**
 * @return Collection<int, string> The local commands (SSH invocations) that ran.
 */
function dockerCleanupFakeProcesses(?Closure $onRun = null): Collection
{
    $commands = collect();
    Process::fake(function ($process) use ($commands, $onRun) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $commands->push($command);
        if ($onRun) {
            $onRun($command);
        }

        return Process::result(output: '');
    });

    return $commands;
}

it('persists the server id when creating an execution record', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $execution = DockerCleanupExecution::create([
        'server_id' => $server->id,
    ]);

    expect($execution->server_id)->toBe($server->id);
    $this->assertDatabaseHas('docker_cleanup_executions', [
        'id' => $execution->id,
        'server_id' => $server->id,
    ]);
});

it('creates a failed execution record when server is not functional', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $server = Server::factory()->create(['team_id' => $team->id]);

    // Make server not functional by setting is_reachable to false
    $server->settings->update(['is_reachable' => false]);

    $job = new DockerCleanupJob($server);
    $job->handle();

    $execution = DockerCleanupExecution::where('server_id', $server->id)->first();

    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe('failed')
        ->and($execution->message)->toContain('not functional')
        ->and($execution->finished_at)->not->toBeNull();
});

it('creates a failed execution record when server is force disabled', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $server = Server::factory()->create(['team_id' => $team->id]);

    // Make server not functional by force disabling
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => true,
    ]);

    $job = new DockerCleanupJob($server);
    $job->handle();

    $execution = DockerCleanupExecution::where('server_id', $server->id)->first();

    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe('failed')
        ->and($execution->message)->toContain('not functional');
});

function dockerCleanupQueueJob(string $uuid): FakeJob
{
    return new class($uuid) extends FakeJob
    {
        public function __construct(private string $jobUuid) {}

        public function getRawBody()
        {
            return json_encode(['uuid' => $this->jobUuid]);
        }
    };
}

it('finishes its own execution when the job fails after a timeout, not a newer running cleanup', function () {
    Notification::fake();
    Event::fake([DockerCleanupDone::class]);
    $server = dockerCleanupReachableServer();
    $queueJob = dockerCleanupQueueJob('prr-fix-timed-out-cleanup');
    $statesWhenTimedOut = null;
    dockerCleanupFakeProcesses(function () use ($server, $queueJob, &$statesWhenTimedOut) {
        if ($statesWhenTimedOut !== null) {
            return;
        }
        $ownExecution = DockerCleanupExecution::query()->sole();
        $scheduledExecution = DockerCleanupExecution::create(['server_id' => $server->id]);

        // The worker timeout calls failed() on a fresh job copy built from the same queue payload.
        (new DockerCleanupJob($server, true))->setJob($queueJob)->failed(new RuntimeException('Docker cleanup job has timed out.'));

        $statesWhenTimedOut = [
            'own' => $ownExecution->fresh()->only(['status', 'message']),
            'ownFinished' => $ownExecution->fresh()->finished_at !== null,
            'scheduled' => $scheduledExecution->fresh()->only(['status', 'finished_at']),
        ];
    });

    (new DockerCleanupJob($server, true))->setJob($queueJob)->handle();

    expect($statesWhenTimedOut['own'])->toBe(['status' => 'failed', 'message' => 'Docker cleanup job has timed out.'])
        ->and($statesWhenTimedOut['ownFinished'])->toBeTrue()
        ->and($statesWhenTimedOut['scheduled'])->toBe(['status' => 'running', 'finished_at' => null]);
});

it('leaves a running scheduled cleanup untouched when a manual cleanup fails before it started', function () {
    Notification::fake();
    Event::fake([DockerCleanupDone::class]);
    $server = dockerCleanupReachableServer();
    $scheduledExecution = DockerCleanupExecution::create(['server_id' => $server->id]);

    (new DockerCleanupJob($server, true))
        ->setJob(dockerCleanupQueueJob('prr-fix-waiting-manual-cleanup'))
        ->failed(new MaxAttemptsExceededException('App\Jobs\DockerCleanupJob has been attempted too many times.'));

    expect($scheduledExecution->fresh()->status)->toBe('running')
        ->and($scheduledExecution->fresh()->finished_at)->toBeNull();
    Notification::assertNothingSent();
    Event::assertNotDispatched(DockerCleanupDone::class);
});

it('skips a scheduled cleanup occurrence while another cleanup holds the server lock, so it is not reported missed later', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'));
    Notification::fake();
    $server = dockerCleanupReachableServer();
    $occurrence = ScheduledJobDelivery::create([
        'schedule_key' => "docker-cleanup:{$server->id}",
        'scheduled_for' => now()->startOfMinute(),
        'job_type' => 'docker-cleanup',
        'resource_id' => $server->id,
        'status' => 'enqueued',
        'enqueued_at' => now(),
    ]);
    $job = new DockerCleanupJob($server, false, false, false, $occurrence->uuid);
    $runningCleanupLock = Cache::lock($job->middleware()[0]->getLockKey($job), 3600);
    $runningCleanupLock->get();
    $commands = dockerCleanupFakeProcesses();

    dispatch_sync($job);
    $runningCleanupLock->release();

    expect($occurrence->fresh()->status)->toBe('skipped')
        ->and(DockerCleanupExecution::query()->exists())->toBeFalse()
        ->and($commands)->toBeEmpty();

    Carbon::setTestNow(now()->addMinutes(ScheduledJobDeliveryService::ENQUEUED_STALE_AFTER_MINUTES + 1));
    Queue::fake();
    app(ScheduledJobDeliveryService::class)->recoverStaleEnqueued();

    Queue::assertNothingPushed();
    expect(DockerCleanupExecution::query()->exists())->toBeFalse();
    Notification::assertNotSentTo($server->team, DockerCleanupFailed::class);
});

function dockerCleanupDatabaseQueue(): void
{
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config(['queue.default' => 'database']);
}

function dockerCleanupWorkOnce(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => maintenance_queue(), '--sleep' => 0]);
}

it('runs a manual cleanup after another cleanup releases the server lock instead of dropping it', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'));
    Notification::fake();
    Event::fake([DockerCleanupDone::class]);
    dockerCleanupDatabaseQueue();
    $server = dockerCleanupReachableServer();
    $runningCleanupLock = Cache::lock('laravel-queue-overlap:docker-cleanup-'.$server->uuid, 3600);
    $runningCleanupLock->get();
    $commands = dockerCleanupFakeProcesses();

    DockerCleanupJob::dispatch($server, true);
    dockerCleanupWorkOnce();

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('available_at'))->toBe(now()->addSeconds(DockerCleanupJob::MANUAL_RELEASE_DELAY)->getTimestamp())
        ->and(DockerCleanupExecution::query()->exists())->toBeFalse()
        ->and($commands)->toBeEmpty();

    $runningCleanupLock->release();
    Carbon::setTestNow(now()->addSeconds(DockerCleanupJob::MANUAL_RELEASE_DELAY + 1));
    dockerCleanupWorkOnce();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DockerCleanupExecution::query()->sole()->status)->toBe('success')
        ->and(ScheduledJobDelivery::query()->exists())->toBeFalse();
});

it('fails a waiting manual cleanup instead of running it after its wait window', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'));
    dockerCleanupDatabaseQueue();
    $server = dockerCleanupReachableServer();
    $runningCleanupLock = Cache::lock('laravel-queue-overlap:docker-cleanup-'.$server->uuid, 3600);
    $runningCleanupLock->get();
    $commands = dockerCleanupFakeProcesses();

    DockerCleanupJob::dispatch($server, true);
    dockerCleanupWorkOnce();
    $runningCleanupLock->release();
    Carbon::setTestNow(now()->addDay());
    dockerCleanupWorkOnce();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DockerCleanupExecution::query()->exists())->toBeFalse()
        ->and($commands)->toBeEmpty();
});

it('drops a stop-triggered cleanup and a scheduled run while another cleanup holds the server lock', function () {
    dockerCleanupDatabaseQueue();
    $server = dockerCleanupReachableServer();
    $runningCleanupLock = Cache::lock('laravel-queue-overlap:docker-cleanup-'.$server->uuid, 3600);
    $runningCleanupLock->get();
    $commands = dockerCleanupFakeProcesses();

    DockerCleanupJob::dispatch($server);
    dockerCleanupWorkOnce();
    $runningCleanupLock->release();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DockerCleanupExecution::query()->exists())->toBeFalse()
        ->and($commands)->toBeEmpty();
});

it('shares one server lock between scheduled cleanups and queued CleanupDocker runs that outlives the job timeout', function () {
    $server = dockerCleanupReachableServer();
    $cleanupJob = new DockerCleanupJob($server);
    $actionJob = CleanupDocker::makeJob($server, false, false);

    $cleanupLock = $cleanupJob->middleware()[0];
    $actionLock = $actionJob->middleware()[0];

    expect($cleanupLock->getLockKey($cleanupJob))
        ->toBe('laravel-queue-overlap:docker-cleanup-'.$server->uuid)
        ->toBe($actionLock->getLockKey($actionJob))
        ->and($cleanupLock->expiresAfter)->toBeGreaterThan($cleanupJob->timeout)
        ->and($actionLock->expiresAfter)->toBeGreaterThan($actionJob->timeout)
        ->and($cleanupLock->releaseAfter)->toBeNull()
        ->and($actionLock->releaseAfter)->toBeNull()
        ->and($actionJob->timeout)->toBe(CleanupDocker::JOB_TIMEOUT)
        ->and($actionJob->tries)->toBe(1);
});

it('drops a queued CleanupDocker run while a scheduled cleanup holds the server lock', function () {
    $server = dockerCleanupReachableServer();
    $actionJob = CleanupDocker::makeJob($server, false, false);
    $runningCleanupLock = Cache::lock('laravel-queue-overlap:docker-cleanup-'.$server->uuid, 3600);
    $runningCleanupLock->get();
    $commands = dockerCleanupFakeProcesses();

    dispatch_sync($actionJob);
    $runningCleanupLock->release();

    expect($commands)->toBeEmpty();
});

it('routes docker cleanups to the maintenance queue on cloud and to high on self-hosted', function (bool $selfHosted, string $queue) {
    config(['constants.coolify.self_hosted' => $selfHosted]);
    Queue::fake();
    $server = dockerCleanupReachableServer();

    DockerCleanupJob::dispatch($server, true);
    CleanupDocker::dispatch($server, false, false);

    expect(maintenance_queue())->toBe($queue);
    Queue::assertPushedOn($queue, DockerCleanupJob::class);
    Queue::assertPushedOn($queue, UniqueUntilProcessingJobDecorator::class, fn (JobDecorator $job) => $job->decorates(CleanupDocker::class));
})->with([
    'cloud' => [false, 'maintenance'],
    'self-hosted' => [true, 'high'],
]);

it('gives remote cleanup commands a local timeout and fails once the cleanup deadline has passed', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 0, 0, 0, 'UTC'));
    $server = dockerCleanupReachableServer();
    $commands = dockerCleanupFakeProcesses(function (string $command) {
        if (str_contains($command, 'docker ')) {
            Carbon::setTestNow(now()->addSeconds(300));
        }
    });

    expect(fn () => CleanupDocker::run($server))
        ->toThrow(RuntimeException::class, 'Docker cleanup did not finish within '.CleanupDocker::REMOTE_COMMANDS_DEADLINE.' seconds');

    $sshCommands = $commands->filter(fn (string $command) => str_contains($command, 'docker container prune') || str_contains($command, 'docker image prune'))->values();

    expect(CleanupDocker::REMOTE_COMMANDS_DEADLINE)->toBeLessThan(CleanupDocker::JOB_TIMEOUT)
        ->and($sshCommands)->toHaveCount(2)
        ->and($sshCommands[0])->toContain('timeout '.CleanupDocker::REMOTE_COMMANDS_DEADLINE.' ssh')
        ->and($sshCommands[1])->toContain('timeout '.(CleanupDocker::REMOTE_COMMANDS_DEADLINE - 300).' ssh')
        ->and($commands->contains(fn (string $command) => str_contains($command, 'docker builder prune')))->toBeFalse();
});

it('coalesces queued cleanups per server and delays them until a bulk stop is over', function () {
    Queue::fake();
    $first = dockerCleanupReachableServer();
    $second = dockerCleanupReachableServer();

    foreach (range(1, 30) as $stop) {
        CleanupDocker::dispatch($first, false, false);
    }
    CleanupDocker::dispatch($second, false, false);

    Queue::assertPushed(UniqueUntilProcessingJobDecorator::class, 2);
    Queue::assertPushed(UniqueUntilProcessingJobDecorator::class, fn (JobDecorator $job) => $job->delay === CleanupDocker::QUEUED_DELAY);
});

it('accepts a new queued cleanup once the waiting one is lost', function () {
    Queue::fake();
    $server = dockerCleanupReachableServer();

    CleanupDocker::dispatch($server, false, false);
    $this->travel(CleanupDocker::QUEUED_DELAY + 241)->seconds();
    CleanupDocker::dispatch($server, false, false);

    Queue::assertPushed(UniqueUntilProcessingJobDecorator::class, 2);
});

it('does not queue a cleanup after a stop when a cleanup ran on the server recently', function () {
    Queue::fake();
    $cleanedServer = dockerCleanupReachableServer();
    $otherServer = dockerCleanupReachableServer();
    Cache::put(CleanupDocker::lastRunCacheKey($cleanedServer), true, CleanupDocker::STOP_CLEANUP_COOLDOWN);

    CleanupDocker::dispatchAfterStop($cleanedServer);
    CleanupDocker::dispatchAfterStop($otherServer);

    Queue::assertPushed(UniqueUntilProcessingJobDecorator::class, 1);
    Queue::assertPushed(UniqueUntilProcessingJobDecorator::class, fn (JobDecorator $job) => $job->getParameters()[0]->is($otherServer));
});

it('cleans after a stop only when the disk usage reaches the server threshold', function (string $diskUsage, bool $cleans) {
    $server = dockerCleanupReachableServer();
    $server->settings()->update(['docker_cleanup_threshold' => 80]);
    $commands = collect();
    Process::fake(function ($process) use ($commands, $diskUsage) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $commands->push($command);

        return Process::result(output: str_contains($command, 'df / --output=pcent') ? $diskUsage : '');
    });

    CleanupDocker::run($server->fresh(), false, false, true);

    expect($commands->contains(fn (string $command) => str_contains($command, 'docker builder prune')))->toBe($cleans)
        ->and(Cache::has(CleanupDocker::lastRunCacheKey($server)))->toBe($cleans);
})->with([
    'below threshold' => ['79', false],
    'at threshold' => ['80', true],
    'unknown usage' => ['', true],
]);

it('scans application images with a fixed number of remote commands and keeps the retention rules', function () {
    $server = dockerCleanupReachableServer();
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $server->team_id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $applications = collect(range(1, 5))->map(fn () => Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]));
    $app = $applications->first();
    $app->settings()->update(['docker_images_to_keep' => 1]);

    $containers = "{$app->uuid}#{$app->uuid}:v2";
    $images = implode("\n", [
        "{$app->uuid}#v1#2026-01-01 00:00:00 +0000 UTC",
        "{$app->uuid}#v2#2026-01-02 00:00:00 +0000 UTC",
        "{$app->uuid}#v3#2026-01-03 00:00:00 +0000 UTC",
        "{$app->uuid}#v4#2026-01-04 00:00:00 +0000 UTC",
        "{$app->uuid}#pr-7#2026-01-05 00:00:00 +0000 UTC",
        "{$app->uuid}#v1-build#2026-01-01 00:00:00 +0000 UTC",
        "{$app->uuid}#v2-build#2026-01-02 00:00:00 +0000 UTC",
        "{$app->uuid}#v4-build#2026-01-04 00:00:00 +0000 UTC",
        "{$app->uuid}_web#v1#2026-01-01 00:00:00 +0000 UTC",
        "{$app->uuid}/other#v1#2026-01-01 00:00:00 +0000 UTC",
    ]);

    $commands = collect();
    Process::fake(function ($process) use ($commands, $containers, $images) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $commands->push($command);

        return match (true) {
            str_contains($command, 'docker ps -a --format') => Process::result(output: $containers),
            str_contains($command, "docker images --format '{{.Repository}}#") => Process::result(output: $images),
            default => Process::result(output: ''),
        };
    });

    CleanupDocker::run($server);

    $applicationScanCommands = $commands->filter(fn (string $command) => str_contains($command, 'docker ps -a --format')
        || str_contains($command, "docker images --format '{{.Repository}}#")
        || str_contains($command, 'docker inspect --format=')
        || str_contains($command, "--filter reference='"));
    $removals = $commands->filter(fn (string $command) => str_contains($command, 'docker rmi \''))->values();

    expect($applicationScanCommands)->toHaveCount(2)
        ->and($removals)->toHaveCount(1);

    $removal = $removals->first();
    foreach (["{$app->uuid}:v1", "{$app->uuid}:v3", "{$app->uuid}:pr-7", "{$app->uuid}:v1-build", "{$app->uuid}_web:v1"] as $deleted) {
        expect($removal)->toContain("'{$deleted}'");
    }
    foreach (["{$app->uuid}:v2'", "{$app->uuid}:v4'", "{$app->uuid}:v2-build", "{$app->uuid}:v4-build", "{$app->uuid}/other"] as $kept) {
        expect($removal)->not->toContain($kept);
    }
});
