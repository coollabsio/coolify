<?php

use App\Actions\Database\StartPostgresql;
use App\Actions\ImageScan\ScanImage;
use App\Actions\Service\StartService;
use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanStatus;
use App\Jobs\ScanResourceImagesJob;
use App\Models\Environment;
use App\Models\ImageScan;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\DatabaseStartCommandExecutor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    InstanceSettings::forceCreate(['id' => 0, 'is_image_scanning_allowed' => true]);
    Process::fake();
    Queue::fake();

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    app()->instance(DatabaseStartCommandExecutor::class, new class
    {
        public function execute(array $commands, $database, Activity $activity): Activity
        {
            return $activity;
        }
    });
});

function startScansService(): Service
{
    return Service::factory()->create([
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
}

function startScansServiceApplication(Service $service, string $image, array $attributes = []): ServiceApplication
{
    return ServiceApplication::forceCreate([
        'uuid' => (string) Str::uuid(),
        'service_id' => $service->id,
        'name' => 'web-'.Str::random(5),
        'image' => $image,
        ...$attributes,
    ]);
}

function startScansServiceDatabase(Service $service, string $image, array $attributes = []): ServiceDatabase
{
    return ServiceDatabase::forceCreate([
        'uuid' => (string) Str::uuid(),
        'service_id' => $service->id,
        'name' => 'db-'.Str::random(5),
        'image' => $image,
        ...$attributes,
    ]);
}

describe('standalone database start', function () {
    it('queues a delayed scan of the database image when scanning is on', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination);

        StartPostgresql::run($database->fresh(), new Activity);

        Queue::assertPushed(ScanResourceImagesJob::class, fn (ScanResourceImagesJob $job) => $job->resource->is($database)
            && $job->delay !== null
            && $job->imageRefsFor($job->resource) === [$database->image]);
    });

    it('queues no scan when scanning is off for the server', function () {
        $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();
        $database = create_standalone_postgresql($this->environment->id, $this->destination);

        StartPostgresql::run($database->fresh(), new Activity);

        Queue::assertNotPushed(ScanResourceImagesJob::class);
    });

    it('queues a scan for the instance database with id 0', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination);
        $database->forceFill(['id' => 0])->saveQuietly();
        $instanceDatabase = $database->newQuery()->find(0);

        expect($instanceDatabase)->not->toBeNull();

        ScanResourceImagesJob::dispatchAfterStart($instanceDatabase);

        Queue::assertPushed(ScanResourceImagesJob::class, fn (ScanResourceImagesJob $job) => (int) $job->resource->getKey() === 0);
    });

    it('does not fail when the scan cannot be queued', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination);
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
        app()->instance(Dispatcher::class, $bus);

        expect(fn () => ScanResourceImagesJob::dispatchAfterStart($database))->not->toThrow(RuntimeException::class);
    });
});

describe('service start', function () {
    it('queues one delayed scan job for the service when scanning is on', function () {
        $service = startScansService();
        startScansServiceApplication($service, 'nginx:1.27');

        ScanResourceImagesJob::dispatchAfterStart($service);

        Queue::assertPushed(ScanResourceImagesJob::class, 1);
        Queue::assertPushed(ScanResourceImagesJob::class, fn (ScanResourceImagesJob $job) => $job->resource->is($service) && $job->delay !== null);
    });

    it('queues no scan when scanning is off', function () {
        $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();
        $service = startScansService();

        ScanResourceImagesJob::dispatchAfterStart($service);

        Queue::assertNotPushed(ScanResourceImagesJob::class);
    });

    it('scans every sub-resource image once, each as its own resource', function () {
        $service = startScansService();
        $app = startScansServiceApplication($service, 'nginx:1.27');
        $db = startScansServiceDatabase($service, 'nginx:1.27');
        startScansServiceApplication($service, '');
        startScansServiceApplication($service, 'redis:7', ['exclude_from_status' => true]);
        $scanned = [];
        ScanImage::shouldRun()->twice()
            ->withArgs(function ($server, $resource, $ref, $deployment, $wait) use (&$scanned) {
                $scanned[] = [$resource::class, $resource->getKey(), $ref];

                return $server->is(test()->server) && $deployment === null && $wait === 0;
            })
            ->andReturn(new ImageScan);

        (new ScanResourceImagesJob($service))->handle();

        expect($scanned)->toBe([
            [ServiceApplication::class, $app->id, 'nginx:1.27'],
            [ServiceDatabase::class, $db->id, 'nginx:1.27'],
        ]);
    });

    it('scans the database image in the job', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination);
        ScanImage::shouldRun()->once()
            ->withArgs(fn ($server, $resource, $ref) => $resource->is($database) && $ref === $database->image)
            ->andReturn(new ImageScan);

        (new ScanResourceImagesJob($database))->handle();
    });

    it('queues the scan when StartService queues the start commands', function () {
        $service = startScansService();
        startScansServiceApplication($service, 'nginx:1.27');

        StartService::run($service);

        Queue::assertPushed(ScanResourceImagesJob::class, fn (ScanResourceImagesJob $job) => $job->resource->is($service));
    });
});

