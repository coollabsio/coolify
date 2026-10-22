<?php

use App\Actions\Docker\GetContainersStatus;
use App\Models\Server;
use App\Models\Team;
use App\Support\Actions\UniqueUntilProcessingJobDecorator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->team = Team::factory()->create();
});

afterEach(function () {
    GetContainersStatus::clearFake();
});

it('queues one status refresh per server while one is waiting', function () {
    Queue::fake();
    $first = Server::factory()->create(['team_id' => $this->team->id]);
    $second = Server::factory()->create(['team_id' => $this->team->id]);

    GetContainersStatus::dispatch($first);
    GetContainersStatus::dispatch($first);
    GetContainersStatus::dispatch($second);

    Queue::assertPushed(UniqueUntilProcessingJobDecorator::class, 2);
    Queue::assertPushedOn('high', UniqueUntilProcessingJobDecorator::class);
    Queue::assertPushed(UniqueUntilProcessingJobDecorator::class, fn ($job) => $job->timeout === 120);
});

it('accepts a new status refresh after the waiting one is lost', function () {
    Queue::fake();
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    GetContainersStatus::dispatch($server);
    $this->travel(61)->seconds();
    GetContainersStatus::dispatch($server);

    Queue::assertPushed(UniqueUntilProcessingJobDecorator::class, 2);
});

it('queues a follow-up status refresh requested while one is running', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $runs = 0;
    GetContainersStatus::partialMock()
        ->shouldReceive('handle')
        ->andReturnUsing(function (Server $server) use (&$runs) {
            $runs++;
            if ($runs === 1) {
                GetContainersStatus::dispatch($server);
            }
        });

    GetContainersStatus::dispatch($server);

    expect($runs)->toBe(2);
});
