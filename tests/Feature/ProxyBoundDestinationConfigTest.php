<?php

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '203.0.113.10',
    ]);
    $this->server->proxy->type = ProxyTypes::TRAEFIK->value;
    $this->server->save();

    SaveProxyConfiguration::shouldRun()->andReturnUsing(fn ($server, $config) => $config);
});

it('keeps wildcard default ports when no destinations have a bind_ip', function () {
    StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'plain',
        'network' => 'coolify-plain',
    ]);

    $yaml = generateDefaultProxyConfiguration($this->server);
    $config = Yaml::parse($yaml);
    $ports = data_get($config, 'services.traefik.ports');

    expect($ports)->toContain('80:80');
    expect($ports)->toContain('443:443');
    expect($ports)->toContain('443:443/udp');
});

it('pins default 80/443 ports to the server ip when any destination has a bind_ip', function () {
    StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'bound',
        'network' => 'coolify-bound',
        'bind_ip' => '192.168.1.10',
    ]);

    $yaml = generateDefaultProxyConfiguration($this->server);
    $config = Yaml::parse($yaml);
    $ports = data_get($config, 'services.traefik.ports');

    expect($ports)->toContain('203.0.113.10:80:80');
    expect($ports)->toContain('203.0.113.10:443:443');
    expect($ports)->toContain('203.0.113.10:443:443/udp');
    expect($ports)->not->toContain('80:80');
    expect($ports)->not->toContain('443:443');
});

it('emits a per-destination port mapping and entrypoint command for each bound destination', function () {
    $bound = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'lan-dest',
        'network' => 'coolify-lan',
        'bind_ip' => '192.168.1.10',
    ]);

    $yaml = generateDefaultProxyConfiguration($this->server);
    $config = Yaml::parse($yaml);
    $ports = data_get($config, 'services.traefik.ports');
    $commands = data_get($config, 'services.traefik.command');

    $httpPort = $bound->traefikInternalHttpPort();
    $httpsPort = $bound->traefikInternalHttpsPort();
    $suffix = $bound->traefikEntrypointSuffix();

    expect($ports)->toContain("192.168.1.10:80:{$httpPort}");
    expect($ports)->toContain("192.168.1.10:443:{$httpsPort}");
    expect($commands)->toContain("--entrypoints.http-{$suffix}.address=:{$httpPort}");
    expect($commands)->toContain("--entrypoints.https-{$suffix}.address=:{$httpsPort}");
});
