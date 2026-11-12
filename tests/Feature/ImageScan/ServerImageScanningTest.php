<?php

use App\Actions\ImageScan\ScanImage;
use App\Events\ImageScanUpdated;
use App\Jobs\PrepareImageScannerJob;
use App\Livewire\ImageScan\ServerImageScanning;
use App\Livewire\Server\New\ByHetzner;
use App\Livewire\Server\New\ByIp;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    $this->withoutVite();
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.max_retries' => 1]);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => 'admin']);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->forceFill(['is_reachable' => true, 'is_usable' => true])->saveQuietly();
});

function imageScanningPage(): Testable
{
    test()->actingAs(test()->admin);
    session(['currentTeam' => test()->team]);

    return Livewire::test(ServerImageScanning::class, ['server_uuid' => test()->server->uuid]);
}

function fakeScannerPreparation(array $overrides = []): void
{
    $outputs = array_merge([
        'prepare' => "coolify-scan-exit pull-syft 0\ncoolify-scan-exit pull-grype 0\ncoolify-scan-exit pull-regctl 0\ncoolify-scan-exit pull-init 0\ncoolify-scan-exit db-volume 0",
        'db-update' => 'coolify-scan-exit db-update 0',
        'disk' => "coolify-scan-exit disk-db 0 1000\ncoolify-scan-exit disk-sboms 0 234",
        'db-status' => "coolify-scan-exit db-status 0 90\ncoolify-scan-payload\n".json_encode(['built' => '2026-10-25T08:15:00Z', 'valid' => true]),
    ], $overrides);

    Process::fake(function (PendingProcess $process) use ($outputs) {
        $key = match (true) {
            str_contains(remoteProcessScript($process), 'docker pull') => 'prepare',
            str_contains(remoteProcessScript($process), 'coolify-scan-exit disk-db') => 'disk',
            str_contains(remoteProcessScript($process), 'coolify-scan-exit db-status') => 'db-status',
            str_contains(remoteProcessScript($process), 'coolify-scan-exit db-update') => 'db-update',
            default => null,
        };

        return Process::result(output: $key === null ? '' : $outputs[$key]);
    });
}

