<?php

use App\Actions\ImageScan\ScanImage;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanStatus;
use App\Jobs\ImageRescanJob;
use App\Jobs\PrepareImageScannerJob;
use App\Jobs\RefreshCandidatesJob;
use App\Jobs\RefreshImageScanSummariesJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\ImageSbom;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\ImageScanNotificationState;
use App\Models\ImageScanPolicy;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Services\ImageScan\ResourceScans;
use App\Services\ImageScan\ScannerCommands;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const RESCAN_TEST_HEX = 'b1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

const RESCAN_TEST_OTHER_HEX = 'c1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.max_retries' => 1]);
    InstanceSettings::forceCreate(['id' => 0, 'is_image_scanning_allowed' => true]);

    $this->team = Team::factory()->create();
    $this->server = rescanTestServer($this->team);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = rescanTestApplication();
    $this->otherApplication = rescanTestApplication();
    $this->containers = [];
});

function rescanTestServer(Team $team, bool $enabled = true): Server
{
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => (PrivateKey::query()->where('team_id', $team->id)->first() ?? PrivateKey::factory()->create(['team_id' => $team->id]))->id,
    ]);
    $server->settings->forceFill(['is_reachable' => true, 'is_usable' => true, 'is_image_scan_enabled' => $enabled])->saveQuietly();

    return $server->fresh();
}

function rescanTestApplication(?StandaloneDocker $destination = null): Application
{
    $destination ??= test()->destination;

    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);
}

/**
 * A running container as the container listing prints it.
 *
 * @param  array<string, string>  $labels
 */
function rescanTestContainer(string $hex, string $ref, array $labels): void
{
    test()->containers = [...test()->containers, json_encode(['id' => 'sha256:'.$hex, 'ref' => $ref, 'labels' => $labels])];
}

function rescanTestApplicationContainer(Application $application, string $hex = RESCAN_TEST_HEX, int $pullRequestId = 0, string $tag = 'abc123'): void
{
    rescanTestContainer($hex, "{$application->uuid}:{$tag}", [
        'coolify.managed' => 'true',
        'coolify.type' => 'application',
        'coolify.applicationId' => (string) $application->id,
        'coolify.applicationUuid' => $application->uuid,
        'coolify.pullRequestId' => (string) $pullRequestId,
    ]);
}

/**
 * Fake server output per command, in the marker/separator format of ScannerCommands.
 *
 * @param  array<string, string|Closure>  $overrides
 */
function rescanTestFake(array $overrides = []): void
{
    $outputs = array_merge([
        'db-update' => 'coolify-scan-exit db-update 0',
        'db-status' => fn () => rescanTestDatabaseStatus(now()->subDay()),
        'containers' => fn () => "coolify-scan-exit containers 0\ncoolify-scan-payload\n".implode("\n", test()->containers),
        'inspect' => fn (string $command) => preg_match('/sha256:([a-f0-9]{64})/', $command, $id) === 1
            ? "coolify-scan-exit inspect 0\ncoolify-scan-payload\nsha256:{$id[1]}||linux/amd64|85000000"
            : 'coolify-scan-exit inspect 1',
        'disk' => '50000000000 50000000000',
        'sbom' => "coolify-scan-exit save 0\ncoolify-scan-exit sbom 0",
        'packages' => rescanTestPayload('packages', file_get_contents(base_path('tests/Fixtures/image-scan/syft-packages.ndjson'))),
        'grype' => rescanTestPayload('grype', file_get_contents(base_path('tests/Fixtures/image-scan/grype.ndjson'))),
        'sbom-delete' => 'coolify-scan-exit sbom-delete 0',
        'disk-usage' => '',
    ], $overrides);

    Process::fake(function (PendingProcess $process) use ($outputs) {
        $key = rescanTestCommandKey(remoteProcessScript($process));
        $output = $key === null ? '' : $outputs[$key];

        return Process::result(output: $output instanceof Closure ? $output(remoteProcessScript($process)) : $output);
    });
}

function rescanTestDatabaseStatus(DateTimeInterface $builtAt): string
{
    return "coolify-scan-exit db-status 0 180\ncoolify-scan-payload\n".json_encode([
        'schemaVersion' => 'v6.1.10',
        'built' => $builtAt->format('Y-m-d\TH:i:s\Z'),
        'path' => '/db/6/vulnerability.db',
        'valid' => true,
    ]);
}

function rescanTestPayload(string $step, string $payload, int $code = 0): string
{
    return "coolify-scan-exit {$step} {$code} ".strlen($payload)."\ncoolify-scan-payload\n{$payload}";
}

