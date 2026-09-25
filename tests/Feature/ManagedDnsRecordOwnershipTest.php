<?php

use App\Jobs\ReleaseManagedDnsRecordsJob;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config()->set('app.maintenance.store', 'array');
    config()->set('app.id', 'test-instance');

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_dns_validation_enabled' => false],
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
 * Fakes the Cloudflare DNS record API with an in-memory record store.
 *
 * @param  array<int, array<string, mixed>>  $records
 */
function fakeCloudflareDns(array $records = []): ArrayObject
{
    $state = new ArrayObject(['records' => collect($records)->keyBy('id')->all(), 'next' => 1]);

    Http::fake(function (Request $request) use ($state) {
        if (! preg_match('#^https://api\.cloudflare\.com/client/v4/zones/[^/]+/dns_records(?:/([^/?]+))?(?:\?(.*))?$#', $request->url(), $matches)) {
            return Http::response(['success' => false], 404);
        }
        $id = ($matches[1] ?? '') !== '' ? $matches[1] : null;
        $records = $state['records'];

        if ($id === null && $request->method() === 'GET') {
            parse_str($matches[2] ?? '', $query);
            $result = collect($records)->filter(fn (array $record): bool => $record['name'] === ($query['name'] ?? null)
                && $record['type'] === ($query['type'] ?? null))->values()->all();

            return Http::response(['success' => true, 'result' => $result]);
        }
        if ($id === null && $request->method() === 'POST') {
            $id = 'record-new-'.$state['next'];
            $state['next']++;
            $records[$id] = array_merge(['proxied' => false, 'ttl' => 1, 'comment' => null], $request->data(), ['id' => $id]);
            $state['records'] = $records;

            return Http::response(['success' => true, 'result' => $records[$id]]);
        }
        if (! isset($records[$id])) {
            return Http::response(['success' => false, 'errors' => [['code' => 81044, 'message' => 'Record does not exist.']]], 404);
        }
        if ($request->method() === 'PATCH') {
            $records[$id] = array_merge($records[$id], $request->data());
            $state['records'] = $records;
        }
        if ($request->method() === 'DELETE') {
            unset($records[$id]);
            $state['records'] = $records;

            return Http::response(['success' => true, 'result' => ['id' => $id]]);
        }

        return Http::response(['success' => true, 'result' => $records[$id]]);
    });

    return $state;
}

function createDnsTestApplication(object $test, string $fqdn): Application
{
    $application = Application::factory()->create([
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
        'fqdn' => $fqdn,
        'build_pack' => 'nixpacks',
    ]);
    $application->settings()->update(['is_container_label_readonly_enabled' => true]);

    return $application->fresh();
}

function sentDnsRequests(string $method): int
{
    return Http::recorded(fn (Request $request) => $request->method() === $method
        && str_contains($request->url(), 'api.cloudflare.com'))->count();
}

test('a created record carries the ownership comment and is owned', function () {
    fakeCloudflareDns();
    $application = createDnsTestApplication($this, 'https://app.example.com');

    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

    expect($record->owned)->toBeTrue()
        ->and($record->ownershipComment())->toBe("managed-by: coolify test-instance/{$record->uuid}")
        ->and($record->references()->where('resource_id', $application->id)->exists())->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->data()['comment'] === $record->ownershipComment()
        && $request->data()['proxied'] === false);
});

test('an adopted hand-made record is never deleted when the domain is removed', function () {
    $cloudflare = fakeCloudflareDns([[
        'id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10',
        'proxied' => true, 'ttl' => 300, 'comment' => 'hand made',
    ]]);
    $application = createDnsTestApplication($this, 'https://app.example.com');

    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

    expect($record->owned)->toBeFalse()->and($record->references()->count())->toBe(1);

    Livewire::test(Domains::class, ['application' => $application])
        ->call('removeDomain', 0, '', ['deleteManagedDns'])
        ->assertDispatched('success');

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and(sentDnsRequests('POST'))->toBe(0)
        ->and(sentDnsRequests('PATCH'))->toBe(0)
        ->and($cloudflare['records'])->toHaveKey('record-1')
        ->and(ManagedDnsRecord::query()->find($record->id))->toBeNull();
});

