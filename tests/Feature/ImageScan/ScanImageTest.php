<?php

use App\Actions\ImageScan\ScanImage;
use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanStatus;
use App\Events\ImageScanUpdated;
use App\Jobs\RefreshCandidatesJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\ImageSbom;
use App\Models\ImageScan;
use App\Models\ImageScanNotificationState;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Notifications\ImageScan\ExploitedProblemFound;
use App\Services\ImageScan\ScannerCommands;
use App\Services\ImageScan\SecurityStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const SCAN_TEST_IMAGE_HEX = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

const SCAN_TEST_REPO_DIGEST = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.max_retries' => 1]);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->server = scanImageTestServer($this->team);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);
});

function scanImageTestServer(Team $team, bool $enabled = true): Server
{
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings->forceFill(['is_reachable' => true, 'is_usable' => true, 'is_image_scan_enabled' => $enabled])->saveQuietly();

    return $server->fresh();
}

/**
 * Fake server output per scanner command, in the marker/separator format of ScannerCommands.
 *
 * @param  array<string, string|Closure>  $overrides
 */
function scanImageTestFake(array $overrides = []): void
{
    $outputs = array_merge([
        'db-status' => "coolify-scan-exit db-status 0 180\ncoolify-scan-payload\n".json_encode([
            'schemaVersion' => 'v6.1.10',
            'built' => now()->subDay()->toIso8601ZuluString(),
            'path' => '/db/6/vulnerability.db',
            'valid' => true,
        ]),
        'db-update' => 'coolify-scan-exit db-update 0',
        'inspect' => "coolify-scan-exit inspect 0\ncoolify-scan-payload\nsha256:".SCAN_TEST_IMAGE_HEX.'|postgres@'.SCAN_TEST_REPO_DIGEST.'|linux/amd64|85000000',
        'disk' => '50000000000 50000000000',
        'sbom' => "coolify-scan-exit save 0\ncoolify-scan-exit sbom 0",
        'packages' => scanImageTestPayload('packages', file_get_contents(base_path('tests/Fixtures/image-scan/syft-packages.ndjson'))),
        'grype' => scanImageTestPayload('grype', file_get_contents(base_path('tests/Fixtures/image-scan/grype.ndjson'))),
    ], $overrides);

    Process::fake(function (PendingProcess $process) use ($outputs) {
        $key = scanImageTestCommandKey($process->command);
        $output = $key === null ? '' : $outputs[$key];

        return Process::result(output: $output instanceof Closure ? $output() : $output);
    });
}

function scanImageTestPayload(string $step, string $payload, ?int $size = null, int $code = 0): string
{
    $size ??= strlen($payload);

    return "coolify-scan-exit {$step} {$code} {$size}\ncoolify-scan-payload\n{$payload}";
}

function scanImageTestCommandKey(string $command): ?string
{
    return match (true) {
        str_contains($command, 'coolify-scan-exit db-status') => 'db-status',
        str_contains($command, 'coolify-scan-exit db-update') => 'db-update',
        str_contains($command, 'docker image inspect') => 'inspect',
        str_contains($command, 'df -P') => 'disk',
        str_contains($command, 'docker save') => 'sbom',
        str_contains($command, 'coolify-scan-exit packages') => 'packages',
        str_contains($command, 'coolify-scan-exit grype') => 'grype',
        default => null,
    };
}

function scanImageTestRun(?Application $application = null, int $lockWaitSeconds = 0): ImageScan
{
    return ScanImage::run(test()->server, $application ?? test()->application, 'postgres:15.0-alpine', null, $lockWaitSeconds);
}

