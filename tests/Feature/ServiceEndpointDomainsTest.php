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
    endpointVariableUpdate($this->service, 'GARAGE_S3_API_URL', 'https://changed.example.org');
    $this->garage->refresh()->importServiceEndpoints();

    expect($this->garage->refresh()->domain_endpoints)->toBe([
        'https://s3.example.org' => 's3',
        'https://other.example.org' => 's3',
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

it('maps new domains by port, by www counterpart, or to the primary endpoint', function () {
    setEndpointVariables($this->service, ['GARAGE_S3_API_URL' => 'https://s3.example.org', 'GARAGE_WEB_URL' => 'https://web.example.org', 'GARAGE_ADMIN_URL' => '']);
    $this->garage->importServiceEndpoints();

    $this->garage->setEditableUrls('https://s3.example.org,https://web.example.org,https://plain.example.org,https://site.example.org:3902,https://www.web.example.org');
    $this->garage->save();

    expect($this->garage->refresh()->domain_endpoints)->toBe([
        'https://s3.example.org' => 's3',
        'https://web.example.org' => 'web',
        'https://plain.example.org' => 's3',
        'https://site.example.org' => 'web',
        'https://www.web.example.org' => 'web',
    ])->and($this->garage->domain_port_overrides)->toBe([
        'https://s3.example.org' => 3900,
        'https://web.example.org' => 3902,
        'https://plain.example.org' => 3900,
        'https://site.example.org' => 3902,
        'https://www.web.example.org' => 3902,
    ]);
});

it('syncs the first domain of each endpoint to its variable and clears removed ones', function () {
    setEndpointVariables($this->service, [
        'GARAGE_S3_API_URL' => 'https://s3.example.org',
        'GARAGE_WEB_URL' => 'https://web.example.org',
        'GARAGE_ADMIN_URL' => 'https://admin.example.org',
    ]);
    $this->garage->importServiceEndpoints();

    $this->garage->setEditableUrls('https://new-s3.example.org,https://s3.example.org,https://admin.example.org:3903');
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
