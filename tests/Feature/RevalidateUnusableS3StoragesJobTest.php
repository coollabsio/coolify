<?php

use App\Jobs\RevalidateUnusableS3StoragesJob;
use App\Models\S3Storage;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function revalidationStorage(Team $team, array $attributes = []): S3Storage
{
    return S3Storage::create(array_merge([
        'name' => 'Storage',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://93.184.216.34',
        'team_id' => $team->id,
        'is_usable' => false,
        'unusable_email_sent' => true,
    ], $attributes));
}

it('marks an unusable storage as usable when the connection works again', function () {
    $storage = revalidationStorage(Team::factory()->create());
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('files')->once()->andReturn([]);
    Storage::shouldReceive('build')->once()->andReturn($disk);

    (new RevalidateUnusableS3StoragesJob)->handle();

    $storage->refresh();
    expect($storage->is_usable)->toBeTrue()
        ->and($storage->unusable_email_sent)->toBeFalsy();
});

it('keeps a storage unusable when the connection still fails and continues with the next one', function () {
    $team = Team::factory()->create();
    $failing = revalidationStorage($team, ['bucket' => 'failing-bucket']);
    $working = revalidationStorage($team, ['bucket' => 'working-bucket']);
    $failingDisk = Mockery::mock(FilesystemAdapter::class);
    $failingDisk->shouldReceive('files')->once()->andThrow(new RuntimeException('Access denied'));
    $workingDisk = Mockery::mock(FilesystemAdapter::class);
    $workingDisk->shouldReceive('files')->once()->andReturn([]);
    Storage::shouldReceive('build')->twice()->andReturnUsing(
        fn (array $config) => $config['bucket'] === 'failing-bucket' ? $failingDisk : $workingDisk
    );

    (new RevalidateUnusableS3StoragesJob)->handle();

    expect($failing->fresh()->is_usable)->toBeFalse()
        ->and($working->fresh()->is_usable)->toBeTrue();
});

it('does not test storages that are already usable', function () {
    revalidationStorage(Team::factory()->create(), ['is_usable' => true]);
    Storage::shouldReceive('build')->never();

    (new RevalidateUnusableS3StoragesJob)->handle();
});

it('includes the storage with id 0 on the first run', function () {
    $storage = revalidationStorage(Team::factory()->create());
    S3Storage::query()->whereKey($storage->id)->update(['id' => 0]);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('files')->once()->andReturn([]);
    Storage::shouldReceive('build')->once()->andReturn($disk);

    (new RevalidateUnusableS3StoragesJob)->handle();

    expect(S3Storage::find(0)->is_usable)->toBeTrue();
});

it('stops when the time budget is used and continues with the next storage on the next run', function () {
    $team = Team::factory()->create();
    $first = revalidationStorage($team, ['bucket' => 'first-bucket']);
    $second = revalidationStorage($team, ['bucket' => 'second-bucket']);
    $tested = [];
    Storage::shouldReceive('build')->andReturnUsing(function (array $config) use (&$tested) {
        $tested[] = $config['bucket'];
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('files')->andReturnUsing(function () {
            // A slow endpoint uses the whole time budget of the run.
            Carbon::setTestNow(now()->addSeconds(RevalidateUnusableS3StoragesJob::TIME_BUDGET_SECONDS + 1));

            throw new RuntimeException('Connection timed out');
        });

        return $disk;
    });

    (new RevalidateUnusableS3StoragesJob)->handle();
    expect($tested)->toBe(['first-bucket']);

    (new RevalidateUnusableS3StoragesJob)->handle();
    expect($tested)->toBe(['first-bucket', 'second-bucket']);

    // After the last storage, the next run starts from the beginning again.
    (new RevalidateUnusableS3StoragesJob)->handle();
    expect($tested)->toBe(['first-bucket', 'second-bucket', 'first-bucket'])
        ->and($first->fresh()->is_usable)->toBeFalse()
        ->and($second->fresh()->is_usable)->toBeFalse();

    Carbon::setTestNow();
});