describe('server image scanning page', function () {
    it('lets an admin turn on image scanning and queues the scanner preparation', function () {
        Queue::fake();

        imageScanningPage()->call('enable')->assertDispatched('success');

        expect($this->server->settings->fresh()->is_image_scan_enabled)->toBeTrue()
            ->and(Cache::get(PrepareImageScannerJob::stateCacheKey($this->server)))->toBe('pulling');
        Queue::assertPushed(PrepareImageScannerJob::class, fn ($job) => $job->server->is($this->server) && $job->queue === 'default');
    });

    it('turns image scanning off without queueing a job', function () {
        Queue::fake();
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), 'ready');

        imageScanningPage()->call('disable')->assertDispatched('success', 'Image scanning turned off.');

        expect($this->server->settings->fresh()->is_image_scan_enabled)->toBeFalse()
            ->and(Cache::has(PrepareImageScannerJob::stateCacheKey($this->server)))->toBeFalse();
        Queue::assertNothingPushed();
    });

    it('shows the off state with the turn on button', function () {
        imageScanningPage()
            ->assertSee('Image scanning is off for '.$this->server->name.'.')
            ->assertSee('Enable image scanning?');
    });

    it('shows only the title row and the database build time in the helper when the scanner is ready', function () {
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), 'ready');
        Cache::put(PrepareImageScannerJob::builtAtCacheKey($this->server), now()->subDay()->toIso8601ZuluString());

        imageScanningPage()
            ->assertSee('Database built 1 day ago')
            ->assertSee('Coolify downloads the latest vulnerability database every day')
            ->assertDontSee('Update database')
            ->assertDontSee('Get scanner')
            ->assertSee('Disable image scanning?')
            ->assertSee('Delete scanner data');
    });

    it('shows the progress of the scanner preparation', function () {
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), 'updating_database');

        imageScanningPage()
            ->assertSee('Get scanner')
            ->assertSee('Downloading the vulnerability database.')
            ->assertDontSee('Database built');
    });

    it('refuses to turn on a server that another instance manages', function () {
        Queue::fake();
        $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();

        imageScanningPage()->call('enable')->assertDispatched('error', message: 'This server is managed by another Coolify instance.');

        expect($this->server->settings->fresh()->is_image_scan_enabled)->toBeFalse();
        Queue::assertNothingPushed();
    });

    it('deletes the scanner data on the server and forgets the progress', function () {
        Process::fake(fn () => Process::result(output: ''));
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), 'ready');

        imageScanningPage()->call('deleteScannerData')->assertDispatched('success', 'Scanner data deleted.');

        Process::assertRan(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'docker volume rm -f coolify-grype-db'));
        expect(Cache::has(PrepareImageScannerJob::stateCacheKey($this->server)))->toBeFalse();
    });

    it('does not delete the scanner data while a scan holds the server lock', function () {
        Process::fake(fn () => Process::result(output: ''));
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), 'ready');
        $lock = ScanImage::serverLock($this->server);
        $lock->get();

        imageScanningPage()
            ->call('deleteScannerData')
            ->assertDispatched('error')
            ->assertNotDispatched('success');
        $lock->release();

        Process::assertDidntRun(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'docker volume rm -f coolify-grype-db'));
        expect(Cache::get(PrepareImageScannerJob::stateCacheKey($this->server)))->toBe('ready')
            ->and(ScanImage::serverLock($this->server)->get())->toBeTrue();
    });

    it('reports a failed deletion and keeps the progress when the server cannot be reached', function () {
        Process::fake(fn () => Process::result(errorOutput: 'ssh: connect to host failed', exitCode: 255));
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), 'ready');

        imageScanningPage()
            ->call('deleteScannerData')
            ->assertDispatched('error')
            ->assertNotDispatched('success');

        expect(Cache::get(PrepareImageScannerJob::stateCacheKey($this->server)))->toBe('ready');
    });

    it('shows the scanner tool versions and the cached disk usage', function () {
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), 'ready');
        Cache::put(PrepareImageScannerJob::diskUsedCacheKey($this->server), 1181116006);

        imageScanningPage()
            ->assertSee('Syft '.config('constants.image_scan.images.syft.version'))
            ->assertSee('Grype '.config('constants.image_scan.images.grype.version'))
            ->assertSee('regctl '.config('constants.image_scan.images.regctl.version'))
            ->assertSee('Disk used by scanner data: 1.1 GB');
    });

    it('hides the delete action only when the server is known to have no scanner data', function () {
        Process::fake(fn () => Process::result(output: ''));
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();

        // No cached state or disk use: the data can still be on the server.
        imageScanningPage()->assertSee('Delete scanner data');

        Cache::put(PrepareImageScannerJob::diskUsedCacheKey($this->server), 0);
        imageScanningPage()->assertDontSee('Delete scanner data');

        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), 'ready');
        imageScanningPage()->assertSee('Delete scanner data');

        imageScanningPage()->call('deleteScannerData')->assertDontSee('Delete scanner data');
    });

    it('shows no disk usage before the job has stored it', function () {
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();

        imageScanningPage()->assertDontSee('Disk used by scanner data');
    });
});

