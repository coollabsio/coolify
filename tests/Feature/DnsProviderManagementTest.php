<?php

use App\Events\DnsRecordConfigurationFinished;
use App\Exceptions\DnsRecordConflictException;
use App\Jobs\CheckDomainDnsJob;
use App\Jobs\ConfigureDnsRecordJob;
use App\Livewire\Project\Application\Domains;
use App\Livewire\Project\Service\Domains as ServiceDomains;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('cloudflare automatic dns is enabled by default and can be disabled per credential', function () {
    $defaultToken = IntegrationToken::factory()->create(['metadata' => null]);
    $manualToken = IntegrationToken::factory()->create(['metadata' => ['automatic_dns' => false]]);

    expect($defaultToken->automaticDnsEnabled())->toBeTrue()
        ->and($manualToken->automaticDnsEnabled())->toBeFalse();
});

test('cloudflare zones are discovered and cached for a token', function () {
    $token = IntegrationToken::factory()->create(['provider' => 'cloudflare', 'token' => 'secret', 'capabilities' => ['dns']]);

    Http::fake(['https://api.cloudflare.com/client/v4/zones*' => Http::response([
        'success' => true,
        'result' => [
            ['id' => 'zone-1', 'name' => 'example.com', 'account' => ['id' => 'account-1', 'name' => 'Production']],
            ['id' => 'zone-2', 'name' => 'example.org', 'account' => ['id' => 'account-1', 'name' => 'Production']],
        ],
        'result_info' => ['page' => 1, 'total_pages' => 1],
    ])]);

    app(CloudflareDnsProvider::class)->syncZones($token);

    expect($token->dnsZones()->orderBy('name')->pluck('name')->all())->toBe(['example.com', 'example.org'])
        ->and($token->fresh()->metadata['zones_synced_at'])->not->toBeNull();
});

test('all credentials for the most specific zone are returned without suffix false positives', function () {
    $team = Team::factory()->create();
    $firstToken = IntegrationToken::factory()->for($team)->create(['provider' => 'cloudflare']);
    $secondToken = IntegrationToken::factory()->for($team)->create(['provider' => 'cloudflare']);
    DnsProviderZone::factory()->for($firstToken)->create(['name' => 'example.com']);
    DnsProviderZone::factory()->for($secondToken)->create(['name' => 'example.com']);
    DnsProviderZone::factory()->for($firstToken)->create(['name' => 'customer.example.com']);

    $provider = app(CloudflareDnsProvider::class);

    expect($provider->findZones($team->id, 'api.customer.example.com'))->toHaveCount(1)
        ->and($provider->findZones($team->id, 'app.example.com'))->toHaveCount(2)
        ->and($provider->findZones($team->id, 'notexample.com'))->toBeEmpty();
});

test('team cloudflare zones are queried once per provider instance', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $token = IntegrationToken::factory()->for($team)->create(['provider' => 'cloudflare']);
    $otherToken = IntegrationToken::factory()->for($otherTeam)->create(['provider' => 'cloudflare']);
    DnsProviderZone::factory()->for($token)->create(['name' => 'example.com']);
    DnsProviderZone::factory()->for($otherToken)->create(['name' => 'other.com']);

    $provider = app(CloudflareDnsProvider::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    expect($provider->findZones($team->id, 'app.example.com'))->toHaveCount(1);
    $firstCallQueries = count(DB::getQueryLog());

    expect($provider->findZones($team->id, 'api.example.com'))->toHaveCount(1)
        ->and($provider->findZones($team->id, 'www.example.com'))->toHaveCount(1)
        ->and(count(DB::getQueryLog()))->toBe($firstCallQueries)
        ->and($firstCallQueries)->toBeGreaterThan(0);

    $afterTeamQueries = count(DB::getQueryLog());
    expect($provider->findZones($otherTeam->id, 'app.other.com'))->toHaveCount(1)
        ->and(count(DB::getQueryLog()))->toBeGreaterThan($afterTeamQueries);
    DB::disableQueryLog();
});

