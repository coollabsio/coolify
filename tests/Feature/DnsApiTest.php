<?php

use App\Jobs\CheckDomainDnsJob;
use App\Jobs\ConfigureDnsRecordJob;
use App\Models\Application;
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
use App\Support\DnsRecordHints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake([CheckDomainDnsJob::class]);
    config()->set('app.maintenance.store', 'array');
    config()->set('app.id', 'test-instance');
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member->id, ['role' => 'member']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'ip' => '203.0.113.10']);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://app.example.com',
    ]);

    $this->token = IntegrationToken::factory()->for($this->team)->create(['provider' => 'cloudflare', 'token' => 'cf-secret-value', 'capabilities' => ['dns']]);
    $this->zone = DnsProviderZone::factory()->for($this->token)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function dnsApiToken(User $user, Team $team, array $abilities = ['*']): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'dns-api-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

/**
 * Fakes the Cloudflare DNS records API of zone-1 with the given existing records.
 *
 * @param  array<int, array<string, mixed>>  $records
 */
function dnsApiFakeCloudflare(array $records = []): void
{
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => $records]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/*' => Http::response(['success' => true, 'result' => ['id' => 'record-old']]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'record-new']]),
    ]);
}

function dnsApiService(object $test, string $fqdn): ServiceApplication
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

describe('integration tokens', function () {
    test('an owner creates a cloudflare token with its zones and options', function () {
        Http::fake([
            'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['result' => ['status' => 'active']]),
            'https://api.cloudflare.com/client/v4/zones/zone-9/dns_records*' => Http::response(['success' => true, 'result' => []]),
            'https://api.cloudflare.com/client/v4/accounts/account-1/cfd_tunnel*' => Http::response(['success' => true, 'result' => []]),
            'https://api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => true,
                'result' => [['id' => 'zone-9', 'name' => 'example.org', 'account' => ['id' => 'account-1', 'name' => 'Main']]],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);

        $uuid = $this->withToken(dnsApiToken($this->user, $this->team))->postJson('/api/v1/security/integration-tokens', [
            'provider' => 'cloudflare',
            'name' => 'Cloudflare',
            'token' => 'new-cf-secret',
            'capabilities' => ['dns', 'tunnel'],
            'automatic_dns' => false,
            'proxied_dns' => true,
        ])->assertCreated()->json('uuid');

        $token = IntegrationToken::query()->where('uuid', $uuid)->firstOrFail();
        expect($token->team_id)->toBe($this->team->id)
            ->and($token->capabilities)->toBe(['dns', 'tunnel'])
            ->and($token->automaticDnsEnabled())->toBeFalse()
            ->and($token->proxiedDnsEnabled())->toBeTrue()
            ->and($token->dnsZones()->pluck('name')->all())->toBe(['example.org']);
    });

    test('a cloudflare token is not stored when cloudflare rejects it', function () {
        Http::fake(['https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([], 401)]);

        $this->withToken(dnsApiToken($this->user, $this->team))->postJson('/api/v1/security/integration-tokens', [
            'provider' => 'cloudflare', 'name' => 'Rejected', 'token' => 'bad',
        ])->assertStatus(400)->assertJson(['message' => 'The token could not access the selected Cloudflare capabilities. Check its permissions and zone resources.']);

        expect(IntegrationToken::query()->where('name', 'Rejected')->exists())->toBeFalse();
    });

    test('a tunnel capability without dns is rejected before cloudflare is called', function () {
        Http::fake();

        $this->withToken(dnsApiToken($this->user, $this->team))->postJson('/api/v1/security/integration-tokens', [
            'provider' => 'cloudflare', 'name' => 'Tunnel', 'token' => 'x', 'capabilities' => ['tunnel'],
        ])->assertUnprocessable()->assertJsonPath('errors.capabilities.0', 'Cloudflare Tunnel also needs the DNS capability.');

        Http::assertNothingSent();
    });

    test('list and get return the token settings but never the secret', function () {
        $bearer = dnsApiToken($this->user, $this->team);

        $list = $this->withToken($bearer)->getJson('/api/v1/security/integration-tokens')->assertOk();
        $one = $this->withToken($bearer)->getJson("/api/v1/security/integration-tokens/{$this->token->uuid}")->assertOk();

        expect($list->getContent())->not->toContain('cf-secret-value')
            ->and($one->getContent())->not->toContain('cf-secret-value')
            ->and($one->json())->toMatchArray([
                'uuid' => $this->token->uuid, 'provider' => 'cloudflare', 'capabilities' => ['dns'],
                'automatic_dns' => true, 'proxied_dns' => false, 'dns_zones_count' => 1,
            ]);
    });

    test('a member cannot list or read integration tokens', function () {
        $bearer = dnsApiToken($this->member, $this->team, ['read']);

        $this->withToken($bearer)->getJson('/api/v1/security/integration-tokens')->assertForbidden();
        $this->withToken($bearer)->getJson("/api/v1/security/integration-tokens/{$this->token->uuid}")->assertForbidden();
        $this->withToken($bearer)->getJson('/api/v1/dns/zones')->assertForbidden();
    });

    test('tokens of another team are not found', function (string $method, string $suffix) {
        $foreign = IntegrationToken::factory()->for(Team::factory()->create())->create(['provider' => 'cloudflare']);

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->json($method, "/api/v1/security/integration-tokens/{$foreign->uuid}{$suffix}")
            ->assertNotFound();

        expect($foreign->fresh())->not->toBeNull();
    })->with([
        'get' => ['GET', ''],
        'delete' => ['DELETE', ''],
        'update' => ['PATCH', ''],
        'sync zones' => ['POST', '/sync-zones'],
    ]);

    test('a token that manages dns records cannot be deleted', function () {
        ManagedDnsRecord::factory()->create(['team_id' => $this->team->id, 'integration_token_id' => $this->token->id, 'dns_provider_zone_id' => $this->zone->id]);

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->deleteJson("/api/v1/security/integration-tokens/{$this->token->uuid}")
            ->assertConflict()
            ->assertJson(['message' => 'This token manages DNS records. Remove those domains or records first.']);

        expect($this->token->fresh())->not->toBeNull();
    });

    test('an unused token is deleted', function () {
        $this->withToken(dnsApiToken($this->user, $this->team))
            ->deleteJson("/api/v1/security/integration-tokens/{$this->token->uuid}")
            ->assertOk();

        expect($this->token->fresh())->toBeNull();
    });

    test('a cloudflare token is renamed and its automatic dns turned off after cloudflare accepts it', function () {
        Http::fake([
            'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['result' => ['status' => 'active']]),
            'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::response(['success' => true, 'result' => []]),
            'https://api.cloudflare.com/client/v4/zones*' => Http::response(['success' => true, 'result' => [['id' => 'zone-1', 'name' => 'example.com']]]),
        ]);

        $response = $this->withToken(dnsApiToken($this->user, $this->team))
            ->patchJson("/api/v1/security/integration-tokens/{$this->token->uuid}", ['name' => 'Renamed', 'automatic_dns' => false])
            ->assertOk()
            ->assertJson(['name' => 'Renamed', 'automatic_dns' => false, 'capabilities' => ['dns']]);

        expect($response->getContent())->not->toContain('cf-secret-value')
            ->and($this->token->fresh()->automaticDnsEnabled())->toBeFalse()
            ->and($this->token->fresh()->token)->toBe('cf-secret-value');
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/user/tokens/verify'
            && $request->header('Authorization') === ['Bearer cf-secret-value']);
    });

    test('a vault base url cannot change without the token again', function () {
        Http::fake();
        $vault = IntegrationToken::factory()->for($this->team)->create([
            'provider' => 'vault', 'token' => 'vault-secret', 'capabilities' => ['secrets'], 'metadata' => ['base_url' => 'https://vault.example.com'],
        ]);

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->patchJson("/api/v1/security/integration-tokens/{$vault->uuid}", ['metadata' => ['base_url' => 'https://attacker.example.com']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.token.0', 'Enter the token again when you change the base URL or the namespace.');

        expect($vault->fresh()->metadata['base_url'])->toBe('https://vault.example.com');
        Http::assertNothingSent();
    });

    test('a member cannot update a token', function () {
        $this->withToken(dnsApiToken($this->member, $this->team, ['read']))
            ->patchJson("/api/v1/security/integration-tokens/{$this->token->uuid}", ['name' => 'Renamed'])
            ->assertForbidden();

        expect($this->token->fresh()->name)->not->toBe('Renamed');
    });

    test('zones of a cloudflare token are synchronized again', function () {
        Http::fake(['https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-1', 'name' => 'example.com'], ['id' => 'zone-2', 'name' => 'example.net']],
            'result_info' => ['total_pages' => 1],
        ])]);

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->postJson("/api/v1/security/integration-tokens/{$this->token->uuid}/sync-zones")
            ->assertOk()
            ->assertJson(['zones_count' => 2]);

        expect($this->token->dnsZones()->orderBy('name')->pluck('name')->all())->toBe(['example.com', 'example.net']);
    });
});

describe('resource dns', function () {
    test('the zones and the dns status of an application are listed', function () {
        $bearer = dnsApiToken($this->user, $this->team);

        $this->withToken($bearer)->getJson('/api/v1/dns/zones')->assertOk()
            ->assertExactJson([[
                'zone' => 'example.com', 'account_name' => $this->zone->account_name,
                'integration_token_uuid' => $this->token->uuid, 'integration_token_name' => $this->token->name,
                'managed_records_count' => 0,
            ]]);

        $this->withToken($bearer)->getJson("/api/v1/applications/{$this->application->uuid}/dns")->assertOk()
            ->assertJson([
                'default_address' => '203.0.113.10',
                'public_addresses' => ['203.0.113.10'],
                'hostnames' => [[
                    'hostname' => 'app.example.com',
                    'providers' => [['integration_token_uuid' => $this->token->uuid, 'zone' => 'example.com', 'automatic_dns' => true]],
                    'records' => [],
                ]],
            ]);
    });

    test('a record is created for a domain of the application', function () {
        dnsApiFakeCloudflare();

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->postJson("/api/v1/applications/{$this->application->uuid}/dns", ['hostname' => 'APP.example.com'])
            ->assertCreated()
            ->assertJson(['record' => ['hostname' => 'app.example.com', 'type' => 'A', 'address' => '203.0.113.10', 'owned' => true]]);

        $record = ManagedDnsRecord::query()->where('name', 'app.example.com')->firstOrFail();
        expect($record->references()->pluck('resource_id')->all())->toBe([$this->application->id]);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST' && $request['content'] === '203.0.113.10');
        $this->assertDatabaseHas('audit_events', ['team_id' => $this->team->id, 'event' => 'api.dns_record.created']);
    });

    test('a record is created for a domain of a service application', function () {
        dnsApiFakeCloudflare();
        $serviceApplication = dnsApiService($this, 'https://web.example.com');

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->postJson("/api/v1/services/{$serviceApplication->service->uuid}/dns", ['hostname' => 'web.example.com'])
            ->assertCreated();

        $reference = ManagedDnsRecord::query()->where('name', 'web.example.com')->firstOrFail()->references()->firstOrFail();
        expect($reference->resource_type)->toBe($serviceApplication->getMorphClass())
            ->and($reference->resource_id)->toBe($serviceApplication->id);
    });

    test('a conflicting record returns 409 and can then be replaced', function () {
        dnsApiFakeCloudflare([['id' => 'record-old', 'type' => 'A', 'name' => 'app.example.com', 'content' => '198.51.100.7']]);
        $bearer = dnsApiToken($this->user, $this->team);

        $conflict = $this->withToken($bearer)
            ->postJson("/api/v1/applications/{$this->application->uuid}/dns", ['hostname' => 'app.example.com'])
            ->assertConflict()
            ->assertJson(['conflict' => ['record_id' => 'record-old', 'current' => '198.51.100.7', 'proposed' => '203.0.113.10']])
            ->json('conflict');

        $this->withToken($bearer)->postJson("/api/v1/applications/{$this->application->uuid}/dns/replace", [
            'hostname' => 'app.example.com', 'record_id' => $conflict['record_id'], 'current' => $conflict['current'],
        ])->assertOk()->assertJson(['record' => ['address' => '203.0.113.10', 'owned' => false]]);

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/dns_records/record-old') && $request['content'] === '203.0.113.10');
    });

    test('a busy hostname returns 423 without a cloudflare call', function () {
        Http::fake();
        app()->instance(ManagedDnsHostnameLock::class, new ManagedDnsHostnameLock(waitSeconds: 0));
        $lock = Cache::lock(ManagedDnsHostnameLock::key($this->team->id, 'app.example.com'), 60);
        $lock->get();

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->postJson("/api/v1/applications/{$this->application->uuid}/dns", ['hostname' => 'app.example.com'])
            ->assertStatus(423)
            ->assertJson(['message' => 'Another DNS change for app.example.com is in progress. Try again in a few seconds.']);

        $lock->release();
        Http::assertNothingSent();
    });

    test('no record is created when the server has no public address', function () {
        Http::fake();
        $this->server->update(['ip' => '10.0.0.5']);

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->postJson("/api/v1/applications/{$this->application->uuid}/dns", ['hostname' => 'app.example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.address.0', DnsRecordHints::NO_PUBLIC_ADDRESS_MESSAGE);

        Http::assertNothingSent();
    });

    test('a record is only created for a public address of the server and a domain of the resource', function (array $payload, string $field, string $message) {
        Http::fake();

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->postJson("/api/v1/applications/{$this->application->uuid}/dns", $payload)
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}.0", $message);

        Http::assertNothingSent();
    })->with([
        'other hostname' => [['hostname' => 'other.example.com'], 'hostname', 'other.example.com is not a domain of this resource.'],
        'other address' => [['hostname' => 'app.example.com', 'address' => '198.51.100.7'], 'address', 'The address must be a public address of the server: 203.0.113.10.'],
        'other token' => [['hostname' => 'app.example.com', 'integration_token_uuid' => 'unknown'], 'integration_token_uuid', 'No connected DNS provider of the team manages app.example.com.'],
    ]);

    test('only zones of the resource team are used', function () {
        Http::fake();
        $this->zone->delete();
        $foreignToken = IntegrationToken::factory()->for(Team::factory()->create())->create(['provider' => 'cloudflare']);
        DnsProviderZone::factory()->for($foreignToken)->create(['name' => 'example.com']);

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->postJson("/api/v1/applications/{$this->application->uuid}/dns", ['hostname' => 'app.example.com', 'integration_token_uuid' => $foreignToken->uuid])
            ->assertUnprocessable();

        Http::assertNothingSent();
    });

    test('a member with read access can see the dns status but not change it', function () {
        Http::fake();
        $bearer = dnsApiToken($this->member, $this->team, ['read']);

        $this->withToken($bearer)->getJson("/api/v1/applications/{$this->application->uuid}/dns")->assertOk();
        $this->withToken($bearer)
            ->postJson("/api/v1/applications/{$this->application->uuid}/dns", ['hostname' => 'app.example.com'])
            ->assertForbidden();

        Http::assertNothingSent();
    });

    test('resources of another team are not found', function (string $method, string $path) {
        Http::fake();
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($this->user->id, ['role' => 'owner']);

        $this->withToken(dnsApiToken($this->user, $otherTeam))
            ->json($method, str_replace('{uuid}', $this->application->uuid, $path), ['hostname' => 'app.example.com'])
            ->assertNotFound();

        Http::assertNothingSent();
    })->with([
        'status' => ['GET', '/api/v1/applications/{uuid}/dns'],
        'create' => ['POST', '/api/v1/applications/{uuid}/dns'],
        'release' => ['DELETE', '/api/v1/applications/{uuid}/dns/app.example.com'],
    ]);

    test('the record of a removed domain is released and deleted', function () {
        $record = ManagedDnsRecord::factory()->create([
            'team_id' => $this->team->id, 'integration_token_id' => $this->token->id, 'dns_provider_zone_id' => $this->zone->id,
            'provider_record_id' => 'record-new', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10', 'owned' => true,
        ]);
        $record->addReference($this->application);
        $this->application->update(['fqdn' => 'https://new.example.com']);
        Http::fake(['https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/record-new' => Http::response(['success' => true, 'result' => [
            'id' => 'record-new', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10', 'comment' => $record->ownershipComment(),
        ]])]);

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/dns/app.example.com")
            ->assertOk()
            ->assertJson(['result' => 'deleted']);

        expect($record->fresh())->toBeNull();
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE');
    });

    test('the record of a domain that the application still uses is not released', function () {
        dnsApiFakeCloudflare();
        $record = app(CloudflareDnsProvider::class)->createRecord($this->zone, 'app.example.com', '203.0.113.10', $this->application);

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/dns/app.example.com")
            ->assertUnprocessable();

        expect($record->fresh())->not->toBeNull();
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE');
    });
});

describe('automatic dns on api domain changes', function () {
    test('an added application domain queues a record when the zone has automatic dns', function () {
        Queue::fake([CheckDomainDnsJob::class, ConfigureDnsRecordJob::class]);

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", ['domains' => 'https://app.example.com,https://new.example.com'])
            ->assertOk();

        Queue::assertPushed(ConfigureDnsRecordJob::class, 1);
        Queue::assertPushed(ConfigureDnsRecordJob::class, fn (ConfigureDnsRecordJob $job): bool => $job->hostname === 'new.example.com'
            && $job->content === '203.0.113.10' && $job->zoneId === $this->zone->id && $job->resourceId === $this->application->id);
    });

    test('an added service domain queues a record when the zone has automatic dns', function () {
        Queue::fake([CheckDomainDnsJob::class, ConfigureDnsRecordJob::class]);
        $serviceApplication = dnsApiService($this, 'https://web.example.com');

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->patchJson("/api/v1/services/{$serviceApplication->service->uuid}", ['urls' => [['name' => 'web', 'url' => 'https://web.example.com,https://shop.example.com']]])
            ->assertOk();

        Queue::assertPushed(ConfigureDnsRecordJob::class, fn (ConfigureDnsRecordJob $job): bool => $job->hostname === 'shop.example.com'
            && $job->resourceId === $serviceApplication->id);
        Queue::assertNotPushed(ConfigureDnsRecordJob::class, fn (ConfigureDnsRecordJob $job): bool => $job->hostname === 'web.example.com');
    });

    test('no record is queued when automatic dns is off', function () {
        Queue::fake([CheckDomainDnsJob::class, ConfigureDnsRecordJob::class]);
        $this->token->update(['metadata' => ['automatic_dns' => false]]);

        $this->withToken(dnsApiToken($this->user, $this->team))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", ['domains' => 'https://app.example.com,https://new.example.com'])
            ->assertOk();

        Queue::assertNotPushed(ConfigureDnsRecordJob::class);
    });
});
