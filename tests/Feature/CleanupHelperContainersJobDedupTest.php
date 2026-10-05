<?php

use App\Jobs\CleanupHelperContainersJob;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->team = Team::factory()->create();
});

it('queues one helper container cleanup per server', function () {
    Queue::fake();
    $first = Server::factory()->create(['team_id' => $this->team->id]);
    $second = Server::factory()->create(['team_id' => $this->team->id]);

    CleanupHelperContainersJob::dispatch($first);
    CleanupHelperContainersJob::dispatch($second);
    CleanupHelperContainersJob::dispatch($first);

    Queue::assertPushed(CleanupHelperContainersJob::class, 2);
});