test('a cloudflare record is created and tracked as managed by coolify', function () {
    $token = IntegrationToken::factory()->create(['provider' => 'cloudflare', 'token' => 'secret']);
    $zone = DnsProviderZone::factory()->for($token)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'record-1']]),
    ]);

    $record = app(CloudflareDnsProvider::class)->createRecord($zone, 'app.example.com', '203.0.113.10');

    expect($record->provider_record_id)->toBe('record-1')->and($record->content)->toBe('203.0.113.10');
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->data()['name'] === 'app.example.com'
        && $request->data()['content'] === '203.0.113.10');
});

test('queued dns configuration creates the record and broadcasts completion', function () {
    Event::fake([DnsRecordConfigurationFinished::class]);
    $token = IntegrationToken::factory()->create(['provider' => 'cloudflare', 'token' => 'secret']);
    $zone = DnsProviderZone::factory()->for($token)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'record-1']]),
    ]);

    $job = new ConfigureDnsRecordJob(
        teamId: $token->team_id,
        zoneId: $zone->id,
        resourceType: null,
        resourceId: null,
        hostname: 'app.example.com',
        content: '203.0.113.10',
    );
    $job->handle(app(CloudflareDnsProvider::class));

    expect(ManagedDnsRecord::query()->where('name', 'app.example.com')->exists())->toBeTrue();
    Event::assertDispatched(DnsRecordConfigurationFinished::class, fn ($event) => $event->successful
        && $event->hostname === 'app.example.com');
});

test('missing zone throws from handle without broadcasting completion', function () {
    Event::fake([DnsRecordConfigurationFinished::class]);
    $token = IntegrationToken::factory()->create(['provider' => 'cloudflare', 'token' => 'secret']);

    $job = new ConfigureDnsRecordJob(
        teamId: $token->team_id,
        zoneId: 999999,
        resourceType: null,
        resourceId: null,
        hostname: 'app.example.com',
        content: '203.0.113.10',
    );

    expect(fn () => $job->handle(app(CloudflareDnsProvider::class)))
        ->toThrow(ModelNotFoundException::class);

    Event::assertNotDispatched(DnsRecordConfigurationFinished::class);
});

test('job failure broadcasts a dns configuration finished event', function () {
    Event::fake([DnsRecordConfigurationFinished::class]);
    $token = IntegrationToken::factory()->create(['provider' => 'cloudflare', 'token' => 'secret']);

    $job = new ConfigureDnsRecordJob(
        teamId: $token->team_id,
        zoneId: 999999,
        resourceType: 'App\\Models\\Application',
        resourceId: 42,
        hostname: 'app.example.com',
        content: '203.0.113.10',
    );
    $job->failed(new ModelNotFoundException);

    Event::assertDispatched(DnsRecordConfigurationFinished::class, fn ($event) => $event->successful === false
        && $event->teamId === $token->team_id
        && $event->resourceType === 'App\\Models\\Application'
        && $event->resourceId === 42
        && $event->hostname === 'app.example.com'
        && $event->credential === ''
        && $event->message === 'The DNS zone is no longer available.');
});

