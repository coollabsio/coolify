<?php

use App\Models\Application;
use App\Models\DnsProviderZone;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\Dns\ManagedDnsHostnameLock;
use App\Services\Dns\ManagedDnsRecordCleanup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('app.id', 'test-instance');

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_dns_validation_enabled' => false],
    ));

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'ip' => '203.0.113.10']);
    $this->destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker'],
    ));
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->token = IntegrationToken::factory()->for($this->team)->create(['provider' => 'cloudflare', 'token' => 'secret']);
    $this->zone = DnsProviderZone::factory()->for($this->token)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);
});

/**
 * Creates an owned record for the application through the provider, then deletes the application without
 * the model events, so the release job never runs (like a release job that gave up after a Cloudflare outage).
 */
function orphanedDnsRecord(object $test, string $hostname = 'app.example.com', bool $forceDelete = false): ManagedDnsRecord
{
    $application = createDnsTestApplication($test, "https://{$hostname}");
    $record = app(CloudflareDnsProvider::class)->createRecord($test->zone, $hostname, '203.0.113.10', $application);
    Application::withoutEvents(fn () => $forceDelete ? $application->forceDelete() : $application->delete());

    return $record;
}

function releaseOrphanedDnsRecords(): void
{
    test()->artisan('dns:release-orphaned-records')->assertSuccessful();
}

test('an orphaned owned record is deleted in Cloudflare and forgotten', function (bool $forceDelete) {
    $cloudflare = fakeCloudflareDns();
    $record = orphanedDnsRecord($this, forceDelete: $forceDelete);

    test()->artisan('dns:release-orphaned-records')
        ->expectsOutputToContain('deleted: 1')
        ->assertSuccessful();

    expect(sentDnsRequests('DELETE'))->toBe(1)
        ->and($cloudflare['records'])->toBeEmpty()
        ->and($record->fresh())->toBeNull();
})->with([
    'soft-deleted resource' => false,
    'force-deleted resource' => true,
]);

test('a record with a live reference is not touched', function () {
    $cloudflare = fakeCloudflareDns();
    $application = createDnsTestApplication($this, 'https://app.example.com');
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
    $deleted = createDnsTestApplication($this, 'https://app.example.com');
    $record->addReference($deleted);
    Application::withoutEvents(fn () => $deleted->delete());
    $this->travel(2)->hours();

    releaseOrphanedDnsRecords();

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and($cloudflare['records'])->toHaveCount(1)
        ->and($record->fresh())->not->toBeNull()
        ->and($record->references()->pluck('resource_id')->sort()->values()->all())->toBe([$application->id, $deleted->id]);
});

test('an orphan whose hostname a live resource uses again is kept and referenced again', function () {
    $cloudflare = fakeCloudflareDns();
    $record = orphanedDnsRecord($this);
    $readded = createDnsTestApplication($this, 'https://app.example.com');

    releaseOrphanedDnsRecords();

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and($cloudflare['records'])->toHaveCount(1)
        ->and($record->fresh())->not->toBeNull()
        ->and($record->references()->pluck('resource_id')->all())->toBe([$readded->id]);
});

test('an orphan is kept for the next run while the hostname lock is held', function () {
    $cloudflare = fakeCloudflareDns();
    app()->instance(ManagedDnsHostnameLock::class, new ManagedDnsHostnameLock(waitSeconds: 0));
    $record = orphanedDnsRecord($this);
    $heldLock = Cache::lock(ManagedDnsHostnameLock::key($this->team->id, 'app.example.com'), 60);
    expect($heldLock->get())->toBeTrue();

    test()->artisan('dns:release-orphaned-records')
        ->expectsOutputToContain('busy: 1')
        ->assertSuccessful();

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and($record->fresh())->not->toBeNull()
        ->and($record->references()->count())->toBe(1);

    $heldLock->release();
    releaseOrphanedDnsRecords();

    expect(sentDnsRequests('DELETE'))->toBe(1)
        ->and($cloudflare['records'])->toBeEmpty()
        ->and($record->fresh())->toBeNull();
});

test('an orphan is kept for the next run when Cloudflare fails', function () {
    $cloudflare = fakeCloudflareDns();
    $record = orphanedDnsRecord($this);
    $cloudflareIsDown = true;
    Http::fake(function () use (&$cloudflareIsDown) {
        if ($cloudflareIsDown) {
            throw new ConnectionException('Cloudflare is unreachable.');
        }

        return null;
    });

    test()->artisan('dns:release-orphaned-records')
        ->expectsOutputToContain('failed: 1')
        ->assertSuccessful();

    expect($record->fresh())->not->toBeNull()
        ->and($cloudflare['records'])->toHaveCount(1);

    $cloudflareIsDown = false;
    $this->travel(2)->hours();
    releaseOrphanedDnsRecords();

    expect($cloudflare['records'])->toBeEmpty()
        ->and($record->fresh())->toBeNull();
});

