<?php

use App\Jobs\RevalidateUnusableS3StoragesJob;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('cache.default', 'array');
    Queue::fake();
});

it('does not queue a second revalidation while the unique lock is held', function () {
    RevalidateUnusableS3StoragesJob::dispatch();
    $this->travel(29)->minutes();
    RevalidateUnusableS3StoragesJob::dispatch();

    Queue::assertPushed(RevalidateUnusableS3StoragesJob::class, 1);
});

it('queues the revalidation again after a lock left by a killed worker expires', function () {
    RevalidateUnusableS3StoragesJob::dispatch();
    $this->travel(31)->minutes();
    RevalidateUnusableS3StoragesJob::dispatch();

    Queue::assertPushed(RevalidateUnusableS3StoragesJob::class, 2);
});