describe('scanner memory limit', function () {
    it('saves the scanner memory limit of the server and shows it in the cost text', function () {
        imageScanningPage()
            ->assertSet('scannerMemoryMb', '1024')
            ->assertSee('1 CPU and 1 GB of memory')
            ->set('scannerMemoryMb', '4096')
            ->assertHasNoErrors()
            ->assertDispatched('success')
            ->assertSee('1 CPU and 4 GB of memory');

        expect($this->server->settings->fresh()->image_scan_memory_mb)->toBe(4096);
    });

    it('refuses a memory limit that is not one of the options', function () {
        imageScanningPage()->set('scannerMemoryMb', '100')->assertHasErrors('scannerMemoryMb');

        expect($this->server->settings->fresh()->image_scan_memory_mb)->toBe(1024);
    });

    it('does not change the memory limit of a server that another instance manages', function () {
        $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();

        imageScanningPage()->set('scannerMemoryMb', '2048')->assertNotDispatched('success');

        expect($this->server->settings->fresh()->image_scan_memory_mb)->toBe(1024);
    });
});

describe('scanner time and CPU limits', function () {
    it('saves the Syft and Grype time limits and the CPU limit, and shows the CPUs in the cost text', function () {
        imageScanningPage()
            ->assertSet('scannerSyftTimeout', '600')
            ->assertSet('scannerGrypeTimeout', '600')
            ->assertSet('scannerCpus', '1')
            ->set('scannerSyftTimeout', '1800')
            ->assertDispatched('success', 'Syft time limit saved. It applies to the next scan.')
            ->set('scannerGrypeTimeout', '1200')
            ->set('scannerCpus', '0.5')
            ->assertHasNoErrors()
            ->assertSee('up to 0.5 CPU and 1 GB of memory');

        $settings = $this->server->settings->fresh();
        expect($settings->image_scan_syft_timeout)->toBe(1800)
            ->and($settings->image_scan_grype_timeout)->toBe(1200)
            ->and($settings->image_scan_cpus)->toBe(0.5);
    });

    it('refuses a limit that is not one of the options', function (string $property, string $value) {
        imageScanningPage()->set($property, $value)->assertHasErrors($property);

        $settings = $this->server->settings->fresh();
        expect($settings->image_scan_syft_timeout)->toBe(600)
            ->and($settings->image_scan_grype_timeout)->toBe(600)
            ->and($settings->image_scan_cpus)->toBe(1.0);
    })->with([
        'syft time limit' => ['scannerSyftTimeout', '45'],
        'grype time limit' => ['scannerGrypeTimeout', '99999'],
        'cpus' => ['scannerCpus', '1; id'],
    ]);

    it('does not change the limits of a server that another instance manages', function () {
        $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();

        imageScanningPage()->set('scannerGrypeTimeout', '3600')->assertNotDispatched('success')->assertSet('scannerGrypeTimeout', '600');

        expect($this->server->settings->fresh()->image_scan_grype_timeout)->toBe(600);
    });
});

describe('scanner limits that fit the server', function () {
    beforeEach(function () {
        $this->server->forceFill(['server_metadata' => ['memory_bytes' => 2 * 1024 ** 3, 'cpus' => 2]])->saveQuietly();
    });

    it('refuses memory and CPU limits above what the server has', function (string $property, string $value) {
        imageScanningPage()->set($property, $value)->assertHasErrors($property);

        $settings = $this->server->settings->fresh();
        expect($settings->image_scan_memory_mb)->toBe(1024)
            ->and($settings->image_scan_cpus)->toBe(1.0);
    })->with([
        'memory' => ['scannerMemoryMb', '4096'],
        'cpus' => ['scannerCpus', '4'],
    ]);

    it('saves the limits that the server has', function () {
        imageScanningPage()->set('scannerMemoryMb', '2048')->set('scannerCpus', '2')->assertHasNoErrors();

        $settings = $this->server->settings->fresh();
        expect($settings->image_scan_memory_mb)->toBe(2048)
            ->and($settings->image_scan_cpus)->toBe(2.0);
    });

    it('keeps a saved limit that no longer fits visible and marked', function () {
        $this->server->settings->forceFill(['image_scan_memory_mb' => 8192])->saveQuietly();

        imageScanningPage()
            ->assertSet('scannerMemoryMb', '8192')
            ->assertSee('8 GB (more than this server has)')
            ->assertDontSee('4 GB');
    });
});

