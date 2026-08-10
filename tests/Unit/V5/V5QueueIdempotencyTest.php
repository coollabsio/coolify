<?php

use App\Events\V5CanvasResourceUpdated;
use App\Events\V5ClusterUpdated;
use App\Events\V5RealtimeTestEvent;
use App\Jobs\V5BootstrapServerJob;
use App\Jobs\V5DeployApplicationJob;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);

it('locks v5 deploy jobs per application for the job timeout plus a margin', function () {
    $job = new V5DeployApplicationJob(42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->uniqueFor)->toBe($job->timeout + 60);
});

it('locks v5 bootstrap jobs per server aligned with the controller bootstrap claim window', function () {
    $job = new V5BootstrapServerJob(7, 13);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('13')
        ->and($job->uniqueFor)->toBe(V5BootstrapServerJob::TIMEOUT_SECONDS + 300);
});

it('does not queue a second v5 deploy job while one is pending for the same application', function () {
    Config::set('cache.default', 'array');
    Queue::fake();

    V5DeployApplicationJob::dispatch(101);
    V5DeployApplicationJob::dispatch(101);
    V5DeployApplicationJob::dispatch(202);

    Queue::assertPushed(V5DeployApplicationJob::class, 2);
    Queue::assertPushed(V5DeployApplicationJob::class, fn (V5DeployApplicationJob $job): bool => $job->applicationId === 101);
    Queue::assertPushed(V5DeployApplicationJob::class, fn (V5DeployApplicationJob $job): bool => $job->applicationId === 202);
});

it('does not queue a second v5 bootstrap job while one is pending for the same server', function () {
    Config::set('cache.default', 'array');
    Queue::fake();

    V5BootstrapServerJob::dispatch(1, 55);
    V5BootstrapServerJob::dispatch(2, 55);
    V5BootstrapServerJob::dispatch(1, 66);

    Queue::assertPushed(V5BootstrapServerJob::class, 2);
    Queue::assertPushed(V5BootstrapServerJob::class, fn (V5BootstrapServerJob $job): bool => $job->serverId === 55);
    Queue::assertPushed(V5BootstrapServerJob::class, fn (V5BootstrapServerJob $job): bool => $job->serverId === 66);
});

it('queues v5 canvas and cluster broadcasts after commit instead of pushing on the request thread', function () {
    $canvasEvent = new V5CanvasResourceUpdated(1, 2);
    $clusterEvent = new V5ClusterUpdated(1, 2);

    expect($canvasEvent)->toBeInstanceOf(ShouldBroadcast::class)
        ->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($canvasEvent->afterCommit)->toBeTrue()
        ->and($clusterEvent)->toBeInstanceOf(ShouldBroadcast::class)
        ->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($clusterEvent->afterCommit)->toBeTrue();
});

it('keeps the manual v5 realtime test ping broadcasting immediately', function () {
    expect(new V5RealtimeTestEvent(1, 'ping'))->toBeInstanceOf(ShouldBroadcastNow::class);
});
