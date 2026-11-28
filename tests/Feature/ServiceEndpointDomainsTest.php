<?php

use App\Livewire\Project\Service\Domains;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->withoutVite();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['is_dns_validation_enabled' => false]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'ip' => '203.0.113.10']);
    $this->server->settings()->update(['wildcard_domain' => 'http://example.com', 'generate_exact_labels' => false]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => Yaml::dump(['services' => ['garage' => [
            'image' => 'dxflrs/garage:v2.5.0',
            'environment' => [
                'GARAGE_S3_API_URL=$GARAGE_S3_API_URL',
                'GARAGE_WEB_URL=$GARAGE_WEB_URL',
                'GARAGE_ADMIN_URL=$GARAGE_ADMIN_URL',
            ],
        ]]]),
    ]);
    $this->garage = ServiceApplication::create([
        'name' => 'garage',
        'service_id' => $this->service->id,
        'image' => 'dxflrs/garage:v2.5.0',
        'fqdn' => 'https://never-routed.example.com',
    ]);
});

function setEndpointVariables(Service $service, array $values): void
{
    foreach ($values as $key => $value) {
        $service->environment_variables()->create(['key' => $key, 'value' => $value]);
    }
}

function endpointVariable(Service $service, string $key): ?string
{
    return $service->environment_variables()->where('key', $key)->first()?->value;
}

function endpointVariableUpdate(Service $service, string $key, string $value): void
{
    $service->environment_variables()->where('key', $key)->first()->update(['value' => $value]);
}

it('imports endpoint urls from the environment and generates the empty ones', function () {
    setEndpointVariables($this->service, [
        'GARAGE_S3_API_URL' => 'https://s3.example.org:3900',
        'GARAGE_WEB_URL' => '',
        'GARAGE_ADMIN_URL' => 'https://admin.example.org',
    ]);
    $uuid = $this->garage->uuid;

    $this->garage->importServiceEndpoints();
    $this->garage->refresh();

    expect($this->garage->fqdn)->toBe("https://s3.example.org,https://web-{$uuid}.example.com,https://admin.example.org")
        ->and($this->garage->domain_endpoints)->toBe([
            'https://s3.example.org' => 's3',
            "https://web-{$uuid}.example.com" => 'web',
            'https://admin.example.org' => 'admin',
        ])
        ->and($this->garage->domain_port_overrides)->toBe([
            'https://s3.example.org' => 3900,
            "https://web-{$uuid}.example.com" => 3902,
            'https://admin.example.org' => 3903,
        ])
        ->and(endpointVariable($this->service, 'GARAGE_WEB_URL'))->toBe("https://web-{$uuid}.example.com")
        ->and(endpointVariable($this->service, 'GARAGE_S3_API_URL'))->toBe('https://s3.example.org:3900');
});

it('treats the image as a normal application when an endpoint variable is missing', function () {
    setEndpointVariables($this->service, ['GARAGE_S3_API_URL' => 'https://s3.example.org', 'GARAGE_WEB_URL' => '']);

    $this->garage->importServiceEndpoints();

    expect($this->garage->serviceEndpoints())->toBe([])
        ->and($this->garage->refresh()->domain_endpoints)->toBeNull()
        ->and($this->garage->fqdn)->toBe('https://never-routed.example.com');
});

it('imports the endpoints only once', function () {
    setEndpointVariables($this->service, ['GARAGE_S3_API_URL' => 'https://s3.example.org', 'GARAGE_WEB_URL' => '', 'GARAGE_ADMIN_URL' => '']);

    $this->garage->importServiceEndpoints();
    $this->garage->update(['fqdn' => 'https://s3.example.org,https://other.example.org']);
    // Without events, so only a second import could take over the value.
    $this->service->environment_variables()->where('key', 'GARAGE_S3_API_URL')->first()->updateQuietly(['value' => 'https://changed.example.org']);
    $this->garage->refresh()->importServiceEndpoints();

    expect($this->garage->refresh()->domain_endpoints)->toBe([
        'https://s3.example.org' => 's3',
        'https://other.example.org' => 'web',
    ]);
});

it('gives the endpoints only to the first application with the image', function () {
    setEndpointVariables($this->service, ['GARAGE_S3_API_URL' => 'https://s3.example.org', 'GARAGE_WEB_URL' => 'https://web.example.org', 'GARAGE_ADMIN_URL' => 'https://admin.example.org']);
    $helper = ServiceApplication::create([
        'name' => 'garage-setup',
        'service_id' => $this->service->id,
        'image' => 'dxflrs/garage:v2.5.0',
        'fqdn' => 'https://helper.example.org',
    ]);

    $helper->importServiceEndpoints();
    $this->garage->importServiceEndpoints();

    expect($helper->refresh()->domain_endpoints)->toBeNull()
        ->and($helper->fqdn)->toBe('https://helper.example.org')
        ->and($this->garage->refresh()->domain_endpoints)->toBe([
            'https://s3.example.org' => 's3',
            'https://web.example.org' => 'web',
            'https://admin.example.org' => 'admin',
        ]);
});

