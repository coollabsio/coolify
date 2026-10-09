<?php

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\StartProxy;
use App\Actions\Server\StartSentinel;
use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Events\SentinelRestarted;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\RestartProxyJob;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

const DEV_PATH_DATA = '/var/lib/docker/volumes/coolify-dev-feature_coolify_data/_data';
const DEV_PATH_BACKUPS = '/var/lib/docker/volumes/coolify-dev-feature_backups_data/_data';

const DEV_PATH_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - ./data:/app/data
      - type: bind
        source: ./config/app.conf
        target: /etc/app.conf
        content: |
          listen 8080;
YAML;

beforeEach(function () {
    // StandaloneDocker::server uses a static identity map that other tests can fill with the same server id.
    Server::flushIdentityMap();
    Bus::fake();
    Notification::fake();
    Event::fake([SentinelRestarted::class, ProxyStatusChanged::class, ProxyStatusChangedUI::class]);
    InstanceSettings::forceCreate(['id' => 0]);
    config([
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
        'constants.coolify.dev_data_volume' => 'coolify-dev-feature_coolify_data',
        'constants.coolify.dev_backups_volume' => 'coolify-dev-feature_backups_data',
    ]);

    $this->commands = [];
    Process::fake(function ($process) {
        $this->commands[] = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return Process::result(output: '');
    });

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

/**
 * Environment and server kinds:
 * - kvm: development, a dev KVM VM with its own Docker daemon and its own /data/coolify.
 * - testing-host: development, the testing-host container that uses the host Docker daemon.
 * - production: production, with the testing-host address (the address alone must not change paths).
 *
 * @return array<string, array{0: string, 1: string}>
 */
function devPathKinds(): array
{
    return [
        'dev KVM server' => ['kvm', '/data/coolify'],
        'dev testing-host server' => ['testing-host', DEV_PATH_DATA],
        'production' => ['production', '/data/coolify'],
    ];
}

function devPathServer(string $kind, ?string $proxyType = null, bool $analytics = false): Server
{
    config()->set('app.env', $kind === 'production' ? 'production' : 'local');

    $server = Server::factory()->create([
        'team_id' => test()->team->id,
        'private_key_id' => test()->privateKey->id,
        'ip' => $kind === 'kvm' ? '10.221.1.10' : 'coolify-testing-host',
    ]);
    if ($proxyType) {
        $server->proxy->set('type', $proxyType);
        $server->save();
    }
    $server->settings->is_traffic_analytics_enabled = $analytics;
    $server->settings->sentinel_custom_url = 'https://coolify.example.com';
    $server->settings->save();

    return $server->fresh();
}

function devPathDestination(Server $server): StandaloneDocker
{
    return StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
}

function devPathAllCommands(): string
{
    return implode("\n", test()->commands);
}

it('detects only the development testing-host server as a host Docker server', function (string $kind, string $base) {
    $server = devPathServer($kind);

    expect($server->sharesDevHostDocker())->toBe($kind === 'testing-host')
        ->and(devHostDockerPath($server, '/data/coolify'))->toBe($base);
})->with(devPathKinds());

it('maps Coolify data and backup paths to the dev volumes on the testing-host server', function () {
    $server = devPathServer('testing-host');

    expect(devHostDockerPath($server, '/data/coolify/applications/abc/data'))->toBe(DEV_PATH_DATA.'/applications/abc/data')
        ->and(devHostDockerPath($server, '/data/coolify/proxy/'))->toBe(DEV_PATH_DATA.'/proxy/')
        ->and(devHostDockerPath($server, '/data/coolify/backups/databases/x/file.dmp'))->toBe(DEV_PATH_BACKUPS.'/databases/x/file.dmp')
        ->and(devHostDockerPath($server, '/data/coolify-other/x'))->toBe('/data/coolify-other/x')
        ->and(devHostDockerPath($server, '/etc/localtime'))->toBe('/etc/localtime')
        ->and(devHostDockerPath(null, '/data/coolify/proxy'))->toBe('/data/coolify/proxy');
});

it('mounts application compose bind and content volumes from the server data path', function (string $kind, string $base) {
    $server = devPathServer($kind);
    $destination = devPathDestination($server);
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => DEV_PATH_COMPOSE,
    ]);

    $volumes = applicationParser($application)->get('services')->get('app')['volumes'];
    $directory = "{$base}/applications/{$application->uuid}";

    expect(collect($volumes)->all())->toContain("{$directory}/data:/app/data", [
        'type' => 'bind',
        'source' => "{$directory}/config/app.conf",
        'target' => '/etc/app.conf',
        'bind' => ['create_host_path' => true],
    ])
        // Coolify writes the files through SSH to the normal path.
        ->and($application->fileStorages()->pluck('fs_path')->all())
        ->toContain("/data/coolify/applications/{$application->uuid}/config/app.conf");
})->with(devPathKinds());

it('mounts service compose bind and content volumes from the server data path', function (string $kind, string $base) {
    $server = devPathServer($kind);
    $destination = devPathDestination($server);
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => DEV_PATH_COMPOSE,
    ]);
    ServiceApplication::create(['name' => 'app', 'service_id' => $service->id]);

    $volumes = serviceParser($service->fresh())->get('services')->get('app')['volumes'];
    $directory = "{$base}/services/{$service->uuid}";

    expect(collect($volumes)->all())->toContain("{$directory}/data:/app/data", [
        'type' => 'bind',
        'source' => "{$directory}/config/app.conf",
        'target' => '/etc/app.conf',
        'bind' => ['create_host_path' => true],
    ]);
})->with(devPathKinds());

