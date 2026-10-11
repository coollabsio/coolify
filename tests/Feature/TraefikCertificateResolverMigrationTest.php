<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
});

function traefikResolverMigrationCompose(array $commands, string $image = 'traefik:v3.6'): string
{
    $lines = collect($commands)->map(fn (string $command) => "      - '{$command}'")->implode("\n");

    return "name: coolify-proxy\nservices:\n  traefik:\n    image: '{$image}'\n    command:\n{$lines}\n";
}

function traefikResolverMigrationServer(Team $team, array $proxy): Server
{
    $server = Server::factory()->create(['team_id' => $team->id, 'proxy' => $proxy]);
    expect($server->settings->fresh()->traefik_certificate_resolver)->toBe('letsencrypt');

    return $server;
}

function runTraefikCertificateResolverMigration(): void
{
    (require database_path('migrations/2026_10_11_075120_add_traefik_certificate_resolver_to_server_settings_table.php'))->up();
}

test('clears the resolver when the saved Traefik configuration has no letsencrypt resolver', function () {
    $compose = traefikResolverMigrationCompose(['--providers.file.directory=/traefik/dynamic/', '--entrypoints.https.address=:443'])
        ."# --certificatesresolvers.letsencrypt.acme.httpchallenge=true\n";
    $server = traefikResolverMigrationServer($this->team, ['type' => 'TRAEFIK', 'last_saved_proxy_configuration' => $compose]);

    runTraefikCertificateResolverMigration();

    expect($server->settings->fresh()->traefik_certificate_resolver)->toBeNull();
});

test('keeps letsencrypt when the resolver is active or the configuration is unknown', function (array $proxy) {
    $server = traefikResolverMigrationServer($this->team, $proxy);

    runTraefikCertificateResolverMigration();

    expect($server->settings->fresh()->traefik_certificate_resolver)->toBe('letsencrypt');
})->with([
    'active resolver' => [['type' => 'TRAEFIK', 'last_saved_proxy_configuration' => traefikResolverMigrationCompose([
        '--providers.file.directory=/traefik/dynamic/',
        '--certificatesresolvers.letsencrypt.acme.httpchallenge=true',
    ])]],
    'no saved configuration' => [['type' => 'TRAEFIK']],
    'caddy proxy' => [['type' => 'CADDY', 'last_saved_proxy_configuration' => "services:\n  caddy:\n    image: 'lucaslorentz/caddy-docker-proxy:2.8-alpine'\n"]],
    'invalid yaml' => [['type' => 'TRAEFIK', 'last_saved_proxy_configuration' => "services: [\n"]],
]);
