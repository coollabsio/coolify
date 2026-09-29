<?php

use App\Enums\ManagedDnsDeletionResult;
use App\Jobs\CheckDomainDnsJob;
use App\Jobs\ConfigureDnsRecordJob;
use App\Jobs\ReleaseManagedDnsRecordsJob;
use App\Jobs\ReleaseRemovedDnsHostnamesJob;
use App\Livewire\Project\Application\Domains;
use App\Livewire\Project\Service\Domains as ServiceDomains;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\DnsProviderZone;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\Dns\ManagedDnsHostnameLock;
use App\Services\Dns\ManagedDnsRecordCleanup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Queue::fake([CheckDomainDnsJob::class]);
    config()->set('app.maintenance.store', 'array');
    config()->set('app.id', 'test-instance');

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_dns_validation_enabled' => false, 'is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'ip' => '203.0.113.10']);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
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
 * A hostname lock that runs a callback right before the next lock is taken, to simulate a concurrent change
 * that happens between the release query and the lock.
 */
class InterleavingManagedDnsHostnameLock extends ManagedDnsHostnameLock
{
    public ?Closure $beforeNextRun = null;

    public function run(int $teamId, string $hostname, callable $callback): mixed
    {
        if ($this->beforeNextRun !== null) {
            $interleaved = $this->beforeNextRun;
            $this->beforeNextRun = null;
            $interleaved();
        }

        return parent::run($teamId, $hostname, $callback);
    }
}

function editReleaseApiToken(User $user, Team $team, array $abilities = ['*']): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'dns-edit-release-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);
    auth()->logout();

    return $token->getKey().'|'.$plainTextToken;
}

