<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Server::flushIdentityMap());

afterEach(fn () => Server::flushIdentityMap());

function basicAuthCaddyProxy(string $image, array $overrides = []): array
{
    return array_merge([
        'type' => ProxyTypes::CADDY->value,
        'status' => 'running',
        'last_saved_settings' => 'applied',
        'last_applied_settings' => 'applied',
        'last_saved_proxy_configuration' => "services:\n  caddy:\n    image: '{$image}'\n",
    ], $overrides);
}

function caddyBasicAuthLabel(iterable $labels): ?string
{
    return collect($labels)->first(fn (string $label) => preg_match('/^caddy_\d+\.basic_?auth\./', $label) === 1);
}

it('uses the directive name that the Caddy version knows', function (bool $supportsBasicAuthDirective, string $directive) {
    $labels = fqdnLabelsForCaddy('coolify', 'app-uuid', collect(['https://example.com']),
        is_http_basic_auth_enabled: true,
        http_basic_auth_username: 'admin',
        http_basic_auth_password: 'secret',
        supports_basic_auth_directive: $supportsBasicAuthDirective,
    );

    $label = caddyBasicAuthLabel($labels);

    expect($label)->toStartWith("caddy_0.{$directive}.admin=\"")
        ->and(password_verify('secret', trim(str($label)->after('=')->value(), '"')))->toBeTrue();
})->with([
    'Caddy 2.8+' => [true, 'basic_auth'],
    'Caddy 2.7' => [false, 'basicauth'],
]);

it('keeps the deprecated directive by default', function () {
    $labels = fqdnLabelsForCaddy('coolify', 'app-uuid', collect(['https://example.com']),
        is_http_basic_auth_enabled: true,
        http_basic_auth_username: 'admin',
        http_basic_auth_password: 'secret',
    );

    expect(caddyBasicAuthLabel($labels))->toStartWith('caddy_0.basicauth.admin=');
});

it('uses basic_auth only when the saved Caddy image runs Caddy 2.8 or newer', function (array $proxy, bool $expected) {
    $server = Server::factory()->make(['proxy' => $proxy]);

    expect($server->caddySupportsBasicAuthDirective())->toBe($expected);
})->with([
    'caddy-docker-proxy 2.8 (Caddy 2.7.6)' => [basicAuthCaddyProxy('lucaslorentz/caddy-docker-proxy:2.8-alpine'), false],
    'caddy-docker-proxy 2.9' => [basicAuthCaddyProxy('lucaslorentz/caddy-docker-proxy:2.9'), true],
    'caddy-docker-proxy 2.13' => [basicAuthCaddyProxy('lucaslorentz/caddy-docker-proxy:2.13-alpine'), true],
    'latest tag' => [basicAuthCaddyProxy('lucaslorentz/caddy-docker-proxy:latest'), false],
    'custom image' => [basicAuthCaddyProxy('caddy:2.11'), false],
    'new image saved but not applied' => [basicAuthCaddyProxy('lucaslorentz/caddy-docker-proxy:2.13-alpine', ['last_saved_settings' => 'new']), false],
]);

