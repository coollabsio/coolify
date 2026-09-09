<?php

use App\Events\DnsRecordConfigurationFinished;
use App\Jobs\ConfigureDnsRecordJob;
use App\Models\DnsProviderZone;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\Team;
use App\Services\Dns\CloudflareDnsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

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

test('domain dns ux prompts after add and keeps later provider actions with manual records', function () {
    $applicationComponent = file_get_contents(app_path('Livewire/Project/Application/Domains.php'));
    $serviceComponent = file_get_contents(app_path('Livewire/Project/Service/Domains.php'));
    $dnsMenu = file_get_contents(resource_path('views/livewire/project/shared/cloudflare-autoconfigure.blade.php'));
    $dnsProviderConcern = file_get_contents(app_path('Livewire/Concerns/InteractsWithDnsProviders.php'));

    expect($applicationComponent)->toContain('configureDnsAfterDomainAdd($addedUrls)')
        ->and($serviceComponent)->toContain('configureDnsAfterDomainAdd($addedUrls)')
        ->and($dnsMenu)->toContain('wire:click="openManualDnsRecords"')
        ->and($dnsMenu)->toContain('Add with {{ $provider[\'credential\'] }}')
        ->and($dnsMenu)->not->toContain('Connected provider')
        ->and($dnsProviderConcern)->toContain('ConfigureDnsRecordJob::dispatch(')
        ->and($dnsProviderConcern)->toContain('Adding DNS record for {$proposal[\'hostname\']}')
        ->and($dnsProviderConcern)->toContain('dispatch(\'success\', $event[\'message\'])');

    $providerModal = file_get_contents(resource_path('views/livewire/project/shared/dns-provider-management.blade.php'));
    expect($providerModal)->toContain('Illuminate\\Support\\Js::from($proposal[\'hostname\'])')
        ->and($providerModal)->not->toContain('createManagedDnsRecord(@js');
});
