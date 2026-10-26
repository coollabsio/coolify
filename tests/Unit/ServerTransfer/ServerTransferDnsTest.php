<?php

use App\Jobs\ReleaseManagedDnsRecordsJob;
use App\Jobs\ValidateAndInstallServerJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\DnsProviderZone;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\ServerTransfer\ServerTransferExporter;
use App\Services\ServerTransfer\ServerTransferImporter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Process::fake();
    Storage::fake('ssh-keys');
    // Deleting the source resources must not release their DNS records at the provider.
    Queue::fake([ValidateAndInstallServerJob::class, ReleaseManagedDnsRecordsJob::class]);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'fqdn' => 'https://coolify-a.test']);
    config(['app.id' => 'source-instance']);

    $this->team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'ip' => '10.66.0.10']);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $onDestination = ['environment_id' => $this->environment->id, 'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()];

    $this->application = Application::factory()->create([...$onDestination, 'name' => 'web', 'fqdn' => 'https://app.example.com']);
    $this->preview = new ApplicationPreview;
    $this->preview->forceFill(['application_id' => $this->application->id, 'pull_request_id' => 3, 'pull_request_html_url' => '', 'fqdn' => 'https://pr-3.example.com']);
    $this->preview->uuid = 'dns-transfer-preview';
    $this->preview->saveQuietly();
    $this->service = Service::factory()->create([...$onDestination, 'server_id' => $this->server->id]);
    $this->serviceApplication = new ServiceApplication;
    $this->serviceApplication->forceFill(['service_id' => $this->service->id, 'name' => 'whoami', 'fqdn' => 'https://svc.example.com']);
    $this->serviceApplication->uuid = 'dns-transfer-service-app';
    $this->serviceApplication->save();

    $this->cloudflareToken = IntegrationToken::factory()->create([
        'team_id' => $this->team->id, 'provider' => 'cloudflare', 'token' => 'cf-api-token', 'capabilities' => ['dns', 'tunnel'],
    ]);
    $this->zone = DnsProviderZone::factory()->create(['integration_token_id' => $this->cloudflareToken->id, 'name' => 'example.com', 'provider_zone_id' => 'zone-1']);

    $this->exporter = app(ServerTransferExporter::class);
    $this->importer = app(ServerTransferImporter::class);
});

/**
 * @param  list<Model>  $resources
 */
function dnsTransferRecord(object $test, string $name, array $resources, bool $owned = true): ManagedDnsRecord
{
    $record = ManagedDnsRecord::factory()->create([
        'dns_provider_zone_id' => $test->zone->id, 'provider_record_id' => 'cf-'.$name,
        'type' => 'A', 'name' => $name, 'content' => '10.66.0.10', 'owned' => $owned,
    ]);
    foreach ($resources as $resource) {
        $record->addReference($resource);
    }

    return $record;
}

/**
 * The source rows are removed so the import can use the same UUIDs. The source forgets the DNS records at transfer.
 */
function removeDnsTransferSource(object $test): void
{
    ManagedDnsRecord::query()->delete();
    ApplicationPreview::withoutEvents(fn () => ApplicationPreview::withTrashed()->get()->each->forceDelete());
    ServiceApplication::withTrashed()->get()->each->forceDelete();
    $test->service->forceDelete();
    $test->application->forceDelete();
    $test->server->forceDelete();
}

test('integration tokens move with the transfer and a token with the same uuid in the team is reused', function () {
    $secretsToken = IntegrationToken::factory()->create([
        'team_id' => $this->team->id, 'provider' => 'infisical', 'token' => 'infisical-secret', 'capabilities' => ['secrets'], 'metadata' => ['client_id' => 'client'],
    ]);
    $this->application->secretManagerLink()->create(['integration_token_id' => $secretsToken->id, 'settings' => ['project_id' => 'p1', 'environment' => 'prod']]);
    $this->service->secretManagerLink()->create(['integration_token_id' => $secretsToken->id, 'settings' => ['project_id' => 'p2', 'environment' => 'dev']]);
    $this->server->settings->forceFill(['cloudflare_tunnel_integration_token_id' => $this->cloudflareToken->id])->saveQuietly();

    $bundle = $this->exporter->export($this->server->fresh());

    expect(collect($bundle['integration_tokens'])->pluck('token', 'uuid')->all())
        ->toEqualCanonicalizing([$secretsToken->uuid => 'infisical-secret', $this->cloudflareToken->uuid => 'cf-api-token'])
        ->and($bundle['server']['settings']['cloudflare_tunnel_integration_token_uuid'])->toBe($this->cloudflareToken->uuid)
        ->and($bundle['server']['settings'])->not->toHaveKey('cloudflare_tunnel_integration_token_id')
        ->and(implode(' ', $bundle['warnings']))->not->toContain('secret manager')
        ->and(implode(' ', $bundle['warnings']))->not->toContain('Cloudflare API token');

    removeDnsTransferSource($this);
    $result = $this->importer->import($bundle, teamId: $this->team->id);

    $application = Application::where('uuid', $this->application->uuid)->firstOrFail();
    $service = Service::where('uuid', $this->service->uuid)->firstOrFail();
    expect(IntegrationToken::count())->toBe(2)
        ->and($result['created']['integration_tokens'])->toBe(2)
        ->and($application->secretManagerLink->integration_token_id)->toBe($secretsToken->id)
        ->and($application->secretManagerLink->settings)->toBe(['project_id' => 'p1', 'environment' => 'prod'])
        ->and($service->secretManagerLink->integration_token_id)->toBe($secretsToken->id)
        ->and($service->secretManagerLink->settings)->toBe(['project_id' => 'p2', 'environment' => 'dev'])
        ->and(Server::where('uuid', $result['server_uuid'])->firstOrFail()->settings->cloudflare_tunnel_integration_token_id)->toBe($this->cloudflareToken->id);
});