function rescanTestCommandKey(string $command): ?string
{
    return match (true) {
        str_contains($command, 'coolify-scan-exit db-update') => 'db-update',
        str_contains($command, 'coolify-scan-exit db-status') => 'db-status',
        str_contains($command, 'coolify-scan-exit containers') => 'containers',
        str_contains($command, 'coolify-scan-exit sbom-delete') => 'sbom-delete',
        str_contains($command, 'coolify-scan-exit disk-db') => 'disk-usage',
        str_contains($command, 'docker image inspect') => 'inspect',
        str_contains($command, 'df -P') => 'disk',
        str_contains($command, 'docker save') => 'sbom',
        str_contains($command, 'coolify-scan-exit packages') => 'packages',
        str_contains($command, 'coolify-scan-exit grype') => 'grype',
        default => null,
    };
}

function rescanTestQueuedJob(ImageRescanJob $rescanJob, int $attempt): Job
{
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('uuid')->andReturn('rescan-job-uuid');
    $queueJob->shouldReceive('attempts')->andReturn($attempt);
    $rescanJob->setJob($queueJob);

    return $queueJob;
}

function rescanTestRun(?Server $server = null): void
{
    (new ImageRescanJob($server ?? test()->server))->handle();
}

function rescanTestRanCommand(string $needle): int
{
    $count = 0;
    Process::assertRan(function (PendingProcess $process) use ($needle, &$count) {
        if (str_contains(remoteProcessScript($process), $needle)) {
            $count++;
        }

        return true;
    });

    return $count;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function rescanTestScan(Model $resource, array $attributes = []): ImageScan
{
    return ImageScan::factory()->create([
        'server_id' => test()->server->id,
        'resource_type' => $resource->getMorphClass(),
        'resource_id' => $resource->getKey(),
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function rescanTestDeployment(Application $application, string $commit, ApplicationDeploymentStatus $status, int $pullRequestId = 0, array $attributes = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'deployment_uuid' => Str::random(12),
        'application_id' => $application->id,
        'server_id' => test()->server->id,
        'status' => $status->value,
        'commit' => $commit,
        'pull_request_id' => $pullRequestId,
        ...$attributes,
    ]);
}

describe('when the job runs', function () {
    it('runs on the scan queue and once per server at a time', function () {
        $job = new ImageRescanJob($this->server);

        expect($job->queue)->toBe('default')
            ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
            ->and($job->uniqueId())->toBe((string) $this->server->id);
    });

    it('skips a server that cannot be scanned', function (Closure $setUp) {
        rescanTestFake();
        rescanTestApplicationContainer($this->application);
        $setUp($this->server);

        rescanTestRun(Server::query()->findOrFail($this->server->id));

        Process::assertNothingRan();
    })->with([
        'scanning off' => [fn (Server $server) => $server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly()],
        'not allowed on the instance' => [fn () => InstanceSettings::query()->whereKey(0)->update(['is_image_scanning_allowed' => false])],
        'unreachable' => [fn (Server $server) => $server->settings->forceFill(['is_reachable' => false])->saveQuietly()],
        'transferred away' => [fn (Server $server) => $server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly()],
    ]);
});

describe('rescan', function () {
    it('adds a rescanned image to the current scans of the latest finished deployment', function () {
        rescanTestFake();
        $deployment = rescanTestDeployment($this->application, 'abc123', ApplicationDeploymentStatus::FINISHED);
        rescanTestScan($this->application, [
            'image_id' => 'sha256:'.RESCAN_TEST_HEX,
            'image_ref' => 'web:1',
            'deployment_queue_id' => $deployment->id,
            'scanned_at' => now()->subDay(),
        ]);
        rescanTestApplicationContainer($this->application, RESCAN_TEST_HEX);
        rescanTestApplicationContainer($this->application, RESCAN_TEST_OTHER_HEX, tag: 'worker');

        rescanTestRun();

        expect(ResourceScans::currentFor($this->application)->pluck('image_id')->sort()->values()->all())
            ->toBe(['sha256:'.RESCAN_TEST_HEX, 'sha256:'.RESCAN_TEST_OTHER_HEX]);
    });

    it('records every image of a Compose application as the baseline when scanning is turned on', function () {
        $hexes = [RESCAN_TEST_HEX, RESCAN_TEST_OTHER_HEX, 'd1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90'];
        rescanTestFake(['grype' => function (string $command) use ($hexes) {
            $index = collect($hexes)->search(fn (string $hex) => str_contains($command, $hex));
            $finding = ['art' => 'a'.$index, 'desc' => '', 'epss' => 0.5, 'fix' => 'fixed', 'fixv' => ['2.0'], 'id' => 'CVE-2026-100'.$index,
                'kev' => true, 'pkg' => 'lib'.$index, 'rel' => [], 'risk' => 90.0, 'sev' => 'Critical', 'sugg' => '2.0', 'type' => 'apk', 'ver' => '1.0'];

            return rescanTestPayload('grype', json_encode($finding));
        }]);
        Notification::fake();
        rescanTestDeployment($this->application, 'abc123', ApplicationDeploymentStatus::FINISHED);
        foreach ($hexes as $index => $hex) {
            rescanTestApplicationContainer($this->application, $hex, tag: 'service-'.$index);
        }

        rescanTestRun();

        $state = ImageScanNotificationState::query()->where('resource_id', $this->application->id)->sole();
        Notification::assertNothingSent();
        expect(ImageScan::query()->where('status', ImageScanStatus::Completed)->count())->toBe(3)
            ->and($state->is_baseline)->toBeTrue()
            ->and(collect($state->notified_ids)->sort()->values()->all())->toBe(['CVE-2026-1000', 'CVE-2026-1001', 'CVE-2026-1002']);
    });

    it('runs Grype once for resources that share an image ID and updates each row', function () {
        rescanTestFake();
        $deployment = rescanTestDeployment($this->application, 'abc123', ApplicationDeploymentStatus::FINISHED);
        $existing = rescanTestScan($this->application, [
            'image_id' => 'sha256:'.RESCAN_TEST_HEX,
            'deployment_queue_id' => $deployment->id,
            'findings' => [],
            'is_baseline' => true,
            'scanned_at' => now()->subDays(2),
        ]);
        rescanTestApplicationContainer($this->application);
        rescanTestApplicationContainer($this->otherApplication);

        rescanTestRun();

        expect(rescanTestRanCommand('coolify-scan-exit grype'))->toBe(1)
            ->and(rescanTestRanCommand('docker save'))->toBe(1)
            ->and(rescanTestRanCommand('coolify-scan-exit db-update'))->toBe(1);

        $existing->refresh();
        expect($existing->status)->toBe(ImageScanStatus::Completed)
            ->and($existing->findings)->toHaveCount(166)
            ->and($existing->deployment_queue_id)->toBe($deployment->id)
            ->and($existing->is_baseline)->toBeFalse()
            ->and($existing->scanned_at->isToday())->toBeTrue()
            ->and($existing->grype_db_built_at)->not->toBeNull();

        $created = $this->otherApplication->imageScans()->sole();
        expect($created->status)->toBe(ImageScanStatus::Completed)
            ->and($created->image_id)->toBe('sha256:'.RESCAN_TEST_HEX)
            ->and($created->server_id)->toBe($this->server->id)
            ->and($created->image_sbom_id)->toBe($existing->image_sbom_id)
            ->and($created->findings)->toHaveCount(166)
            ->and($created->is_baseline)->toBeTrue()
            ->and($created->summary)->toBeArray();
    });

    it('never changes a scan of the same image on another server', function () {
        rescanTestFake();
        $otherTeam = Team::factory()->create();
        $otherServer = rescanTestServer($otherTeam);
        $otherRow = ImageScan::factory()->create([
            'server_id' => $otherServer->id,
            'image_id' => 'sha256:'.RESCAN_TEST_HEX,
            'findings' => [],
            'summary' => ['counts' => []],
            'scanned_at' => now()->subDays(3),
        ]);
        rescanTestApplicationContainer($this->application);

        rescanTestRun();

        $fresh = $otherRow->fresh();
        expect($fresh->findings)->toBe([])
            ->and($fresh->summary)->toBe(['counts' => []])
            ->and($fresh->scanned_at->toDateString())->toBe(now()->subDays(3)->toDateString())
            ->and($this->application->imageScans()->sole()->server_id)->toBe($this->server->id);
    });

    it('maps service, database, and preview containers and skips containers of no resource on this server', function () {
        rescanTestFake();
        $service = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        $serviceApplication = ServiceApplication::forceCreate([
            'uuid' => (string) Str::uuid(),
            'service_id' => $service->id,
            'name' => 'web',
            'image' => 'nginx:1.27',
        ]);
        $database = StandalonePostgresql::forceCreate([
            'name' => 'pg',
            'postgres_password' => 'secret',
            'image' => 'postgres:16',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        ApplicationPreview::forceCreate([
            'application_id' => $this->application->id,
            'pull_request_id' => 7,
            'pull_request_html_url' => 'https://example.com/pull/7',
        ]);
        $foreignServer = rescanTestServer($this->team);
        $foreignApplication = rescanTestApplication(StandaloneDocker::query()->where('server_id', $foreignServer->id)->firstOrFail());

        rescanTestContainer(RESCAN_TEST_HEX, 'nginx:1.27', [
            'coolify.type' => 'service',
            'coolify.serviceId' => (string) $service->id,
            'coolify.serviceUuid' => $service->uuid,
            'coolify.service.subType' => 'application',
            'coolify.service.subUuid' => $serviceApplication->uuid,
            'com.docker.compose.service' => 'web',
        ]);
        rescanTestContainer(RESCAN_TEST_HEX, 'postgres:16', [
            'coolify.type' => 'database',
            'coolify.databaseId' => (string) $database->id,
            'coolify.databaseUuid' => $database->uuid,
        ]);
        rescanTestApplicationContainer($this->application, RESCAN_TEST_HEX, 7);
        rescanTestApplicationContainer($this->otherApplication, RESCAN_TEST_OTHER_HEX, 8);
        rescanTestApplicationContainer($foreignApplication, RESCAN_TEST_OTHER_HEX);
        rescanTestContainer(RESCAN_TEST_OTHER_HEX, 'traefik:v3', ['coolify.managed' => 'true']);

        rescanTestRun();

        expect($serviceApplication->imageScans()->sole()->status)->toBe(ImageScanStatus::Completed)
            ->and($database->imageScans()->sole()->status)->toBe(ImageScanStatus::Completed)
            ->and($this->application->imageScans()->sole()->image_ref)->toBe("{$this->application->uuid}:abc123")
            ->and($this->otherApplication->imageScans()->count())->toBe(0)
            ->and($foreignApplication->imageScans()->count())->toBe(0)
            ->and(rescanTestRanCommand('sha256:'.RESCAN_TEST_OTHER_HEX))->toBe(0)
            ->and(rescanTestRanCommand('coolify-scan-exit grype'))->toBe(1);
    });

    it('scans with the existing database when the database update fails', function () {
        rescanTestFake(['db-update' => 'coolify-scan-exit db-update 1']);
        rescanTestApplicationContainer($this->application);

        rescanTestRun();

        expect($this->application->imageScans()->sole()->status)->toBe(ImageScanStatus::Completed)
            ->and(Cache::get(PrepareImageScannerJob::builtAtCacheKey($this->server)))->toBeNull();
    });

    it('refreshes the database date and the disk use on the server page after a database update', function () {
        $builtAt = now()->subHours(3)->startOfSecond();
        rescanTestFake([
            'db-status' => fn () => rescanTestDatabaseStatus($builtAt),
            'disk-usage' => "coolify-scan-exit disk-db 0 1000\ncoolify-scan-exit disk-sboms 0 24",
        ]);

        rescanTestRun();

        expect(Cache::get(PrepareImageScannerJob::builtAtCacheKey($this->server)))->toBe($builtAt->toIso8601ZuluString())
            ->and(Cache::get(PrepareImageScannerJob::diskUsedCacheKey($this->server)))->toBe(1024);
    });

    it('keeps the results and records the error for each resource when the database is too old', function () {
        rescanTestFake([
            'db-update' => 'coolify-scan-exit db-update 1',
            'db-status' => fn () => rescanTestDatabaseStatus(now()->subDays(8)),
        ]);
        $existing = rescanTestScan($this->application, ['image_id' => 'sha256:'.RESCAN_TEST_HEX, 'findings' => [], 'scanned_at' => now()->subDays(2)]);
        rescanTestApplicationContainer($this->application);
        rescanTestApplicationContainer($this->otherApplication);

        rescanTestRun();

        expect($existing->fresh()->status)->toBe(ImageScanStatus::Completed)
            ->and($existing->fresh()->findings)->toBe([])
            ->and(Cache::get(ScanImage::lastErrorCacheKey($this->application))['code'])->toBe('db_too_old')
            ->and(Cache::get(ScanImage::lastErrorCacheKey($this->otherApplication))['code'])->toBe('db_too_old')
            ->and(rescanTestRanCommand('coolify-scan-exit grype'))->toBe(0);
    });

    it('checks for newer versions of a running image with a relevant finding', function () {
        rescanTestFake();
        $database = StandalonePostgresql::forceCreate([
            'name' => 'pg',
            'postgres_password' => 'secret',
            'image' => 'postgres:15.0-alpine',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        rescanTestContainer(RESCAN_TEST_HEX, 'postgres:15.0-alpine', [
            'coolify.type' => 'database',
            'coolify.databaseId' => (string) $database->id,
            'coolify.databaseUuid' => $database->uuid,
        ]);
        rescanTestApplicationContainer($this->application, RESCAN_TEST_OTHER_HEX);

        rescanTestRun();

        expect(ImageScan::query()->where('resource_type', $database->getMorphClass())->sole()->candidateStatus())->not->toBeNull()
            ->and(ImageScan::query()->where('resource_type', $this->application->getMorphClass())->sole()->candidates)->toBeNull()
            ->and(rescanTestRanCommand("'tag'\\'' '\\''ls'\\'' '\\''docker.io/library/postgres'"))->toBe(1);
    });

    it('skips the candidate check of a scan whose candidate job is queued or running', function () {
        rescanTestFake();
        $database = StandalonePostgresql::forceCreate([
            'name' => 'pg',
            'postgres_password' => 'secret',
            'image' => 'postgres:15.0-alpine',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        $scan = rescanTestScan($database, ['image_id' => 'sha256:'.RESCAN_TEST_HEX, 'image_ref' => 'postgres:15.0-alpine']);
        rescanTestContainer(RESCAN_TEST_HEX, 'postgres:15.0-alpine', [
            'coolify.type' => 'database',
            'coolify.databaseId' => (string) $database->id,
            'coolify.databaseUuid' => $database->uuid,
        ]);
        $uniqueLock = new UniqueLock(Cache::store());
        expect($uniqueLock->acquire(new RefreshCandidatesJob($scan)))->toBeTrue();

        rescanTestRun();

        expect($scan->fresh()->candidates)->toBeNull()
            ->and(rescanTestRanCommand('coolify-scan-exit tags'))->toBe(0);

        $uniqueLock->release(new RefreshCandidatesJob($scan));
        rescanTestRun();

        expect($scan->fresh()->candidateStatus())->not->toBeNull()
            ->and((new UniqueLock(Cache::store()))->acquire(new RefreshCandidatesJob($scan)))->toBeTrue();
    });
});

describe('failed rescans', function () {
    it('counts days in a row on which the rescan of a server failed and resets after a success', function () {
        Carbon::setTestNow('2026-10-20 03:00:00');
        rescanTestFake(['containers' => '']);
        rescanTestRun();
        Carbon::setTestNow('2026-10-21 03:00:00');
        rescanTestRun();

        expect(ImageRescanJob::failedDays($this->server))->toBe(2);

        rescanTestFake();
        rescanTestApplicationContainer($this->application);
        rescanTestRun();

        expect(ImageRescanJob::failedDays($this->server))->toBe(0);
        Carbon::setTestNow();
    });

    it('counts a day on which no running image could be scanned as failed', function () {
        rescanTestFake(['grype' => 'coolify-scan-exit grype 1']);
        rescanTestApplicationContainer($this->application);

        rescanTestRun();

        expect(ImageRescanJob::failedDays($this->server))->toBe(1);
    });
});

describe('ended ignores', function () {
    it('writes the stored counts again for a resource whose ignore ended, also when its image no longer runs', function () {
        rescanTestFake();
        $finding = [
            'id' => 'CVE-2024-44444', 'aliases' => [], 'severity' => 'critical', 'package' => 'openssl', 'version' => '1',
            'type' => 'npm', 'artifactId' => 'a', 'fixedIn' => '2', 'fixState' => 'fixed', 'kev' => false, 'epss' => null,
            'risk' => 1.0, 'summary' => '', 'layer' => 'app',
        ];
        $scan = rescanTestScan($this->otherApplication, ['status' => ImageScanStatus::Completed, 'builds_image' => true, 'findings' => [$finding], 'summary' => []]);
        ImageScanIgnore::factory()->create([
            'team_id' => $this->team->id,
            'resource_type' => $this->otherApplication->getMorphClass(),
            'resource_id' => $this->otherApplication->id,
            'vulnerability_id' => 'CVE-2024-44444',
            'expires_at' => now()->subHour(),
        ]);

        rescanTestRun();

        expect(ResourceScans::storedFixedCount($scan->fresh()))->toBe(1);
    });

    it('queues a refresh of the team counts when a team-wide ignore ended', function () {
        rescanTestFake();
        Queue::fake();
        ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'resource_type' => null, 'resource_id' => null, 'expires_at' => now()->subHour()]);

        rescanTestRun();
        rescanTestRun();

        Queue::assertPushed(RefreshImageScanSummariesJob::class, 1);
    });

    it('queues a refresh of the team counts when a never-ending ignore reached its 90 days', function () {
        rescanTestFake();
        Queue::fake();
        $this->travelTo(now()->subDays(90)->subHour());
        ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'resource_type' => null, 'resource_id' => null, 'expires_at' => null]);
        $this->travelBack();

        rescanTestRun();

        Queue::assertPushed(RefreshImageScanSummariesJob::class, 1);
    });

    it('queues no refresh for a never-ending ignore before its 90 days', function () {
        rescanTestFake();
        Queue::fake();
        $this->travelTo(now()->subDays(89));
        ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'resource_type' => null, 'resource_id' => null, 'expires_at' => null]);
        $this->travelBack();

        rescanTestRun();

        Queue::assertNotPushed(RefreshImageScanSummariesJob::class);
    });
});

describe('retention', function () {
    it('keeps the running image, the rollback list, and the last 5 scans of a resource', function () {
        rescanTestFake(['db-status' => fn () => rescanTestDatabaseStatus(now()->subDays(8))]);
        $this->application->settings->forceFill(['docker_images_to_keep' => 2])->save();
        $third = rescanTestDeployment($this->application, 'c3', ApplicationDeploymentStatus::FINISHED, attributes: ['created_at' => now()->subDays(3)]);
        rescanTestDeployment($this->application, 'c2', ApplicationDeploymentStatus::FINISHED, attributes: ['created_at' => now()->subDays(2)]);
        $first = rescanTestDeployment($this->application, 'c1', ApplicationDeploymentStatus::FINISHED, attributes: ['created_at' => now()->subDay()]);
        rescanTestDeployment($this->application, 'c0', ApplicationDeploymentStatus::FAILED);

        $running = rescanTestScan($this->application, ['image_id' => 'sha256:'.RESCAN_TEST_HEX, 'scanned_at' => now()->subDays(40)]);
        $rollbackByDeployment = rescanTestScan($this->application, ['deployment_queue_id' => $first->id, 'scanned_at' => now()->subDays(39)]);
        $rollbackByTag = rescanTestScan($this->application, ['image_ref' => "{$this->application->uuid}:c2", 'scanned_at' => now()->subDays(38)]);
        $tooOld = rescanTestScan($this->application, ['deployment_queue_id' => $third->id, 'scanned_at' => now()->subDays(37)]);
        $old = rescanTestScan($this->application, ['image_ref' => "{$this->application->uuid}:c0", 'scanned_at' => now()->subDays(36)]);
        $latest = collect(range(1, 5))->map(fn (int $days) => rescanTestScan($this->application, ['scanned_at' => now()->subDays($days)]));
        $otherResource = rescanTestScan($this->otherApplication, ['scanned_at' => now()->subDays(50)]);
        $otherServer = ImageScan::factory()->create([
            'server_id' => rescanTestServer($this->team)->id,
            'resource_type' => $this->application->getMorphClass(),
            'resource_id' => $this->application->id,
            'scanned_at' => now()->subDays(60),
        ]);
        rescanTestApplicationContainer($this->application);

        rescanTestRun();

        $kept = [$running->id, $rollbackByDeployment->id, $rollbackByTag->id, ...$latest->pluck('id'), $otherResource->id, $otherServer->id];
        expect(ImageScan::query()->pluck('id')->sort()->values()->all())->toBe(collect($kept)->sort()->values()->all())
            ->and(ImageScan::query()->whereKey([$tooOld->id, $old->id])->exists())->toBeFalse();
    });

    it('keeps the CleanupDocker rollback images: the latest images to keep besides the running one', function () {
        rescanTestFake(['db-status' => fn () => rescanTestDatabaseStatus(now()->subDays(8))]);
        $this->application->settings->forceFill(['docker_images_to_keep' => 2])->save();
        $rows = collect(['c4', 'c3', 'c2', 'c1'])->mapWithKeys(function (string $commit, int $index) {
            $deployment = rescanTestDeployment($this->application, $commit, ApplicationDeploymentStatus::FINISHED, attributes: ['created_at' => now()->subDays(4 - $index)]);

            return [$commit => rescanTestScan($this->application, [
                'image_id' => $commit === 'c1' ? 'sha256:'.RESCAN_TEST_HEX : 'sha256:'.str_repeat((string) $index, 64),
                'deployment_queue_id' => $deployment->id,
                'scanned_at' => now()->subDays(40 + $index),
            ])];
        });
        collect(range(1, 5))->each(fn (int $days) => rescanTestScan($this->application, ['scanned_at' => now()->subDays($days)]));
        rescanTestApplicationContainer($this->application, tag: 'c1');

        rescanTestRun();

        expect(ImageScan::query()->whereKey([$rows['c1']->id, $rows['c2']->id, $rows['c3']->id])->count())->toBe(3)
            ->and(ImageScan::query()->whereKey($rows['c4']->id)->exists())->toBeFalse();
    });

    it('keeps no rollback images when the server turned off image retention', function () {
        rescanTestFake();
        $this->server->settings->forceFill(['disable_application_image_retention' => true])->saveQuietly();
        $deployment = rescanTestDeployment($this->application, 'c1', ApplicationDeploymentStatus::FINISHED);
        $rollback = rescanTestScan($this->application, ['deployment_queue_id' => $deployment->id, 'scanned_at' => now()->subDays(40)]);
        collect(range(1, 5))->each(fn (int $days) => rescanTestScan($this->application, ['scanned_at' => now()->subDays($days)]));

        rescanTestRun();

        expect(ImageScan::query()->whereKey($rollback->id)->exists())->toBeFalse();
    });

    it('keeps the scan of an image that production runs when a closed preview shared it', function () {
        rescanTestFake(['db-status' => fn () => rescanTestDatabaseStatus(now()->subDays(8))]);
        $shared = rescanTestScan($this->application, [
            'image_id' => 'sha256:'.RESCAN_TEST_HEX,
            'deployment_queue_id' => rescanTestDeployment($this->application, 'p9', ApplicationDeploymentStatus::FINISHED, 9)->id,
            'scanned_at' => now()->subDays(40),
        ]);
        collect(range(1, 5))->each(fn (int $days) => rescanTestScan($this->application, ['scanned_at' => now()->subDays($days)]));
        rescanTestApplicationContainer($this->application);

        rescanTestRun();

        expect(ImageScan::query()->whereKey($shared->id)->exists())->toBeTrue();
    });

    it('deletes nothing when the container list was cut at the output cap', function () {
        rescanTestFake(['containers' => fn () => "coolify-scan-exit containers 0\ncoolify-scan-payload\n".str_repeat('x', ScannerCommands::OUTPUT_LIMIT + 1)]);
        collect(range(1, 7))->each(fn (int $days) => rescanTestScan($this->application, ['scanned_at' => now()->subDays($days)]));
        $unused = ImageSbom::factory()->create(['server_id' => $this->server->id, 'last_used_at' => now()->subDays(31)]);

        rescanTestRun();

        expect(ImageScan::query()->count())->toBe(7)
            ->and(ImageSbom::query()->whereKey($unused->id)->exists())->toBeTrue()
            ->and(rescanTestRanCommand('coolify-scan-exit sbom-delete'))->toBe(0);
    });

    it('skips retention while a scan holds the server lock', function () {
        rescanTestFake();
        collect(range(1, 7))->each(fn (int $days) => rescanTestScan($this->application, ['scanned_at' => now()->subDays($days)]));
        $unused = ImageSbom::factory()->create(['server_id' => $this->server->id, 'last_used_at' => now()->subDays(31)]);
        $lock = ScanImage::serverLock($this->server);
        $lock->get();
        $job = new ImageRescanJob($this->server);
        rescanTestQueuedJob($job, $job->tries)->shouldReceive('release')->never();

        $job->handle();
        $lock->release();

        expect(ImageScan::query()->count())->toBe(7)
            ->and(ImageSbom::query()->whereKey($unused->id)->exists())->toBeTrue();
    });

    it('does not wait for a busy scan lock and runs again later from where it stopped', function () {
        rescanTestFake();
        rescanTestApplicationContainer($this->application);
        $lock = ScanImage::serverLock($this->server);
        $lock->get();

        $first = new ImageRescanJob($this->server);
        rescanTestQueuedJob($first, 1)->shouldReceive('release')->once()->with(60);
        $first->handle();

        Process::assertDidntRun(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'coolify-scan-exit db-update'));
        expect(ImageScan::query()->count())->toBe(0);

        $lock->release();
        $second = new ImageRescanJob($this->server);
        rescanTestQueuedJob($second, 2)->shouldReceive('release')->never();
        $second->handle();

        expect(rescanTestRanCommand('coolify-scan-exit db-update'))->toBe(1)
            ->and(ImageScan::query()->where('status', ImageScanStatus::Completed)->count())->toBe(1)
            ->and(Cache::has('image-rescan:progress:rescan-job-uuid'))->toBeFalse();
    });

    it('rescans an image after a deploy gate that waited for the lock', function () {
        rescanTestFake();
        rescanTestApplicationContainer($this->application);
        Cache::put(ScanImage::gateWaitingCacheKey($this->server), true, 120);

        $job = new ImageRescanJob($this->server);
        rescanTestQueuedJob($job, 1)->shouldReceive('release')->once()->with(60);
        $job->handle();

        Process::assertDidntRun(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'coolify-scan-exit db-update'));
        expect(ScanImage::serverLock($this->server)->get())->toBeTrue();
    });

    it('updates the database under the scan lock', function () {
        $lockHeld = null;
        rescanTestFake(['db-update' => function () use (&$lockHeld) {
            $lockHeld = ! ScanImage::serverLock(test()->server)->get();

            return 'coolify-scan-exit db-update 0';
        }]);

        rescanTestRun();

        expect($lockHeld)->toBeTrue();
    });

    it('deletes the scans of a preview that no longer exists', function () {
        rescanTestFake();
        ApplicationPreview::forceCreate([
            'application_id' => $this->application->id,
            'pull_request_id' => 7,
            'pull_request_html_url' => 'https://example.com/pull/7',
        ]);
        $livePreview = rescanTestScan($this->application, [
            'deployment_queue_id' => rescanTestDeployment($this->application, 'p7', ApplicationDeploymentStatus::FINISHED, 7)->id,
        ]);
        $closedByDeployment = rescanTestScan($this->application, [
            'deployment_queue_id' => rescanTestDeployment($this->application, 'p8', ApplicationDeploymentStatus::FINISHED, 8)->id,
        ]);
        $closedByTag = rescanTestScan($this->application, ['image_ref' => "{$this->application->uuid}:pr-9-abc"]);
        $production = rescanTestScan($this->application, ['image_ref' => "{$this->application->uuid}:abc"]);

        rescanTestRun();

        expect(ImageScan::query()->whereKey($livePreview->id)->exists())->toBeTrue()
            ->and(ImageScan::query()->whereKey($production->id)->exists())->toBeTrue()
            ->and(ImageScan::query()->whereKey([$closedByDeployment->id, $closedByTag->id])->exists())->toBeFalse();
    });

    it('deletes SBOMs that no scan used for 30 days with one command', function () {
        rescanTestFake();
        $unused = ImageSbom::factory()->create(['server_id' => $this->server->id, 'image_id' => 'sha256:'.str_repeat('1', 64), 'last_used_at' => now()->subDays(31)]);
        $alsoUnused = ImageSbom::factory()->create(['server_id' => $this->server->id, 'image_id' => 'sha256:'.str_repeat('2', 64), 'last_used_at' => now()->subDays(45)]);
        $referenced = ImageSbom::factory()->create(['server_id' => $this->server->id, 'last_used_at' => now()->subDays(31)]);
        rescanTestScan($this->application, ['image_sbom_id' => $referenced->id]);
        $recent = ImageSbom::factory()->create(['server_id' => $this->server->id, 'last_used_at' => now()->subDays(10)]);
        $otherServer = ImageSbom::factory()->create(['server_id' => rescanTestServer($this->team)->id, 'last_used_at' => now()->subDays(31)]);

        rescanTestRun();

        expect(ImageSbom::query()->pluck('id')->sort()->values()->all())->toBe(collect([$referenced->id, $recent->id, $otherServer->id])->sort()->values()->all())
            ->and(rescanTestRanCommand('coolify-scan-exit sbom-delete'))->toBe(1);
        Process::assertRan(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'coolify-scan-exit sbom-delete')
            && str_contains(remoteProcessScript($process), '/data/coolify/image-scans/sboms/'.str_repeat('1', 64).'.json')
            && str_contains(remoteProcessScript($process), '/data/coolify/image-scans/sboms/'.str_repeat('2', 64).'.json'));
    });

    it('keeps the SBOM rows when the delete command fails', function () {
        rescanTestFake(['sbom-delete' => 'coolify-scan-exit sbom-delete 1']);
        $unused = ImageSbom::factory()->create(['server_id' => $this->server->id, 'last_used_at' => now()->subDays(31)]);

        rescanTestRun();

        expect(ImageSbom::query()->whereKey($unused->id)->exists())->toBeTrue();
    });

    it('deletes nothing when the running containers cannot be listed', function () {
        rescanTestFake(['containers' => '']);
        $scans = collect(range(1, 7))->map(fn (int $days) => rescanTestScan($this->application, ['scanned_at' => now()->subDays($days)]));

        rescanTestRun();

        expect(ImageScan::query()->count())->toBe(7);
    });
});