it('stores the findings, the summary, and the package list of a completed scan', function () {
    scanImageTestFake();

    $scan = scanImageTestRun();

    expect($scan->exists)->toBeTrue()
        ->and($scan->status)->toBe(ImageScanStatus::Completed)
        ->and($scan->error_code)->toBeNull()
        ->and($scan->image_id)->toBe('sha256:'.SCAN_TEST_IMAGE_HEX)
        ->and($scan->repo_digest)->toBe(SCAN_TEST_REPO_DIGEST)
        ->and($scan->server_id)->toBe($this->server->id)
        ->and($scan->image_ref)->toBe('postgres:15.0-alpine')
        ->and($scan->builds_image)->toBeTrue()
        ->and($scan->is_baseline)->toBeTrue()
        ->and($scan->grype_db_built_at)->not->toBeNull()
        ->and($scan->scanned_at)->not->toBeNull()
        ->and($scan->findings)->toHaveCount(166)
        ->and($scan->summary['eol_distro'])->toBe(['name' => 'alpine', 'version' => '3.16.2'])
        ->and($scan->summary['truncated'])->toBeFalse()
        ->and($scan->summary['ignored'])->toBe(0)
        ->and($scan->fresh()->summary['security']['status'])->toBe(SecurityStatus::forScan($scan->fresh())->status->value);

    $sbom = ImageSbom::query()->sole();
    expect($scan->image_sbom_id)->toBe($sbom->id)
        ->and($sbom->server_id)->toBe($this->server->id)
        ->and($sbom->image_id)->toBe('sha256:'.SCAN_TEST_IMAGE_HEX)
        ->and($sbom->platform)->toBe('linux/amd64')
        ->and($sbom->packages)->toHaveCount(46)
        ->and(collect($scan->findings)->pluck('layer')->unique()->values()->all())->toBe(['app']);
});

it('stores the base image of a Dockerfile build on the scan', function (string $baseImageRef, ?string $stored) {
    scanImageTestFake();

    $scan = ScanImage::run($this->server, $this->application, 'postgres:15.0-alpine', null, 0, $baseImageRef);

    expect($scan->base_image_ref)->toBe($stored);
})->with([
    'valid' => ['node:20.11-alpine', 'node:20.11-alpine'],
    'invalid' => ['Node:20!', null],
]);

it('keeps the base image of a scan when a rescan has none', function () {
    scanImageTestFake();
    ScanImage::run($this->server, $this->application, 'postgres:15.0-alpine', null, 0, 'postgres:15.0-alpine');

    $scan = scanImageTestRun();

    expect($scan->base_image_ref)->toBe('postgres:15.0-alpine');
});

it('reuses the SBOM of an image that was scanned on the server before', function () {
    scanImageTestFake();

    scanImageTestRun();
    $sbomUsedAt = ImageSbom::query()->sole()->last_used_at;
    $this->travel(1)->hours();
    $scan = scanImageTestRun();

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'docker save'), 1);
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'df -P'), 1);
    expect($scan->status)->toBe(ImageScanStatus::Completed)
        ->and($scan->findings)->toHaveCount(166)
        ->and($scan->is_baseline)->toBeFalse()
        ->and(ImageScan::query()->count())->toBe(1)
        ->and(ImageSbom::query()->sole()->last_used_at->gt($sbomUsedAt))->toBeTrue();
});

it('creates the SBOM again when the stored SBOM file is missing', function () {
    $oldSbom = ImageSbom::factory()->create([
        'server_id' => $this->server->id,
        'image_id' => 'sha256:'.SCAN_TEST_IMAGE_HEX,
    ]);
    $grypeRuns = 0;
    scanImageTestFake(['grype' => function () use (&$grypeRuns) {
        return ++$grypeRuns === 1
            ? scanImageTestPayload('grype', '', 0, 125)
            : scanImageTestPayload('grype', file_get_contents(base_path('tests/Fixtures/image-scan/grype.ndjson')));
    }]);

    $scan = scanImageTestRun();

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'docker save'), 1);
    expect($scan->status)->toBe(ImageScanStatus::Completed)
        ->and($grypeRuns)->toBe(2)
        ->and($scan->image_sbom_id)->toBe($oldSbom->id)
        ->and(ImageSbom::query()->sole()->packages)->toHaveCount(46);
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'df -P'), 1);
});

it('fails with the needed disk space when the server has too little', function () {
    scanImageTestFake([
        'inspect' => "coolify-scan-exit inspect 0\ncoolify-scan-payload\nsha256:".SCAN_TEST_IMAGE_HEX.'||linux/amd64|'.(10 * 1024 ** 3),
        'disk' => '500000000000 5000000000',
    ]);

    $scan = scanImageTestRun();

    expect($scan->exists)->toBeTrue()
        ->and($scan->status)->toBe(ImageScanStatus::Failed)
        ->and($scan->error_code)->toBe(ImageScanErrorCode::DiskSpace)
        ->and($scan->error_context)->toBe(['server' => $this->server->name, 'n' => 21]);
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'docker save'));
});