test('integration tokens get a new uuid in the target team when another team has the uuid', function () {
    $secretsToken = IntegrationToken::factory()->create(['team_id' => $this->team->id, 'provider' => 'doppler', 'token' => 'dp.st.secret', 'capabilities' => ['secrets']]);
    $this->application->secretManagerLink()->create(['integration_token_id' => $secretsToken->id, 'settings' => ['project' => 'web', 'config' => 'prd']]);
    $this->server->settings->forceFill(['cloudflare_tunnel_integration_token_id' => $this->cloudflareToken->id])->saveQuietly();
    $bundle = $this->exporter->export($this->server->fresh());
    $targetTeam = Team::factory()->create();

    removeDnsTransferSource($this);
    $result = $this->importer->import($bundle, teamId: $targetTeam->id);

    $targetTokens = IntegrationToken::where('team_id', $targetTeam->id)->get()->keyBy('provider');
    $link = Application::where('uuid', $this->application->uuid)->firstOrFail()->secretManagerLink;
    expect($targetTokens)->toHaveCount(2)
        ->and($targetTokens['doppler']->uuid)->not->toBe($secretsToken->uuid)
        ->and($targetTokens['doppler']->token)->toBe('dp.st.secret')
        ->and($targetTokens['cloudflare']->uuid)->not->toBe($this->cloudflareToken->uuid)
        ->and($link->integration_token_id)->toBe($targetTokens['doppler']->id)
        ->and(Server::where('uuid', $result['server_uuid'])->firstOrFail()->settings->cloudflare_tunnel_integration_token_id)->toBe($targetTokens['cloudflare']->id)
        ->and($secretsToken->fresh()->team_id)->toBe($this->team->id);
});

test('managed DNS records, their zone and references move with the resources', function () {
    Http::fake();
    $appRecord = dnsTransferRecord($this, 'app.example.com', [$this->application]);
    dnsTransferRecord($this, 'pr-3.example.com', [$this->preview], owned: false);
    $serviceRecord = dnsTransferRecord($this, 'svc.example.com', [$this->serviceApplication]);

    $bundle = $this->exporter->export($this->server->fresh());

    expect($bundle['dns_zones'])->toHaveCount(1)
        ->and($bundle['integration_tokens'][0]['uuid'])->toBe($this->cloudflareToken->uuid)
        ->and(collect($bundle['managed_dns_records'])->pluck('references', 'name')->all())->toBe([
            'app.example.com' => [['kind' => 'application', 'uuid' => $this->application->uuid]],
            'pr-3.example.com' => [['kind' => 'preview', 'uuid' => 'dns-transfer-preview']],
            'svc.example.com' => [['kind' => 'service_application', 'uuid' => 'dns-transfer-service-app']],
        ])
        ->and($bundle['managed_dns_records'][0]['ownership_comment'])->toBe($appRecord->ownershipComment())
        ->and(implode(' ', $bundle['warnings']))->not->toContain('DNS record');

    removeDnsTransferSource($this);
    $result = $this->importer->import($bundle, teamId: $this->team->id);

    $records = ManagedDnsRecord::with('references.resource')->get()->keyBy('name');
    expect($result['created']['managed_dns_records'])->toBe(3)
        ->and(DnsProviderZone::count())->toBe(1)
        ->and($records)->toHaveCount(3)
        ->and($records['app.example.com']->uuid)->toBe($appRecord->uuid)
        ->and($records['app.example.com']->dns_provider_zone_id)->toBe($this->zone->id)
        ->and($records['app.example.com']->integration_token_id)->toBe($this->cloudflareToken->id)
        ->and($records['app.example.com']->owned)->toBeTrue()
        ->and($records['pr-3.example.com']->owned)->toBeFalse()
        ->and($records['svc.example.com']->uuid)->toBe($serviceRecord->uuid)
        ->and($records['app.example.com']->references->sole()->resource->uuid)->toBe($this->application->uuid)
        ->and($records['pr-3.example.com']->references->sole()->resource)->toBeInstanceOf(ApplicationPreview::class)
        ->and($records['pr-3.example.com']->references->sole()->resource->uuid)->toBe('dns-transfer-preview')
        ->and($records['svc.example.com']->references->sole()->resource)->toBeInstanceOf(ServiceApplication::class)
        ->and($records['svc.example.com']->references->sole()->resource->uuid)->toBe('dns-transfer-service-app');
    // Same instance id and UUID: the provider comment already proves ownership here.
    Http::assertNothingSent();
});

