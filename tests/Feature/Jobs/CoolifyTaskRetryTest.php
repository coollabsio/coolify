<?php

use App\Enums\ProcessStatus;
use App\Jobs\CoolifyTask;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $team = Team::factory()->create();
    Server::factory()->create(['team_id' => $team->id]);

    $this->activity = activity()
        ->withProperties([
            'server_uuid' => Server::first()->uuid,
            'command' => 'echo "test"',
            'type' => 'inline',
            'status' => ProcessStatus::QUEUED->value,
        ])
        ->event('inline')
        ->log('[]');

    $this->job = new CoolifyTask(
        activity: $this->activity,
        ignore_errors: false,
        call_event_on_finish: null,
        call_event_data: null,
    );
});

test('has correct retry configuration', function () {
    expect($this->job->tries)->toBe(3)
        ->and($this->job->maxExceptions)->toBe(1)
        ->and($this->job->timeout)->toBe(600)
        ->and($this->job->backoff())->toBe([30, 90, 180]);
});

test('is queued on the high priority queue', function () {
    expect($this->job->queue)->toBe('high');
});

test('marks activity as error on permanent failure', function () {
    $exception = new \RuntimeException('SSH connection failed');

    $this->job->failed($exception);

    $this->activity->refresh();
    $properties = $this->activity->properties;

    expect($properties['status'])->toBe(ProcessStatus::ERROR->value)
        ->and($properties['error'])->toBe('SSH connection failed')
        ->and($properties)->toHaveKey('failed_at');
});

test('marks activity as error with default message when exception is null', function () {
    $this->job->failed(null);

    $this->activity->refresh();
    $properties = $this->activity->properties;

    expect($properties['status'])->toBe(ProcessStatus::ERROR->value)
        ->and($properties['error'])->toBe('Job permanently failed');
});