it('fails with lock busy after the wait when another scan holds the server lock', function () {
    scanImageTestFake();
    Cache::lock("image-scan:{$this->server->id}", 1800)->get();

    $started = microtime(true);
    $scan = scanImageTestRun(lockWaitSeconds: 1);

    expect(microtime(true) - $started)->toBeGreaterThanOrEqual(0.5)
        ->and($scan->exists)->toBeFalse()
        ->and($scan->status)->toBe(ImageScanStatus::Failed)
        ->and($scan->error_code)->toBe(ImageScanErrorCode::LockBusy)
        ->and($scan->error_context)->toBe(['server' => $this->server->name])
        ->and(ImageScan::query()->count())->toBe(0)
        ->and(Cache::get(ScanImage::lastErrorCacheKey($this->application)))->toMatchArray([
            'code' => 'lock_busy',
            'context' => ['server' => $this->server->name],
        ]);
    Process::assertNothingRan();
});

it('keeps the progress of another run of the resource and records no busy lock for it', function () {
    scanImageTestFake();
    $progress = ['run_id' => 'otherrun123456', 'stage' => 'sbom', 'started_at' => now()->toIso8601String()];
    Cache::put(ScanImage::progressCacheKey($this->application), $progress, 600);
    Cache::lock("image-scan:{$this->server->id}", 1800)->get();

    $scan = scanImageTestRun();

    expect($scan->error_code)->toBe(ImageScanErrorCode::LockBusy)
        ->and(Cache::get(ScanImage::progressCacheKey($this->application)))->toBe($progress)
        ->and(Cache::has(ScanImage::lastErrorCacheKey($this->application)))->toBeFalse();
});

it('records nothing for a busy lock that the caller retries later', function () {
    scanImageTestFake();
    Event::fake([ImageScanUpdated::class]);
    Cache::lock("image-scan:{$this->server->id}", 1800)->get();

    $scan = ScanImage::run($this->server, $this->application, 'postgres:15.0-alpine', null, 0, retryWhenBusy: true);

    expect(ScanImage::lockWasBusy($scan))->toBeTrue()
        ->and(Cache::has(ScanImage::lastErrorCacheKey($this->application)))->toBeFalse();
    Event::assertNotDispatched(ImageScanUpdated::class);
});

it('marks progress broadcasts as not final and results as final', function () {
    scanImageTestFake();
    Event::fake([ImageScanUpdated::class]);

    $scan = scanImageTestRun();

    Event::assertDispatched(ImageScanUpdated::class, fn (ImageScanUpdated $event) => $event->scanUuid === $scan->uuid && $event->final && $event->broadcastWith()['final'] === true);
    Event::assertDispatched(ImageScanUpdated::class, fn (ImageScanUpdated $event) => $event->scanUuid !== $scan->uuid && ! $event->final);
});

it('does not change a completed scan when a concurrent call finds the lock busy', function () {
    scanImageTestFake();
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep-a',
        'server_id' => $this->server->id,
    ]);
    $completed = ScanImage::run($this->server, $this->application, 'postgres:15.0-alpine', $deployment);
    $otherDeployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep-b',
        'server_id' => $this->server->id,
    ]);
    $otherServer = scanImageTestServer(Team::factory()->create());
    Cache::lock("image-scan:{$otherServer->id}", 1800)->get();

    $busy = ScanImage::run($otherServer, $this->application, 'postgres:15.0-alpine', $otherDeployment);

    $row = $completed->fresh();
    expect($busy->exists)->toBeFalse()
        ->and($busy->error_code)->toBe(ImageScanErrorCode::LockBusy)
        ->and(ImageScan::query()->count())->toBe(1)
        ->and($row->status)->toBe(ImageScanStatus::Completed)
        ->and($row->error_code)->toBeNull()
        ->and($row->deployment_queue_id)->toBe($deployment->id)
        ->and($row->server_id)->toBe($this->server->id);
});

function scanImageTestDeployment(string $uuid, string $status, int $pullRequestId = 0): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => test()->application->id,
        'deployment_uuid' => $uuid,
        'server_id' => test()->server->id,
        'status' => $status,
        'pull_request_id' => $pullRequestId,
    ]);
}

it('links a scan without a deployment to the latest finished deployment of the application', function () {
    scanImageTestFake();
    scanImageTestDeployment('dep-old', 'finished');
    $latest = scanImageTestDeployment('dep-latest', 'finished');
    scanImageTestDeployment('dep-preview', 'finished', 4);
    scanImageTestDeployment('dep-failed', 'failed');

    expect(scanImageTestRun()->deployment_queue_id)->toBe($latest->id);
});

