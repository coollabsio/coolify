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

it('targets normal database start status changes to the initiating user', function () {
    $source = file_get_contents(__DIR__.'/../../app/Jobs/DatabaseStartJob.php');

    expect($source)
        ->toContain('event(new DatabaseStatusChanged($this->userId));')
        ->not->toContain('event(new DatabaseStatusChanged($database));');
});