it('maps new domains by port, by www counterpart, or to an endpoint without a domain', function () {
    setEndpointVariables($this->service, ['GARAGE_S3_API_URL' => 'https://s3.example.org', 'GARAGE_WEB_URL' => 'https://web.example.org', 'GARAGE_ADMIN_URL' => '']);
    $this->garage->importServiceEndpoints();

    $this->garage->setEditableUrls('https://s3.example.org,https://web.example.org,https://plain.example.org,https://site.example.org:3902,https://www.web.example.org');
    $this->garage->save();

    expect($this->garage->refresh()->domain_endpoints)->toBe([
        'https://s3.example.org' => 's3',
        'https://web.example.org' => 'web',
        'https://plain.example.org' => 'admin',
        'https://site.example.org' => 'web',
        'https://www.web.example.org' => 'web',
    ])->and($this->garage->domain_port_overrides)->toBe([
        'https://s3.example.org' => 3900,
        'https://web.example.org' => 3902,
        'https://plain.example.org' => 3903,
        'https://site.example.org' => 3902,
        'https://www.web.example.org' => 3902,
    ]);
});

it('keeps every endpoint when new domains without ports replace the domains', function () {
    setEndpointVariables($this->service, [
        'GARAGE_S3_API_URL' => 'https://s3.example.org',
        'GARAGE_WEB_URL' => 'https://web.example.org',
        'GARAGE_ADMIN_URL' => 'https://admin.example.org',
    ]);
    $this->garage->importServiceEndpoints();

    // Like the API urls field: the list replaces the domains, a kept domain can come after a new one.
    $this->garage->fqdn = 'https://new-web.example.org,https://s3.example.org,https://new-admin.example.org';
    $this->garage->save();
    $this->garage->syncServiceEndpointVariables();

    expect($this->garage->refresh()->domain_endpoints)->toBe([
        'https://new-web.example.org' => 'web',
        'https://s3.example.org' => 's3',
        'https://new-admin.example.org' => 'admin',
    ])
        ->and(endpointVariable($this->service, 'GARAGE_WEB_URL'))->toBe('https://new-web.example.org')
        ->and(endpointVariable($this->service, 'GARAGE_ADMIN_URL'))->toBe('https://new-admin.example.org');
});

it('moves a domain to another endpoint from the edit form of the Domains page', function () {
    Queue::fake();
    setEndpointVariables($this->service, [
        'GARAGE_S3_API_URL' => 'https://s3.example.org',
        'GARAGE_WEB_URL' => 'https://web.example.org',
        'GARAGE_ADMIN_URL' => 'https://admin.example.org',
    ]);

    $this->garage->importServiceEndpoints();

    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])]);
    $index = collect($component->get('domainRows'))->search(fn (array $row): bool => $row['url'] === 'https://web.example.org');
    $component->call('startEdit', $index)
        ->assertSet('editingEndpoint', 'web')
        ->set('editingEndpoint', 'admin')
        ->call('updateDomain')
        ->assertHasNoErrors();

    $this->garage->refresh();
    expect($this->garage->domain_endpoints['https://web.example.org'])->toBe('admin')
        ->and($this->garage->domain_port_overrides['https://web.example.org'])->toBe(3903);
});

it('syncs the first domain of each endpoint to its variable and clears removed ones', function () {
    setEndpointVariables($this->service, [
        'GARAGE_S3_API_URL' => 'https://s3.example.org',
        'GARAGE_WEB_URL' => 'https://web.example.org',
        'GARAGE_ADMIN_URL' => 'https://admin.example.org',
    ]);
    $this->garage->importServiceEndpoints();

    $this->garage->setEditableUrls('https://new-s3.example.org:3900,https://s3.example.org,https://admin.example.org:3903');
    $this->garage->save();
    $this->garage->syncServiceEndpointVariables();

    expect(endpointVariable($this->service, 'GARAGE_S3_API_URL'))->toBe('https://new-s3.example.org')
        ->and(endpointVariable($this->service, 'GARAGE_WEB_URL'))->toBe('')
        ->and(endpointVariable($this->service, 'GARAGE_ADMIN_URL'))->toBe('https://admin.example.org');
});

