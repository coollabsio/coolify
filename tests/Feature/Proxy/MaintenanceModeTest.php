<?php

use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Livewire\Project\Shared\Maintenance;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

// Servers stay in a static identity map; do not leak them into later test files.
afterEach(fn () => Server::flushIdentityMap());

beforeEach(function () {
    Server::flushIdentityMap();
    Event::fake([ProxyStatusChanged::class, ProxyStatusChangedUI::class]);
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);
    fakeProxyFileUploads();

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'user' => 'cooluser',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->server->proxy->type = ProxyTypes::TRAEFIK->value;
    $this->server->save();

    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

function maintenanceApplication(array $attributes = []): Application
{
    $test = test();

    return Application::factory()->create(array_merge([
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
        'fqdn' => 'https://app.example.com',
    ], $attributes));
}

function maintenanceService(array $domains): Service
{
    $test = test();
    $service = Service::factory()->create([
        'environment_id' => $test->environment->id,
        'server_id' => $test->server->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
    ]);
    foreach ($domains as $name => $fqdn) {
        ServiceApplication::create(['name' => $name, 'service_id' => $service->id, 'image' => 'nginx', 'fqdn' => $fqdn]);
    }

    return $service;
}

function maintenanceServer(): Server
{
    return Server::find(test()->server->id);
}

/**
 * @return array<int, string>
 */
function maintenanceScriptLines(): array
{
    $lines = [];
    Process::assertRan(function ($process) use (&$lines) {
        array_push($lines, ...explode("\n", $process->command));

        return true;
    });

    return $lines;
}

it('routes the maintenance domains through Traefik with a priority just above the resource router', function () {
    $application = maintenanceApplication(['fqdn' => 'https://app.example.com,http://app.example.com/api', 'is_maintenance_enabled' => true]);

    $config = Yaml::parse(maintenanceServer()->maintenanceTraefikConfiguration(collect([$application])));
    $routers = $config['http']['routers'];
    $rootRule = 'Host(`app.example.com`) && PathPrefix(`/`)';
    $apiRule = 'Host(`app.example.com`) && PathPrefix(`/api`)';

    expect($routers["coolify-maintenance-{$application->uuid}-0-http"])->toMatchArray([
        'entryPoints' => ['http'],
        'rule' => $rootRule,
        'service' => 'coolify-maintenance-noop',
        'middlewares' => ['coolify-maintenance-headers', "coolify-maintenance-{$application->uuid}"],
        'priority' => strlen($rootRule) + 1,
    ])
        ->and($routers["coolify-maintenance-{$application->uuid}-0-https"]['tls'])->toBe(['certResolver' => 'letsencrypt'])
        // http:// domains get no TLS router.
        ->and($routers)->not->toHaveKey("coolify-maintenance-{$application->uuid}-1-https")
        ->and($routers["coolify-maintenance-{$application->uuid}-1-http"]['priority'])->toBe(strlen($apiRule) + 1)
        // A resource router on a longer path of the same host keeps a higher priority.
        ->and(strlen($apiRule))->toBeGreaterThan($routers["coolify-maintenance-{$application->uuid}-0-http"]['priority'])
        ->and($config['http']['middlewares']["coolify-maintenance-{$application->uuid}"]['errors'])->toBe([
            'status' => ['503'],
            'service' => 'coolify-maintenance-pages',
            'query' => "/maintenance-{$application->uuid}.html",
        ])
        ->and($config['http']['middlewares']['coolify-maintenance-headers']['headers']['customResponseHeaders'])->toBe(['Retry-After' => '300'])
        ->and($config['http']['services']['coolify-maintenance-noop']['loadBalancer']['servers'])->toBe([])
        ->and($config['http']['services']['coolify-maintenance-pages']['loadBalancer']['servers'])->toBe([['url' => 'http://coolify-proxy-error-pages:80']]);
});

it('writes Caddy maintenance blocks with the same site address as the resource labels', function (bool $forceHttps, string $domain) {
    $application = maintenanceApplication(['fqdn' => $domain, 'is_maintenance_enabled' => true]);
    $application->settings->update(['is_force_https_enabled' => $forceHttps]);
    $application->refresh();
    $labelAddress = str(fqdnLabelsForCaddy('coolify', $application->uuid, collect([$domain]), $forceHttps)->first(fn ($label) => str_starts_with($label, 'caddy_0=')))->after('caddy_0=')->value();
    $matcher = "@coolify-maintenance-{$application->uuid}-0";
    $path = parse_url($domain, PHP_URL_PATH) ?: '/';

    $caddyfile = maintenanceServer()->caddyBaseCaddyfile(collect([$application]));

    expect($caddyfile)
        ->toStartWith("import /dynamic/*.caddy\n")
        ->toContain("\n\n{$labelAddress} {\n\t{$matcher} path {$path}*\n\thandle {$matcher} {")
        ->toContain("rewrite /maintenance-{$application->uuid}.html")
        ->toContain('copy_response 503');
})->with([
    'https' => [false, 'https://app.example.com'],
    'forced https' => [true, 'https://app.example.com'],
    'http with path' => [false, 'http://app.example.com/api'],
]);

it('keeps the base Caddyfile to the import line without maintenance resources', function () {
    expect(maintenanceServer()->caddyBaseCaddyfile(collect()))->toBe("import /dynamic/*.caddy\n");
});

it('skips domains that could inject proxy configuration', function () {
    $application = maintenanceApplication(['fqdn' => 'https://ok.example.com,https://bad.example.com/a b,https://bad.example.com/{x},https://evil.example.com`)', 'is_maintenance_enabled' => true]);

    expect($application->maintenanceRoutes()->pluck('host')->all())->toBe(['ok.example.com']);
});

it('collects the domains of Docker Compose applications and services', function () {
    $compose = maintenanceApplication([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_domains' => json_encode(['web' => ['domain' => 'https://web.example.com,https://www.example.com'], 'api' => ['domain' => 'https://web.example.com/api'], 'db' => ['domain' => '']]),
    ]);
    $service = maintenanceService(['ghost' => 'https://blog.example.com', 'admin' => 'https://admin.example.com/ghost']);

    expect($compose->maintenanceRoutes()->map(fn ($route) => $route['host'].$route['path'])->all())->toBe(['web.example.com/', 'www.example.com/', 'web.example.com/api'])
        ->and($service->maintenanceRoutes()->map(fn ($route) => $route['host'].$route['path'])->all())->toBe(['blog.example.com/', 'admin.example.com/ghost']);
});

it('writes the pages and the Traefik routes through sudo, and removes them when maintenance ends', function () {
    $application = maintenanceApplication(['is_maintenance_enabled' => true]);
    $service = maintenanceService(['ghost' => 'https://blog.example.com']);
    $service->forceFill(['is_maintenance_enabled' => true])->save();
    $pages = '/data/coolify/proxy/error-pages';

    maintenanceServer()->setupMaintenancePages();
    $lines = maintenanceScriptLines();

    expect($lines)->toContain("sudo find $pages -maxdepth 1 -name 'maintenance-*.html' ! -name 'maintenance-{$application->uuid}.html' ! -name 'maintenance-{$service->uuid}.html' -delete")
        ->and(collect($lines)->contains(fn ($line) => str_starts_with($line, 'sudo docker start coolify-proxy-error-pages')))->toBeTrue()
        ->and(writtenProxyFile($lines, "$pages/maintenance-{$application->uuid}.html"))->toContain('We are doing some maintenance')
        ->and(writtenProxyFile($lines, "$pages/maintenance-{$service->uuid}.html"))->toContain('We are doing some maintenance')
        ->and(writtenProxyFile($lines, '/data/coolify/proxy//dynamic/coolify-maintenance.yaml'))
        ->toContain('app.example.com')->toContain('blog.example.com');

    $application->forceFill(['is_maintenance_enabled' => false])->save();
    $service->forceFill(['is_maintenance_enabled' => false])->save();
    maintenanceServer()->setupMaintenancePages();

    expect(maintenanceScriptLines())->toContain('sudo rm -f /data/coolify/proxy//dynamic/coolify-maintenance.yaml')
        ->toContain("sudo find $pages -maxdepth 1 -name 'maintenance-*.html' -delete");
});

it('writes the maintenance blocks into the base Caddyfile on Caddy servers', function () {
    $this->server->proxy->type = ProxyTypes::CADDY->value;
    $this->server->save();
    $application = maintenanceApplication(['is_maintenance_enabled' => true]);

    maintenanceServer()->setupMaintenancePages();

    expect(writtenProxyFile(maintenanceScriptLines(), '/data/coolify/proxy/caddy/dynamic/Caddyfile'))
        ->toContain("@coolify-maintenance-{$application->uuid}-0 path /*");
});

it('keeps the page container for maintenance when a redirect URL replaces the catch-all page', function () {
    maintenanceApplication(['is_maintenance_enabled' => true]);
    $server = maintenanceServer();
    $server->proxy->redirect_url = 'https://example.com';
    $server->save();

    $server->setupProxyErrorPageContainer();

    expect(collect(maintenanceScriptLines())->contains(fn ($line) => str_contains($line, 'docker run -d --name coolify-proxy-error-pages')))->toBeTrue();
});

it('does not touch Swarm servers', function () {
    maintenanceApplication(['is_maintenance_enabled' => true]);
    $this->server->settings->update(['is_swarm_manager' => true]);

    $server = maintenanceServer();
    $server->setupMaintenancePages();

    expect($server->maintenanceResources())->toBeEmpty();
    Process::assertNothingRan();
});

function maintenanceUser(Team $team, string $role): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role]);

    return $user;
}