test('replacing a hand-made record keeps proxied, ttl and comment and never deletes it later', function () {
    $cloudflare = fakeCloudflareDns([[
        'id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '198.51.100.50',
        'proxied' => true, 'ttl' => 300, 'comment' => 'hand made',
    ]]);
    $application = createDnsTestApplication($this, 'https://app.example.com');

    $record = app(CloudflareDnsProvider::class)->replaceRecord(
        $this->zone, 'record-1', 'app.example.com', '203.0.113.10', $application, expectedCurrent: '198.51.100.50',
    );

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/dns_records/record-1')
        && $request->data() === ['content' => '203.0.113.10']);
    expect($record->owned)->toBeFalse()
        ->and($cloudflare['records']['record-1'])->toMatchArray([
            'content' => '203.0.113.10', 'proxied' => true, 'ttl' => 300, 'comment' => 'hand made',
        ]);

    Livewire::test(Domains::class, ['application' => $application])
        ->call('removeDomain', 0, '', ['deleteManagedDns'])
        ->assertDispatched('success');

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and($cloudflare['records'])->toHaveKey('record-1');
});

test('an owned record is kept while another resource references it and deleted after the last reference', function () {
    $cloudflare = fakeCloudflareDns();
    $first = createDnsTestApplication($this, 'https://app.example.com');
    $second = createDnsTestApplication($this, 'https://app.example.com/api');
    $provider = app(CloudflareDnsProvider::class);

    $record = $provider->createRecord($this->zone, 'app.example.com', '203.0.113.10', $first);
    $adopted = $provider->createRecord($this->zone, 'app.example.com', '203.0.113.10', $second);

    expect($adopted->id)->toBe($record->id)
        ->and($adopted->fresh()->owned)->toBeTrue()
        ->and($record->references()->count())->toBe(2)
        ->and(sentDnsRequests('POST'))->toBe(1);

    Livewire::test(Domains::class, ['application' => $first])
        ->call('removeDomain', 0, '', ['deleteManagedDns'])
        ->assertDispatched('success');

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and($record->fresh())->not->toBeNull()
        ->and($record->references()->pluck('resource_id')->all())->toBe([$second->id]);

    Livewire::test(Domains::class, ['application' => $second])
        ->call('removeDomain', 0, '', ['deleteManagedDns'])
        ->assertDispatched('success');

    expect(sentDnsRequests('DELETE'))->toBe(1)
        ->and($cloudflare['records'])->toBeEmpty()
        ->and($record->fresh())->toBeNull();
});

test('another application of the same team still using the hostname prevents deletion and takes over the reference', function () {
    $cloudflare = fakeCloudflareDns();
    $application = createDnsTestApplication($this, 'https://app.example.com');
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);
    $otherApplication = createDnsTestApplication($this, 'http://other.example.org,https://APP.example.com:8443');

    Livewire::test(Domains::class, ['application' => $application])
        ->call('removeDomain', 0, '', ['deleteManagedDns'])
        ->assertDispatched('success');

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and($cloudflare['records'])->toHaveCount(1)
        ->and($record->fresh())->not->toBeNull()
        ->and($record->references()->pluck('resource_id')->all())->toBe([$otherApplication->id]);

    $otherApplication->delete();

    expect(sentDnsRequests('DELETE'))->toBe(1)
        ->and($cloudflare['records'])->toBeEmpty()
        ->and($record->fresh())->toBeNull();
});

test('an application of another team using the hostname prevents deletion', function () {
    $cloudflare = fakeCloudflareDns();
    $application = createDnsTestApplication($this, 'https://app.example.com');
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://app.example.com',
        'build_pack' => 'nixpacks',
    ]);

    Livewire::test(Domains::class, ['application' => $application])
        ->call('removeDomain', 0, '', ['deleteManagedDns'])
        ->assertDispatched('success');

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and($cloudflare['records'])->toHaveCount(1)
        ->and(ManagedDnsRecord::query()->find($record->id))->toBeNull();
});

