<?php

use App\Actions\ImageScan\ScanHostPackages;
use App\Actions\ImageScan\ScanImage;
use App\Enums\ImageScanErrorCode;
use App\Events\ServerPackagesUpdateFinished;
use App\Jobs\ImageRescanJob;
use App\Jobs\ScanHostPackagesJob;
use App\Livewire\Server\Security\HostVulnerabilities;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\ServerHostScan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    $this->withoutVite();
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.max_retries' => 1]);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->forceFill(['is_reachable' => true, 'is_usable' => true, 'is_image_scan_enabled' => true])->saveQuietly();
    $this->server = $this->server->fresh();
});

/**
 * @param  array<string, string|Closure(): string>  $overrides
 */
function hostScanTestFake(array $overrides = []): void
{
    $payload = file_get_contents(base_path('tests/Fixtures/image-scan/grype-host.ndjson'));
    $outputs = array_merge([
        'db-status' => "coolify-scan-exit db-status 0 180\ncoolify-scan-payload\n".json_encode(['built' => now()->subDay()->format('Y-m-d\TH:i:s\Z'), 'valid' => true]),
        'packages-size' => 'coolify-scan-exit packages-size 0 204800',
        'disk' => '107374182400 107374182400',
        'sbom' => "coolify-scan-exit copy 0\ncoolify-scan-exit sbom 0",
        'grype' => 'coolify-scan-exit grype 0 '.strlen($payload)."\ncoolify-scan-payload\n{$payload}",
        'containers' => '',
    ], $overrides);

    Process::fake(function (PendingProcess $process) use ($outputs) {
        $script = remoteProcessScript($process);
        $key = match (true) {
            str_contains($script, 'coolify-scan-exit db-status') => 'db-status',
            str_contains($script, 'coolify-scan-exit packages-size') => 'packages-size',
            str_contains($script, 'df -P') => 'disk',
            str_contains($script, 'dir:/in/host') => 'sbom',
            str_contains($script, 'coolify-scan-exit grype') => 'grype',
            str_contains($script, 'coolify-scan-exit containers') => 'containers',
            default => null,
        };

        return Process::result(output: $key === null ? '' : value($outputs[$key]));
    });
}

it('saves the relevant host findings, exploited and fixable ones first', function () {
    hostScanTestFake();

    expect(ScanHostPackages::run($this->server))->toBeTrue();

    $scan = $this->server->hostScan()->firstOrFail();
    expect($scan->error_code)->toBeNull()
        ->and($scan->scanned_at)->not->toBeNull()
        ->and($scan->findings_truncated)->toBeFalse()
        ->and($scan->summary)->toBe(['critical' => 2, 'high' => 1, 'medium' => 0, 'low' => 1, 'unknown' => 0, 'fixable' => 2, 'exploited' => 2, 'likely' => 1, 'other_kernels' => 0, 'total' => 5, 'running_kernel' => null])
        ->and(array_map(fn ($finding) => [$finding->displayId, $finding->package, $finding->fixedIn], $scan->findingList()))->toBe([
            ['CVE-2026-111497', 'libbrotli-dev', '1.1.0-2+deb13u1'],
            ['CVE-2023-5217', 'firefox-esr', null],
            ['CVE-2024-1283', 'gir1.2-javascriptcoregtk-4.1', '2.54.0-1~deb13u1'],
            ['CVE-2026-34980', 'cups', null],
        ]);
    Process::assertRan(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'coolify-scan-exit grype') && str_contains(remoteProcessScript($process), "'sbom:/sbom/sbom.json'"));
});

it('keeps the last findings and saves the error when the server has no package database', function () {
    $previous = ServerHostScan::factory()->create(['server_id' => $this->server->id, 'findings' => [['id' => 'CVE-2024-0001', 'package' => 'curl']]]);
    hostScanTestFake(['sbom' => "coolify-scan-exit copy 1\ncoolify-scan-exit sbom 0"]);

    ScanHostPackages::run($this->server);

    $scan = $previous->fresh();
    expect($scan->error_code)->toBe(ImageScanErrorCode::NoPackageDatabase)
        ->and($scan->findings)->toBe([['id' => 'CVE-2024-0001', 'package' => 'curl']])
        ->and($scan->scanned_at->equalTo($previous->scanned_at))->toBeTrue();
});

it('keeps the last findings when a package database copy fails', function () {
    $previous = ServerHostScan::factory()->create(['server_id' => $this->server->id, 'findings' => [['id' => 'CVE-2024-0001', 'package' => 'curl']]]);
    hostScanTestFake(['sbom' => "coolify-scan-exit copy 2\ncoolify-scan-exit sbom 0"]);

    ScanHostPackages::run($this->server);

    $scan = $previous->fresh();
    expect($scan->error_code)->toBe(ImageScanErrorCode::Unknown)
        ->and($scan->findings)->toBe([['id' => 'CVE-2024-0001', 'package' => 'curl']])
        ->and($scan->scanned_at->equalTo($previous->scanned_at))->toBeTrue();
    Process::assertDidntRun(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'coolify-scan-exit grype'));
});