it('keeps the newer deployment of a row when a scan without a deployment updates it', function () {
    scanImageTestFake();
    scanImageTestDeployment('dep-finished', 'finished');
    $blocked = scanImageTestDeployment('dep-blocked', 'failed');
    ScanImage::run($this->server, $this->application, 'postgres:15.0-alpine', $blocked);

    expect(scanImageTestRun()->deployment_queue_id)->toBe($blocked->id);
});

it('never moves the row of the application to a preview deployment', function () {
    scanImageTestFake();
    $production = scanImageTestDeployment('dep-production', 'finished');
    $preview = scanImageTestDeployment('dep-preview', 'in_progress', 4);
    $scan = ScanImage::run($this->server, $this->application, 'postgres:15.0-alpine', $production);

    ScanImage::run($this->server, $this->application, 'postgres:15.0-alpine', $preview);

    expect($scan->fresh()->deployment_queue_id)->toBe($production->id)
        ->and($scan->fresh()->status)->toBe(ImageScanStatus::Completed);
});

it('links a new image of a preview to the preview deployment', function () {
    scanImageTestFake();
    $preview = scanImageTestDeployment('dep-preview', 'in_progress', 4);

    expect(ScanImage::run($this->server, $this->application, 'postgres:15.0-alpine', $preview)->deployment_queue_id)->toBe($preview->id);
});

it('releases the server lock after a scan', function () {
    scanImageTestFake();

    scanImageTestRun();

    expect(Cache::lock("image-scan:{$this->server->id}", 1800)->get())->toBeTrue();
});

it('completes a scan of an image without a repo digest', function () {
    scanImageTestFake([
        'inspect' => "coolify-scan-exit inspect 0\ncoolify-scan-payload\nsha256:".SCAN_TEST_IMAGE_HEX.'||linux/amd64|85000000',
    ]);

    $scan = scanImageTestRun();

    expect($scan->status)->toBe(ImageScanStatus::Completed)
        ->and($scan->repo_digest)->toBeNull()
        ->and($scan->findings)->toHaveCount(166);
});

it('removes a leftover scanner container at the start of every command batch', function () {
    scanImageTestFake();

    scanImageTestRun();

    $firstRemoteLine = fn (string $command) => explode(PHP_EOL, $command)[1] ?? '';
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'coolify-scan-'), 7);
    Process::assertDidntRun(fn ($process) => ! str_starts_with($firstRemoteLine($process->command), 'docker rm -f coolify-scan-'));
});

it('runs nothing and saves nothing when scanning is off on the server', function () {
    scanImageTestFake();
    $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

    $scan = ScanImage::run($this->server->fresh(), $this->application, 'postgres:15.0-alpine');

    expect($scan->exists)->toBeFalse()
        ->and($scan->status)->toBe(ImageScanStatus::Failed)
        ->and($scan->error_code)->toBe(ImageScanErrorCode::ScanningOff)
        ->and(ImageScan::query()->count())->toBe(0);
    Process::assertNothingRan();
});

it('runs nothing when the instance does not allow scanning or the app scan is off', function (string $off) {
    scanImageTestFake();
    if ($off === 'instance') {
        InstanceSettings::get()->forceFill(['is_image_scanning_allowed' => false])->save();
    } else {
        $this->application->settings->forceFill(['is_image_scan_enabled' => false])->save();
    }

    $scan = ScanImage::run($this->server->fresh(), $this->application->fresh(), 'postgres:15.0-alpine');

    expect($scan->exists)->toBeFalse()
        ->and($scan->error_code)->toBe(ImageScanErrorCode::ScanningOff);
    Process::assertNothingRan();
})->with(['instance', 'app']);

it('runs nothing for a server that was transferred to another instance', function () {
    scanImageTestFake();
    $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();

    $scan = ScanImage::run($this->server->fresh(), $this->application, 'postgres:15.0-alpine');

    expect($scan->exists)->toBeFalse()
        ->and($scan->error_code)->toBe(ImageScanErrorCode::ScanningOff)
        ->and(ImageScan::query()->count())->toBe(0);
    Process::assertNothingRan();
});