test('a hostname still used by a compose application or preview prevents deletion', function (string $usage) {
    fakeCloudflareDns();
    $application = createDnsTestApplication($this, 'https://app.example.com');
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

    $other = createDnsTestApplication($this, 'https://unrelated.example.org');
    if ($usage === 'compose') {
        $other->update(['docker_compose_domains' => json_encode(['web' => ['domain' => 'https://app.example.com']])]);
    } else {
        ApplicationPreview::query()->create([
            'application_id' => $other->id, 'pull_request_id' => 7, 'pull_request_html_url' => 'https://github.com/x/y/pull/7',
            'fqdn' => 'https://app.example.com',
        ]);
    }

    Livewire::test(Domains::class, ['application' => $application])
        ->call('removeDomain', 0, '', ['deleteManagedDns'])
        ->assertDispatched('success');

    expect(sentDnsRequests('DELETE'))->toBe(0)->and($record->fresh())->not->toBeNull();
})->with(['compose', 'preview']);

test('an owned record whose remote comment was changed or removed is not deleted', function (?string $comment) {
    $cloudflare = fakeCloudflareDns();
    $application = createDnsTestApplication($this, 'https://app.example.com');
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

    $records = $cloudflare['records'];
    $records[$record->provider_record_id]['comment'] = $comment;
    $cloudflare['records'] = $records;

    Livewire::test(Domains::class, ['application' => $application])
        ->call('removeDomain', 0, '', ['deleteManagedDns'])
        ->assertDispatched('warning', 'The domain was removed, but its DNS record changed externally and was left untouched.');

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and($cloudflare['records'])->toHaveKey($record->provider_record_id)
        ->and($record->fresh())->toBeNull();
})->with([
    'removed' => [null],
    'changed' => ['production record'],
    'other coolify record' => ['managed-by: coolify test-instance/someotherrecord'],
]);

test('removing a domain without deleting dns forgets the reference and keeps the remote record', function () {
    $cloudflare = fakeCloudflareDns();
    $application = createDnsTestApplication($this, 'https://app.example.com');
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

    Livewire::test(Domains::class, ['application' => $application])
        ->call('removeDomain', 0, '', [])
        ->assertDispatched('success');

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and($cloudflare['records'])->toHaveCount(1)
        ->and($record->fresh())->toBeNull();
});

test('removing one of two urls with the same hostname keeps the reference', function () {
    $cloudflare = fakeCloudflareDns();
    $application = createDnsTestApplication($this, 'https://app.example.com,http://app.example.com:8080');
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

    Livewire::test(Domains::class, ['application' => $application])
        ->call('removeDomain', 0, '', ['deleteManagedDns'])
        ->assertDispatched('success');

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and($record->fresh())->not->toBeNull()
        ->and($record->references()->pluck('resource_id')->all())->toBe([$application->id]);
});

test('an existing round-robin record with the wanted content satisfies the request', function () {
    fakeCloudflareDns([
        ['id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '198.51.100.50', 'comment' => null],
        ['id' => 'record-2', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10', 'comment' => null],
    ]);
    $application = createDnsTestApplication($this, 'https://app.example.com');

    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

    expect($record->provider_record_id)->toBe('record-2')->and($record->owned)->toBeFalse();
    expect(sentDnsRequests('POST'))->toBe(0);
});

test('several round-robin records without the wanted content are not replaced', function () {
    fakeCloudflareDns([
        ['id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '198.51.100.50'],
        ['id' => 'record-2', 'type' => 'A', 'name' => 'app.example.com', 'content' => '198.51.100.51'],
    ]);
    $provider = app(CloudflareDnsProvider::class);

    expect(fn () => $provider->createRecord($this->zone, 'app.example.com', '203.0.113.10'))
        ->toThrow(RuntimeException::class, 'Several DNS records already exist for app.example.com');
    expect(fn () => $provider->replaceRecord($this->zone, 'record-1', 'app.example.com', '203.0.113.10', expectedCurrent: '198.51.100.50'))
        ->toThrow(RuntimeException::class);

    expect(sentDnsRequests('POST') + sentDnsRequests('PATCH') + sentDnsRequests('PUT'))->toBe(0);
});

test('deleting an application removes its owned dns records', function () {
    $cloudflare = fakeCloudflareDns();
    $application = createDnsTestApplication($this, 'https://app.example.com');
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

    $application->delete();

    expect(sentDnsRequests('DELETE'))->toBe(1)
        ->and($cloudflare['records'])->toBeEmpty()
        ->and($record->fresh())->toBeNull();
});

test('deleting a service application or preview removes its owned dns records', function (string $kind) {
    $cloudflare = fakeCloudflareDns();
    if ($kind === 'service application') {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);
        $resource = ServiceApplication::create([
            'uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:alpine',
            'fqdn' => 'https://app.example.com',
        ]);
    } else {
        $application = createDnsTestApplication($this, 'https://main.example.org');
        $resource = ApplicationPreview::query()->create([
            'application_id' => $application->id, 'pull_request_id' => 3, 'pull_request_html_url' => 'https://github.com/x/y/pull/3',
            'fqdn' => 'https://app.example.com',
        ]);
    }
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $resource);

    $resource->delete();

    expect(sentDnsRequests('DELETE'))->toBe(1)
        ->and($cloudflare['records'])->toBeEmpty()
        ->and($record->fresh())->toBeNull();
})->with(['service application', 'preview']);

test('deleting an application also releases the references of its previews', function () {
    $cloudflare = fakeCloudflareDns();
    $application = createDnsTestApplication($this, 'https://main.example.org');
    $preview = ApplicationPreview::query()->create([
        'application_id' => $application->id, 'pull_request_id' => 4, 'pull_request_html_url' => 'https://github.com/x/y/pull/4',
        'fqdn' => 'https://pr-4.example.com',
    ]);
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'pr-4.example.com', '203.0.113.10', $preview);

    $application->forceDelete();

    expect($cloudflare['records'])->toBeEmpty()->and($record->fresh())->toBeNull();
});

