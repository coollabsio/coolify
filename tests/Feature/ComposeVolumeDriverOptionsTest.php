<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const DRIVER_OPTIONS_SHORT_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - 'opts:/data'
volumes:
  opts:
    driver: local
    driver_opts:
      type: none
      o: bind
      device: ${OPTS_DEVICE}
    labels:
      com.example.purpose: host-folder
YAML;

const DRIVER_OPTIONS_LONG_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - type: volume
        source: opts
        target: /data
volumes:
  opts:
    driver: local
    driver_opts:
      type: none
      o: bind
      device: ${OPTS_DEVICE}
    labels:
      com.example.purpose: host-folder
YAML;

const DRIVER_OPTIONS_NAMED_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - 'opts:/data'
volumes:
  opts:
    name: custom-name
    driver: local
    driver_opts:
      type: none
      o: bind
      device: /srv/data
YAML;

const DRIVER_OPTIONS_NFS_MIXED_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - 'nfs-data:/nfs'
      - 'app-data:/app'
volumes:
  nfs-data:
    driver: local
    driver_opts:
      type: nfs
      o: addr=10.0.0.1,rw
      device: ':/exports/data'
  app-data: {}
YAML;

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
    Bus::fake();
    Queue::fake();
    Process::fake(['*' => Process::result(output: '')]);

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
});

function driverOptionsApplication(string $compose, ?string $parsingVersion = null): Application
{
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
    if ($parsingVersion !== null) {
        $application->update(['compose_parsing_version' => $parsingVersion]);
    }

    return $application->fresh();
}

function driverOptionsService(string $compose, ?string $parsingVersion = null): Service
{
    $service = Service::factory()->create([
        'docker_compose_raw' => $compose,
        'environment_id' => test()->environment->id,
        'server_id' => test()->destination->server_id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
    if ($parsingVersion !== null) {
        $service->update(['compose_parsing_version' => $parsingVersion]);
    }

    return $service->fresh();
}

function driverOptionsPreview(Application $application, int $pullRequestId = 42): ApplicationPreview
{
    return ApplicationPreview::create([
        'uuid' => 'driver-options-preview-'.$pullRequestId,
        'application_id' => $application->id,
        'pull_request_id' => $pullRequestId,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/'.$pullRequestId,
    ]);
}

/**
 * @return array<string, mixed>
 */
function expectedDriverOptionsVolume(string $name): array
{
    return [
        'driver' => 'local',
        'driver_opts' => [
            'type' => 'none',
            'o' => 'bind',
            'device' => '${OPTS_DEVICE}',
        ],
        'labels' => [
            'com.example.purpose' => 'host-folder',
        ],
        'name' => $name,
    ];
}

/**
 * @param  array<int, mixed>  $volumes
 * @return list<string>
 */
function driverOptionsVolumeSources(array $volumes): array
{
    return collect($volumes)
        ->map(fn (mixed $volume): string => is_array($volume) ? (string) $volume['source'] : explode(':', $volume)[0])
        ->values()
        ->all();
}

describe('applicationParser', function () {
    it('keeps driver, driver_opts and labels on the renamed volume', function (string $compose) {
        $application = driverOptionsApplication($compose);
        $uuid = $application->uuid;

        $parsed = applicationParser($application)->toArray();

        expect(driverOptionsVolumeSources($parsed['services']['web']['volumes']))->toBe(["{$uuid}_opts"])
            ->and($parsed['volumes']["{$uuid}_opts"])->toBe(expectedDriverOptionsVolume("{$uuid}_opts"));
    })->with([
        'short syntax' => [DRIVER_OPTIONS_SHORT_COMPOSE],
        'long syntax' => [DRIVER_OPTIONS_LONG_COMPOSE],
    ]);

    it('keeps driver options on the preview volume', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE);
        $uuid = $application->uuid;
        $preview = driverOptionsPreview($application);

        $parsed = applicationParser($application, 42, $preview->id)->toArray();
        $name = addPreviewDeploymentSuffix("{$uuid}_opts", 42);

        expect(driverOptionsVolumeSources($parsed['services']['web-pr-42']['volumes']))->toBe([$name])
            ->and($parsed['volumes'][$name])->toBe(expectedDriverOptionsVolume($name));
    });

    it('replaces the name of the declaration with the renamed volume', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_NAMED_COMPOSE);
        $uuid = $application->uuid;

        $parsed = applicationParser($application)->toArray();

        expect($parsed['volumes']["{$uuid}_opts"])->toBe([
            'driver' => 'local',
            'driver_opts' => ['type' => 'none', 'o' => 'bind', 'device' => '/srv/data'],
            'name' => "{$uuid}_opts",
        ]);
    });

    it('keeps an nfs volume next to other volumes', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_NFS_MIXED_COMPOSE);
        $uuid = $application->uuid;

        $parsed = applicationParser($application)->toArray();

        expect($parsed['services']['web']['volumes'])->toBe(['nfs-data:/nfs', "{$uuid}_app-data:/app"])
            ->and($parsed['volumes']['nfs-data']['driver_opts']['type'])->toBe('nfs');
    });
});