it('mounts the Traefik proxy and log rotation directory from the server data path', function (string $kind, string $base) {
    $server = devPathServer($kind, 'TRAEFIK', analytics: true);

    $config = Yaml::parse(generateDefaultProxyConfiguration($server));

    expect($config['services']['traefik']['volumes'])->toContain("{$base}/proxy/:/traefik")
        ->and($config['services']['traefik-logrotate']['volumes'])->toBe(["{$base}/proxy/:/traefik"]);
})->with(devPathKinds());

it('mounts the Caddy proxy and traffic directories from the server data path', function (string $kind, string $base) {
    $server = devPathServer($kind, 'CADDY', analytics: true);

    $volumes = Yaml::parse(generateDefaultProxyConfiguration($server))['services']['caddy']['volumes'];

    expect($volumes)->toContain(
        "{$base}/proxy/caddy/dynamic:/dynamic",
        "{$base}/proxy/caddy/config:/config",
        "{$base}/proxy/caddy/data:/data",
        "{$base}/proxy/caddy:/traffic",
    )->and(StartSentinel::trafficLogDirectory($server))->toBe("{$base}/proxy/caddy");
})->with(devPathKinds());

it('mounts the Sentinel database and traffic log directory from the server data path', function (string $kind, string $base) {
    $server = devPathServer($kind, 'TRAEFIK', analytics: true);

    StartSentinel::run($server, latestVersion: '1.0.1');

    $directory = "{$base}/proxy";
    $script = collect($this->commands)->first(fn (string $command): bool => str_contains($command, 'docker run -d'));

    expect(StartSentinel::trafficLogDirectory($server))->toBe($directory)
        ->and($script)->toContain("-v {$base}/sentinel:/app/db")
        ->toContain(escapeshellarg("{$directory}:{$directory}:ro"))
        ->toContain(escapeshellarg("TRAFFIC_ACCESS_LOG_PATH={$directory}/access.log"));
    if ($kind !== 'testing-host') {
        expect($script)->not->toContain('/var/lib/docker/volumes');
    }
})->with(devPathKinds());

it('mounts the database proxy configuration from the server data path', function (string $kind, string $base) {
    $server = devPathServer($kind);
    $database = create_standalone_postgresql($this->environment->id, devPathDestination($server));
    $database->update(['is_public' => true, 'public_port' => 15432]);

    StartDatabaseProxy::run($database->fresh());

    $script = devPathAllCommands();
    preg_match("/echo '([A-Za-z0-9+\/=]+)' \| base64 -d \| tee [^ ]+docker-compose\.yaml/", $script, $matches);
    $compose = Yaml::parse(base64_decode($matches[1]));
    $volume = $compose['services']["{$database->uuid}-proxy"]['volumes'][0];

    expect($volume['source'])->toBe("{$base}/databases/{$database->uuid}/proxy/nginx.conf")
        // Coolify writes the file through SSH to the normal path.
        ->and($script)->toContain("mkdir -p /data/coolify/databases/{$database->uuid}/proxy");
})->with(devPathKinds());

it('mounts the database backup file from the server backups path', function (string $kind, string $base) {
    $server = devPathServer($kind);
    $backupsBase = $kind === 'testing-host' ? DEV_PATH_BACKUPS : '/data/coolify/backups';
    $job = (new ReflectionClass(DatabaseBackupJob::class))->newInstanceWithoutConstructor();
    $job->server = $server;
    $job->backup_location = '/data/coolify/backups/databases/team-1/postgres-abc/pg-dump-1.dmp';

    $source = (new ReflectionMethod($job, 'backupMountSource'))->invoke($job);

    expect($source)->toBe("{$backupsBase}/databases/team-1/postgres-abc/pg-dump-1.dmp");
})->with(devPathKinds());

it('uses the normal Caddy proxy path on start and restart in development', function () {
    config()->set('constants.coolify.base_config_path', '/srv/coolify');
    $server = devPathServer('kvm', 'CADDY');

    StartProxy::run($server, async: false, force: true);
    $restartCommands = (new ReflectionMethod(RestartProxyJob::class, 'buildRestartCommands'))
        ->invoke(new RestartProxyJob($server), 'services: {}');

    expect(devPathAllCommands())->toContain('mkdir -p /srv/coolify/proxy/caddy/dynamic')
        ->not->toContain('mkdir -p /data/coolify/proxy/caddy/dynamic')
        ->and(implode("\n", $restartCommands))->toContain('mkdir -p /srv/coolify/proxy/caddy/dynamic')
        ->not->toContain('/data/coolify/proxy/caddy');
});

it('replaces an old dev volume path in a saved proxy configuration in development', function (string $kind, string $expected) {
    $server = devPathServer($kind, 'TRAEFIK');
    $saved = "services:\n  traefik:\n    image: 'traefik:v3.6'\n    volumes:\n      - '/var/run/docker.sock:/var/run/docker.sock:ro'\n      - '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/proxy/:/traefik'\n";
    $server->proxy->last_saved_proxy_configuration = $saved;
    $server->save();

    $configuration = GetProxyConfiguration::run($server->fresh());

    expect(Yaml::parse($configuration)['services']['traefik']['volumes'])->toContain("{$expected}:/traefik");
})->with([
    'dev KVM server' => ['kvm', '/data/coolify/proxy/'],
    'dev testing-host server' => ['testing-host', DEV_PATH_DATA.'/proxy/'],
    'production keeps the saved configuration' => ['production', '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/proxy/'],
]);