it('broadcasts the running and the final state to the resource team', function () {
    Event::fake([ImageScanUpdated::class]);
    scanImageTestFake();

    $scan = scanImageTestRun();

    Event::assertDispatched(ImageScanUpdated::class, fn (ImageScanUpdated $event) => $event->teamId === $this->team->id
        && $event->resourceUuid === $this->application->uuid);
    Event::assertDispatchedTimes(ImageScanUpdated::class, 5);
    expect(Event::dispatched(ImageScanUpdated::class, fn (ImageScanUpdated $event) => $event->scanUuid === $scan->uuid))->toHaveCount(1);
    expect((new ImageScanUpdated($this->team->id, 'r', 's'))->broadcastAs())->toBe('ImageScanUpdated');
});

it('marks the summary as truncated when the output is over 10 MB', function () {
    $grype = file_get_contents(base_path('tests/Fixtures/image-scan/grype.ndjson'));
    scanImageTestFake(['grype' => scanImageTestPayload('grype', $grype, ScannerCommands::OUTPUT_LIMIT + 5_000_000)]);

    $scan = scanImageTestRun();

    expect($scan->status)->toBe(ImageScanStatus::Completed)
        ->and($scan->summary['truncated'])->toBeTrue()
        ->and($scan->findings)->not->toBeEmpty();
});

it('parses the part of an over-limit output that was read', function () {
    $line = collect(explode("\n", file_get_contents(base_path('tests/Fixtures/image-scan/grype.ndjson'))))->map(fn ($l) => trim($l))->first(fn ($l) => str_starts_with($l, '{"art"'));
    $payload = str_repeat($line."\n", intdiv(ScannerCommands::OUTPUT_LIMIT, strlen($line) + 1) + 10);
    $payload = substr($payload, 0, ScannerCommands::OUTPUT_LIMIT + 1);
    scanImageTestFake(['grype' => scanImageTestPayload('grype', $payload, ScannerCommands::OUTPUT_LIMIT + 100)]);

    $scan = scanImageTestRun();

    expect($scan->status)->toBe(ImageScanStatus::Completed)
        ->and($scan->summary['truncated'])->toBeTrue()
        ->and($scan->findings)->toHaveCount(5000);
});

it('fails with a timeout when grype is stopped by the server timeout, and still cleans up', function () {
    scanImageTestFake(['grype' => scanImageTestPayload('grype', '', 0, 124)]);

    $scan = scanImageTestRun();

    expect($scan->status)->toBe(ImageScanStatus::Failed)
        ->and($scan->error_code)->toBe(ImageScanErrorCode::Timeout);
    Process::assertRan(fn ($process) => str_contains($process->command, "-name 'tmp-*' -mmin +60"));
});

it('fails with a timeout when `timeout` kills a scanner after its grace period', function (string $step, string $output) {
    // A kill by `timeout` exits with 137 right away, long before the SSH budget of the step, and Docker reports no OOM.
    scanImageTestFake([$step => $output]);

    $scan = scanImageTestRun();

    expect($scan->status)->toBe(ImageScanStatus::Failed)
        ->and($scan->error_code)->toBe(ImageScanErrorCode::Timeout);
})->with([
    'docker save' => ['sbom', "coolify-scan-exit save 137\ncoolify-scan-exit sbom 1"],
    'docker save, even with an OOM line of another step' => ['sbom', "coolify-scan-exit save 137\ncoolify-scan-oom save\ncoolify-scan-exit sbom 1"],
    'syft' => ['sbom', "coolify-scan-exit save 0\ncoolify-scan-exit sbom 137"],
    'packages' => ['packages', scanImageTestPayload('packages', '', 0, 137)],
    'grype' => ['grype', scanImageTestPayload('grype', '', 0, 137)],
]);

it('says that the scanner ran out of memory only when Docker reports that the memory limit stopped it', function (string $step, string $output) {
    scanImageTestFake([$step => $output]);

    $scan = scanImageTestRun();

    expect($scan->status)->toBe(ImageScanStatus::Failed)
        ->and($scan->error_code)->toBe(ImageScanErrorCode::OutOfMemory)
        ->and(ImageScanErrorCode::OutOfMemory->message())->toBe('The scanner needed more than 1 GB of memory for this image and was stopped.');
})->with([
    'syft' => ['sbom', "coolify-scan-exit save 0\ncoolify-scan-oom sbom\ncoolify-scan-exit sbom 137"],
    'packages' => ['packages', "coolify-scan-oom packages\n".scanImageTestPayload('packages', '', 0, 137)],
    'grype' => ['grype', "coolify-scan-oom grype\n".scanImageTestPayload('grype', '', 0, 137)],
]);