describe('serviceParser', function () {
    it('keeps driver, driver_opts and labels on the renamed volume', function (string $compose) {
        $service = driverOptionsService($compose);
        $uuid = $service->uuid;

        $parsed = serviceParser($service)->toArray();

        expect(driverOptionsVolumeSources($parsed['services']['web']['volumes']))->toBe(["{$uuid}_opts"])
            ->and($parsed['volumes']["{$uuid}_opts"])->toBe(expectedDriverOptionsVolume("{$uuid}_opts"));
    })->with([
        'short syntax' => [DRIVER_OPTIONS_SHORT_COMPOSE],
        'long syntax' => [DRIVER_OPTIONS_LONG_COMPOSE],
    ]);

    it('keeps an nfs volume next to other volumes', function () {
        $service = driverOptionsService(DRIVER_OPTIONS_NFS_MIXED_COMPOSE);
        $uuid = $service->uuid;

        $parsed = serviceParser($service)->toArray();

        expect($parsed['services']['web']['volumes'])->toBe(['nfs-data:/nfs', "{$uuid}_app-data:/app"])
            ->and($parsed['volumes']['nfs-data']['driver_opts']['type'])->toBe('nfs');
    });
});

describe('legacy parsers', function () {
    it('keeps driver options on the renamed volume of a legacy service', function (string $compose) {
        $service = driverOptionsService($compose, '2');
        $uuid = $service->uuid;

        $parsed = parseDockerComposeFile($service)->toArray();

        expect($parsed['volumes']["{$uuid}_opts"])->toBe(expectedDriverOptionsVolume("{$uuid}_opts"));
    })->with([
        'short syntax' => [DRIVER_OPTIONS_SHORT_COMPOSE],
        'long syntax' => [DRIVER_OPTIONS_LONG_COMPOSE],
    ]);

    it('keeps driver options on the renamed volume of a legacy application', function (string $parsingVersion, int $pullRequestId, string $compose, string $expectedName) {
        $application = driverOptionsApplication($compose, $parsingVersion);
        $uuid = $application->uuid;
        $previewId = $pullRequestId === 0 ? null : driverOptionsPreview($application, $pullRequestId)->id;
        $expectedName = str_replace('{uuid}', $uuid, $expectedName);

        $parsed = parseDockerComposeFile($application, pull_request_id: $pullRequestId, preview_id: $previewId)->toArray();

        expect($parsed['volumes'][$expectedName])->toBe(expectedDriverOptionsVolume($expectedName));
    })->with([
        'v1 short preview' => ['1', 42, DRIVER_OPTIONS_SHORT_COMPOSE, 'opts-pr-42'],
        'v1 long preview' => ['1', 42, DRIVER_OPTIONS_LONG_COMPOSE, 'opts-pr-42'],
        'v2 short' => ['2', 0, DRIVER_OPTIONS_SHORT_COMPOSE, '{uuid}-opts'],
        'v2 short preview' => ['2', 42, DRIVER_OPTIONS_SHORT_COMPOSE, '{uuid}-opts-pr-42'],
        'v2 long' => ['2', 0, DRIVER_OPTIONS_LONG_COMPOSE, '{uuid}-opts'],
        'v2 long preview' => ['2', 42, DRIVER_OPTIONS_LONG_COMPOSE, '{uuid}-opts-pr-42'],
    ]);

    it('keeps the volume of a legacy application as written', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE, '1');

        $parsed = parseDockerComposeFile($application)->toArray();

        expect($parsed['services']['web']['volumes'])->toBe(['opts:/data'])
            ->and($parsed['volumes']['opts']['driver_opts']['device'])->toBe('${OPTS_DEVICE}');
    });
});
