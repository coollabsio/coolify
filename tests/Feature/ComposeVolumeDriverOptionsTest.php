<?php

use App\Livewire\Project\Shared\Storages\All;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

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

const DRIVER_OPTIONS_TMPFS_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - 'opts:/data'
volumes:
  opts:
    driver: local
    driver_opts:
      type: tmpfs
      device: tmpfs
      o: size=100m
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
 * The declaration of a preview volume: the bind device of the production volume is not shared.
 *
 * @return array<string, mixed>
 */
function expectedPreviewDriverOptionsVolume(string $name): array
{
    return [
        'driver' => 'local',
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

/**
 * Marks every storage entry as created before Coolify kept the driver options, as the migration does.
 */
function markVolumesAsCreatedBeforeDriverOptions(): void
{
    LocalPersistentVolume::query()->update(['ignores_compose_driver_options' => true]);
}

const DRIVER_OPTIONS_NOTE = 'Coolify does not apply the driver options of this volume because it was created before they were supported.';

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

    it('does not bind the preview volume to the host folder of the production volume', function () {
        // A preview volume with the same bind device would mount the production data.
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE);
        $uuid = $application->uuid;
        $preview = driverOptionsPreview($application);

        $parsed = applicationParser($application, 42, $preview->id)->toArray();
        $name = addPreviewDeploymentSuffix("{$uuid}_opts", 42);

        expect(driverOptionsVolumeSources($parsed['services']['web-pr-42']['volumes']))->toBe([$name])
            ->and($parsed['volumes'][$name])->toBe(expectedPreviewDriverOptionsVolume($name));
    });

    it('keeps tmpfs driver options on the preview volume', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_TMPFS_COMPOSE);
        $preview = driverOptionsPreview($application);
        $name = addPreviewDeploymentSuffix("{$application->uuid}_opts", 42);

        $parsed = applicationParser($application, 42, $preview->id)->toArray();

        expect($parsed['volumes'][$name])->toBe([
            'driver' => 'local',
            'driver_opts' => ['type' => 'tmpfs', 'device' => 'tmpfs', 'o' => 'size=100m'],
            'name' => $name,
        ]);
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

    it('gives the preview its own volume instead of the network volume of production', function (string $type, string $device) {
        // The preview must not mount the network share of production: two deployments that write into the
        // same data directory (for example two databases) can corrupt it.
        $compose = str_replace(['type: nfs', "':/exports/data'"], ["type: {$type}", "'{$device}'"], DRIVER_OPTIONS_NFS_MIXED_COMPOSE);
        $application = driverOptionsApplication($compose);
        $uuid = $application->uuid;
        $preview = driverOptionsPreview($application);
        $name = addPreviewDeploymentSuffix("{$uuid}_nfs-data", 42);

        $parsed = applicationParser($application, 42, $preview->id)->toArray();

        expect($parsed['services']['web-pr-42']['volumes'])->toBe(["{$name}:/nfs", addPreviewDeploymentSuffix("{$uuid}_app-data", 42).':/app'])
            ->and($parsed['volumes'])->not->toHaveKey('nfs-data')
            ->and($parsed['volumes'][$name])->toBe(['name' => $name])
            ->and(LocalPersistentVolume::where('resource_id', $application->id)->where('name', $name)->exists())->toBeTrue();
    })->with([
        'nfs' => ['nfs', ':/exports/data'],
        'cifs' => ['cifs', '//10.0.0.1/share'],
    ]);
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

    it('keeps the name-only declaration of the renamed volume of a legacy application', function (string $parsingVersion, int $pullRequestId, string $compose, string $expectedName) {
        // Legacy parsers run only for applications that exist since before driver options were kept.
        $application = driverOptionsApplication($compose, $parsingVersion);
        $uuid = $application->uuid;
        $previewId = $pullRequestId === 0 ? null : driverOptionsPreview($application, $pullRequestId)->id;
        $expectedName = str_replace('{uuid}', $uuid, $expectedName);

        $parsed = parseDockerComposeFile($application, pull_request_id: $pullRequestId, preview_id: $previewId)->toArray();

        expect($parsed['volumes'][$expectedName])->toBe(['name' => $expectedName]);
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

describe('volumes created before driver options were kept', function () {
    it('marks only the storage entries that exist when the migration runs', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE);
        $existing = $application->persistentStorages()->create(['name' => 'existing', 'mount_path' => '/existing']);
        $migration = require database_path('migrations/2026_09_30_115702_add_ignores_compose_driver_options_to_local_persistent_volumes_table.php');
        $migration->down();
        $migration->up();

        $new = $application->persistentStorages()->create(['name' => 'new', 'mount_path' => '/new']);

        expect($existing->refresh()->ignores_compose_driver_options)->toBeTrue()
            ->and($new->refresh()->ignores_compose_driver_options)->toBeFalse();
    });

    it('keeps the name-only declaration of an existing application volume', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE);
        $name = "{$application->uuid}_opts";
        applicationParser($application);
        markVolumesAsCreatedBeforeDriverOptions();

        $parsed = applicationParser($application->fresh())->toArray();

        expect($parsed['volumes'][$name])->toBe(['name' => $name])
            ->and(LocalPersistentVolume::where('name', $name)->sole()->ignores_compose_driver_options)->toBeTrue();
    });

    it('keeps the name-only declaration of an existing preview volume', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE);
        $preview = driverOptionsPreview($application);
        $name = addPreviewDeploymentSuffix("{$application->uuid}_opts", 42);
        applicationParser($application, 42, $preview->id);
        markVolumesAsCreatedBeforeDriverOptions();

        $parsed = applicationParser($application->fresh(), 42, $preview->id)->toArray();

        expect($parsed['volumes'][$name])->toBe(['name' => $name]);
    });

    it('applies the preview driver options to a new preview volume of an existing application', function () {
        // The new preview volume is a new Docker volume, so the flag of the production volume does not apply;
        // the bind device of the production volume is still not shared with the preview.
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE);
        applicationParser($application);
        markVolumesAsCreatedBeforeDriverOptions();
        $preview = driverOptionsPreview($application);
        $name = addPreviewDeploymentSuffix("{$application->uuid}_opts", 42);

        $parsed = applicationParser($application->fresh(), 42, $preview->id)->toArray();

        expect($parsed['volumes'][$name])->toBe(expectedPreviewDriverOptionsVolume($name));
    });

    it('keeps the name-only declaration of an existing service volume', function () {
        $service = driverOptionsService(DRIVER_OPTIONS_SHORT_COMPOSE);
        $name = "{$service->uuid}_opts";
        serviceParser($service);
        markVolumesAsCreatedBeforeDriverOptions();

        $parsed = serviceParser($service->fresh())->toArray();

        expect($parsed['volumes'][$name])->toBe(['name' => $name]);
    });

    it('keeps the name-only declaration of an existing legacy service volume', function () {
        $service = driverOptionsService(DRIVER_OPTIONS_SHORT_COMPOSE, '2');
        $name = "{$service->uuid}_opts";
        parseDockerComposeFile($service);
        markVolumesAsCreatedBeforeDriverOptions();

        $parsed = parseDockerComposeFile($service->fresh())->toArray();

        expect($parsed['volumes'][$name])->toBe(['name' => $name]);
    });

    it('keeps driver options on a volume that a new resource adds', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE);
        $name = "{$application->uuid}_opts";

        applicationParser($application);
        $parsed = applicationParser($application->fresh())->toArray();

        expect($parsed['volumes'][$name])->toBe(expectedDriverOptionsVolume($name))
            ->and(LocalPersistentVolume::where('name', $name)->sole()->ignores_compose_driver_options)->toBeFalse();
    });

    it('applies the driver options after the storage entry of an existing volume is deleted', function (string $resourceType) {
        $resource = $resourceType === 'application'
            ? driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE)
            : driverOptionsService(DRIVER_OPTIONS_SHORT_COMPOSE);
        $parse = fn () => $resourceType === 'application' ? applicationParser($resource->fresh()) : serviceParser($resource->fresh());
        $name = "{$resource->uuid}_opts";
        $parse();
        markVolumesAsCreatedBeforeDriverOptions();

        LocalPersistentVolume::where('name', $name)->sole()->delete();
        $parsed = $parse()->toArray();

        expect($parsed['volumes'][$name])->toBe(expectedDriverOptionsVolume($name))
            ->and(LocalPersistentVolume::where('name', $name)->sole()->ignores_compose_driver_options)->toBeFalse();
    })->with(['application', 'service']);

    it('gives a copied storage entry the driver options', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE);
        $volume = $application->persistentStorages()->create(['name' => "{$application->uuid}_opts", 'mount_path' => '/data']);
        markVolumesAsCreatedBeforeDriverOptions();

        $copy = $volume->fresh()->replicate(['id', 'uuid'])->fill(['name' => 'copy_opts']);
        $copy->save();

        expect($copy->refresh()->ignores_compose_driver_options)->toBeFalse()
            ->and($volume->refresh()->ignores_compose_driver_options)->toBeTrue();
    });
});

