<?php

use App\Enums\ProcessStatus;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function storedCommandActivity(string $status, ?CarbonInterface $updatedAt = null): Activity
{
    $activity = activity()
        ->withProperties(['command' => 'echo POSTGRES_PASSWORD=secret', 'type' => 'inline', 'status' => $status, 'server_uuid' => 'server'])
        ->event('inline')
        ->log('[]');

    if ($updatedAt !== null) {
        Activity::query()->whereKey($activity->id)->toBase()->update(['updated_at' => $updatedAt]);
    }

    return $activity;
}

it('removes stored commands from activities that no longer run', function () {
    $finished = storedCommandActivity(ProcessStatus::FINISHED->value);
    $failed = storedCommandActivity(ProcessStatus::ERROR->value);
    $running = storedCommandActivity(ProcessStatus::IN_PROGRESS->value);
    $queued = storedCommandActivity(ProcessStatus::QUEUED->value);
    $stuck = storedCommandActivity(ProcessStatus::IN_PROGRESS->value, now()->subDays(2));

    (require database_path('migrations/2026_09_28_093936_remove_stored_commands_from_finished_activities.php'))->up();

    $command = fn (Activity $activity) => Activity::query()->findOrFail($activity->id)->getExtraProperty('command');
    expect($command($finished))->toBeNull()
        ->and($command($failed))->toBeNull()
        ->and($command($stuck))->toBeNull()
        ->and($command($running))->toBe('echo POSTGRES_PASSWORD=secret')
        ->and($command($queued))->toBe('echo POSTGRES_PASSWORD=secret')
        ->and(Activity::query()->findOrFail($finished->id)->getExtraProperty('status'))->toBe(ProcessStatus::FINISHED->value);
});