it('saves the database age when the vulnerability database is too old and clears it after a later scan', function () {
    hostScanTestFake(['db-status' => "coolify-scan-exit db-status 0 180\ncoolify-scan-payload\n".json_encode(['built' => now()->subDays(9)->format('Y-m-d\TH:i:s\Z'), 'valid' => true])]);

    ScanHostPackages::run($this->server);

    $scan = $this->server->hostScan()->first();
    expect($scan->error_code)->toBe(ImageScanErrorCode::DbTooOld)
        ->and($scan->error_context)->toBe(['n' => 9])
        ->and($scan->error_code->message($scan->error_context))->toStartWith('The vulnerability database is 9 days old.');

    hostScanTestFake();
    ScanHostPackages::run($this->server);

    expect($scan->fresh())->error_code->toBeNull()->error_context->toBeNull();
});

it('does not copy the package databases when the disk has not enough free space', function () {
    $previous = ServerHostScan::factory()->create(['server_id' => $this->server->id, 'findings' => [['id' => 'CVE-2024-0001', 'package' => 'curl']]]);
    hostScanTestFake(['packages-size' => 'coolify-scan-exit packages-size 0 512000', 'disk' => '107374182400 1500000000']);

    expect(ScanHostPackages::run($this->server))->toBeTrue();

    $scan = $previous->fresh();
    expect($scan->error_code)->toBe(ImageScanErrorCode::DiskSpace)
        ->and($scan->findings)->toBe([['id' => 'CVE-2024-0001', 'package' => 'curl']]);
    Process::assertNotRan(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'dir:/in/host'));
});

it('does not copy the package databases when their size cannot be measured', function (string $output) {
    $previous = ServerHostScan::factory()->create(['server_id' => $this->server->id, 'findings' => [['id' => 'CVE-2024-0001', 'package' => 'curl']]]);
    hostScanTestFake(['packages-size' => $output]);

    expect(ScanHostPackages::run($this->server))->toBeTrue();

    $scan = $previous->fresh();
    expect($scan->error_code)->toBe(ImageScanErrorCode::Unknown)
        ->and($scan->findings)->toBe([['id' => 'CVE-2024-0001', 'package' => 'curl']]);
    Process::assertNotRan(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'dir:/in/host'));
})->with([
    'du failed' => ['coolify-scan-exit packages-size 1 0'],
    'no output' => [''],
]);

it('returns false without scanning when another scan holds the server lock', function () {
    hostScanTestFake();
    $lock = ScanImage::serverLock($this->server);
    $lock->get();

    try {
        expect(ScanHostPackages::run($this->server))->toBeFalse();
    } finally {
        $lock->release();
    }

    expect($this->server->hostScan()->exists())->toBeFalse();
    Process::assertNothingRan();
});

it('retries a busy host scan and records lock_busy after the last try', function () {
    hostScanTestFake();
    $lock = ScanImage::serverLock($this->server);
    $lock->get();
    Cache::put(ScanHostPackagesJob::runningCacheKey($this->server), true);

    try {
        $job = (new ScanHostPackagesJob($this->server))->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased(300);
        expect($this->server->hostScan()->exists())->toBeFalse()
            ->and(Cache::has(ScanHostPackagesJob::runningCacheKey($this->server)))->toBeTrue();

        $lastTry = (new ScanHostPackagesJob($this->server))->withFakeQueueInteractions();
        $lastTry->job->attempts = $lastTry->tries;
        $lastTry->handle();
        $lastTry->assertNotReleased();
    } finally {
        $lock->release();
    }

    expect($this->server->hostScan()->first()->error_code)->toBe(ImageScanErrorCode::LockBusy)
        ->and(Cache::has(ScanHostPackagesJob::runningCacheKey($this->server)))->toBeFalse();
    Process::assertNothingRan();
});

it('keeps the running marker of a scan requested while a scan runs', function () {
    Queue::fake([ScanHostPackagesJob::class]);
    $payload = file_get_contents(base_path('tests/Fixtures/image-scan/grype-host.ndjson'));
    hostScanTestFake(['grype' => function () use ($payload) {
        ScanHostPackagesJob::dispatchManual($this->server);

        return 'coolify-scan-exit grype 0 '.strlen($payload)."\ncoolify-scan-payload\n{$payload}";
    }]);
    ScanHostPackagesJob::dispatchManual($this->server);

    (new ScanHostPackagesJob($this->server))->withFakeQueueInteractions()->handle();
    expect(Cache::has(ScanHostPackagesJob::runningCacheKey($this->server)))->toBeTrue();

    hostScanTestFake();
    (new ScanHostPackagesJob($this->server))->withFakeQueueInteractions()->handle();
    expect(Cache::has(ScanHostPackagesJob::runningCacheKey($this->server)))->toBeFalse();
});

it('does not scan a server with image scanning off', function () {
    hostScanTestFake();
    $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

    ScanHostPackages::run($this->server->fresh());

    expect($this->server->hostScan()->exists())->toBeFalse();
    Process::assertNothingRan();
});