function editReleaseService(object $test, string $fqdn): ServiceApplication
{
    $service = Service::factory()->create([
        'server_id' => $test->server->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
        'environment_id' => $test->environment->id,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);

    return ServiceApplication::create([
        'uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'web', 'human_name' => 'Web',
        'image' => 'nginx:alpine', 'fqdn' => $fqdn,
    ]);
}

function editApplicationDomain(Application $application, string $newHost): void
{
    Livewire::test(Domains::class, ['application' => $application->fresh()])
        ->call('startEdit', 0)
        ->set('editingDomainParts.scheme', 'https')
        ->set('editingDomainParts.host', $newHost)
        ->call('updateDomain')
        ->assertHasNoErrors();
}

describe('UI application domain edit', function () {
    test('an edited hostname releases and deletes its owned record', function () {
        $cloudflare = fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

        Livewire::test(Domains::class, ['application' => $application])
            ->call('startEdit', 0)
            ->set('editingDomainParts.host', 'new.example.com')
            ->call('updateDomain')
            ->assertDispatched('success', 'Domain updated.')
            ->assertNotDispatched('warning');

        expect($application->fresh()->fqdn)->toBe('https://new.example.com')
            ->and(sentDnsRequests('DELETE'))->toBe(1)
            ->and($cloudflare['records'])->toBeEmpty()
            ->and($record->fresh())->toBeNull();
    });

    test('an edit that keeps the hostname keeps the record', function () {
        $cloudflare = fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

        Livewire::test(Domains::class, ['application' => $application])
            ->call('startEdit', 0)
            ->set('editingDomainParts.scheme', 'http')
            ->set('editingDomainParts.path', '/api')
            ->call('updateDomain')
            ->assertDispatched('success', 'Domain updated.');

        expect($application->fresh()->fqdn)->toBe('http://app.example.com/api')
            ->and(sentDnsRequests('DELETE'))->toBe(0)
            ->and($cloudflare['records'])->toHaveCount(1)
            ->and($record->references()->pluck('resource_id')->all())->toBe([$application->id]);
    });

    test('a hostname still used by another application of the team keeps the record', function () {
        $cloudflare = fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
        $other = createDnsTestApplication($this, 'https://app.example.com/other');

        editApplicationDomain($application, 'new.example.com');

        expect(sentDnsRequests('DELETE'))->toBe(0)
            ->and($cloudflare['records'])->toHaveCount(1)
            ->and($record->references()->pluck('resource_id')->all())->toBe([$other->id]);
    });

    test('records of another team are never touched', function () {
        $cloudflare = fakeCloudflareDns([[
            'id' => 'record-foreign', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10', 'comment' => null,
        ]]);
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $otherToken = IntegrationToken::factory()->for(Team::factory()->create())->create(['provider' => 'cloudflare', 'token' => 'other']);
        $otherZone = DnsProviderZone::factory()->for($otherToken)->create(['provider_zone_id' => 'zone-2', 'name' => 'example.com']);
        $foreignRecord = ManagedDnsRecord::factory()->owned()->create([
            'dns_provider_zone_id' => $otherZone->id, 'provider_record_id' => 'record-foreign',
            'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10',
        ]);
        $foreignRecord->addReference($application);

        editApplicationDomain($application, 'new.example.com');

        expect(Http::recorded())->toBeEmpty()
            ->and($cloudflare['records'])->toHaveKey('record-foreign')
            ->and($foreignRecord->fresh())->not->toBeNull()
            ->and($foreignRecord->references()->count())->toBe(1);
    });

    test('a member without update permission cannot release a record by editing', function () {
        $cloudflare = fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
        $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
        $this->actingAs($this->user->fresh());

        Livewire::test(Domains::class, ['application' => $application->fresh()])
            ->set('editingIndex', 0)
            ->set('editingDomainParts.host', 'new.example.com')
            ->call('updateDomain')
            ->assertNotDispatched('success');

        expect($application->fresh()->fqdn)->toBe('https://app.example.com')
            ->and(sentDnsRequests('DELETE'))->toBe(0)
            ->and($cloudflare['records'])->toHaveCount(1)
            ->and($record->fresh())->not->toBeNull();
    });

    test('an adopted record is forgotten but never deleted', function () {
        $cloudflare = fakeCloudflareDns([[
            'id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10', 'comment' => 'hand made',
        ]]);
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

        Livewire::test(Domains::class, ['application' => $application])
            ->call('startEdit', 0)
            ->set('editingDomainParts.host', 'new.example.com')
            ->call('updateDomain')
            ->assertDispatched('success', 'Domain updated.')
            ->assertNotDispatched('warning');

        expect($record->owned)->toBeFalse()
            ->and(sentDnsRequests('DELETE'))->toBe(0)
            ->and($cloudflare['records'])->toHaveKey('record-1')
            ->and($record->fresh())->toBeNull();
    });

    test('a record changed outside Coolify stays and a warning is shown', function () {
        $cloudflare = fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
        $records = $cloudflare['records'];
        $records[$record->provider_record_id]['comment'] = 'production record';
        $cloudflare['records'] = $records;

        Livewire::test(Domains::class, ['application' => $application])
            ->call('startEdit', 0)
            ->set('editingDomainParts.host', 'new.example.com')
            ->call('updateDomain')
            ->assertDispatched('warning', 'The domain was updated, but its DNS record changed externally and was left untouched.');

        expect(sentDnsRequests('DELETE'))->toBe(0)
            ->and($cloudflare['records'])->toHaveKey($record->provider_record_id)
            ->and($record->fresh())->toBeNull();
    });

    test('an unreachable provider keeps the record and shows a warning', function () {
        fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
        Http::fake(fn () => throw new ConnectionException('Cloudflare is unreachable.'));

        Livewire::test(Domains::class, ['application' => $application])
            ->call('startEdit', 0)
            ->set('editingDomainParts.host', 'new.example.com')
            ->call('updateDomain')
            ->assertDispatched('warning', 'The domain was updated, but its DNS record could not be deleted because Cloudflare did not respond. It was left untouched.');

        expect($application->fresh()->fqdn)->toBe('https://new.example.com')
            ->and($record->fresh())->not->toBeNull()
            ->and($record->references()->count())->toBe(1);
    });
});

describe('UI service domain edit', function () {
    test('an edited hostname releases and deletes its owned record', function () {
        $cloudflare = fakeCloudflareDns();
        $serviceApplication = editReleaseService($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $serviceApplication);

        Livewire::test(ServiceDomains::class, ['service' => $serviceApplication->service->fresh(['applications', 'server'])])
            ->call('startEdit', 0)
            ->set('editingDomainParts.host', 'new.example.com')
            ->call('updateDomain')
            ->assertDispatched('success', 'Domain updated.')
            ->assertNotDispatched('warning');

        expect($serviceApplication->fresh()->fqdn)->toBe('https://new.example.com')
            ->and(sentDnsRequests('DELETE'))->toBe(1)
            ->and($cloudflare['records'])->toBeEmpty()
            ->and($record->fresh())->toBeNull();
    });

    test('a member without update permission cannot release a record by editing', function () {
        $cloudflare = fakeCloudflareDns();
        $serviceApplication = editReleaseService($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $serviceApplication);
        $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
        $this->actingAs($this->user->fresh());

        Livewire::test(ServiceDomains::class, ['service' => $serviceApplication->service->fresh(['applications', 'server'])])
            ->set('editingIndex', 0)
            ->set('editingServiceApplicationId', $serviceApplication->id)
            ->set('editingDomainParts.host', 'new.example.com')
            ->call('updateDomain')
            ->assertNotDispatched('success');

        expect($serviceApplication->fresh()->fqdn)->toBe('https://app.example.com')
            ->and(sentDnsRequests('DELETE'))->toBe(0)
            ->and($cloudflare['records'])->toHaveCount(1)
            ->and($record->fresh())->not->toBeNull();
    });
});

describe('API domain edit', function () {
    test('an application domain update releases the removed hostname', function () {
        $cloudflare = fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://app.example.com,https://keep.example.com');
        $provider = app(CloudflareDnsProvider::class);
        $removed = $provider->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
        $kept = $provider->createRecord($this->zone, 'keep.example.com', '203.0.113.10', $application);

        $this->withToken(editReleaseApiToken($this->user, $this->team))
            ->patchJson("/api/v1/applications/{$application->uuid}", ['domains' => 'https://keep.example.com,https://new.example.com'])
            ->assertOk();

        expect($application->fresh()->fqdn)->toBe('https://keep.example.com,https://new.example.com')
            ->and(sentDnsRequests('DELETE'))->toBe(1)
            ->and($removed->fresh())->toBeNull()
            ->and($kept->fresh())->not->toBeNull()
            ->and($cloudflare['records'])->toHaveCount(1);
    });

    test('an application docker compose domain update releases the removed hostname', function () {
        $cloudflare = fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://unused.example.org');
        $application->update([
            'build_pack' => 'dockercompose',
            'fqdn' => null,
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  api:\n    image: nginx:alpine\n",
            'docker_compose_domains' => json_encode([
                'web' => ['domain' => 'https://web.example.com'],
                'api' => ['domain' => 'https://api.example.com'],
            ]),
        ]);
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'api.example.com', '203.0.113.10', $application);

        $this->withToken(editReleaseApiToken($this->user, $this->team))
            ->patchJson("/api/v1/applications/{$application->uuid}", ['docker_compose_domains' => [
                ['name' => 'web', 'domain' => 'https://web.example.com'],
                ['name' => 'api', 'domain' => 'https://api2.example.com'],
            ]])
            ->assertOk();

        expect(sentDnsRequests('DELETE'))->toBe(1)
            ->and($cloudflare['records'])->toBeEmpty()
            ->and($record->fresh())->toBeNull();
    });

    test('a service domain update releases the removed hostname', function (string $endpoint) {
        $cloudflare = fakeCloudflareDns();
        $serviceApplication = editReleaseService($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $serviceApplication);
        $service = $serviceApplication->service;
        [$path, $payload] = $endpoint === 'service'
            ? ["/api/v1/services/{$service->uuid}", ['urls' => [['name' => 'web', 'url' => 'https://new.example.com']]]]
            : ["/api/v1/services/{$service->uuid}/applications/{$serviceApplication->uuid}", ['url' => 'https://new.example.com']];

        $this->withToken(editReleaseApiToken($this->user, $this->team))->patchJson($path, $payload)->assertOk();

        expect($serviceApplication->fresh()->fqdn)->toBe('https://new.example.com')
            ->and(sentDnsRequests('DELETE'))->toBe(1)
            ->and($cloudflare['records'])->toBeEmpty()
            ->and($record->fresh())->toBeNull();
    })->with(['service', 'service application']);

    test('a preview domain update releases the removed hostname', function () {
        $cloudflare = fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://main.example.org');
        $preview = ApplicationPreview::query()->create([
            'application_id' => $application->id, 'pull_request_id' => 5, 'pull_request_html_url' => 'https://github.com/x/y/pull/5',
            'fqdn' => 'https://pr-5.example.com',
        ]);
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'pr-5.example.com', '203.0.113.10', $preview);

        $this->withToken(editReleaseApiToken($this->user, $this->team))
            ->patchJson("/api/v1/applications/{$application->uuid}/previews/5", ['domains' => 'https://pr-5-new.example.com'])
            ->assertOk();

        expect(sentDnsRequests('DELETE'))->toBe(1)
            ->and($cloudflare['records'])->toBeEmpty()
            ->and($record->fresh())->toBeNull();
    });

    test('a member token cannot release a record by editing', function () {
        $cloudflare = fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
        $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

        $this->withToken(editReleaseApiToken($this->user->fresh(), $this->team))
            ->patchJson("/api/v1/applications/{$application->uuid}", ['domains' => 'https://new.example.com'])
            ->assertForbidden();

        expect($application->fresh()->fqdn)->toBe('https://app.example.com')
            ->and(sentDnsRequests('DELETE'))->toBe(0)
            ->and($cloudflare['records'])->toHaveCount(1)
            ->and($record->fresh())->not->toBeNull();
    });

    test('a token of another team cannot release a record by editing', function () {
        $cloudflare = fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
        $otherTeam = Team::factory()->create();
        $otherUser = User::factory()->create();
        $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);

        $this->withToken(editReleaseApiToken($otherUser, $otherTeam))
            ->patchJson("/api/v1/applications/{$application->uuid}", ['domains' => 'https://new.example.com'])
            ->assertNotFound();

        expect(sentDnsRequests('DELETE'))->toBe(0)
            ->and($cloudflare['records'])->toHaveCount(1)
            ->and($record->fresh())->not->toBeNull();
    });

    test('nothing is queued when the resource references no managed record', function () {
        Queue::fake([ReleaseRemovedDnsHostnamesJob::class]);
        fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://app.example.com');

        $this->withToken(editReleaseApiToken($this->user, $this->team))
            ->patchJson("/api/v1/applications/{$application->uuid}", ['domains' => 'https://new.example.com'])
            ->assertOk();

        Queue::assertNotPushed(ReleaseRemovedDnsHostnamesJob::class);
    });

    test('the queued release retries while cloudflare is unreachable', function () {
        fakeCloudflareDns();
        $application = createDnsTestApplication($this, 'https://new.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
        Http::fake(fn () => throw new ConnectionException('Cloudflare is unreachable.'));

        $job = (new ReleaseRemovedDnsHostnamesJob($application->getMorphClass(), $application->id, $this->team->id, ['app.example.com']))
            ->withFakeQueueInteractions();
        $job->handle(app(ManagedDnsRecordCleanup::class));

        $job->assertReleased(60);
        expect($record->fresh())->not->toBeNull();
    });
});