test('an existing matching remote record is tracked without creating a new one', function () {
    $token = IntegrationToken::factory()->create(['provider' => 'cloudflare', 'token' => 'secret']);
    $zone = DnsProviderZone::factory()->for($token)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true,
            'result' => [[
                'id' => 'record-1',
                'type' => 'A',
                'name' => 'app.example.com',
                'content' => '203.0.113.10',
            ]],
        ]),
    ]);

    $record = app(CloudflareDnsProvider::class)->createRecord($zone, 'app.example.com', '203.0.113.10');

    expect($record->provider_record_id)->toBe('record-1')
        ->and($record->content)->toBe('203.0.113.10')
        ->and(ManagedDnsRecord::query()->where('name', 'app.example.com')->exists())->toBeTrue();
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('queued dns configuration treats a matching existing record as success', function () {
    Event::fake([DnsRecordConfigurationFinished::class]);
    $token = IntegrationToken::factory()->create(['provider' => 'cloudflare', 'token' => 'secret']);
    $zone = DnsProviderZone::factory()->for($token)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true,
            'result' => [[
                'id' => 'record-1',
                'type' => 'A',
                'name' => 'app.example.com',
                'content' => '203.0.113.10',
            ]],
        ]),
    ]);

    $job = new ConfigureDnsRecordJob(
        teamId: $token->team_id,
        zoneId: $zone->id,
        resourceType: null,
        resourceId: null,
        hostname: 'app.example.com',
        content: '203.0.113.10',
    );
    $job->handle(app(CloudflareDnsProvider::class));

    expect(ManagedDnsRecord::query()->where('name', 'app.example.com')->exists())->toBeTrue();
    Event::assertDispatched(DnsRecordConfigurationFinished::class, fn ($event) => $event->successful
        && $event->hostname === 'app.example.com');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('an existing remote record with different content remains a conflict', function () {
    $token = IntegrationToken::factory()->create(['provider' => 'cloudflare', 'token' => 'secret']);
    $zone = DnsProviderZone::factory()->for($token)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true,
            'result' => [[
                'id' => 'record-1',
                'type' => 'A',
                'name' => 'app.example.com',
                'content' => '203.0.113.99',
            ]],
        ]),
    ]);

    expect(fn () => app(CloudflareDnsProvider::class)->createRecord($zone, 'app.example.com', '203.0.113.10'))
        ->toThrow(DnsRecordConflictException::class);
    expect(ManagedDnsRecord::query()->where('name', 'app.example.com')->exists())->toBeFalse();
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('a managed record changed outside coolify is not deleted', function () {
    $record = ManagedDnsRecord::factory()->create([
        'provider_record_id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10',
    ]);

    Http::fake(['https://api.cloudflare.com/client/v4/zones/*/dns_records/record-1' => Http::response([
        'success' => true,
        'result' => ['id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.99'],
    ])]);

    expect(app(CloudflareDnsProvider::class)->deleteRecord($record))->toBeFalse()->and($record->fresh())->not->toBeNull();
});

test('an unchanged managed record is deleted from cloudflare and coolify', function () {
    $record = ManagedDnsRecord::factory()->create([
        'provider_record_id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10',
    ]);

    Http::fake(['https://api.cloudflare.com/client/v4/zones/*/dns_records/record-1' => Http::sequence()
        ->push(['success' => true, 'result' => ['id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10']])
        ->push(['success' => true, 'result' => ['id' => 'record-1']])]);

    expect(app(CloudflareDnsProvider::class)->deleteRecord($record))->toBeTrue()
        ->and(ManagedDnsRecord::query()->find($record->id))->toBeNull();
});

test('dns provider modal view always has a single root element', function () {
    $providerModal = file_get_contents(resource_path('views/livewire/project/shared/dns-provider-management.blade.php'));

    expect(ltrim($providerModal))->toStartWith('<div class="contents">')
        ->and($providerModal)->toContain('@if ($showDnsProviderModal)');
});