it('routes every Garage endpoint to its own port when the service is parsed', function () {
    setEndpointVariables($this->service, ['GARAGE_ADMIN_URL' => 'https://admin.example.org']);
    $uuid = $this->garage->uuid;

    $this->service->parse();

    $labels = Yaml::parse($this->service->refresh()->docker_compose)['services']['garage']['labels'];
    $ports = [];
    $hosts = [];
    foreach ($labels as $label) {
        if (preg_match('/^traefik\.http\.services\.(https-\d+-\S+)\.loadbalancer\.server\.port=(\d+)$/', $label, $match)) {
            $ports[$match[1]] = (int) $match[2];
        }
        if (preg_match('/^traefik\.http\.routers\.(https-\d+-\S+)\.rule=Host\(`([^`]+)`\)/', $label, $match)) {
            $hosts[$match[1]] = $match[2];
        }
    }
    $routes = collect($hosts)->mapWithKeys(fn (string $host, string $router): array => [$host => $ports[$router] ?? null])->all();

    expect($this->garage->refresh()->fqdn)->toBe("https://s3-{$uuid}.example.com,https://web-{$uuid}.example.com,https://admin.example.org")
        ->and($routes)->toBe([
            "s3-{$uuid}.example.com" => 3900,
            "web-{$uuid}.example.com" => 3902,
            'admin.example.org' => 3903,
        ])
        ->and(endpointVariable($this->service, 'GARAGE_S3_API_URL'))->toBe("https://s3-{$uuid}.example.com");
});

it('adds a domain to the selected endpoint from the Domains page', function () {
    Queue::fake();
    setEndpointVariables($this->service, [
        'GARAGE_S3_API_URL' => 'https://s3.example.org',
        'GARAGE_WEB_URL' => 'https://web.example.org',
        'GARAGE_ADMIN_URL' => 'https://admin.example.org',
    ]);

    $this->garage->importServiceEndpoints();

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSet('newEndpoint', 's3')
        ->assertSee('Website')
        ->set('newServiceApplicationId', $this->garage->id)
        ->set('newEndpoint', 'web')
        ->set('newDomainParts', ['scheme' => 'https', 'host' => 'files.example.org', 'port' => '8080', 'path' => ''])
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->firstWhere('url', 'https://files.example.org')['endpoint_label'] === 'Website');

    $this->garage->refresh();
    expect($this->garage->domain_endpoints['https://files.example.org'])->toBe('web')
        ->and($this->garage->domain_port_overrides['https://files.example.org'])->toBe(3902)
        ->and(endpointVariable($this->service, 'GARAGE_WEB_URL'))->toBe('https://web.example.org');
});

it('keeps the variable routing of services on the legacy parser', function () {
    setEndpointVariables($this->service, ['GARAGE_S3_API_URL' => 'https://s3.example.org', 'GARAGE_WEB_URL' => '', 'GARAGE_ADMIN_URL' => '']);
    $this->service->forceFill(['compose_parsing_version' => '2'])->saveQuietly();

    $garage = $this->garage->fresh();
    $garage->importServiceEndpoints();

    expect($garage->hasLegacyServiceSpecificRouting())->toBeTrue()
        ->and($garage->serviceEndpoints())->toBe([])
        ->and($garage->refresh()->domain_endpoints)->toBeNull()
        ->and($garage->fqdn)->toBe('https://never-routed.example.com')
        ->and($this->service->fresh()->endpointVariableKeys())->toBe([]);
});

it('moves the domain of an endpoint when its variable changes', function () {
    setEndpointVariables($this->service, [
        'GARAGE_S3_API_URL' => 'https://s3.example.org',
        'GARAGE_WEB_URL' => 'https://web.example.org',
        'GARAGE_ADMIN_URL' => 'https://admin.example.org',
    ]);
    $this->garage->importServiceEndpoints();

    // Like the environment variables API: the value changes, the Domains page did not save it.
    endpointVariableUpdate($this->service, 'GARAGE_WEB_URL', 'https://site.example.org');
    endpointVariableUpdate($this->service, 'GARAGE_ADMIN_URL', '');
    $this->garage->refresh()->syncServiceEndpointVariables();

    expect($this->garage->refresh()->domain_endpoints)->toBe([
        'https://s3.example.org' => 's3',
        'https://site.example.org' => 'web',
    ])
        ->and($this->garage->domain_port_overrides['https://site.example.org'])->toBe(3902)
        ->and(endpointVariable($this->service, 'GARAGE_WEB_URL'))->toBe('https://site.example.org')
        ->and(endpointVariable($this->service, 'GARAGE_ADMIN_URL'))->toBe('');
});

it('imports the endpoint domains of existing services in a migration', function () {
    setEndpointVariables($this->service, [
        'GARAGE_S3_API_URL' => 'https://s3.example.org',
        'GARAGE_WEB_URL' => 'https://web.example.org',
        'GARAGE_ADMIN_URL' => 'https://admin.example.org',
    ]);

    (require database_path('migrations/2026_11_26_160000_import_service_endpoint_domains.php'))->up();

    expect($this->garage->refresh()->domain_endpoints)->toBe([
        'https://s3.example.org' => 's3',
        'https://web.example.org' => 'web',
        'https://admin.example.org' => 'admin',
    ])->and($this->garage->fqdn)->toBe('https://s3.example.org,https://web.example.org,https://admin.example.org');
});