it('emits the matching basic auth directive in application labels', function (string $image, bool $exactLabels, bool $preview, string $directive) {
    $team = Team::factory()->create();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'proxy' => basicAuthCaddyProxy($image)]);
    $server->settings->update(['generate_exact_labels' => $exactLabels]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $application = Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://example.com',
        'is_http_basic_auth_enabled' => true,
        'http_basic_auth_username' => 'admin',
        'http_basic_auth_password' => 'secret',
    ]);
    Server::flushIdentityMap();

    $applicationPreview = $preview
        ? (new ApplicationPreview)->forceFill(['pull_request_id' => 7, 'fqdn' => 'https://pr-7.example.com'])
        : null;

    $label = caddyBasicAuthLabel(generateLabelsApplication($application->fresh(), $applicationPreview));

    expect($label)->toStartWith("caddy_0.{$directive}.admin=\"");
})->with([
    'caddy-docker-proxy 2.8, all proxies' => ['lucaslorentz/caddy-docker-proxy:2.8-alpine', false, false, 'basicauth'],
    'caddy-docker-proxy 2.8, exact labels' => ['lucaslorentz/caddy-docker-proxy:2.8-alpine', true, false, 'basicauth'],
    'caddy-docker-proxy 2.8, preview, all proxies' => ['lucaslorentz/caddy-docker-proxy:2.8-alpine', false, true, 'basicauth'],
    'caddy-docker-proxy 2.8, preview, exact labels' => ['lucaslorentz/caddy-docker-proxy:2.8-alpine', true, true, 'basicauth'],
    'caddy-docker-proxy 2.13, all proxies' => ['lucaslorentz/caddy-docker-proxy:2.13-alpine', false, false, 'basic_auth'],
    'caddy-docker-proxy 2.13, exact labels' => ['lucaslorentz/caddy-docker-proxy:2.13-alpine', true, false, 'basic_auth'],
    'caddy-docker-proxy 2.13, preview, all proxies' => ['lucaslorentz/caddy-docker-proxy:2.13-alpine', false, true, 'basic_auth'],
    'caddy-docker-proxy 2.13, preview, exact labels' => ['lucaslorentz/caddy-docker-proxy:2.13-alpine', true, true, 'basic_auth'],
]);

it('hashes the password in application labels with the selected settings', function (array $proxy, bool $exactLabels, bool $preview, array $hashSettings, array $expectedLabels) {
    $team = Team::factory()->create();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'proxy' => $proxy]);
    $server->settings->update(['generate_exact_labels' => $exactLabels]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $application = Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://example.com',
        'is_http_basic_auth_enabled' => true,
        'http_basic_auth_username' => 'admin',
        'http_basic_auth_password' => 'secret',
        ...$hashSettings,
    ]);
    Server::flushIdentityMap();

    $applicationPreview = $preview
        ? (new ApplicationPreview)->forceFill(['pull_request_id' => 7, 'fqdn' => 'https://pr-7.example.com'])
        : null;

    $labels = collect(generateLabelsApplication($application->fresh(), $applicationPreview))->implode("\n");

    expect($labels)->toContain(...$expectedLabels);
})->with(function () {
    $caddy = basicAuthCaddyProxy('lucaslorentz/caddy-docker-proxy:2.13-alpine');
    $traefik = basicAuthCaddyProxy('traefik:v3.7', ['type' => ProxyTypes::TRAEFIK->value]);
    $bcrypt = ['http_basic_auth_bcrypt_cost' => 4];
    $argon2id = ['http_basic_auth_hash_algorithm' => 'argon2id', 'http_basic_auth_argon2id_memory_cost' => 8192, 'http_basic_auth_argon2id_time_cost' => 2];
    $caddyBcrypt = 'caddy_0.basic_auth.admin="$2y$04$';
    $traefikBcrypt = '.basicauth.users=admin:$2y$04$';

    return [
        'bcrypt, all proxies' => [$caddy, false, false, $bcrypt, [$caddyBcrypt, $traefikBcrypt]],
        'bcrypt, all proxies, preview' => [$caddy, false, true, $bcrypt, [$caddyBcrypt, $traefikBcrypt]],
        'bcrypt, exact Caddy labels' => [$caddy, true, false, $bcrypt, [$caddyBcrypt]],
        'bcrypt, exact Caddy labels, preview' => [$caddy, true, true, $bcrypt, [$caddyBcrypt]],
        'bcrypt, exact Traefik labels' => [$traefik, true, false, $bcrypt, [$traefikBcrypt]],
        'bcrypt, exact Traefik labels, preview' => [$traefik, true, true, $bcrypt, [$traefikBcrypt]],
        'Argon2id for Caddy, bcrypt for Traefik' => [$caddy, false, false, $argon2id, [
            'caddy_0.basic_auth=argon2id',
            'caddy_0.basic_auth.admin="$argon2id$v=19$m=8192,t=2,p=1$',
            '.basicauth.users=admin:$2y$10$',
        ]],
        'bcrypt when Caddy is too old for the stored Argon2id' => [basicAuthCaddyProxy('lucaslorentz/caddy-docker-proxy:2.10-alpine'), false, false, $argon2id, [
            'caddy_0.basic_auth.admin="$2y$10$',
            '.basicauth.users=admin:$2y$10$',
        ]],
    ];
});
