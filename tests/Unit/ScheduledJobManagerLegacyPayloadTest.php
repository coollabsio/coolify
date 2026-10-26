<?php

use App\Jobs\ScheduledJobManager;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

uses(TestCase::class);

it('uses the shared lock for a job queued before scheduled job types existed', function () {
    // v4.3.23 serialized ScheduledJobManager without a type property.
    $job = unserialize('O:'.strlen(ScheduledJobManager::class).':"'.ScheduledJobManager::class.'":0:{}');

    $middleware = $job->middleware();

    expect($job->type)->toBeNull()
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('scheduled-job-manager');
});
