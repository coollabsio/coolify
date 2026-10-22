<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function cleanupTestActivity(int $ageInDays): Activity
{
    $activity = activity()->withProperties(['type' => 'inline', 'status' => 'finished'])->event('inline')->log('[]');
    Activity::query()->whereKey($activity->id)->toBase()->update(['created_at' => now()->subDays($ageInDays)]);

    return $activity;
}

beforeEach(function () {
    $this->old = collect(range(1, 12))->map(fn () => cleanupTestActivity(61));
    $this->recent = collect(range(1, 2))->map(fn () => cleanupTestActivity(5));
});

it('reports the real number of old activities in a dry run and deletes nothing', function () {
    $this->artisan('cleanup:database')
        ->expectsOutputToContain('Delete 12 entries from activity_log.')
        ->assertSuccessful();

    expect(Activity::query()->count())->toBe(14);
});

it('deletes all activities older than the retention and keeps newer ones', function () {
    $this->artisan('cleanup:database --yes')
        ->expectsOutputToContain('Delete 12 entries from activity_log.')
        ->assertSuccessful();

    expect(Activity::query()->pluck('id')->sort()->values()->all())
        ->toBe($this->recent->pluck('id')->sort()->values()->all());
});