describe('domain DNS configuration after add', function () {
    beforeEach(function () {
        $this->withoutVite();
        config()->set('app.maintenance.store', 'array');
        Queue::fake();

        InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
            ['id' => 0],
            ['id' => 0, 'is_dns_validation_enabled' => false],
        ));

        $this->team = Team::factory()->create();
        $this->user = User::factory()->create();
        $this->team->members()->attach($this->user->id, ['role' => 'owner']);
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);

        $keyId = DB::table('private_keys')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Key',
            'private_key' => 'test-key',
            'team_id' => $this->team->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->server = Server::factory()->create([
            'team_id' => $this->team->id,
            'private_key_id' => $keyId,
            'ip' => '203.0.113.10',
        ]);
        $this->server->settings()->update([
            'is_reachable' => true,
            'is_usable' => true,
        ]);

        StandaloneDocker::withoutEvents(function () {
            $this->destination = StandaloneDocker::firstOrCreate(
                ['server_id' => $this->server->id, 'network' => 'coolify'],
                ['uuid' => (string) Str::uuid(), 'name' => 'test-docker'],
            );
        });

        $this->project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

        $this->token = IntegrationToken::factory()->for($this->team)->create([
            'provider' => 'cloudflare',
            'name' => 'Production DNS',
            'token' => 'secret',
            'capabilities' => ['dns'],
        ]);
        $this->zone = DnsProviderZone::factory()->for($this->token)->create([
            'name' => 'example.com',
            'provider_zone_id' => 'zone-1',
        ]);
    });

    test('application domains queues a dns record job and marks the row pending', function () {
        $application = Application::factory()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'DNS App',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'fqdn' => null,
            'redirect' => 'both',
            'build_pack' => 'nixpacks',
        ]);
        $application->settings()->update([
            'is_container_label_readonly_enabled' => true,
        ]);

        Livewire::test(Domains::class, ['application' => $application->fresh()])
            ->set('newDomain', 'https://app.example.com')
            ->call('addDomain')
            ->assertHasNoErrors()
            ->assertDispatched('success', 'Domain added.')
            ->assertDispatched('info', 'Adding DNS record for app.example.com.')
            ->assertSet('showDnsProviderModal', false)
            ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->contains(
                fn (array $row): bool => $row['url'] === 'https://app.example.com'
                    && $row['dns_status'] === 'pending'
            ))
            ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->contains(
                fn (array $row): bool => $row['url'] === 'https://www.app.example.com'
                    && $row['dns_status'] === 'pending'
            ));

        Queue::assertPushed(ConfigureDnsRecordJob::class, 2);
        Queue::assertPushed(ConfigureDnsRecordJob::class, fn (ConfigureDnsRecordJob $job): bool => $job->hostname === 'app.example.com'
            && $job->content === '203.0.113.10'
            && $job->teamId === $this->team->id
            && $job->zoneId === $this->zone->id
            && $job->resourceType === $application->getMorphClass()
            && (string) $job->resourceId === (string) $application->id);
        Queue::assertPushed(ConfigureDnsRecordJob::class, fn (ConfigureDnsRecordJob $job): bool => $job->hostname === 'www.app.example.com'
            && $job->content === '203.0.113.10');
        Queue::assertNotPushed(CheckDomainDnsJob::class);
    });

    test('service domains queues a dns record job and marks the row pending', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        ]);
        $webApp = ServiceApplication::create([
            'uuid' => (string) Str::uuid(),
            'service_id' => $service->id,
            'name' => 'web',
            'human_name' => 'Web',
            'image' => 'nginx:alpine',
            'fqdn' => null,
        ]);

        Livewire::test(ServiceDomains::class, ['service' => $service->fresh(['applications', 'server'])])
            ->set('newServiceApplicationId', $webApp->id)
            ->set('newDomain', 'https://web.example.com')
            ->call('addDomain')
            ->assertHasNoErrors()
            ->assertDispatched('success', 'Domain added.')
            ->assertDispatched('info', 'Adding DNS record for web.example.com.')
            ->assertSet('showDnsProviderModal', false)
            ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->contains(
                fn (array $row): bool => $row['url'] === 'https://web.example.com'
                    && $row['dns_status'] === 'pending'
            ));

        Queue::assertPushed(ConfigureDnsRecordJob::class, 1);
        Queue::assertPushed(ConfigureDnsRecordJob::class, fn (ConfigureDnsRecordJob $job): bool => $job->hostname === 'web.example.com'
            && $job->content === '203.0.113.10'
            && $job->teamId === $this->team->id
            && $job->zoneId === $this->zone->id
            && $job->resourceType === $webApp->getMorphClass()
            && (string) $job->resourceId === (string) $webApp->id);
        Queue::assertNotPushed(CheckDomainDnsJob::class);
    });
});