describe('server image scanning authorization', function () {
    beforeEach(function () {
        Queue::fake();
        $this->member = User::factory()->create();
        $this->team->members()->attach($this->member, ['role' => 'member']);
    });

    it('does not let a member open the page', function () {
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        Livewire::test(ServerImageScanning::class, ['server_uuid' => $this->server->uuid])->assertForbidden();
    });

    it('does not let a member of the server team act after switching to a team they own', function () {
        $otherTeam = Team::factory()->create();
        $this->member->teams()->attach($otherTeam, ['role' => 'owner']);
        $this->member->teams()->updateExistingPivot($this->team->id, ['role' => 'admin']);
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        $components = collect(['enable', 'disable', 'deleteScannerData'])
            ->mapWithKeys(fn (string $action) => [$action => Livewire::test(ServerImageScanning::class, ['server_uuid' => $this->server->uuid])]);
        $memoryComponent = Livewire::test(ServerImageScanning::class, ['server_uuid' => $this->server->uuid]);
        $timeoutComponent = Livewire::test(ServerImageScanning::class, ['server_uuid' => $this->server->uuid]);

        $this->member->teams()->updateExistingPivot($this->team->id, ['role' => 'member']);
        $this->member->unsetRelation('teams');
        session(['currentTeam' => $otherTeam]);

        foreach ($components as $action => $component) {
            $component->call($action)->assertForbidden();
        }
        $memoryComponent->set('scannerMemoryMb', '8192')->assertForbidden();
        $timeoutComponent->set('scannerGrypeTimeout', '3600')->assertForbidden();

        expect($this->server->settings->fresh()->is_image_scan_enabled)->toBeFalse()
            ->and($this->server->settings->fresh()->image_scan_memory_mb)->toBe(1024)
            ->and($this->server->settings->fresh()->image_scan_grype_timeout)->toBe(600);
        Queue::assertNothingPushed();
    });

    it('does not show a server of another team', function () {
        $otherTeam = Team::factory()->create();
        $this->admin->teams()->attach($otherTeam, ['role' => 'owner']);
        $this->actingAs($this->admin);
        session(['currentTeam' => $otherTeam]);

        expect(fn () => Livewire::test(ServerImageScanning::class, ['server_uuid' => $this->server->uuid]))
            ->toThrow(ModelNotFoundException::class);
    });
});