function convexLikeService(Service $garageService, array $environment): ServiceApplication
{
    $service = Service::factory()->create([
        'environment_id' => $garageService->environment_id,
        'server_id' => $garageService->server_id,
        'destination_id' => $garageService->destination_id,
        'destination_type' => $garageService->destination_type,
        'docker_compose_raw' => Yaml::dump(['services' => ['backend' => [
            'image' => 'ghcr.io/get-convex/convex-backend:latest',
            'environment' => $environment,
        ]]]),
    ]);

    return ServiceApplication::create(['name' => 'backend', 'service_id' => $service->id, 'image' => 'ghcr.io/get-convex/convex-backend:latest']);
}

/**
 * @return array<string, int>
 */
function routedPorts(Service $service, string $container): array
{
    $labels = Yaml::parse($service->refresh()->docker_compose)['services'][$container]['labels'];
    $ports = [];
    $hosts = [];
    foreach ($labels as $label) {
        if (preg_match('/^traefik\.http\.services\.(http-\d+-\S+)\.loadbalancer\.server\.port=(\d+)$/', $label, $match)) {
            $ports[$match[1]] = (int) $match[2];
        }
        if (preg_match('/^traefik\.http\.routers\.(http-\d+-\S+)\.rule=Host\(`([^`]+)`\)/', $label, $match)) {
            $hosts[$match[1]] = $match[2];
        }
    }

    return collect($hosts)->mapWithKeys(fn (string $host, string $router): array => [$host => $ports[$router] ?? null])->all();
}

it('gives each magic URL name of a container its own domain and port', function () {
    $backend = convexLikeService($this->service, [
        'SERVICE_URL_BACKEND_3210',
        'SERVICE_URL_SITE_3211',
        'CONVEX_SITE_ORIGIN=${SERVICE_URL_SITE}',
    ]);
    $service = $backend->service;

    $service->parse();

    expect($backend->refresh()->domain_endpoints)->toBe([
        "http://backend-{$service->uuid}.example.com" => 'backend',
        "http://site-{$service->uuid}.example.com" => 'site',
    ])
        ->and(routedPorts($service, 'backend'))->toBe([
            "backend-{$service->uuid}.example.com" => 3210,
            "site-{$service->uuid}.example.com" => 3211,
        ])
        ->and(endpointVariable($service, 'SERVICE_URL_SITE'))->toBe("http://site-{$service->uuid}.example.com")
        ->and(endpointVariable($service, 'SERVICE_URL_SITE_3211'))->toBe("http://site-{$service->uuid}.example.com:3211")
        ->and(endpointVariable($service, 'SERVICE_URL_BACKEND'))->toBe("http://backend-{$service->uuid}.example.com");

    // A domain saved on the Domains page moves only the variables of its endpoint.
    $backend->setEditableUrls("http://backend-{$service->uuid}.example.com,https://site.example.org:3211");
    $backend->save();
    updateCompose($backend->fresh());

    expect(endpointVariable($service, 'SERVICE_URL_SITE'))->toBe('https://site.example.org')
        ->and(endpointVariable($service, 'SERVICE_FQDN_SITE'))->toBe('site.example.org')
        ->and(endpointVariable($service, 'SERVICE_URL_BACKEND'))->toBe("http://backend-{$service->uuid}.example.com");
});

it('keeps the domains of an existing container on the endpoint they routed to', function () {
    $backend = convexLikeService($this->service, ['SERVICE_URL_BACKEND_3210', 'SERVICE_URL_SITE_3211']);
    $backend->update(['fqdn' => 'https://api.example.org,https://www.api.example.org']);

    $backend->importServiceEndpoints();

    expect($backend->refresh()->domain_endpoints)->toBe([
        'https://api.example.org' => 'backend',
        'https://www.api.example.org' => 'backend',
        "http://site-{$backend->service->uuid}.example.com" => 'site',
    ])->and($backend->domain_port_overrides['https://api.example.org'])->toBe(3210);
});

it('keeps one route for a container with a name without a port or a domain with another port', function () {
    $proxy = convexLikeService($this->service, ['SERVICE_URL_BACKEND', 'SERVICE_URL_METRICS_2112']);
    expect($proxy->serviceEndpoints())->toBe([]);

    $backend = convexLikeService($this->service, ['SERVICE_URL_BACKEND_3210', 'SERVICE_URL_SITE_3211']);
    $backend->update(['fqdn' => 'https://api.example.org:8080']);
    $backend->importServiceEndpoints();

    expect($backend->refresh()->domain_endpoints)->toBeNull()
        ->and($backend->fqdn)->toBe('https://api.example.org');
});
