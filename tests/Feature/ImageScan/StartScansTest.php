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

                return $server->is(test()->server) && $deployment === null && $wait === ScanResourceImagesJob::LOCK_WAIT_SECONDS;
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

        expect($job->tries)->toBe(3)
            ->and(Cache::has(ScanImage::lastErrorCacheKey($web)))->toBeTrue();
    });

    it('does not retry a job that was not queued by a start', function () {
        $service = startScansService();
        startScansServiceApplication($service, 'nginx:1.27');
        ScanImage::shouldRun()->once()->andReturnUsing(fn ($server, $resource, $ref) => startScansMissingScan($resource, $ref));

        $job = new ScanResourceImagesJob($service);
        startScansQueuedJob($job, 1)->shouldReceive('release')->never();
        $job->handle();

        expect($job->tries)->toBe(1);
    });

    it('queues the start job with retries', function () {
        ScanResourceImagesJob::dispatchAfterStart(startScansService());

        Queue::assertPushed(ScanResourceImagesJob::class, fn (ScanResourceImagesJob $job) => $job->retryWhenImageMissing && $job->tries === 3);
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