it('keeps a completed scan when a rescan of the same image fails', function () {
    scanImageTestFake();
    $completed = scanImageTestRun();
    scanImageTestFake(['grype' => scanImageTestPayload('grype', '', 0, 124)]);

    $failed = scanImageTestRun();

    $row = $completed->fresh();
    expect($failed->exists)->toBeFalse()
        ->and($failed->status)->toBe(ImageScanStatus::Failed)
        ->and($failed->error_code)->toBe(ImageScanErrorCode::Timeout)
        ->and($failed->image_id)->toBe('sha256:'.SCAN_TEST_IMAGE_HEX)
        ->and(ImageScan::query()->count())->toBe(1)
        ->and($row->status)->toBe(ImageScanStatus::Completed)
        ->and($row->error_code)->toBeNull()
        ->and($row->findings)->toHaveCount(166)
        ->and(Cache::get(ScanImage::lastErrorCacheKey($this->application))['code'])->toBe('timeout');
});

it('clears the last error after a successful scan', function () {
    scanImageTestFake();
    Cache::put(ScanImage::lastErrorCacheKey($this->application), ['code' => 'lock_busy'], 60);

    scanImageTestRun();

    expect(Cache::has(ScanImage::lastErrorCacheKey($this->application)))->toBeFalse();
});

it('updates a scan row that another run inserted at the same time', function () {
    scanImageTestFake();
    $inserted = false;
    ImageScan::creating(function (ImageScan $scan) use (&$inserted) {
        if ($inserted) {
            return;
        }
        $inserted = true;
        ImageScan::factory()->create([
            'server_id' => $this->server->id,
            'resource_type' => $this->application->getMorphClass(),
            'resource_id' => $this->application->id,
            'image_id' => 'sha256:'.SCAN_TEST_IMAGE_HEX,
            'status' => ImageScanStatus::Failed,
        ]);
    });

    $scan = scanImageTestRun();

    expect($inserted)->toBeTrue()
        ->and(ImageScan::query()->count())->toBe(1)
        ->and($scan->status)->toBe(ImageScanStatus::Completed)
        ->and(ImageScan::query()->sole()->status)->toBe(ImageScanStatus::Completed)
        ->and(ImageScan::query()->sole()->findings)->toHaveCount(166);
});

it('updates a scan row that appeared while the scan ran', function () {
    $created = null;
    scanImageTestFake(['grype' => function () use (&$created) {
        $created ??= ImageScan::factory()->create([
            'server_id' => $this->server->id,
            'resource_type' => $this->application->getMorphClass(),
            'resource_id' => $this->application->id,
            'image_id' => 'sha256:'.SCAN_TEST_IMAGE_HEX,
            'status' => ImageScanStatus::Failed,
            'error_code' => ImageScanErrorCode::LockBusy,
        ]);

        return scanImageTestPayload('grype', file_get_contents(base_path('tests/Fixtures/image-scan/grype.ndjson')));
    }]);

    $scan = scanImageTestRun();

    $row = $created->fresh();
    expect($scan->id)->toBe($created->id)
        ->and($row->status)->toBe(ImageScanStatus::Completed)
        ->and($row->error_code)->toBeNull()
        ->and($row->error_context)->toBeNull()
        ->and($row->findings)->toHaveCount(166);
});

it('keeps the progress in the cache while the scan runs and forgets it after', function () {
    $seen = null;
    $key = ScanImage::progressCacheKey($this->application);
    scanImageTestFake(['grype' => function () use (&$seen, $key) {
        $seen = Cache::get($key);

        return scanImageTestPayload('grype', file_get_contents(base_path('tests/Fixtures/image-scan/grype.ndjson')));
    }]);

    scanImageTestRun();

    expect($seen)->toBeArray()
        ->and($seen['stage'])->toBe('vulnerabilities')
        ->and($seen['run_id'])->toMatch('/\A[a-z0-9]{24}\z/')
        ->and($seen['started_at'])->toBeString()
        ->and(Cache::has($key))->toBeFalse();
});

it('fails as unknown when the server returns no output', function () {
    scanImageTestFake(['grype' => '']);

    $scan = scanImageTestRun();

    expect($scan->status)->toBe(ImageScanStatus::Failed)
        ->and($scan->error_code)->toBe(ImageScanErrorCode::Unknown)
        ->and($scan->error_context)->toBe(['server' => $this->server->name]);
    Process::assertRan(fn ($process) => str_contains($process->command, "-name 'tmp-*' -mmin +60"));
});

