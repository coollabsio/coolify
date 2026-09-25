<?php

use App\Actions\Proxy\GetProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
});

/**
 * The Traefik configuration that Coolify generated before the dashboard exposure fix.
 */
function legacyTraefikConfiguration(array $extraLabels = [], bool $swarm = false): string
{
    $labels = [
        'traefik.enable=true',
        'traefik.http.routers.traefik.entrypoints=http',
        'traefik.http.routers.traefik.service=api@internal',
        'traefik.http.services.traefik.loadbalancer.server.port=8080',
        'coolify.managed=true',
        'coolify.proxy=true',
        ...$extraLabels,
    ];
    $service = [
        'image' => 'traefik:v3.6',
        'ports' => ['80:80', '443:443', '8080:8080'],
        'command' => ['--api.dashboard=true', '--api.insecure=false', '--providers.docker.exposedbydefault=false'],
    ];
    if ($swarm) {
        $service['deploy'] = ['labels' => $labels];
    } else {
        $service['container_name'] = 'coolify-proxy';
        $service['labels'] = $labels;
    }

    return "# my custom comment\n".Yaml::dump(['name' => 'coolify-proxy', 'services' => ['traefik' => $service]], 10, 2);
}

function traefikServerWithConfiguration(string $configuration, bool $applied = true): Server
{
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $server->proxy->type = ProxyTypes::TRAEFIK->value;
    $server->proxy->status = 'running';
    $server->proxy->last_saved_proxy_configuration = $configuration;
    $server->proxy->last_saved_settings = md5(base64_encode($configuration));
    $server->proxy->last_applied_settings = $applied ? $server->proxy->last_saved_settings : null;
    $server->save();

    return $server->fresh();
}

test('the legacy dashboard router labels are replaced with traefik.enable=false', function (bool $swarm) {
    $configuration = legacyTraefikConfiguration(swarm: $swarm);

    $fixed = removeLegacyTraefikDashboardLabels($configuration);
    $labels = data_get(Yaml::parse($fixed), $swarm ? 'services.traefik.deploy.labels' : 'services.traefik.labels');

    expect($labels)->toBe(['traefik.enable=false', 'coolify.managed=true', 'coolify.proxy=true'])
        ->and($fixed)->toContain('# my custom comment')
        ->and($fixed)->not->toContain('api@internal')
        ->and(removeLegacyTraefikDashboardLabels($fixed))->toBe($fixed);
})->with(['standalone' => false, 'swarm' => true]);

test('quoted legacy labels are also replaced', function () {
    $configuration = str_replace(
        ['- traefik.enable=true', '- traefik.http.routers.traefik.service=api@internal'],
        ["- 'traefik.enable=true'", '- "traefik.http.routers.traefik.service=api@internal"'],
        legacyTraefikConfiguration(),
    );

    $labels = data_get(Yaml::parse(removeLegacyTraefikDashboardLabels($configuration)), 'services.traefik.labels');

    expect($labels)->toBe(['traefik.enable=false', 'coolify.managed=true', 'coolify.proxy=true']);
});

test('a dashboard router that the user changed is kept', function (array $extraLabels) {
    $configuration = legacyTraefikConfiguration($extraLabels);

    expect(removeLegacyTraefikDashboardLabels($configuration))->toBe($configuration);
})->with([
    'custom rule' => [['traefik.http.routers.traefik.rule=Host(`traefik.example.com`)']],
    'auth middleware' => [['traefik.http.routers.traefik.middlewares=auth']],
    'other router on the proxy' => [['traefik.http.routers.other.rule=Host(`other.example.com`)']],
]);

test('configurations without the legacy dashboard router are not changed', function () {
    $configuration = Yaml::dump(['services' => ['traefik' => ['labels' => ['traefik.enable=false', 'coolify.managed=true', 'coolify.proxy=true']]]], 10, 2);

    expect(removeLegacyTraefikDashboardLabels($configuration))->toBe($configuration)
        ->and(removeLegacyTraefikDashboardLabels("services:\n  traefik: [\n"))->toBe("services:\n  traefik: [\n");
});

test('loading a legacy configuration saves the fix and marks the proxy for restart', function () {
    $server = traefikServerWithConfiguration(legacyTraefikConfiguration());
    expect($server->hasPendingProxyConfiguration())->toBeFalse();

    $configuration = GetProxyConfiguration::run($server);

    $server->refresh();
    expect($configuration)->not->toContain('api@internal')
        ->and($server->proxy->last_saved_proxy_configuration)->toBe($configuration)
        ->and($server->proxy->last_saved_settings)->toBe(md5(base64_encode($configuration)))
        ->and($server->hasPendingProxyConfiguration())->toBeTrue();
});

test('the fix marks a running proxy for restart when no applied settings were recorded', function () {
    $legacy = legacyTraefikConfiguration();
    $server = traefikServerWithConfiguration($legacy, applied: false);

    GetProxyConfiguration::run($server);

    $server->refresh();
    expect($server->proxy->last_applied_settings)->toBe(md5(base64_encode($legacy)))
        ->and($server->hasPendingProxyConfiguration())->toBeTrue();
});

test('the migration fixes saved legacy configurations without connecting to servers', function () {
    $legacy = traefikServerWithConfiguration(legacyTraefikConfiguration());
    $custom = traefikServerWithConfiguration(legacyTraefikConfiguration(['traefik.http.routers.traefik.middlewares=auth']));
    $caddy = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $caddy->proxy->type = ProxyTypes::CADDY->value;
    $caddy->proxy->last_saved_proxy_configuration = 'services: {}';
    $caddy->save();

    (require database_path('migrations/2026_09_25_210000_remove_legacy_traefik_dashboard_labels.php'))->up();

    expect($legacy->fresh()->proxy->last_saved_proxy_configuration)->not->toContain('api@internal')
        ->and($legacy->fresh()->hasPendingProxyConfiguration())->toBeTrue()
        ->and($custom->fresh()->proxy->last_saved_proxy_configuration)->toContain('api@internal')
        ->and($custom->fresh()->hasPendingProxyConfiguration())->toBeFalse()
        ->and($caddy->fresh()->proxy->last_saved_proxy_configuration)->toBe('services: {}');
});

test('the pending proxy notice asks the user to restart the proxy', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $server->proxy->type = ProxyTypes::TRAEFIK->value;
    $server->proxy->status = 'running';
    $server->proxy->last_saved_settings = 'new-hash';
    $server->proxy->last_applied_settings = 'old-hash';
    $server->save();

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test('server.navbar', ['server' => $server->fresh()])
        ->assertSee('Your configuration changed, please restart the proxy.')
        ->assertDontSee('The saved proxy configuration has not been applied');
});
