<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Server::flushIdentityMap());

afterEach(fn () => Server::flushIdentityMap());

function caddyProxy(?string $image, array $overrides = []): array
{
    return array_merge([
        'type' => ProxyTypes::CADDY->value,
        'status' => 'running',
        'last_saved_settings' => 'applied',
        'last_applied_settings' => 'applied',
        'last_saved_proxy_configuration' => $image === null ? null : "services:\n  caddy:\n    image: '{$image}'\n",
    ], $overrides);
}

it('uses log_append only when the saved Caddy image supports it', function (?string $image, bool $expected) {
    $server = Server::factory()->make(['proxy' => caddyProxy($image)]);

    expect($server->caddySupportsLogAppend())->toBe($expected);
})->with([
    'default caddy-docker-proxy 2.8 (Caddy 2.7.6)' => ['lucaslorentz/caddy-docker-proxy:2.8-alpine', false],
    'caddy-docker-proxy 2.9' => ['lucaslorentz/caddy-docker-proxy:2.9-alpine', true],
    'caddy-docker-proxy 2.10' => ['lucaslorentz/caddy-docker-proxy:2.10', true],
    'caddy-docker-proxy with a patch version' => ['docker.io/lucaslorentz/caddy-docker-proxy:2.11.4-alpine', true],
    'caddy-docker-proxy 3.0' => ['lucaslorentz/caddy-docker-proxy:3.0', true],
    'unknown version tag' => ['lucaslorentz/caddy-docker-proxy:latest', false],
    'other image' => ['caddy:2.11', false],
    'no saved configuration' => [null, false],
]);

it('does not use log_append while a saved proxy change is not applied yet', function () {
    $server = Server::factory()->make(['proxy' => caddyProxy('lucaslorentz/caddy-docker-proxy:2.11-alpine', ['last_saved_settings' => 'new'])]);

    expect($server->caddySupportsLogAppend())->toBeFalse();
});

it('does not use log_append for invalid YAML or other proxies', function () {
    $invalid = Server::factory()->make(['proxy' => caddyProxy(null, ['last_saved_proxy_configuration' => "services: [\n"])]);
    $traefik = Server::factory()->make(['proxy' => caddyProxy('lucaslorentz/caddy-docker-proxy:2.11-alpine', ['type' => ProxyTypes::TRAEFIK->value])]);

    expect($invalid->caddySupportsLogAppend())->toBeFalse()
        ->and($traefik->caddySupportsLogAppend())->toBeFalse();
});

it('adds log_append to application labels only when the server Caddy supports it', function (string $image, bool $expected) {
    $team = Team::factory()->create();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'proxy' => caddyProxy($image)]);
    $server->settings->update(['is_traffic_analytics_enabled' => true]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $application = Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://example.com',
    ]);

    $labels = collect(generateLabelsApplication($application->fresh()));

    expect($labels->contains(fn (string $label) => str_contains($label, 'log_append=coolify_app_id')))->toBe($expected)
        ->and($labels->contains(fn (string $label) => str_ends_with($label, 'log.output=file /traffic/access.log')))->toBeTrue();
})->with([
    'caddy-docker-proxy 2.8' => ['lucaslorentz/caddy-docker-proxy:2.8-alpine', false],
    'caddy-docker-proxy 2.11' => ['lucaslorentz/caddy-docker-proxy:2.11-alpine', true],
]);