describe('prepare image scanner job', function () {
    beforeEach(function () {
        Event::fake([ImageScanUpdated::class]);
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
    });

    it('pulls the scanner, updates the database, and broadcasts every change', function () {
        fakeScannerPreparation();

        (new PrepareImageScannerJob($this->server))->handle();

        expect(Cache::get(PrepareImageScannerJob::stateCacheKey($this->server)))->toBe('ready')
            ->and(Cache::get(PrepareImageScannerJob::builtAtCacheKey($this->server)))->toBe('2026-10-25T08:15:00Z')
            ->and(ScanImage::serverLock($this->server)->get())->toBeTrue();
        Event::assertDispatched(ImageScanUpdated::class, 3);
        Event::assertDispatched(ImageScanUpdated::class, fn ($event) => $event->resourceUuid === $this->server->uuid && $event->scanUuid === 'prepare' && $event->teamId === $this->team->id);
    });

    it('stores the disk used by the scanner data next to the database date', function () {
        fakeScannerPreparation();

        (new PrepareImageScannerJob($this->server))->handle();

        expect(Cache::get(PrepareImageScannerJob::diskUsedCacheKey($this->server)))->toBe(1234);
    });

    it('prepares the scanner under the scan lock and runs again later when it is busy', function () {
        fakeScannerPreparation();
        $lock = ScanImage::serverLock($this->server);
        $lock->get();

        $job = new PrepareImageScannerJob($this->server);
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(60);
        $job->setJob($queueJob);
        $job->handle();
        $lock->release();

        Process::assertNothingRan();
        expect(Cache::get(PrepareImageScannerJob::stateCacheKey($this->server)))->toBe('pulling');
    });

    it('is unique per server', function () {
        expect((new PrepareImageScannerJob($this->server))->uniqueId())->toBe((string) $this->server->id)
            ->and(new PrepareImageScannerJob($this->server))->toBeInstanceOf(ShouldBeUnique::class);
    });

    it('marks the state failed when the job fails', function () {
        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), 'pulling');

        (new PrepareImageScannerJob($this->server))->failed(new RuntimeException('killed'));

        expect(Cache::get(PrepareImageScannerJob::stateCacheKey($this->server)))->toBe('failed');
    });

    it('forgets the state when scanning was turned off during the database update', function () {
        Process::fake(function (PendingProcess $process) {
            if (str_contains(remoteProcessScript($process), 'coolify-scan-exit db-update')) {
                $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

                return Process::result(output: 'coolify-scan-exit db-update 0');
            }

            return Process::result(output: str_contains(remoteProcessScript($process), 'docker pull')
                ? "coolify-scan-exit pull-syft 0\ncoolify-scan-exit pull-grype 0\ncoolify-scan-exit pull-regctl 0\ncoolify-scan-exit pull-init 0\ncoolify-scan-exit db-volume 0"
                : '');
        });

        (new PrepareImageScannerJob($this->server))->handle();

        expect(Cache::has(PrepareImageScannerJob::stateCacheKey($this->server)))->toBeFalse();
    });

    it('marks a failed pull and stops before the database update', function () {
        fakeScannerPreparation(['prepare' => "coolify-scan-exit pull-syft 0\ncoolify-scan-exit pull-grype 125\ncoolify-scan-exit pull-regctl 0"]);

        (new PrepareImageScannerJob($this->server))->handle();

        expect(Cache::get(PrepareImageScannerJob::stateCacheKey($this->server)))->toBe('failed')
            ->and(Cache::get(PrepareImageScannerJob::failedStepCacheKey($this->server)))->toBe('pulling');
        Process::assertNotRan(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'coolify-scan-exit db-update'));
    });

    it('marks a failed storage setup and stops before the database update', function () {
        fakeScannerPreparation(['prepare' => "coolify-scan-exit pull-syft 0\ncoolify-scan-exit pull-grype 0\ncoolify-scan-exit pull-regctl 0\ncoolify-scan-exit pull-init 0\ncoolify-scan-exit db-volume 1"]);

        (new PrepareImageScannerJob($this->server))->handle();

        expect(Cache::get(PrepareImageScannerJob::stateCacheKey($this->server)))->toBe('failed')
            ->and(Cache::get(PrepareImageScannerJob::failedStepCacheKey($this->server)))->toBe('setup');
        Process::assertNotRan(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'coolify-scan-exit db-update'));
    });

    it('marks a connection failure separately from a failed pull', function () {
        Process::fake(fn () => Process::result(errorOutput: 'ssh: connect to host failed', exitCode: 255));

        (new PrepareImageScannerJob($this->server))->handle();

        expect(Cache::get(PrepareImageScannerJob::stateCacheKey($this->server)))->toBe('failed')
            ->and(Cache::get(PrepareImageScannerJob::failedStepCacheKey($this->server)))->toBe('connection');
    });

    it('names the step that failed and the registry the images come from', function (string $step, string $text) {
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), 'failed');
        Cache::put(PrepareImageScannerJob::failedStepCacheKey($this->server), $step);

        imageScanningPage()->assertSee($text)->assertDontSee('ghcr.io');
    })->with([
        'pull' => ['pulling', 'Coolify could not pull the scanner images. Check that the server can reach docker.io.'],
        'setup' => ['setup', 'Coolify could not set up the scanner storage on the server.'],
        'connection' => ['connection', 'Coolify could not connect to the server.'],
    ]);

    it('marks a failed database update', function () {
        fakeScannerPreparation(['db-update' => 'coolify-scan-exit db-update 1']);

        (new PrepareImageScannerJob($this->server))->handle();

        expect(Cache::get(PrepareImageScannerJob::stateCacheKey($this->server)))->toBe('failed')
            ->and(Cache::get(PrepareImageScannerJob::failedStepCacheKey($this->server)))->toBe('updating_database');
    });

    it('runs no command when scanning was turned off meanwhile', function () {
        Process::fake();
        $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

        (new PrepareImageScannerJob($this->server))->handle();

        Process::assertNothingRan();
        expect(Cache::has(PrepareImageScannerJob::stateCacheKey($this->server)))->toBeFalse();
    });

    it('runs no command for a server that another instance manages', function () {
        Process::fake();
        $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();

        (new PrepareImageScannerJob($this->server))->handle();

        Process::assertNothingRan();
    });
});