describe('image not pulled yet', function () {
    function startScansMissingScan(Model $resource, string $ref): ImageScan
    {
        Cache::put(ScanImage::lastErrorCacheKey($resource), ['code' => 'image_missing']);

        return (new ImageScan)->forceFill(['status' => ImageScanStatus::Failed, 'error_code' => ImageScanErrorCode::ImageMissing, 'image_ref' => $ref]);
    }

    function startScansQueuedJob(ScanResourceImagesJob $scanJob, int $attempt): Job
    {
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('uuid')->andReturn('job-uuid');
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $scanJob->setJob($queueJob);

        return $queueJob;
    }

    it('retries only the images that are missing and keeps the error hidden until the last attempt', function () {
        $service = startScansService();
        $web = startScansServiceApplication($service, 'nginx:1.27');
        $db = startScansServiceDatabase($service, 'postgres:16');
        $calls = [];
        $pulled = false;
        ScanImage::shouldRun()->times(3)
            ->withArgs(function ($server, $resource, $ref) use (&$calls) {
                $calls[] = [$resource->getKey(), $ref];

                return true;
            })
            ->andReturnUsing(function ($server, $resource, $ref) use (&$pulled) {
                if ($ref === 'nginx:1.27' && ! $pulled) {
                    $pulled = true;

                    return startScansMissingScan($resource, $ref);
                }

                return new ImageScan;
            });

        $first = new ScanResourceImagesJob($service, retryWhenImageMissing: true);
        $queueJob = startScansQueuedJob($first, 1);
        $queueJob->shouldReceive('release')->once()->with(120);
        $first->handle();

        expect(Cache::has(ScanImage::lastErrorCacheKey($web)))->toBeFalse();

        $second = new ScanResourceImagesJob($service, retryWhenImageMissing: true);
        $queueJob = startScansQueuedJob($second, 2);
        $queueJob->shouldReceive('release')->never();
        $second->handle();

        expect($calls)->toBe([[$web->id, 'nginx:1.27'], [$db->id, 'postgres:16'], [$web->id, 'nginx:1.27']]);
    });

    it('releases at most twice and leaves the error on the last attempt', function () {
        $service = startScansService();
        $web = startScansServiceApplication($service, 'nginx:1.27');
        ScanImage::shouldRun()->once()->andReturnUsing(fn ($server, $resource, $ref) => startScansMissingScan($resource, $ref));

        $job = new ScanResourceImagesJob($service, retryWhenImageMissing: true);
        startScansQueuedJob($job, 3)->shouldReceive('release')->never();
        $job->handle();

        expect(ScanResourceImagesJob::START_MAX_ATTEMPTS)->toBe(3)
            ->and(Cache::has(ScanImage::lastErrorCacheKey($web)))->toBeTrue();
    });

    it('does not retry a job that was not queued by a start', function () {
        $service = startScansService();
        startScansServiceApplication($service, 'nginx:1.27');
        ScanImage::shouldRun()->once()->andReturnUsing(fn ($server, $resource, $ref) => startScansMissingScan($resource, $ref));

        $job = new ScanResourceImagesJob($service);
        startScansQueuedJob($job, 1)->shouldReceive('release')->never();
        $job->handle();

        expect($job->retryWhenImageMissing)->toBeFalse();
    });

    it('queues the start job with retries', function () {
        ScanResourceImagesJob::dispatchAfterStart(startScansService());

        Queue::assertPushed(ScanResourceImagesJob::class, fn (ScanResourceImagesJob $job) => $job->retryWhenImageMissing && $job->tries === ScanResourceImagesJob::MAX_ATTEMPTS);
    });

    it('uses the server of the scan policy when the service has no destination', function () {
        $service = startScansService();
        $service->forceFill(['destination_id' => 999999])->saveQuietly();
        startScansServiceApplication($service, 'nginx:1.27');
        ScanImage::shouldRun()->once()
            ->withArgs(fn ($server) => $server->is(test()->server))
            ->andReturn(new ImageScan);

        (new ScanResourceImagesJob($service->fresh()))->handle();
    });
});

