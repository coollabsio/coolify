<?php

use App\Livewire\Project\Service\Analytics as ServiceAnalytics;
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
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class FakeServiceAnalyticsTrafficClient extends SentinelTrafficClient
{
    public array $responses = [];

    protected function raw(string $url): string
    {
        foreach ($this->responses as $needle => $response) {
            if (str_contains($url, $needle)) {
                return $response;
            }
        }

        return '{}';
    }
}

function serviceAnalyticsOverview(int $requests): string
{
    return json_encode([
        'requests' => $requests,
        'bytes_in' => 100,
        'bytes_out' => 900,
        'status' => ['s2xx' => $requests, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0],
        'latency' => ['p50' => 5.0, 'p95' => 12.0, 'p99' => 30.0],
        'unique_visitors' => 3,
    ]);
}

/**
 * @return array{server: Server, project: Project, environment: Environment, service: Service}
 */
function makeServiceAnalyticsStack(Team $team, bool $enabled, string $name): array
{
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $server->settings->is_traffic_analytics_enabled = $enabled;
    $server->settings->save();
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test-'.$team->id]);

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $service = Service::factory()->create([
        'name' => $name,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
    ]);
    foreach (['web' => 'https://app.svc.test', 'admin' => 'https://admin.svc.test'] as $serviceName => $fqdn) {
        ServiceApplication::create([
            'service_id' => $service->id,
            'name' => $serviceName,
            'image' => 'nginx:alpine',
            'fqdn' => $fqdn,
        ]);
    }

    return compact('server', 'project', 'environment', 'service');
}

function serviceAnalyticsRoute(array $stack): string
{
    return route('project.service.analytics', [
        'project_uuid' => $stack['project']->uuid,
        'environment_uuid' => $stack['environment']->uuid,
        'service_uuid' => $stack['service']->uuid,
    ]);
}

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    Cache::flush();
    Once::flush();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('renders the service analytics page with the disabled state and a navigation item', function () {
    $stack = makeServiceAnalyticsStack($this->team, false, 'Umami');

    $this->get(serviceAnalyticsRoute($stack))
        ->assertOk()
        ->assertSee('Traffic analytics is not enabled')
        ->assertSee(serviceAnalyticsRoute($stack), false)
        ->assertSee(route('server.analytics', ['server_uuid' => $stack['server']->uuid]), false)
        ->assertDontSee('__lazyLoad', escape: false);
});

it('lazy loads the service analytics page when traffic analytics is enabled', function () {
    $stack = makeServiceAnalyticsStack($this->team, true, 'Umami');

    $this->get(serviceAnalyticsRoute($stack))
        ->assertOk()
        ->assertSee('__lazyLoad', escape: false);
});

it('merges the traffic of every service application key', function () {
    $stack = makeServiceAnalyticsStack($this->team, true, 'Umami');
    $service = $stack['service'];
    $webKey = $service->uuid.'-'.traefikSafeServiceNameSegment('web');
    $adminKey = $service->uuid.'-'.traefikSafeServiceNameSegment('admin');

    app()->bind(SentinelTrafficClient::class, function ($app, $params) use ($webKey, $adminKey) {
        $client = new FakeServiceAnalyticsTrafficClient($params['server']);
        $client->responses = [
            '/traffic/apps' => json_encode([$webKey, $adminKey, 'unrelated']),
            "/app/{$webKey}/traffic/overview" => serviceAnalyticsOverview(300),
            "/app/{$adminKey}/traffic/overview" => serviceAnalyticsOverview(20),
            "/app/{$adminKey}/traffic/paths" => json_encode([['path' => '/login', 'app' => $adminKey, 'requests' => 20]]),
        ];

        return $client;
    });

    $component = loadLazy(Livewire::test(ServiceAnalytics::class, ['service' => $service]))
        ->assertOk()
        ->assertSee('320')
        ->assertSee('admin.svc.test')
        ->assertSet('latencyApproximate', true)
        ->assertDispatched('refreshChartData-service-analytics-status');

    expect($component->instance()->overview['requests'])->toBe(320)
        ->and($component->instance()->topPaths[0]['domain'])->toBe('admin.svc.test');
});

it('returns not found for another team service analytics page', function () {
    $otherTeam = Team::factory()->create();
    $other = makeServiceAnalyticsStack($otherTeam, true, 'Secret Other Service');
    $own = makeServiceAnalyticsStack($this->team, true, 'Umami');

    $this->get(serviceAnalyticsRoute($other))->assertNotFound();

    // Own project path with the other team's service uuid.
    $this->get(route('project.service.analytics', [
        'project_uuid' => $own['project']->uuid,
        'environment_uuid' => $own['environment']->uuid,
        'service_uuid' => $other['service']->uuid,
    ]))->assertNotFound()->assertDontSee('Secret Other Service');
});

it('forbids the service analytics component for another team service', function () {
    $otherTeam = Team::factory()->create();
    $other = makeServiceAnalyticsStack($otherTeam, false, 'Secret Other Service');

    Livewire::test(ServiceAnalytics::class, ['service' => $other['service'], 'lazy' => false])
        ->assertForbidden();
});