it('fails with image missing when the image is not on the server', function () {
    scanImageTestFake(['inspect' => "coolify-scan-exit inspect 1\ncoolify-scan-payload\n"]);

    $scan = scanImageTestRun();

    expect($scan->exists)->toBeFalse()
        ->and(ImageScan::query()->count())->toBe(0);

    expect($scan->status)->toBe(ImageScanStatus::Failed)
        ->and($scan->error_code)->toBe(ImageScanErrorCode::ImageMissing);
});

it('updates the vulnerability database once when it is missing', function (?string $url) {
    InstanceSettings::get()->forceFill(['image_scan_db_url' => $url])->save();
    $statusRuns = 0;
    $valid = scanImageTestPayload('db-status', json_encode(['built' => now()->subDay()->toIso8601ZuluString(), 'valid' => true]));
    scanImageTestFake(['db-status' => function () use (&$statusRuns, $valid) {
        return ++$statusRuns === 1 ? "coolify-scan-exit db-status 1 0\ncoolify-scan-payload\n" : $valid;
    }]);

    $scan = scanImageTestRun();

    expect($scan->status)->toBe(ImageScanStatus::Completed);
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'coolify-scan-exit db-update'), 1);
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'GRYPE_DB_UPDATE_URL'));
})->with(['no mirror' => [null], 'empty mirror' => ['']]);

it('fails when the vulnerability database is older than 5 days', function () {
    scanImageTestFake(['db-status' => scanImageTestPayload('db-status', json_encode(['built' => now()->subDays(7)->toIso8601ZuluString(), 'valid' => true]))]);

    $scan = scanImageTestRun();

    expect($scan->exists)->toBeFalse()
        ->and($scan->status)->toBe(ImageScanStatus::Failed)
        ->and($scan->error_code)->toBe(ImageScanErrorCode::DbTooOld)
        ->and($scan->error_context)->toBe(['server' => $this->server->name, 'n' => 7]);
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'coolify-scan-exit db-update'));
});

it('stores the security status of a build server scan with the rules of the main destination server', function () {
    scanImageTestFake();
    $buildServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->server->private_key_id]);
    $buildServer->settings->forceFill(['is_reachable' => true, 'is_usable' => true, 'is_image_scan_enabled' => true])->saveQuietly();
    $buildServer = $buildServer->fresh();

    $scan = ScanImage::run($buildServer, $this->application, 'postgres:15.0-alpine');

    expect($scan->server_id)->toBe($buildServer->id)
        ->and($scan->fresh()->summary['security']['status'])->toBe(SecurityStatus::forScan($scan->fresh())->status->value)
        ->and($scan->fresh()->summary)->not->toHaveKeys(['counts', 'fixable_now_at_level']);
});

it('scopes the SBOM reuse and the scan writes to the server', function () {
    $otherServer = scanImageTestServer(Team::factory()->create());
    $otherSbom = ImageSbom::factory()->create(['server_id' => $otherServer->id, 'image_id' => 'sha256:'.SCAN_TEST_IMAGE_HEX]);
    $otherScan = ImageScan::factory()->create([
        'server_id' => $otherServer->id,
        'image_sbom_id' => $otherSbom->id,
        'image_id' => 'sha256:'.SCAN_TEST_IMAGE_HEX,
        'summary' => ['marker' => 'other'],
    ]);
    scanImageTestFake();

    $scan = scanImageTestRun();

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'docker save'), 1);
    expect($scan->image_sbom_id)->not->toBe($otherSbom->id)
        ->and($otherScan->fresh()->summary)->toBe(['marker' => 'other'])
        ->and($otherScan->fresh()->server_id)->toBe($otherServer->id)
        ->and($otherSbom->fresh()->packages)->toBe([]);
});

it('records the deployment and decides who builds a docker image app', function () {
    scanImageTestFake();
    $this->application->forceFill(['build_pack' => 'dockerimage'])->save();
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep123',
        'server_id' => $this->server->id,
    ]);

    $scan = ScanImage::run($this->server, $this->application->fresh(), 'postgres:15.0-alpine', $deployment);

    expect($scan->deployment_queue_id)->toBe($deployment->id)
        ->and($scan->builds_image)->toBeFalse();

    $this->application->settings->forceFill(['image_builds_by_team' => true])->save();
    $scan = ScanImage::run($this->server, $this->application->fresh(), 'postgres:15.0-alpine');

    expect($scan->builds_image)->toBeTrue();
});