test('dns provider action controls declare update authorization against the resource', function () {
    $dnsMenu = file_get_contents(resource_path('views/livewire/project/shared/cloudflare-autoconfigure.blade.php'));
    $providerModal = file_get_contents(resource_path('views/livewire/project/shared/dns-provider-management.blade.php'));

    expect($dnsMenu)
        ->toContain('$dnsAuthResource = property_exists($this, \'application\') ? $this->application : $this->service')
        ->toMatch('/<x-forms\.button(?=[^>]*wire:click="createManagedDnsRecord)(?=[^>]*canGate="update")(?=[^>]*:canResource="\$dnsAuthResource")[^>]*>/');

    expect($providerModal)
        ->toContain('$dnsAuthResource = property_exists($this, \'application\') ? $this->application : $this->service')
        ->toMatch('/<x-modal-confirmation(?=[^>]*submitAction="replaceManagedDnsRecord)(?=[^>]*canGate="update")(?=[^>]*:canResource="\$dnsAuthResource")[^>]*>/')
        ->toMatch('/<x-forms\.button(?=[^>]*wire:click="createManagedDnsRecord)(?=[^>]*canGate="update")(?=[^>]*:canResource="\$dnsAuthResource")[^>]*>/');
});

test('removing a domain deletes only the managed dns record for that resource', function () {
    $this->withoutVite();
    config(['app.maintenance.driver' => 'file']);
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_dns_validation_enabled' => false],
    ));

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id, 'ip' => '203.0.113.10']);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker'],
    ));
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://app.example.com',
        'build_pack' => 'nixpacks',
    ]);
    $application->settings()->update(['is_container_label_readonly_enabled' => true]);

    $otherApplication = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://other.example.com',
        'build_pack' => 'nixpacks',
    ]);

    $token = IntegrationToken::factory()->for($team)->create(['provider' => 'cloudflare', 'token' => 'secret']);
    $zone = DnsProviderZone::factory()->for($token)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);

    $otherRecord = ManagedDnsRecord::factory()->create([
        'team_id' => $team->id,
        'integration_token_id' => $token->id,
        'dns_provider_zone_id' => $zone->id,
        'resource_type' => $otherApplication->getMorphClass(),
        'resource_id' => $otherApplication->getKey(),
        'provider_record_id' => 'record-other',
        'type' => 'A',
        'name' => 'app.example.com',
        'content' => '203.0.113.10',
    ]);
    $ownRecord = ManagedDnsRecord::factory()->create([
        'team_id' => $team->id,
        'integration_token_id' => $token->id,
        'dns_provider_zone_id' => $zone->id,
        'resource_type' => $application->getMorphClass(),
        'resource_id' => $application->getKey(),
        'provider_record_id' => 'record-own',
        'type' => 'A',
        'name' => 'app.example.com',
        'content' => '203.0.113.10',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/*/dns_records/record-own' => Http::sequence()
            ->push(['success' => true, 'result' => ['id' => 'record-own', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10']])
            ->push(['success' => true, 'result' => ['id' => 'record-own']]),
        'https://api.cloudflare.com/client/v4/zones/*/dns_records/record-other' => Http::response([
            'success' => true,
            'result' => ['id' => 'record-other', 'type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10'],
        ]),
    ]);

    Livewire::test(Domains::class, ['application' => $application->fresh()])
        ->call('removeDomain', 0, '', ['deleteManagedDns'])
        ->assertDispatched('success');

    expect(ManagedDnsRecord::query()->find($ownRecord->id))->toBeNull()
        ->and(ManagedDnsRecord::query()->find($otherRecord->id))->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'record-own') && $request->method() === 'DELETE');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'record-other'));
});

