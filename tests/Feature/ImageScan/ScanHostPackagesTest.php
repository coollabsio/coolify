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
    InstanceSettings::forceCreate(['id' => 0, 'is_image_scanning_allowed' => true]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->forceFill(['is_reachable' => true, 'is_usable' => true, 'is_image_scan_enabled' => true])->saveQuietly();
    $this->server = $this->server->fresh();
});

/**
 * @param  array<string, string>  $overrides
 */
function hostScanTestFake(array $overrides = []): void
{
    $payload = file_get_contents(base_path('tests/Fixtures/image-scan/grype-host.ndjson'));
    $outputs = array_merge([
        'db-status' => "coolify-scan-exit db-status 0 180\ncoolify-scan-payload\n".json_encode(['built' => now()->subDay()->format('Y-m-d\TH:i:s\Z'), 'valid' => true]),
        'sbom' => "coolify-scan-exit copy 0\ncoolify-scan-exit sbom 0",
        'grype' => 'coolify-scan-exit grype 0 '.strlen($payload)."\ncoolify-scan-payload\n{$payload}",
    ], $overrides);

    Process::fake(function (PendingProcess $process) use ($outputs) {
        $script = remoteProcessScript($process);
        $key = match (true) {
            str_contains($script, 'coolify-scan-exit db-status') => 'db-status',
            str_contains($script, 'dir:/in/host') => 'sbom',
            str_contains($script, 'coolify-scan-exit grype') => 'grype',
            default => null,
        };

        return Process::result(output: $key === null ? '' : $outputs[$key]);
    });
}

it('saves the relevant host findings, exploited and fixable ones first', function () {
    hostScanTestFake();

    expect(ScanHostPackages::run($this->server))->toBeTrue();

    $scan = $this->server->hostScan()->firstOrFail();
    expect($scan->error_code)->toBeNull()
        ->and($scan->scanned_at)->not->toBeNull()
        ->and($scan->findings_truncated)->toBeFalse()
        ->and($scan->summary)->toBe(['critical' => 2, 'high' => 1, 'medium' => 0, 'low' => 1, 'unknown' => 0, 'fixable' => 2, 'exploited' => 2, 'likely' => 1, 'total' => 5])
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

it('does not scan a server with image scanning off', function () {
    hostScanTestFake();
    $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

    ScanHostPackages::run($this->server->fresh());

    expect($this->server->hostScan()->exists())->toBeFalse();
    Process::assertNothingRan();
});

it('queues a host scan after the daily rescan', function () {
    Queue::fake([ScanHostPackagesJob::class]);
    hostScanTestFake();

    (new ImageRescanJob($this->server))->handle();

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