it('fails without running a command when the image reference is invalid', function () {
    scanImageTestFake();

    $scan = ScanImage::run($this->server, $this->application, 'postgres:15;rm -rf /');

    expect($scan->exists)->toBeFalse()
        ->and($scan->status)->toBe(ImageScanStatus::Failed)
        ->and($scan->error_code)->toBe(ImageScanErrorCode::Unknown);
    Process::assertNothingRan();
});

it('keeps the deployment of a scan when a later rescan has none', function () {
    scanImageTestFake();
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'dep456',
        'server_id' => $this->server->id,
    ]);

    ScanImage::run($this->server, $this->application, 'postgres:15.0-alpine', $deployment);
    $scan = scanImageTestRun();

    expect($scan->deployment_queue_id)->toBe($deployment->id)
        ->and(ImageScan::query()->count())->toBe(1);
});

describe('candidate check', function () {
    beforeEach(function () {
        Queue::fake();
        $this->application->forceFill(['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'postgres', 'docker_registry_image_tag' => '15.0-alpine'])->save();
    });

    it('queues a candidate check after a scan of an image with a relevant finding', function () {
        scanImageTestFake();

        $scan = scanImageTestRun($this->application->fresh());

        Queue::assertPushed(RefreshCandidatesJob::class, fn (RefreshCandidatesJob $job) => $job->scan->is($scan));
        expect(Cache::lock("image-scan:{$this->server->id}", 10)->get())->toBeTrue();
    });

    it('queues no candidate check for an image that Coolify builds', function () {
        scanImageTestFake();
        $this->application->forceFill(['build_pack' => 'dockerfile'])->save();

        scanImageTestRun($this->application->fresh());

        Queue::assertNotPushed(RefreshCandidatesJob::class);
    });

    it('queues a base image check for a Dockerfile build with relevant findings', function () {
        scanImageTestFake();
        $this->application->forceFill(['build_pack' => 'dockerfile'])->save();

        $scan = ScanImage::run($this->server, $this->application->fresh(), 'postgres:15.0-alpine', null, 0, 'postgres:15.0-alpine');

        Queue::assertPushed(RefreshCandidatesJob::class, fn (RefreshCandidatesJob $job) => $job->scan->is($scan));
    });

    it('queues no candidate check when the last check is recent', function () {
        scanImageTestFake();
        $scan = scanImageTestRun($this->application->fresh());
        $scan->update(['candidates' => ['status' => 'no_better', 'checked_at' => now()->subHour()->toIso8601String(), 'error' => null, 'entries' => []]]);
        Queue::fake();

        scanImageTestRun($this->application->fresh());

        Queue::assertNotPushed(RefreshCandidatesJob::class);
    });
});

describe('notifications', function () {
    it('alerts once for a new exploited finding after the baseline scan', function () {
        Notification::fake();
        $this->team->discordNotificationSettings->update([
            'discord_enabled' => true,
            'discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc',
            'image_vulnerabilities_discord_notifications' => true,
        ]);
        $grype = file_get_contents(base_path('tests/Fixtures/image-scan/grype.ndjson'));
        // A language package with a fix gets an action in an image that a builder builds (spec 15.5).
        $this->application->forceFill(['build_pack' => 'nixpacks'])->save();
        $known = json_encode(['art' => '0bbf5c9dd36d4a50', 'desc' => '', 'epss' => 0.9, 'fix' => 'fixed', 'fixv' => ['4.17.21'], 'id' => 'CVE-2026-7777',
            'kev' => true, 'pkg' => 'lodash', 'rel' => [], 'risk' => 90.0, 'sev' => 'Critical', 'sugg' => '4.17.21', 'type' => 'npm', 'ver' => '4.17.0']);
        $withKnown = scanImageTestPayload('grype', rtrim($grype)."\n".$known);

        scanImageTestFake();
        $baseline = scanImageTestRun();
        scanImageTestFake(['grype' => $withKnown]);
        $rescan = scanImageTestRun();
        scanImageTestRun();

        expect($baseline->is_baseline)->toBeTrue()
            ->and($rescan->is_baseline)->toBeFalse()
            ->and(ImageScanNotificationState::query()->sole()->notified_ids)->toContain('CVE-2026-7777');
        Notification::assertSentToTimes($this->team, ExploitedProblemFound::class, 1);
        Notification::assertSentTo($this->team, ExploitedProblemFound::class, fn (ExploitedProblemFound $notification) => $notification->count === 1);
    });
});