test('replaceRecord updates the cloudflare record when the conflict still matches', function () {
    $token = IntegrationToken::factory()->create(['provider' => 'cloudflare', 'token' => 'secret']);
    $zone = DnsProviderZone::factory()->for($token)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/record-1' => Http::response([
            'success' => true, 'result' => ['id' => 'record-1'],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '198.51.100.50']],
        ]),
    ]);

    $record = app(CloudflareDnsProvider::class)->replaceRecord(
        $zone, 'record-1', 'app.example.com', '203.0.113.10', expectedCurrent: '198.51.100.50',
    );

    expect($record->provider_record_id)->toBe('record-1')->and($record->content)->toBe('203.0.113.10');
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/dns_records/record-1')
        && $request->data()['name'] === 'app.example.com'
        && $request->data()['content'] === '203.0.113.10');
});

test('replaceRecord rejects a stale or tampered conflict without updating dns', function (string $recordId, string $current) {
    $token = IntegrationToken::factory()->create(['provider' => 'cloudflare', 'token' => 'secret']);
    $zone = DnsProviderZone::factory()->for($token)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '198.51.100.50']],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/*' => Http::response(['success' => true, 'result' => ['id' => 'record-other']]),
    ]);

    expect(fn () => app(CloudflareDnsProvider::class)->replaceRecord(
        $zone, $recordId, 'app.example.com', '203.0.113.10', expectedCurrent: $current,
    ))->toThrow(RuntimeException::class, 'The DNS conflict is no longer available. Check the record again.');

    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
})->with([
    'wrong record id' => ['record-other', '198.51.100.50'],
    'wrong current value' => ['record-1', '203.0.113.99'],
]);

test('replacing a managed dns record uses the server ip and live cloudflare record id', function () {
    ['application' => $application, 'zone' => $zone] = prepareManagedDnsApplication();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/record-1' => Http::response([
            'success' => true, 'result' => ['id' => 'record-1'],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '198.51.100.50']],
        ]),
    ]);

    Livewire::test(Domains::class, ['application' => $application->fresh()])
        ->set('dnsProviderConflicts', [
            'app.example.com|'.$zone->id => [
                'record_id' => 'record-1',
                'current' => '198.51.100.50',
                'proposed' => '198.51.100.1',
            ],
        ])
        ->call('replaceManagedDnsRecord', 'app.example.com', $zone->id)
        ->assertDispatched('success', 'DNS record replaced for app.example.com.');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/dns_records/record-1')
        && $request->data()['content'] === '203.0.113.10');
    expect(ManagedDnsRecord::query()->where('name', 'app.example.com')->where('content', '203.0.113.10')->exists())->toBeTrue();
});

test('replacing a managed dns record ignores a tampered conflict record id', function () {
    ['application' => $application, 'zone' => $zone] = prepareManagedDnsApplication();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'record-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '198.51.100.50']],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/*' => Http::response([
            'success' => true, 'result' => ['id' => 'record-other'],
        ]),
    ]);

    Livewire::test(Domains::class, ['application' => $application->fresh()])
        ->set('dnsProviderConflicts', [
            'app.example.com|'.$zone->id => [
                'record_id' => 'record-other',
                'current' => '198.51.100.50',
                'proposed' => '198.51.100.1',
            ],
        ])
        ->call('replaceManagedDnsRecord', 'app.example.com', $zone->id)
        ->assertDispatched('error', 'The DNS conflict is no longer available. Check the record again.')
        ->assertSet('dnsProviderConflicts', []);

    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    expect(ManagedDnsRecord::query()->where('name', 'app.example.com')->exists())->toBeFalse();
});

/**
 * @return array{application: Application, zone: DnsProviderZone}
 */
function prepareManagedDnsApplication(): array
{
    test()->withoutVite();
    config(['app.maintenance.driver' => 'file']);
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_dns_validation_enabled' => false],
    ));

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id, 'ip' => '203.0.113.10']);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker'],
    ));
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://app.example.com',
        'build_pack' => 'nixpacks',
    ]);
    $application->settings()->update(['is_container_label_readonly_enabled' => true]);

    $token = IntegrationToken::factory()->for($team)->create(['provider' => 'cloudflare', 'token' => 'secret']);
    $zone = DnsProviderZone::factory()->for($token)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);

    return ['application' => $application, 'zone' => $zone];
}