it('lets an admin enable maintenance and save a custom page', function () {
    $application = maintenanceApplication();
    $this->actingAs(maintenanceUser($this->team, 'admin'));
    session(['currentTeam' => $this->team]);

    Livewire::test(Maintenance::class, ['resource' => $application])
        ->set('maintenancePage', '<h1>Back soon</h1>')
        ->call('submit')
        ->assertDispatched('success')
        ->set('isMaintenanceEnabled', true)
        ->call('instantSaveMaintenance')
        ->assertDispatched('success');

    $application->refresh();
    expect($application->is_maintenance_enabled)->toBeTrue()
        ->and($application->maintenance_page)->toBe('<h1>Back soon</h1>')
        ->and(writtenProxyFile(maintenanceScriptLines(), "/data/coolify/proxy/error-pages/maintenance-{$application->uuid}.html"))->toBe('<h1>Back soon</h1>');
});

it('rejects a maintenance page larger than the limit', function () {
    $application = maintenanceApplication();
    $this->actingAs(maintenanceUser($this->team, 'owner'));
    session(['currentTeam' => $this->team]);

    Livewire::test(Maintenance::class, ['resource' => $application])
        ->set('maintenancePage', str_repeat('a', Server::PROXY_ERROR_PAGE_MAX_BYTES + 1))
        ->call('submit')
        ->assertDispatched('error');

    expect($application->fresh()->maintenance_page)->toBeNull();
});

it('does not let a member or another team change maintenance mode', function (string $who) {
    $service = maintenanceService(['ghost' => 'https://blog.example.com']);
    $team = $who === 'member' ? $this->team : Team::factory()->create();
    $this->actingAs(maintenanceUser($team, $who === 'member' ? 'member' : 'owner'));
    session(['currentTeam' => $team]);

    Livewire::test(Maintenance::class, ['resource' => $service])
        ->set('isMaintenanceEnabled', true)
        ->call('instantSaveMaintenance')
        ->assertDispatched('error')
        ->set('maintenancePage', '<h1>Hacked</h1>')
        ->call('submit')
        ->assertDispatched('error');

    $service->refresh();
    expect($service->is_maintenance_enabled)->toBeFalse()
        ->and($service->maintenance_page)->toBeNull();
    Process::assertNothingRan();
})->with(['member', 'other team']);