describe('create server image scanning choice', function () {
    beforeEach(function () {
        config(['app.maintenance.driver' => 'file']);
        $this->owner = User::factory()->create();
        $this->team->members()->attach($this->owner, ['role' => 'owner']);
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);
        $this->privateKey = $this->server->privateKey;
    });

    function createServerByIp(string $scanImages): ?Server
    {
        Livewire::test(ByIp::class, ['private_keys' => collect([test()->privateKey]), 'limit_reached' => false])
            ->set('name', 'scan-test')
            ->set('ip', '192.0.2.77')
            ->set('scan_images', $scanImages)
            ->call('submit');

        return Server::query()->where('ip', '192.0.2.77')->first();
    }

    it('starts from the team default', function () {
        $this->team->forceFill(['scan_images_on_new_servers' => true])->save();
        Cache::flush();

        Livewire::test(ByIp::class, ['private_keys' => collect([$this->privateKey]), 'limit_reached' => false])
            ->assertSet('scan_images', 'on');
    });

    it('starts with scanning off when the team default is off', function () {
        Livewire::test(ByIp::class, ['private_keys' => collect([$this->privateKey]), 'limit_reached' => false])
            ->assertSet('scan_images', 'off');
    });

    it('turns scanning on for the new server without queueing the scanner preparation', function () {
        Queue::fake();

        $server = createServerByIp('on');

        expect($server)->not->toBeNull()
            ->and($server->settings->is_image_scan_enabled)->toBeTrue();
        Queue::assertNothingPushed();
    });

    it('turns scanning on for a server created by a provider flow', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys' => Http::response(['ssh_key' => ['id' => 42, 'fingerprint' => 'ff:ff']], 201),
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response(['ssh_keys' => []], 200),
            'https://api.hetzner.cloud/v1/servers' => Http::response([
                'server' => ['id' => 777, 'status' => 'running', 'public_net' => ['ipv4' => ['ip' => '203.0.113.30']]],
            ], 201),
        ]);
        $token = CloudProviderToken::create(['team_id' => $this->team->id, 'provider' => 'hetzner', 'token' => 'test-hetzner-token', 'name' => 'Test token']);

        Livewire::test(ByHetzner::class, ['selectedTokenUuid' => $token->uuid])
            ->set('server_name', 'scan-hetzner')
            ->set('selected_location', 'fsn1')
            ->set('selected_server_type', 'cx22')
            ->set('selected_image', 114690387)
            ->set('private_key_id', $this->privateKey->id)
            ->set('scan_images', 'on')
            ->call('submit')
            ->assertHasNoErrors();

        expect(Server::query()->where('ip', '203.0.113.30')->firstOrFail()->settings->is_image_scan_enabled)->toBeTrue();
    });

    it('keeps scanning off when the choice is off', function () {
        expect(createServerByIp('off')->settings->is_image_scan_enabled)->toBeFalse();
    });

});
