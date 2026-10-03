<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Process::fake();
    config(['constants.ssh.mux_enabled' => false]);
    Server::flushIdentityMap();

    $this->team = Team::factory()->create();
    $this->environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
});

afterEach(fn () => Server::flushIdentityMap());

/**
 * A server that runs a Caddy image with `log_append` support (caddy-docker-proxy 2.9+).
 */
function caddyComposeTrafficServer(object $test, bool $analyticsEnabled, bool $exactLabels = false): Server
{
    $server = Server::factory()->create([
        'team_id' => $test->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $test->team->id])->id,
        'proxy' => [
            'type' => ProxyTypes::CADDY->value,
            'status' => 'running',
            'last_saved_settings' => 'applied',
            'last_applied_settings' => 'applied',
            'last_saved_proxy_configuration' => "services:\n  caddy:\n    image: 'lucaslorentz/caddy-docker-proxy:2.11-alpine'\n",
        ],
    ]);
    $server->settings->update([
        'is_traffic_analytics_enabled' => $analyticsEnabled,
        'generate_exact_labels' => $exactLabels,
    ]);
    Server::flushIdentityMap();

    return $server->fresh();
}

function caddyComposeTrafficApplication(object $test, Server $server): Application
{
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    return Application::factory()->create([
        'environment_id' => $test->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  web.app:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'web.app' => ['domain' => 'https://app.example.com'],
        ]),
    ]);
}

function caddyComposeTrafficService(object $test, Server $server): Service
{
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $service = Service::factory()->create([
        'environment_id' => $test->environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  web_app:\n    image: nginx:alpine\n",
    ]);
    ServiceApplication::create([
        'name' => 'web_app',
        'service_id' => $service->id,
        'fqdn' => 'https://service.example.com',
    ]);

    return $service->fresh();
}

function composeServiceLabels(Collection $compose, string $serviceName): Collection
{
    // Service names can contain dots, so data_get() cannot be used for the service key.
    return collect(data_get($compose, 'services')[$serviceName]['labels'] ?? [])->values();
}

it('logs compose application traffic on Caddy with the same key as the Traefik router', function (bool $exactLabels) {
    $server = caddyComposeTrafficServer($this, analyticsEnabled: true, exactLabels: $exactLabels);
    $application = caddyComposeTrafficApplication($this, $server);

    $labels = composeServiceLabels(applicationParser($application), 'web.app');
    $key = $application->uuid.'-'.traefikSafeServiceNameSegment('web.app');

    expect($labels->all())
        ->toContain('caddy_0.log.output=file /traffic/access.log')
        ->toContain("caddy_0.log_append=coolify_app_id {$key}");
    if (! $exactLabels) {
        expect($labels->all())->toContain("traefik.http.routers.https-0-{$key}.entryPoints=https");
    }
})->with([
    'exact Caddy labels' => true,
    'Traefik and Caddy labels' => false,
]);

it('uses the preview uuid in the Caddy traffic key of a compose preview', function () {
    $server = caddyComposeTrafficServer($this, analyticsEnabled: true);
    $application = caddyComposeTrafficApplication($this, $server);
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
        'docker_compose_domains' => $application->docker_compose_domains,
    ]);

    $labels = composeServiceLabels(applicationParser($application, 42, $preview->id), 'web.app-pr-42');
    $key = "{$application->uuid}-42-".traefikSafeServiceNameSegment('web.app');

    expect($labels->all())
        ->toContain("caddy_0.log_append=coolify_app_id {$key}")
        ->toContain("traefik.http.routers.https-0-{$key}.entryPoints=https");
});

it('logs service traffic on Caddy with the same key as the Traefik router', function (bool $exactLabels) {
    $server = caddyComposeTrafficServer($this, analyticsEnabled: true, exactLabels: $exactLabels);
    $service = caddyComposeTrafficService($this, $server);

    $labels = composeServiceLabels(serviceParser($service), 'web_app');
    $key = $service->uuid.'-'.traefikSafeServiceNameSegment('web_app');

    expect($labels->all())
        ->toContain('caddy_0.log.output=file /traffic/access.log')
        ->toContain("caddy_0.log_append=coolify_app_id {$key}");
    if (! $exactLabels) {
        expect($labels->all())->toContain("traefik.http.routers.https-0-{$key}.entryPoints=https");
    }
})->with([
    'exact Caddy labels' => true,
    'Traefik and Caddy labels' => false,
]);

it('logs legacy parser service traffic on Caddy with the same key as the Traefik router', function () {
    $server = caddyComposeTrafficServer($this, analyticsEnabled: true);
    $service = caddyComposeTrafficService($this, $server);
    $service->update(['compose_parsing_version' => '2']);

    $compose = parseDockerComposeFile($service->fresh());
    $labels = composeServiceLabels($compose, 'web_app');
    $key = $service->uuid.'-'.traefikSafeServiceNameSegment('web_app');

    expect($labels->all())
        ->toContain('caddy_0.log.output=file /traffic/access.log')
        ->toContain("caddy_0.log_append=coolify_app_id {$key}")
        ->toContain("traefik.http.routers.https-0-{$key}.entryPoints=https");
});

it('logs legacy parser compose application traffic on Caddy with the service key', function () {
    $server = caddyComposeTrafficServer($this, analyticsEnabled: true);
    $application = caddyComposeTrafficApplication($this, $server);
    $application->update(['compose_parsing_version' => '2']);

    $compose = parseDockerComposeFile($application->fresh());
    $labels = composeServiceLabels($compose, 'web.app');
    $key = $application->uuid.'-'.traefikSafeServiceNameSegment('web.app');

    expect($labels->all())
        ->toContain('caddy_0.log.output=file /traffic/access.log')
        ->toContain("caddy_0.log_append=coolify_app_id {$key}");
});

it('adds no Caddy traffic labels to compose resources when analytics is off', function () {
    $server = caddyComposeTrafficServer($this, analyticsEnabled: false);
    $application = caddyComposeTrafficApplication($this, $server);
    $service = caddyComposeTrafficService($this, $server);

    $labels = composeServiceLabels(applicationParser($application), 'web.app')
        ->merge(composeServiceLabels(serviceParser($service), 'web_app'));

    expect($labels->filter(fn (string $label) => str_contains($label, '.log')))->toBeEmpty();
});

it('keeps the application uuid as the Caddy traffic key of a normal application', function () {
    $server = caddyComposeTrafficServer($this, analyticsEnabled: true);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://normal.example.com',
    ]);

    $labels = collect(generateLabelsApplication($application->fresh()));

    expect($labels->filter(fn (string $label) => str_contains($label, 'log_append'))->values()->all())
        ->toBe(["caddy_0.log_append=coolify_app_id {$application->uuid}"]);
});