describe('storage page', function () {
    beforeEach(function () {
        $this->withoutVite();
        $team = $this->environment->project->team;
        $user = User::factory()->create();
        $team->members()->attach($user->id, ['role' => 'owner']);
        $this->actingAs($user);
        session(['currentTeam' => $team]);
    });

    it('shows a note on an existing volume whose driver options Coolify does not apply', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE);
        applicationParser($application);
        markVolumesAsCreatedBeforeDriverOptions();

        Livewire::test(All::class, ['resource' => $application->fresh()])
            ->assertSee(DRIVER_OPTIONS_NOTE);
    });

    it('shows the note on an existing service volume', function () {
        $service = driverOptionsService(DRIVER_OPTIONS_SHORT_COMPOSE);
        serviceParser($service);
        markVolumesAsCreatedBeforeDriverOptions();

        Livewire::test(All::class, ['resource' => $service->fresh()->applications()->sole()])
            ->assertSee(DRIVER_OPTIONS_NOTE);
    });

    it('lets the storage page delete the entry of an existing volume so the next parse applies the driver options', function (bool $deleteDockerVolume) {
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE);
        $name = "{$application->uuid}_opts";
        applicationParser($application);
        markVolumesAsCreatedBeforeDriverOptions();
        $volume = LocalPersistentVolume::where('name', $name)->sole();
        $commands = [];
        Process::fake(function ($process) use (&$commands) {
            $commands[] = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return Process::result(output: '');
        });

        Livewire::test(All::class, ['resource' => $application->fresh()])
            ->call('delete', $volume->id, 'password', $deleteDockerVolume ? ['deleteDockerVolume'] : [])
            ->assertHasNoErrors()
            ->assertReturned(true);

        expect(LocalPersistentVolume::find($volume->id))->toBeNull()
            ->and(implode("\n", $commands))->{$deleteDockerVolume ? 'toContain' : 'not'}("docker volume rm -f '{$name}'");
        $parsed = applicationParser($application->fresh())->toArray();
        expect($parsed['volumes'][$name])->toBe(expectedDriverOptionsVolume($name));
    })->with([
        'keep the Docker volume' => [false],
        'delete the Docker volume' => [true],
    ]);

    it('does not let the storage page delete the entry of a new volume', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE);
        applicationParser($application);
        $volume = LocalPersistentVolume::where('name', "{$application->uuid}_opts")->sole();

        Livewire::test(All::class, ['resource' => $application->fresh()])
            ->call('delete', $volume->id, 'password')
            ->assertReturned(false);

        expect(LocalPersistentVolume::find($volume->id))->not->toBeNull();
    });

    it('does not show the note on a new volume', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_SHORT_COMPOSE);
        applicationParser($application);

        Livewire::test(All::class, ['resource' => $application->fresh()])
            ->assertDontSee(DRIVER_OPTIONS_NOTE);
    });

    it('does not show the note on an existing volume without driver options', function () {
        $application = driverOptionsApplication(DRIVER_OPTIONS_NFS_MIXED_COMPOSE);
        applicationParser($application);
        markVolumesAsCreatedBeforeDriverOptions();

        Livewire::test(All::class, ['resource' => $application->fresh()])
            ->assertDontSee(DRIVER_OPTIONS_NOTE);
    });
});
