<?php

use App\Enums\LoadBalancerStrategy;
use App\Enums\ProxyTypes;
use App\Livewire\Project\Application\Scaling;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    useTraefikForLoadBalancer('3.6.2');
    $destination = $this->server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->application = Application::factory()->create([
        'environment_id' => Environment::factory()->create(['project_id' => $project->id])->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerfile',
        'ports_exposes' => '3000',
        'fqdn' => 'https://app.example.com',
        'replicas' => 3,
    ])->fresh();
});

function useTraefikForLoadBalancer(?string $version): void
{
    test()->server->forceFill([
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'detected_traefik_version' => $version,
    ])->save();
}

function loadBalancerLabels(array $attributes = []): array
{
    $application = test()->application;
    $application->forceFill($attributes)->save();

    return collect(generateLabelsApplication($application->fresh()))
        ->filter(fn (string $label): bool => str_contains($label, '.loadbalancer.') && ! str_contains($label, 'server.port')
            || str_contains($label, '_reverse_proxy.lb_policy'))
        ->values()
        ->all();
}

test('the Traefik labels add the sticky session cookie to the strategy, secure on the HTTPS service', function () {
    $uuid = $this->application->uuid;

    expect(loadBalancerLabels([
        'load_balancer_strategy' => LoadBalancerStrategy::LeastConnections,
        'is_sticky_session_enabled' => true,
        'sticky_session_cookie_name' => 'app_replica',
        'sticky_session_max_age' => 86400,
    ]))->toEqualCanonicalizing([
        "traefik.http.services.https-0-{$uuid}.loadbalancer.strategy=p2c",
        "traefik.http.services.https-0-{$uuid}.loadbalancer.sticky.cookie=true",
        "traefik.http.services.https-0-{$uuid}.loadbalancer.sticky.cookie.name=app_replica",
        "traefik.http.services.https-0-{$uuid}.loadbalancer.sticky.cookie.httponly=true",
        "traefik.http.services.https-0-{$uuid}.loadbalancer.sticky.cookie.maxage=86400",
        "traefik.http.services.https-0-{$uuid}.loadbalancer.sticky.cookie.secure=true",
        "traefik.http.services.http-0-{$uuid}.loadbalancer.strategy=p2c",
        "traefik.http.services.http-0-{$uuid}.loadbalancer.sticky.cookie=true",
        "traefik.http.services.http-0-{$uuid}.loadbalancer.sticky.cookie.name=app_replica",
        "traefik.http.services.http-0-{$uuid}.loadbalancer.sticky.cookie.httponly=true",
        "traefik.http.services.http-0-{$uuid}.loadbalancer.sticky.cookie.maxage=86400",
    ]);
});

test('Client IP sets no sticky session cookie', function () {
    $uuid = $this->application->uuid;

    expect(loadBalancerLabels(['load_balancer_strategy' => LoadBalancerStrategy::ClientIp, 'is_sticky_session_enabled' => true]))->toEqualCanonicalizing([
        "traefik.http.services.https-0-{$uuid}.loadbalancer.strategy=hrw",
        "traefik.http.services.http-0-{$uuid}.loadbalancer.strategy=hrw",
    ]);
});

test('a Traefik version without the strategy falls back to round robin', function () {
    useTraefikForLoadBalancer('v3.4.5');
    $this->application->forceFill(['load_balancer_strategy' => LoadBalancerStrategy::LeastTime])->save();

    expect(loadBalancerLabels())->toBe([])
        ->and($this->application->fresh()->loadBalancer()->fallbackNotice())
        ->toBe('Balancing the replicas with round robin instead of Fastest response. Needs Traefik 3.6 or newer. This server runs Traefik 3.4.5.');
});

test('the Caddy labels use the cookie policy with the strategy as fallback for a sticky session', function () {
    $this->server->forceFill(['proxy' => [
        'type' => ProxyTypes::CADDY->value,
        'status' => 'running',
        'last_saved_settings' => 'applied',
        'last_applied_settings' => 'applied',
        'last_saved_proxy_configuration' => "services:\n  caddy:\n    image: 'lucaslorentz/caddy-docker-proxy:2.13-alpine'\n",
    ]])->save();

    expect(loadBalancerLabels([
        'load_balancer_strategy' => LoadBalancerStrategy::LeastConnections,
        'is_sticky_session_enabled' => true,
        'sticky_session_max_age' => 3600,
    ]))->toEqualCanonicalizing([
        'caddy_0.handle_path.0_reverse_proxy.lb_policy=cookie lb',
        'caddy_0.handle_path.0_reverse_proxy.lb_policy.fallback=least_conn',
        'caddy_0.handle_path.0_reverse_proxy.lb_policy.max_age=3600s',
    ]);
});

test('the Scaling page saves the load balancer', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(Scaling::class, ['application' => $this->application])
        ->set('loadBalancerStrategy', LoadBalancerStrategy::LeastConnections)
        ->set('isStickySessionEnabled', true)
        ->set('stickySessionCookieName', 'app_replica')
        ->set('stickySessionMaxAge', '86400')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $application = $this->application->fresh();
    expect($application->load_balancer_strategy)->toBe(LoadBalancerStrategy::LeastConnections)
        ->and($application->is_sticky_session_enabled)->toBeTrue()
        ->and($application->sticky_session_cookie_name)->toBe('app_replica')
        ->and($application->sticky_session_max_age)->toBe(86400)
        ->and(base64_decode($application->custom_labels))->toContain("traefik.http.services.https-0-{$application->uuid}.loadbalancer.strategy=p2c");
});

test('a member cannot change the load balancer', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Scaling::class, ['application' => $this->application])
        ->set('loadBalancerStrategy', LoadBalancerStrategy::LeastConnections)
        ->call('submit')
        ->assertForbidden();

    expect($this->application->fresh()->load_balancer_strategy)->toBe(LoadBalancerStrategy::RoundRobin);
});