describe('orphaned scan settings', function () {
    it('deletes the scan rules and the ignores of a deleted resource', function () {
        $policy = ImageScanPolicy::factory()->create(['scope_type' => $this->application->getMorphClass(), 'scope_id' => $this->application->id]);
        $otherPolicy = ImageScanPolicy::factory()->create(['scope_type' => $this->otherApplication->getMorphClass(), 'scope_id' => $this->otherApplication->id]);
        $ignore = ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'resource_type' => $this->application->getMorphClass(), 'resource_id' => $this->application->id]);
        $teamIgnore = ImageScanIgnore::factory()->create(['team_id' => $this->team->id]);
        $otherIgnore = ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'resource_type' => $this->otherApplication->getMorphClass(), 'resource_id' => $this->otherApplication->id]);

        $this->application->delete();

        expect(ImageScanPolicy::query()->whereKey($policy->id)->exists())->toBeFalse()
            ->and(ImageScanIgnore::query()->whereKey($ignore->id)->exists())->toBeFalse()
            ->and(ImageScanPolicy::query()->whereKey($otherPolicy->id)->exists())->toBeTrue()
            ->and(ImageScanIgnore::query()->whereKey([$teamIgnore->id, $otherIgnore->id])->count())->toBe(2);
    });

    it('deletes the scan rules of a deleted server', function () {
        $policy = ImageScanPolicy::factory()->create(['scope_type' => $this->server->getMorphClass(), 'scope_id' => $this->server->id]);
        $otherServer = rescanTestServer($this->team);
        $otherPolicy = ImageScanPolicy::factory()->create(['scope_type' => $otherServer->getMorphClass(), 'scope_id' => $otherServer->id]);
        $this->application->forceDelete();
        $this->otherApplication->forceDelete();
        Process::fake();

        $this->server->forceDelete();

        expect(ImageScanPolicy::query()->whereKey($policy->id)->exists())->toBeFalse()
            ->and(ImageScanPolicy::query()->whereKey($otherPolicy->id)->exists())->toBeTrue();
    });
});