test('a record without references is kept during the grace period and released after it', function () {
    $cloudflare = fakeCloudflareDns();
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10');

    releaseOrphanedDnsRecords();

    expect(sentDnsRequests('GET'))->toBe(1)
        ->and(sentDnsRequests('DELETE'))->toBe(0)
        ->and($record->fresh())->not->toBeNull();

    $this->travel(ManagedDnsRecordCleanup::ORPHAN_GRACE_MINUTES + 1)->minutes();
    releaseOrphanedDnsRecords();

    expect(sentDnsRequests('DELETE'))->toBe(1)
        ->and($cloudflare['records'])->toBeEmpty()
        ->and($record->fresh())->toBeNull();
});

test('a reference added before the lock is taken keeps the record and the reference', function () {
    $cloudflare = fakeCloudflareDns();
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10');
    $application = createDnsTestApplication($this, 'https://app.example.com');
    $lock = new class extends ManagedDnsHostnameLock
    {
        public ?Closure $beforeNextRun = null;

        public function run(int $teamId, string $hostname, callable $callback): mixed
        {
            if ($this->beforeNextRun !== null) {
                ($this->beforeNextRun)();
                $this->beforeNextRun = null;
            }

            return parent::run($teamId, $hostname, $callback);
        }
    };
    $lock->beforeNextRun = fn () => $record->addReference($application);
    app()->instance(ManagedDnsHostnameLock::class, $lock);
    $this->travel(2)->hours();

    releaseOrphanedDnsRecords();

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and($cloudflare['records'])->toHaveCount(1)
        ->and($record->references()->pluck('resource_id')->all())->toBe([$application->id]);
});

test('a not owned orphan is only forgotten and never changed in Cloudflare', function () {
    $cloudflare = fakeCloudflareDns([[
        'id' => 'hand-made', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10',
        'proxied' => true, 'ttl' => 1, 'comment' => null,
    ]]);
    $application = createDnsTestApplication($this, 'https://app.example.com');
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
    Application::withoutEvents(fn () => $application->delete());
    expect($record->owned)->toBeFalse();
    $requestsBefore = count(Http::recorded());

    test()->artisan('dns:release-orphaned-records')
        ->expectsOutputToContain('not_owned: 1')
        ->assertSuccessful();

    expect(count(Http::recorded()))->toBe($requestsBefore)
        ->and($cloudflare['records'])->toHaveCount(1)
        ->and($record->fresh())->toBeNull();
});

describe('instance sentinels', function () {
    beforeEach(function () {
        $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
        $this->rootToken = IntegrationToken::factory()->for($this->rootTeam)->create(['provider' => 'cloudflare', 'token' => 'root-secret']);
        $this->rootZone = DnsProviderZone::factory()->for($this->rootToken)->create(['provider_zone_id' => 'zone-root', 'name' => 'root.example.com']);
        $rootProject = Project::factory()->create(['team_id' => 0]);
        $this->rootEnvironment = Environment::factory()->create(['project_id' => $rootProject->id]);
        $this->rootApplication = Application::factory()->create([
            'id' => 0,
            'environment_id' => $this->rootEnvironment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'fqdn' => 'https://coolify.root.example.com',
            'build_pack' => 'nixpacks',
        ]);
        $this->rootRecord = ManagedDnsRecord::factory()->owned()->create([
            'id' => 0, 'dns_provider_zone_id' => $this->rootZone->id, 'provider_record_id' => 'record-root',
            'name' => 'coolify.root.example.com', 'content' => '203.0.113.10',
        ]);
        $this->rootRecord->addReference($this->rootApplication);
        $this->cloudflareRecords = [[
            'id' => 'record-root', 'type' => 'A', 'name' => 'coolify.root.example.com', 'content' => '203.0.113.10',
            'proxied' => false, 'ttl' => 1, 'comment' => $this->rootRecord->ownershipComment(),
        ]];
    });

    test('a record with id 0 of the root team is released when its resource with id 0 is gone', function () {
        $cloudflare = fakeCloudflareDns($this->cloudflareRecords);
        Application::withoutEvents(fn () => $this->rootApplication->delete());

        releaseOrphanedDnsRecords();

        expect(sentDnsRequests('DELETE'))->toBe(1)
            ->and($cloudflare['records'])->toBeEmpty()
            ->and(ManagedDnsRecord::query()->find(0))->toBeNull();
    });

    test('a record with id 0 of a live root team resource with id 0 is kept and later orphans are still released', function () {
        $cloudflare = fakeCloudflareDns($this->cloudflareRecords);
        $orphan = orphanedDnsRecord($this);

        releaseOrphanedDnsRecords();

        expect(sentDnsRequests('DELETE'))->toBe(1)
            ->and(collect($cloudflare['records'])->keys()->all())->toBe(['record-root'])
            ->and($orphan->fresh())->toBeNull()
            ->and(ManagedDnsRecord::query()->find(0))->not->toBeNull()
            ->and(ManagedDnsRecord::query()->find(0)->references()->pluck('resource_id')->all())->toBe([0]);
    });
});

test('the orphan clean-up is scheduled hourly on one server', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'dns:release-orphaned-records'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});