test('a DNS record that a resource staying on the source also uses is not exported', function () {
    $otherServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->server->private_key_id, 'ip' => '10.66.0.11']);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
    $staying = Application::factory()->create([
        'environment_id' => $this->environment->id, 'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(), 'fqdn' => 'https://shared.example.com',
    ]);
    dnsTransferRecord($this, 'shared.example.com', [$this->application, $staying]);

    $bundle = $this->exporter->export($this->server->fresh());

    expect($bundle['managed_dns_records'])->toBe([])
        ->and($bundle['dns_zones'])->toBe([])
        ->and($bundle['integration_tokens'])->toBe([])
        ->and(implode(' ', $bundle['warnings']))->toContain('DNS record shared.example.com stays managed by this instance');
});

test('the target takes ownership of an owned record when the provider still has the source comment', function () {
    $record = dnsTransferRecord($this, 'app.example.com', [$this->application]);
    dnsTransferRecord($this, 'pr-3.example.com', [$this->preview], owned: false);
    $sourceComment = $record->ownershipComment();
    $bundle = $this->exporter->export($this->server->fresh());
    removeDnsTransferSource($this);

    $state = fakeCloudflareDns([
        ['id' => 'cf-app.example.com', 'type' => 'A', 'name' => 'app.example.com', 'content' => '10.66.0.10', 'comment' => $sourceComment],
        ['id' => 'cf-pr-3.example.com', 'type' => 'A', 'name' => 'pr-3.example.com', 'content' => '10.66.0.10', 'comment' => null],
    ]);
    config(['app.id' => 'target-instance']);
    $result = $this->importer->import($bundle, teamId: $this->team->id);

    $targetComment = ManagedDnsRecord::ownershipCommentFor($record->uuid);
    expect($targetComment)->not->toBe($sourceComment)
        ->and(sentDnsRequests('PATCH'))->toBe(1)
        ->and(Http::recorded(fn (Request $request) => $request->method() === 'PATCH')->sole()[0]->data())->toBe(['comment' => $targetComment])
        ->and($state['records']['cf-app.example.com']['comment'])->toBe($targetComment)
        ->and(ManagedDnsRecord::where('name', 'app.example.com')->sole()->owned)->toBeTrue()
        ->and(ManagedDnsRecord::where('name', 'pr-3.example.com')->sole()->owned)->toBeFalse()
        ->and(implode(' ', $result['warnings']))->not->toContain('could not take ownership');
});

test('the target does not own a record it cannot adopt and the import still succeeds', function (Closure $fakeProvider) {
    $record = dnsTransferRecord($this, 'app.example.com', [$this->application]);
    $bundle = $this->exporter->export($this->server->fresh());
    removeDnsTransferSource($this);

    $fakeProvider($record);
    config(['app.id' => 'target-instance']);
    $result = $this->importer->import($bundle, teamId: $this->team->id);

    expect(sentDnsRequests('PATCH'))->toBe(0)
        ->and(Server::where('uuid', $result['server_uuid'])->exists())->toBeTrue()
        ->and(ManagedDnsRecord::where('name', 'app.example.com')->sole()->owned)->toBeFalse()
        ->and($result['warnings'])->toContain('DNS record app.example.com: this instance could not take ownership; it will not change or delete it.');
})->with([
    'the provider comment changed' => [fn (ManagedDnsRecord $record) => fakeCloudflareDns([
        ['id' => 'cf-app.example.com', 'type' => 'A', 'name' => 'app.example.com', 'content' => '10.66.0.10', 'comment' => 'edited by hand'],
    ])],
    'the provider cannot be reached' => [fn (ManagedDnsRecord $record) => Http::fake(fn () => throw new ConnectionException('Connection refused'))],
]);

test('the DNS record of the Cloudflare tunnel moves with the server', function () {
    $this->server->settings->forceFill(['is_cloudflare_tunnel' => true, 'cloudflare_tunnel_id' => 'tun-1', 'cloudflare_tunnel_integration_token_id' => $this->cloudflareToken->id])->saveQuietly();
    $record = dnsTransferRecord($this, 'ssh.example.com', [$this->server]);
    $bundle = $this->exporter->export($this->server->fresh());

    removeDnsTransferSource($this);
    $this->importer->import($bundle, teamId: $this->team->id);

    $imported = ManagedDnsRecord::where('name', 'ssh.example.com')->firstOrFail();
    $server = Server::where('uuid', $this->server->uuid)->firstOrFail();
    expect($imported->uuid)->toBe($record->uuid)
        ->and($imported->references()->where('resource_type', $server->getMorphClass())->where('resource_id', $server->id)->exists())->toBeTrue();
});