describe('busy scan lock', function () {
    it('does not wait for a busy lock and runs again later with a growing delay', function (int $attempt, int $delay) {
        $service = startScansService();
        $web = startScansServiceApplication($service, 'nginx:1.27');
        $lock = ScanImage::serverLock($this->server);
        $lock->get();

        $job = new ScanResourceImagesJob($service);
        startScansQueuedJob($job, $attempt)->shouldReceive('release')->once()->with($delay);
        $job->handle();
        $lock->release();

        expect(Cache::has(ScanImage::lastErrorCacheKey($web)))->toBeFalse();
        Process::assertNothingRan();
    })->with([
        'first attempt' => [1, 60],
        'second attempt' => [2, 120],
        'third attempt' => [3, 300],
        'later attempt' => [6, 300],
    ]);

    it('keeps the images that were scanned before the lock was busy', function () {
        $service = startScansService();
        startScansServiceApplication($service, 'nginx:1.27');
        startScansServiceApplication($service, 'redis:7');
        $scanned = [];
        ScanImage::shouldRun()->times(3)
            ->andReturnUsing(function ($server, $resource, $ref) use (&$scanned) {
                $scanned[] = $ref;

                return count($scanned) === 2
                    ? (new ImageScan)->forceFill(['status' => ImageScanStatus::Failed, 'error_code' => ImageScanErrorCode::LockBusy])
                    : new ImageScan;
            });

        $first = new ScanResourceImagesJob($service);
        startScansQueuedJob($first, 1)->shouldReceive('release')->once()->with(60);
        $first->handle();
        $second = new ScanResourceImagesJob($service);
        startScansQueuedJob($second, 2)->shouldReceive('release')->never();
        $second->handle();

        expect($scanned)->toBe(['nginx:1.27', 'redis:7', 'redis:7']);
    });

    it('leaves a free lock to a deploy gate that waits for it', function () {
        $service = startScansService();
        startScansServiceApplication($service, 'nginx:1.27');
        Cache::put(ScanImage::gateWaitingCacheKey($this->server), true, 120);

        $job = new ScanResourceImagesJob($service);
        startScansQueuedJob($job, 1)->shouldReceive('release')->once()->with(60);
        $job->handle();

        expect(ScanImage::serverLock($this->server)->get())->toBeTrue();
        Process::assertNothingRan();
    });

    it('records the busy lock on the last attempt', function () {
        $service = startScansService();
        $web = startScansServiceApplication($service, 'nginx:1.27');
        $lock = ScanImage::serverLock($this->server);
        $lock->get();

        $job = new ScanResourceImagesJob($service);
        startScansQueuedJob($job, ScanResourceImagesJob::MAX_ATTEMPTS)->shouldReceive('release')->never();
        $job->handle();
        $lock->release();

        expect(data_get(Cache::get(ScanImage::lastErrorCacheKey($web)), 'code'))->toBe(ImageScanErrorCode::LockBusy->value);
    });

    it('marks a deploy gate that waits for the lock so background scans back off', function () {
        $gateWaiting = null;
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnFalse();
        $lock->shouldReceive('block')->once()->with(120)->andReturnUsing(function () use (&$gateWaiting) {
            $gateWaiting = ScanImage::isGateWaiting(test()->server);

            return true;
        });

        expect(ScanImage::acquireServerLock($lock, $this->server, ScanImage::GATE_LOCK_WAIT_SECONDS))->toBeTrue()
            ->and($gateWaiting)->toBeTrue()
            ->and(ScanImage::isGateWaiting($this->server))->toBeFalse();
    });

    it('does not mark a deploy gate that gets the lock right away', function () {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('block')->never();
        Cache::spy();

        expect(ScanImage::acquireServerLock($lock, $this->server, ScanImage::GATE_LOCK_WAIT_SECONDS))->toBeTrue();
        Cache::shouldNotHaveReceived('put');
    });
});
