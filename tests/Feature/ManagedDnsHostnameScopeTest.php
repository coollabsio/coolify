<?php

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
});

/**
 * @return array{application: Application, zone: DnsProviderZone}
 */
function prepareHostnameScopeDnsApplication(): array
{
    test()->withoutVite();
    config(['app.maintenance.driver' => 'file']);
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_dns_validation_enabled' => false],
    ));

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'admin']);
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

function fakeCloudflareWithExistingWwwRecord(): void
{
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'record-www', 'type' => 'A', 'name' => 'www.company.example.com', 'content' => '198.51.100.50']],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/record-www' => Http::response([
            'success' => true, 'result' => ['id' => 'record-www'],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response([
            'success' => true, 'result' => ['id' => 'record-new'],
        ]),
    ]);
}

test('an admin cannot create or replace a dns record for a hostname that is not a domain of the application', function () {
    ['application' => $application, 'zone' => $zone] = prepareHostnameScopeDnsApplication();
    fakeCloudflareWithExistingWwwRecord();

    Livewire::test(Domains::class, ['application' => $application->fresh()])
        ->call('createManagedDnsRecord', 'WWW.Company.Example.com', $zone->id)
        ->assertDispatched('error')
        ->assertSet('dnsProviderConflicts', [])
        ->call('replaceManagedDnsRecord', 'www.company.example.com', $zone->id)
        ->assertDispatched('error');

    Http::assertNothingSent();
    expect(ManagedDnsRecord::query()->exists())->toBeFalse();
});

test('an admin cannot create a dns record for a hostname typed into the add domain form', function () {
    ['application' => $application, 'zone' => $zone] = prepareHostnameScopeDnsApplication();
    fakeCloudflareWithExistingWwwRecord();

    Livewire::test(Domains::class, ['application' => $application->fresh()])
        ->set('newDomain', 'https://www.company.example.com')
        ->call('createManagedDnsRecord', 'www.company.example.com', $zone->id)
        ->assertDispatched('error');

    Http::assertNothingSent();
    expect(ManagedDnsRecord::query()->exists())->toBeFalse();
});

test('an admin cannot create a dns record for a hostname that is not a domain of the service', function () {
    ['application' => $application, 'zone' => $zone] = prepareHostnameScopeDnsApplication();
    $service = Service::factory()->create([
        'server_id' => $application->destination->server->id,
        'destination_id' => $application->destination_id,
        'destination_type' => $application->destination_type,
        'environment_id' => $application->environment_id,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);
    ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'service_id' => $service->id,
        'name' => 'web',
        'human_name' => 'Web',
        'image' => 'nginx:alpine',
        'fqdn' => 'https://web.example.com',
    ]);
    fakeCloudflareWithExistingWwwRecord();

    Livewire::test(ServiceDomains::class, ['service' => $service->fresh(['applications', 'server'])])
        ->call('createManagedDnsRecord', 'www.company.example.com', $zone->id)
        ->assertDispatched('error')
        ->call('replaceManagedDnsRecord', 'www.company.example.com', $zone->id)
        ->assertDispatched('error');

    Http::assertNothingSent();
    expect(ManagedDnsRecord::query()->exists())->toBeFalse();
});

test('an admin can still create a dns record for a domain of the application', function () {
    ['application' => $application, 'zone' => $zone] = prepareHostnameScopeDnsApplication();
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'record-1']]),
    ]);

    Livewire::test(Domains::class, ['application' => $application->fresh()])
        ->call('createManagedDnsRecord', 'App.Example.com', $zone->id)
        ->assertDispatched('success', 'DNS record created for app.example.com.');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->data()['name'] === 'app.example.com'
        && $request->data()['content'] === '203.0.113.10');
    expect(ManagedDnsRecord::query()->where('name', 'app.example.com')->exists())->toBeTrue();
});
