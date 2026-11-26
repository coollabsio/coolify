<?php

use App\Enums\ProxyTypes;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Process::fake();
});

function proxyComposeMergeTagsServer(ProxyTypes $proxyType, bool $trafficAnalytics): Server
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->proxy->set('type', $proxyType->value);
    $server->save();
    $server->settings->update(['is_traffic_analytics_enabled' => $trafficAnalytics]);

    return $server->fresh();
}

it('enables traffic analytics on a Traefik configuration with Compose merge tags and keeps the tags', function () {
    $server = proxyComposeMergeTagsServer(ProxyTypes::TRAEFIK, trafficAnalytics: true);
    $configuration = <<<'YAML'
services:
  traefik:
    image: 'traefik:v3.6'
    ports: !override
      - '8080:80'
      - '8443:443'
    expose: !reset []
    environment: !reset {}
    dns: !reset null
    healthcheck: {}
    command: !override
      - '--ping=true'
      - '--providers.docker=true'
YAML;

    $result = applyTrafficAnalyticsToProxyConfiguration($server, $configuration);
    $traefik = Yaml::parse($result, Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_OBJECT_FOR_MAP)->services->traefik;

    expect($traefik->ports)->toBeInstanceOf(TaggedValue::class)
        ->and($traefik->ports->getTag())->toBe('override')
        ->and($traefik->ports->getValue())->toBe(['8080:80', '8443:443'])
        ->and($traefik->expose->getTag())->toBe('reset')
        ->and($traefik->expose->getValue())->toBe([])
        ->and($traefik->environment->getTag())->toBe('reset')
        ->and($traefik->environment->getValue())->toEqual(new stdClass)
        ->and($traefik->dns->getTag())->toBe('reset')
        ->and($traefik->dns->getValue())->toBeNull()
        ->and($traefik->healthcheck)->toEqual(new stdClass)
        ->and($traefik->command->getTag())->toBe('override')
        ->and($traefik->command->getValue())->toBe(['--ping=true', '--providers.docker=true', ...traefikAccessLogCommands(true)]);
});

it('keeps a Traefik command list with a reset tag when traffic analytics does not change it', function () {
    $server = proxyComposeMergeTagsServer(ProxyTypes::TRAEFIK, trafficAnalytics: false);
    $configuration = "services:\n  traefik:\n    image: 'traefik:v3.6'\n    command: !reset []\n";

    $traefik = Yaml::parse(applyTrafficAnalyticsToProxyConfiguration($server, $configuration), Yaml::PARSE_CUSTOM_TAGS)['services']['traefik'];

    expect($traefik['command'])->toEqual(new TaggedValue('reset', []));
});

it('adds the access log flags without the reset tag, which would make Compose drop them', function () {
    $server = proxyComposeMergeTagsServer(ProxyTypes::TRAEFIK, trafficAnalytics: true);
    $configuration = "services:\n  traefik:\n    image: 'traefik:v3.6'\n    command: !reset []\n";

    $traefik = Yaml::parse(applyTrafficAnalyticsToProxyConfiguration($server, $configuration), Yaml::PARSE_CUSTOM_TAGS)['services']['traefik'];

    expect($traefik['command'])->toBe(traefikAccessLogCommands(true));
});

it('adds the traffic volume to a Caddy volume list with an override tag', function () {
    $server = proxyComposeMergeTagsServer(ProxyTypes::CADDY, trafficAnalytics: true);
    $configuration = "services:\n  caddy:\n    image: 'lucaslorentz/caddy-docker-proxy:2.8-alpine'\n    ports: !override\n      - '80:80'\n    volumes: !override\n      - '/var/run/docker.sock:/var/run/docker.sock:ro'\n";

    $caddy = Yaml::parse(applyTrafficAnalyticsToProxyConfiguration($server, $configuration), Yaml::PARSE_CUSTOM_TAGS)['services']['caddy'];

    expect($caddy['ports'])->toEqual(new TaggedValue('override', ['80:80']))
        ->and($caddy['volumes']->getTag())->toBe('override')
        ->and($caddy['volumes']->getValue())->toContain('/var/run/docker.sock:/var/run/docker.sock:ro')
        ->toHaveCount(2);
});

it('removes the legacy dashboard labels from a configuration with Compose merge tags', function () {
    $configuration = <<<'YAML'
# my custom comment
services:
  traefik:
    container_name: coolify-proxy
    image: 'traefik:v3.6'
    ports: !override
      - '80:80'
      - '443:443'
    expose: !reset []
    labels:
      - traefik.enable=true
      - traefik.http.routers.traefik.entrypoints=http
      - traefik.http.routers.traefik.service=api@internal
      - traefik.http.services.traefik.loadbalancer.server.port=8080
      - coolify.managed=true
YAML;

    $fixed = removeLegacyTraefikDashboardLabels($configuration);
    $traefik = Yaml::parse($fixed, Yaml::PARSE_CUSTOM_TAGS)['services']['traefik'];

    expect($traefik['labels'])->toBe(['traefik.enable=false', 'coolify.managed=true'])
        ->and($traefik['ports'])->toEqual(new TaggedValue('override', ['80:80', '443:443']))
        ->and($fixed)->toContain('# my custom comment')
        ->toContain('expose: !reset []');
});

it('keeps custom Traefik commands of a configuration with Compose merge tags', function () {
    $server = proxyComposeMergeTagsServer(ProxyTypes::TRAEFIK, trafficAnalytics: false);
    $configuration = "services:\n  traefik:\n    ports: !override\n      - '80:80'\n    command: !override\n      - '--ping=true'\n      - '--entrypoints.http.forwardedHeaders.trustedIPs=10.0.0.0/8'\n";

    expect(extractCustomProxyCommands($server, $configuration))->toBe(['--entrypoints.http.forwardedHeaders.trustedIPs=10.0.0.0/8']);
});