test('resource deletion succeeds and keeps the record when cloudflare is unreachable', function () {
    fakeCloudflareDns();
    $application = createDnsTestApplication($this, 'https://app.example.com');
    $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $application);

    Http::fake(fn () => throw new ConnectionException('Cloudflare is unreachable.'));

    $application->forceDelete();

    expect(Application::withTrashed()->find($application->id))->toBeNull()
        ->and($record->fresh())->not->toBeNull()
        ->and($record->references()->count())->toBe(1);
});

test('deleting a resource dispatches the release job only when it references dns records', function () {
    Queue::fake();
    fakeCloudflareDns();
    $withRecord = createDnsTestApplication($this, 'https://app.example.com');
    $withoutRecord = createDnsTestApplication($this, 'https://plain.example.com');
    app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $withRecord);

    $withoutRecord->delete();
    Queue::assertNotPushed(ReleaseManagedDnsRecordsJob::class);

    $withRecord->delete();
    Queue::assertPushed(ReleaseManagedDnsRecordsJob::class, fn (ReleaseManagedDnsRecordsJob $job): bool => $job->resourceType === $withRecord->getMorphClass()
        && (string) $job->resourceId === (string) $withRecord->id);
});

test('service domain removal releases only the removed service application reference', function () {
    $cloudflare = fakeCloudflareDns();
    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  api:\n    image: nginx:alpine\n",
    ]);
    $web = ServiceApplication::create([
        'uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:alpine',
        'fqdn' => 'https://app.example.com',
    ]);
    $api = ServiceApplication::create([
        'uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'api', 'image' => 'nginx:alpine',
        'fqdn' => 'https://app.example.com/api',
    ]);
    $provider = app(CloudflareDnsProvider::class);
    $record = $provider->createRecord($this->zone, 'app.example.com', '203.0.113.10', $web);
    $provider->createRecord($this->zone, 'app.example.com', '203.0.113.10', $api);

    Livewire::test(ServiceDomains::class, ['service' => $service->fresh(['applications', 'server'])])
        ->call('removeDomainByKey', hash('sha256', 'https://app.example.com/api|'.$api->id), '', ['deleteManagedDns'])
        ->assertDispatched('success');

    expect(sentDnsRequests('DELETE'))->toBe(0)
        ->and($cloudflare['records'])->toHaveCount(1)
        ->and($record->references()->pluck('resource_id')->all())->toBe([$web->id]);
});

test('releasing a hostname never touches records of another team', function () {
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

    Livewire::test(Domains::class, ['application' => $application])
        ->call('removeDomain', 0, '', ['deleteManagedDns'])
        ->assertDispatched('success');

    expect(Http::recorded())->toBeEmpty()
        ->and($cloudflare['records'])->toHaveKey('record-foreign')
        ->and($foreignRecord->fresh())->not->toBeNull()
        ->and($foreignRecord->references()->count())->toBe(1);
});
