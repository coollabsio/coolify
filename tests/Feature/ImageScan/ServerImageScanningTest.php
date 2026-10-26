<?php

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
        'prepare' => "coolify-scan-exit pull-syft 0\ncoolify-scan-exit pull-grype 0\ncoolify-scan-exit pull-regctl 0",
        'db-update' => 'coolify-scan-exit db-update 0',
        'disk' => "coolify-scan-exit disk-db 0 1000\ncoolify-scan-exit disk-sboms 0 234",
        'db-status' => "coolify-scan-exit db-status 0 90\ncoolify-scan-payload\n".json_encode(['built' => '2026-10-25T08:15:00Z', 'valid' => true]),
    ], $overrides);

    Process::fake(function (PendingProcess $process) use ($outputs) {
        $key = match (true) {
            str_contains($process->command, 'docker pull') => 'prepare',
            str_contains($process->command, 'coolify-scan-exit disk-db') => 'disk',
            str_contains($process->command, 'coolify-scan-exit db-status') => 'db-status',
            str_contains($process->command, 'coolify-scan-exit db-update') => 'db-update',
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
        Queue::assertPushed(PrepareImageScannerJob::class, fn ($job) => $job->server->is($this->server) && $job->queue === maintenance_queue());
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
            ->assertSee('Turn on image scanning');
    });

    it('shows the progress of the scanner preparation', function () {
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), 'ready');
        Cache::put(PrepareImageScannerJob::builtAtCacheKey($this->server), now()->subDay()->toIso8601ZuluString());

        imageScanningPage()
            ->assertSee('Get scanner')
            ->assertSee('Update database')
            ->assertSee('Database built')
            ->assertSee('Turn off image scanning')
            ->assertSee('Delete scanner data');
    });

    it('refuses to turn on a server that another instance manages', function () {
        Queue::fake();
        $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();

        imageScanningPage()->call('enable')->assertDispatched('error', 'This server is managed by another Coolify instance.');

        expect($this->server->settings->fresh()->is_image_scan_enabled)->toBeFalse();
        Queue::assertNothingPushed();
    });

    it('shows the not allowed state and refuses to turn on when the instance does not allow scanning', function () {
        Queue::fake();
        InstanceSettings::get()->forceFill(['is_image_scanning_allowed' => false])->save();

        imageScanningPage()
            ->assertSee('Image scanning is turned off for this Coolify instance.')
            ->assertDontSee('Turn on image scanning')
            ->call('enable')
            ->assertDispatched('error', message: 'Image scanning is turned off for this Coolify instance.');

        expect($this->server->settings->fresh()->is_image_scan_enabled)->toBeFalse();
        Queue::assertNothingPushed();
    });

    it('deletes the scanner data on the server and forgets the progress', function () {
        Process::fake(fn () => Process::result(output: ''));
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), 'ready');

        imageScanningPage()->call('deleteScannerData')->assertDispatched('success', 'Scanner data deleted.');

        Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'docker volume rm -f coolify-grype-db'));
        expect(Cache::has(PrepareImageScannerJob::stateCacheKey($this->server)))->toBeFalse();
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

    it('shows no disk usage before the job has stored it', function () {
        $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();

        imageScanningPage()->assertDontSee('Disk used by scanner data');
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

        $this->member->teams()->updateExistingPivot($this->team->id, ['role' => 'member']);
        $this->member->unsetRelation('teams');
        session(['currentTeam' => $otherTeam]);

        foreach ($components as $action => $component) {
            $component->call($action)->assertForbidden();
        }

        expect($this->server->settings->fresh()->is_image_scan_enabled)->toBeFalse();
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
            ->and(Cache::get(PrepareImageScannerJob::builtAtCacheKey($this->server)))->toBe('2026-10-25T08:15:00Z');
        Event::assertDispatched(ImageScanUpdated::class, 3);
        Event::assertDispatched(ImageScanUpdated::class, fn ($event) => $event->resourceUuid === $this->server->uuid && $event->scanUuid === 'prepare' && $event->teamId === $this->team->id);
    });

    it('stores the disk used by the scanner data next to the database date', function () {
        fakeScannerPreparation();

        (new PrepareImageScannerJob($this->server))->handle();

        expect(Cache::get(PrepareImageScannerJob::diskUsedCacheKey($this->server)))->toBe(1234);
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
            if (str_contains($process->command, 'coolify-scan-exit db-update')) {
                $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

                return Process::result(output: 'coolify-scan-exit db-update 0');
            }

            return Process::result(output: str_contains($process->command, 'docker pull')
                ? "coolify-scan-exit pull-syft 0\ncoolify-scan-exit pull-grype 0\ncoolify-scan-exit pull-regctl 0"
                : '');
        });

        (new PrepareImageScannerJob($this->server))->handle();

        expect(Cache::has(PrepareImageScannerJob::stateCacheKey($this->server)))->toBeFalse();
    });

    it('uses the database URL of the instance', function () {
        InstanceSettings::get()->forceFill(['image_scan_db_url' => 'https://mirror.example.com/grype/listing.json'])->save();
        fakeScannerPreparation();

        (new PrepareImageScannerJob($this->server))->handle();

        Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'GRYPE_DB_UPDATE_URL') && str_contains($process->command, 'mirror.example.com'));
    });

    it('marks a failed pull and stops before the database update', function () {
        fakeScannerPreparation(['prepare' => "coolify-scan-exit pull-syft 0\ncoolify-scan-exit pull-grype 125\ncoolify-scan-exit pull-regctl 0"]);

        (new PrepareImageScannerJob($this->server))->handle();

        expect(Cache::get(PrepareImageScannerJob::stateCacheKey($this->server)))->toBe('failed')
            ->and(Cache::get(PrepareImageScannerJob::failedStepCacheKey($this->server)))->toBe('pulling');
        Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'coolify-scan-exit db-update'));
    });

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

    it('runs no command when the instance does not allow scanning', function () {
        Process::fake();
        InstanceSettings::get()->forceFill(['is_image_scanning_allowed' => false])->save();

        (new PrepareImageScannerJob($this->server))->handle();

        Process::assertNothingRan();
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

    it('ignores the choice when the instance does not allow scanning', function () {
        InstanceSettings::get()->forceFill(['is_image_scanning_allowed' => false])->save();

        expect(createServerByIp('on')->settings->is_image_scan_enabled)->toBeFalse();
    });
});