describe('hostname lock', function () {
    test('a release while the hostname lock is held keeps the record and warns', function () {
        $cloudflare = fakeCloudflareDns();
        app()->instance(ManagedDnsHostnameLock::class, new ManagedDnsHostnameLock(waitSeconds: 0));
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
        $heldLock = Cache::lock(ManagedDnsHostnameLock::key($this->team->id, 'app.example.com'), 60);
        expect($heldLock->get())->toBeTrue();

        Livewire::test(Domains::class, ['application' => $application])
            ->call('startEdit', 0)
            ->set('editingDomainParts.host', 'new.example.com')
            ->call('updateDomain')
            ->assertDispatched('warning', 'The domain was updated, but another DNS change for the same hostname was in progress. Its DNS record was left untouched.');

        expect(sentDnsRequests('DELETE'))->toBe(0)
            ->and($cloudflare['records'])->toHaveCount(1)
            ->and($record->fresh())->not->toBeNull()
            ->and($record->references()->count())->toBe(1);
        $heldLock->release();
    });

    test('the release job of a deleted resource retries while the hostname lock is held', function () {
        $cloudflare = fakeCloudflareDns();
        app()->instance(ManagedDnsHostnameLock::class, new ManagedDnsHostnameLock(waitSeconds: 0));
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
        Application::withoutEvents(fn () => $application->delete());
        $heldLock = Cache::lock(ManagedDnsHostnameLock::key($this->team->id, 'app.example.com'), 60);
        $heldLock->get();

        $job = (new ReleaseManagedDnsRecordsJob($application->getMorphClass(), $application->id))->withFakeQueueInteractions();
        $job->handle(app(ManagedDnsRecordCleanup::class));

        $job->assertReleased(60);
        expect(sentDnsRequests('DELETE'))->toBe(0)->and($record->fresh())->not->toBeNull();

        $heldLock->release();
        $retry = (new ReleaseManagedDnsRecordsJob($application->getMorphClass(), $application->id))->withFakeQueueInteractions();
        $retry->handle(app(ManagedDnsRecordCleanup::class));

        $retry->assertNotReleased();
        expect(sentDnsRequests('DELETE'))->toBe(1)
            ->and($cloudflare['records'])->toBeEmpty()
            ->and($record->fresh())->toBeNull();
    });

    test('a create while a release holds the lock is retried and then creates the record', function () {
        fakeCloudflareDns();
        app()->instance(ManagedDnsHostnameLock::class, new ManagedDnsHostnameLock(waitSeconds: 0));
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $heldLock = Cache::lock(ManagedDnsHostnameLock::key($this->team->id, 'app.example.com'), 60);
        $heldLock->get();

        $job = (new ConfigureDnsRecordJob($this->team->id, $this->zone->id, $application->getMorphClass(), $application->id, 'app.example.com', '203.0.113.10'))
            ->withFakeQueueInteractions();
        $job->handle(app(CloudflareDnsProvider::class));

        $job->assertReleased(10);
        expect(sentDnsRequests('POST'))->toBe(0)->and(ManagedDnsRecord::query()->count())->toBe(0);

        $heldLock->release();
        $retry = (new ConfigureDnsRecordJob($this->team->id, $this->zone->id, $application->getMorphClass(), $application->id, 'app.example.com', '203.0.113.10'))
            ->withFakeQueueInteractions();
        $retry->handle(app(CloudflareDnsProvider::class));

        $retry->assertNotReleased();
        $record = ManagedDnsRecord::query()->sole();
        expect(sentDnsRequests('POST'))->toBe(1)
            ->and($record->owned)->toBeTrue()
            ->and($record->references()->pluck('resource_id')->all())->toBe([$application->id]);
    });

    test('a create from the UI while the lock is held shows a clear message', function () {
        fakeCloudflareDns();
        app()->instance(ManagedDnsHostnameLock::class, new ManagedDnsHostnameLock(waitSeconds: 0));
        $application = createDnsTestApplication($this, 'https://app.example.com');
        $heldLock = Cache::lock(ManagedDnsHostnameLock::key($this->team->id, 'app.example.com'), 60);
        $heldLock->get();

        Livewire::test(Domains::class, ['application' => $application])
            ->call('createManagedDnsRecord', 'app.example.com', $this->zone->id)
            ->assertDispatched('error', 'Another DNS change for app.example.com is in progress. Try again in a few seconds.');

        expect(sentDnsRequests('POST'))->toBe(0)->and(ManagedDnsRecord::query()->count())->toBe(0);
        $heldLock->release();
    });

    test('a create that waited for a release creates the deleted record again', function () {
        $cloudflare = fakeCloudflareDns();
        $lock = new InterleavingManagedDnsHostnameLock;
        app()->instance(ManagedDnsHostnameLock::class, $lock);
        $oldApplication = createDnsTestApplication($this, 'https://app.example.com');
        $oldRecord = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $oldApplication);
        $oldApplication->update(['fqdn' => 'https://moved.example.com']);
        $newApplication = createDnsTestApplication($this, 'https://unrelated.example.org');

        // The release holds the lock first; the new application adds the hostname while the create waits for it.
        $lock->beforeNextRun = function () use ($oldApplication, $newApplication): void {
            app(ManagedDnsRecordCleanup::class)->releaseHostname($oldApplication, 'app.example.com', $this->team->id);
            $newApplication->update(['fqdn' => 'https://app.example.com']);
        };
        $newRecord = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $newApplication);

        expect(sentDnsRequests('DELETE'))->toBe(1)
            ->and(sentDnsRequests('POST'))->toBe(2)
            ->and($oldRecord->fresh())->toBeNull()
            ->and($newRecord->fresh())->not->toBeNull()
            ->and($newRecord->provider_record_id)->not->toBe($oldRecord->provider_record_id)
            ->and($newRecord->owned)->toBeTrue()
            ->and($newRecord->references()->pluck('resource_id')->all())->toBe([$newApplication->id])
            ->and($cloudflare['records'])->toHaveCount(1);
    });

    test('the release keeps the record when a new reference appears before the lock is taken', function () {
        $cloudflare = fakeCloudflareDns();
        $lock = new InterleavingManagedDnsHostnameLock;
        app()->instance(ManagedDnsHostnameLock::class, $lock);
        $application = createDnsTestApplication($this, 'https://moved.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
        $other = createDnsTestApplication($this, 'https://unrelated.example.org');

        $lock->beforeNextRun = fn () => $record->addReference($other);
        $result = app(ManagedDnsRecordCleanup::class)->releaseHostname($application, 'app.example.com', $this->team->id);

        expect($result)->toBeNull()
            ->and(sentDnsRequests('DELETE'))->toBe(0)
            ->and($cloudflare['records'])->toHaveCount(1)
            ->and($record->references()->pluck('resource_id')->all())->toBe([$other->id]);
    });

    test('the release keeps the record when the resource uses the hostname again before the lock is taken', function () {
        $cloudflare = fakeCloudflareDns();
        $lock = new InterleavingManagedDnsHostnameLock;
        app()->instance(ManagedDnsHostnameLock::class, $lock);
        $application = createDnsTestApplication($this, 'https://moved.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

        $lock->beforeNextRun = fn () => $application->update(['fqdn' => 'https://app.example.com']);
        $result = app(ManagedDnsRecordCleanup::class)->releaseHostname($application, 'app.example.com', $this->team->id);

        expect($result)->toBeNull()
            ->and(sentDnsRequests('DELETE'))->toBe(0)
            ->and($cloudflare['records'])->toHaveCount(1)
            ->and($record->references()->pluck('resource_id')->all())->toBe([$application->id]);
    });

    test('a release reports busy when the lock is not free in time', function () {
        fakeCloudflareDns();
        app()->instance(ManagedDnsHostnameLock::class, new ManagedDnsHostnameLock(waitSeconds: 0));
        $application = createDnsTestApplication($this, 'https://moved.example.com');
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
        $heldLock = Cache::lock(ManagedDnsHostnameLock::key($this->team->id, 'app.example.com'), 60);
        $heldLock->get();

        $result = app(ManagedDnsRecordCleanup::class)->releaseHostname($application, 'app.example.com', $this->team->id);

        expect($result)->toBe(ManagedDnsDeletionResult::Busy)
            ->and($result->shouldRetry())->toBeTrue()
            ->and($record->fresh())->not->toBeNull();
        $heldLock->release();
    });
});
