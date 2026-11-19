<?php

use App\Events\DatabaseStatusChanged;
use App\Jobs\DatabaseStartJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('broadcasts failed database starts to the initiating user even when the activity is missing', function () {
    Event::fake([DatabaseStatusChanged::class]);

    $job = new DatabaseStartJob(
        databaseClass: 'MissingDatabase',
        databaseId: 123,
        teamId: 456,
        activityId: 789,
        userId: 42,
    );

    $job->failed(new RuntimeException('Database start failed.'));

    Event::assertDispatched(
        DatabaseStatusChanged::class,
        fn (DatabaseStatusChanged $event): bool => $event->userId === 42,
    );
});
