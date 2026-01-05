<?php

use App\Jobs\CoolifyTask;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('can dispatch CoolifyTask successfully', function () {
    // Skip if no servers available
    $server = Server::where('ip', '!=', '1.2.3.4')->first();

    if (! $server) {
        $this->markTestSkipped('No servers available for testing');
    }

    Queue::fake();

    // Create an activity for the task
    $activity = activity()
        ->withProperties([
            'server_uuid' => $server->uuid,
            'command' => 'echo "test"',
            'type' => 'inline',
        ])
        ->event('inline')
        ->log('[]');

    // Dispatch the job
    CoolifyTask::dispatch(
        activity: $activity,
        ignore_errors: false,
        call_event_on_finish: null,
        call_event_data: null
    );

    // Assert job was dispatched
    Queue::assertPushed(CoolifyTask::class);
});

it('has correct retry configuration on CoolifyTask', function () {
    $server = Server::where('ip', '!=', '1.2.3.4')->first();

    if (! $server) {
        $this->markTestSkipped('No servers available for testing');
    }

    $activity = activity()
        ->withProperties([
            'server_uuid' => $server->uuid,
            'command' => 'echo "test"',
            'type' => 'inline',
        ])
        ->event('inline')
        ->log('[]');

    $job = new CoolifyTask(
        activity: $activity,
        ignore_errors: false,
        call_event_on_finish: null,
        call_event_data: null
    );

    // Assert retry configuration
    expect($job->tries)->toBe(3);
    expect($job->maxExceptions)->toBe(1);
    expect($job->timeout)->toBe(600);
    expect($job->backoff())->toBe([30, 90, 180]);
});