it('queues a host scan after a daily rescan that cannot list the containers', function () {
    Queue::fake([ScanHostPackagesJob::class]);
    hostScanTestFake();

    (new ImageRescanJob($this->server))->handle();

    expect(ImageRescanJob::failedDays($this->server))->toBe(1);
    Queue::assertPushed(ScanHostPackagesJob::class, fn ($job) => $job->server->is($this->server));
});

it('queues a host scan after a completed daily rescan', function () {
    Queue::fake([ScanHostPackagesJob::class]);
    hostScanTestFake(['containers' => "coolify-scan-exit containers 0\ncoolify-scan-payload\n"]);

    (new ImageRescanJob($this->server))->handle();

    expect(ImageRescanJob::failedDays($this->server))->toBe(0);
    Queue::assertPushed(ScanHostPackagesJob::class, fn ($job) => $job->server->is($this->server));
});

it('queues a host scan after a package update', function () {
    Queue::fake();

    ServerPackagesUpdateFinished::dispatch(['server_uuid' => $this->server->uuid, 'packages' => ['curl']]);

    Queue::assertPushed(ScanHostPackagesJob::class, fn ($job) => $job->server->is($this->server));
    expect(Cache::has(ScanHostPackagesJob::runningCacheKey($this->server)))->toBeTrue();
});

describe('vulnerabilities page', function () {
    beforeEach(function () {
        Queue::fake();
        $this->admin = User::factory()->create();
        $this->team->members()->attach($this->admin, ['role' => 'admin']);
        ServerHostScan::factory()->create([
            'server_id' => $this->server->id,
            'summary' => ['critical' => 1, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0, 'fixable' => 1, 'exploited' => 1, 'likely' => 0, 'total' => 3],
            'findings' => [['id' => 'CVE-2026-111497', 'severity' => 'critical', 'package' => 'libbrotli-dev', 'version' => '1.1.0-2+b7', 'fixedIn' => '1.1.0-2+deb13u1', 'kev' => true]],
        ]);
        $this->server->settings->forceFill(['patch_check_result' => ['osId' => 'debian', 'package_manager' => 'apt', 'updates' => [['package' => 'libbrotli-dev', 'new_version' => '1.1.0-2+deb13u1']]]])->saveQuietly();
    });

    it('shows the host findings with the available update to an admin and queues a scan', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(HostVulnerabilities::class, ['server_uuid' => $this->server->uuid])
            ->assertSee('CVE-2026-111497')
            ->assertSee('Update available: 1.1.0-2+deb13u1')
            ->call('scanHostPackages')
            ->assertSet('hostScanRunning', true);

        Queue::assertPushed(ScanHostPackagesJob::class, fn ($job) => $job->server->is($this->server));
    });

    it('classifies kernel findings by the kernel that ran during the scan, not by an older patch check', function () {
        $this->server->hostScan()->firstOrFail()->update([
            'summary' => ['critical' => 1, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0, 'fixable' => 0, 'exploited' => 0, 'likely' => 0, 'other_kernels' => 1, 'total' => 2, 'running_kernel' => '6.8.0-140-generic'],
            'findings' => [
                ['id' => 'CVE-2026-1001', 'severity' => 'critical', 'package' => 'linux-image-6.8.0-140-generic', 'version' => '6.8.0-140.140'],
                ['id' => 'CVE-2026-1002', 'severity' => 'high', 'package' => 'linux-image-6.8.0-139-generic', 'version' => '6.8.0-139.139'],
            ],
        ]);
        $this->server->settings->forceFill(['patch_check_result' => ['osId' => 'ubuntu', 'package_manager' => 'apt', 'updates' => [], 'kernels' => [
            'running' => '6.8.0-139-generic',
            'installed' => [
                ['package' => 'linux-image-6.8.0-139-generic', 'version' => '6.8.0-139.139', 'release' => '6.8.0-139-generic'],
                ['package' => 'linux-image-6.8.0-140-generic', 'version' => '6.8.0-140.140', 'release' => '6.8.0-140-generic'],
            ],
        ]]])->saveQuietly();
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(HostVulnerabilities::class, ['server_uuid' => $this->server->uuid])
            ->assertSee('CVE-2026-1001')
            ->assertDontSee('CVE-2026-1002')
            ->assertDontSee('starts after a reboot');
    });

    it('refuses the page to a member', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => 'member']);
        $this->actingAs($member);
        session(['currentTeam' => $this->team]);

        Livewire::test(HostVulnerabilities::class, ['server_uuid' => $this->server->uuid])->assertForbidden();
        Queue::assertNotPushed(ScanHostPackagesJob::class);
    });

    it('does not show the server of another team', function () {
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($this->admin, ['role' => 'owner']);
        $this->actingAs($this->admin);
        session(['currentTeam' => $otherTeam]);

        expect(fn () => Livewire::test(HostVulnerabilities::class, ['server_uuid' => $this->server->uuid]))->toThrow(ModelNotFoundException::class);
    });
});
