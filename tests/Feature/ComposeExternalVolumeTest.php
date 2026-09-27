<?php

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const EXTERNAL_VOLUME_SHORT_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - 'shared-data:/data:ro'
      - 'app-data:/app'
volumes:
  shared-data:
    external: true
  app-data: {}
YAML;

const EXTERNAL_VOLUME_LONG_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - type: volume
        source: shared-data
        target: /data
      - type: volume
        source: app-data
        target: /app
volumes:
  shared-data:
    external: true
    name: existing-shared-volume
  app-data: {}
YAML;

const EXTERNAL_VOLUME_OLD_SYNTAX_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - 'shared-data:/data'
volumes:
  shared-data:
    external:
      name: legacy-shared-volume
YAML;

const EXTERNAL_VOLUME_NFS_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - 'nfs-data:/data'
volumes:
  nfs-data:
    driver: local
    driver_opts:
      type: nfs
      o: addr=10.0.0.1,rw
      device: ':/exports/data'
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

function externalVolumeApplication(string $compose, ?string $parsingVersion = null): Application
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

function externalVolumeService(string $compose, ?string $parsingVersion = null): Service
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

function externalVolumePreview(Application $application, int $pullRequestId = 42): ApplicationPreview
{
    return ApplicationPreview::create([
        'uuid' => 'external-volume-preview-'.$pullRequestId,
        'application_id' => $application->id,
        'pull_request_id' => $pullRequestId,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/'.$pullRequestId,
    ]);
}

/**
 * @return list<string>
 */
function persistentVolumeNames(): array
{
    return LocalPersistentVolume::query()->pluck('name')->sort()->values()->all();
}

/**
 * @param  array<int, string>  $commands
 */
function fakeExternalVolumeServer(array &$commands): void
{
    Process::fake(function ($process) use (&$commands) {
        $commands[] = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return Process::result(output: '');
    });
}

describe('applicationParser', function () {
    it('keeps an external volume in short syntax and renames only the other volumes', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $application->uuid;

        $compose = applicationParser($application)->toArray();

        expect($compose['services']['web']['volumes'])->toContain('shared-data:/data:ro')
            ->toContain("{$uuid}_app-data:/app")
            ->and($compose['volumes']['shared-data'])->toBe(['external' => true])
            ->and($compose['volumes'])->toHaveKey("{$uuid}_app-data")
            ->not->toHaveKey("{$uuid}_shared-data")
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data"]);
    });

    it('keeps an external volume in long syntax with its name', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_LONG_COMPOSE);
        $uuid = $application->uuid;

        $compose = applicationParser($application)->toArray();
        $sources = collect($compose['services']['web']['volumes'])->pluck('source')->all();

        expect($sources)->toBe(['shared-data', "{$uuid}_app-data"])
            ->and($compose['volumes']['shared-data'])->toBe(['external' => true, 'name' => 'existing-shared-volume'])
            ->and($compose['volumes'])->not->toHaveKey("{$uuid}_shared-data")
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data"]);
    });

    it('keeps an external volume in the old external name syntax', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_OLD_SYNTAX_COMPOSE);

        $compose = applicationParser($application)->toArray();

        expect($compose['services']['web']['volumes'])->toBe(['shared-data:/data'])
            ->and($compose['volumes'])->toBe(['shared-data' => ['external' => ['name' => 'legacy-shared-volume']]])
            ->and(persistentVolumeNames())->toBe([]);
    });

    it('shares the external volume with a preview deployment', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $application->uuid;
        $preview = externalVolumePreview($application);

        $compose = applicationParser($application, 42, $preview->id)->toArray();

        expect($compose['services']['web-pr-42']['volumes'])->toContain('shared-data:/data:ro')
            ->toContain("{$uuid}_app-data-pr-42:/app")
            ->and($compose['volumes']['shared-data'])->toBe(['external' => true])
            ->and($compose['volumes'])->not->toHaveKey("{$uuid}_shared-data-pr-42")
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data-pr-42"]);
    });

    it('keeps an existing row of a volume that an earlier parse prefixed', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $application->uuid;
        $oldVolume = $application->persistentStorages()->create([
            'name' => "{$uuid}_shared-data",
            'mount_path' => '/data',
        ]);

        applicationParser($application);

        expect(LocalPersistentVolume::find($oldVolume->id))->not->toBeNull()
            ->and(LocalPersistentVolume::find($oldVolume->id)->name)->toBe("{$uuid}_shared-data")
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data", "{$uuid}_shared-data"]);
    });

    it('does not change nfs volumes', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_NFS_COMPOSE);

        $compose = applicationParser($application)->toArray();

        expect($compose['services']['web']['volumes'])->toBe(['nfs-data:/data'])
            ->and($compose['volumes']['nfs-data']['driver_opts']['type'])->toBe('nfs')
            ->and(persistentVolumeNames())->toBe([]);
    });
});

describe('serviceParser', function () {
    it('keeps an external volume in short syntax', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $service->uuid;

        $compose = serviceParser($service)->toArray();

        expect($compose['services']['web']['volumes'])->toContain('shared-data:/data:ro')
            ->toContain("{$uuid}_app-data:/app")
            ->and($compose['volumes']['shared-data'])->toBe(['external' => true])
            ->and($compose['volumes'])->not->toHaveKey("{$uuid}_shared-data")
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data"]);
    });

    it('keeps an external volume in long syntax with its name', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_LONG_COMPOSE);
        $uuid = $service->uuid;

        $compose = serviceParser($service)->toArray();
        $sources = collect($compose['services']['web']['volumes'])->pluck('source')->all();

        expect($sources)->toBe(['shared-data', "{$uuid}_app-data"])
            ->and($compose['volumes']['shared-data'])->toBe(['external' => true, 'name' => 'existing-shared-volume'])
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data"]);
    });

    it('keeps an existing row of a volume that an earlier parse prefixed', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $service->uuid;
        $serviceApplication = ServiceApplication::create(['name' => 'web', 'service_id' => $service->id, 'image' => 'nginx:alpine']);
        $oldVolume = $serviceApplication->persistentStorages()->create([
            'name' => "{$uuid}_shared-data",
            'mount_path' => '/data',
        ]);

        serviceParser($service);

        expect(LocalPersistentVolume::find($oldVolume->id)?->name)->toBe("{$uuid}_shared-data")
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data", "{$uuid}_shared-data"]);
    });

    it('does not change nfs volumes', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_NFS_COMPOSE);

        $compose = serviceParser($service)->toArray();

        expect($compose['volumes']['nfs-data']['driver_opts']['type'])->toBe('nfs')
            ->and(persistentVolumeNames())->toBe([]);
    });
});

describe('legacy parsers', function () {
    it('keeps an external volume in a legacy service', function (string $compose, string $expectedVolume) {
        $service = externalVolumeService($compose, '2');
        $uuid = $service->uuid;

        $parsed = parseDockerComposeFile($service)->toArray();
        $volumes = collect($parsed['services']['web']['volumes'])
            ->map(fn (mixed $volume): string => is_array($volume) ? $volume['source'] : $volume)
            ->all();

        expect($volumes)->toContain($expectedVolume)
            ->toContain(str_ends_with($expectedVolume, ':ro') ? "{$uuid}_app-data:/app" : "{$uuid}_app-data")
            ->and($parsed['volumes']['shared-data']['external'])->toBeTrue()
            ->and($parsed['volumes'])->not->toHaveKey("{$uuid}_shared-data")
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data"]);
    })->with([
        'short syntax' => [EXTERNAL_VOLUME_SHORT_COMPOSE, 'shared-data:/data:ro'],
        'long syntax' => [EXTERNAL_VOLUME_LONG_COMPOSE, 'shared-data'],
    ]);

    it('keeps an existing row of a volume that an earlier legacy service parse prefixed', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE, '2');
        $uuid = $service->uuid;
        $serviceApplication = ServiceApplication::create(['name' => 'web', 'service_id' => $service->id, 'image' => 'nginx:alpine']);
        $oldVolume = $serviceApplication->persistentStorages()->create([
            'name' => "{$uuid}_shared-data",
            'mount_path' => '/data',
        ]);

        parseDockerComposeFile($service);

        expect(LocalPersistentVolume::find($oldVolume->id)?->name)->toBe("{$uuid}_shared-data");
    });

    it('keeps an external volume in a legacy application', function (string $parsingVersion, int $pullRequestId, string $compose, string $expectedExternal, string $expectedOther) {
        $application = externalVolumeApplication($compose, $parsingVersion);
        $uuid = $application->uuid;
        $previewId = $pullRequestId === 0 ? null : externalVolumePreview($application, $pullRequestId)->id;

        $parsed = parseDockerComposeFile($application, pull_request_id: $pullRequestId, preview_id: $previewId)->toArray();
        $serviceName = $pullRequestId === 0 ? 'web' : "web-pr-{$pullRequestId}";
        $expectedOther = str_replace('{uuid}', $uuid, $expectedOther);

        expect($parsed['services'][$serviceName]['volumes'])->toContain($expectedExternal)
            ->toContain($expectedOther)
            ->and($parsed['volumes']['shared-data']['external'])->toBeTrue()
            ->and(array_keys($parsed['volumes']))->not->toContain('shared-data-pr-42')
            ->not->toContain("{$uuid}-shared-data")
            ->not->toContain("{$uuid}-shared-data-pr-42");
    })->with([
        'v1 short' => ['1', 0, EXTERNAL_VOLUME_SHORT_COMPOSE, 'shared-data:/data:ro', 'app-data:/app'],
        'v1 short preview' => ['1', 42, EXTERNAL_VOLUME_SHORT_COMPOSE, 'shared-data:/data:ro', 'app-data-pr-42:/app'],
        'v1 long preview' => ['1', 42, EXTERNAL_VOLUME_LONG_COMPOSE, 'shared-data:/data', 'app-data-pr-42:/app'],
        'v2 short' => ['2', 0, EXTERNAL_VOLUME_SHORT_COMPOSE, 'shared-data:/data:ro', '{uuid}-app-data:/app'],
        'v2 short preview' => ['2', 42, EXTERNAL_VOLUME_SHORT_COMPOSE, 'shared-data:/data:ro', '{uuid}-app-data-pr-42:/app'],
        'v2 long' => ['2', 0, EXTERNAL_VOLUME_LONG_COMPOSE, 'shared-data:/data', '{uuid}-app-data:/app'],
        'v2 long preview' => ['2', 42, EXTERNAL_VOLUME_LONG_COMPOSE, 'shared-data:/data', '{uuid}-app-data-pr-42:/app'],
    ]);
});

describe('validation', function () {
    it('rejects an external volume name with a variable', function (string $compose) {
        expect(fn () => validateDockerComposeForInjection($compose))
            ->toThrow(Exception::class, 'Coolify does not resolve variables in the name of an external volume');

        $application = externalVolumeApplication($compose);
        expect(fn () => applicationParser($application))
            ->toThrow(Exception::class, 'Coolify does not resolve variables in the name of an external volume');

        $service = externalVolumeService($compose);
        expect(fn () => serviceParser($service))
            ->toThrow(Exception::class, 'Coolify does not resolve variables in the name of an external volume');
    })->with([
        'name field' => ["services:\n  web:\n    image: nginx\n    volumes:\n      - 'shared-data:/data'\nvolumes:\n  shared-data:\n    external: true\n    name: \${SHARED_VOLUME}\n"],
        'name field with default' => ["services:\n  web:\n    image: nginx\n    volumes:\n      - 'shared-data:/data'\nvolumes:\n  shared-data:\n    external: true\n    name: \${SHARED_VOLUME:-shared}\n"],
        'old syntax' => ["services:\n  web:\n    image: nginx\n    volumes:\n      - 'shared-data:/data'\nvolumes:\n  shared-data:\n    external:\n      name: \$SHARED_VOLUME\n"],
    ]);

    it('rejects an external volume name that is not a valid Docker volume name', function () {
        $compose = "services:\n  web:\n    image: nginx\nvolumes:\n  shared-data:\n    external: true\n    name: 'shared data;rm -rf /'\n";

        expect(fn () => validateDockerComposeForInjection($compose))
            ->toThrow(Exception::class, 'Invalid external Docker Compose volume shared-data');
    });

    it('accepts external volumes with a literal name', function (string $compose) {
        validateDockerComposeForInjection($compose);

        expect(true)->toBeTrue();
    })->with([
        'short' => [EXTERNAL_VOLUME_SHORT_COMPOSE],
        'long' => [EXTERNAL_VOLUME_LONG_COMPOSE],
        'old syntax' => [EXTERNAL_VOLUME_OLD_SYNTAX_COMPOSE],
    ]);
});

describe('delete', function () {
    it('never removes the external volume when a service is deleted with its volumes', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $service->uuid;
        serviceParser($service);
        $service->delete();
        $commands = [];
        fakeExternalVolumeServer($commands);

        (new DeleteResourceJob($service))->handle();

        $all = implode("\n", $commands);
        expect($all)->toContain("docker volume rm -f '{$uuid}_app-data'")
            ->not->toContain("'shared-data'")
            ->not->toContain("{$uuid}_shared-data");
    });

    it('lets Docker Compose keep the external volume when a Compose application is deleted with its volumes', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        applicationParser($application);
        $application->delete();
        $commands = [];
        fakeExternalVolumeServer($commands);

        (new DeleteResourceJob($application))->handle();

        $all = implode("\n", $commands);
        expect($all)->toContain('docker compose down -v')
            ->not->toContain('docker volume rm')
            ->and($application->docker_compose)->toContain("shared-data:\n    external: true");
    });

    it('does not remove the external volume when a preview is deleted', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $application->uuid;
        $preview = externalVolumePreview($application);
        $commands = [];
        fakeExternalVolumeServer($commands);

        $preview->forceDelete();

        $volumeCommands = collect($commands)->filter(fn (string $command): bool => str_contains($command, 'docker volume'))->implode("\n");
        expect($volumeCommands)->toContain("docker volume rm -f '{$uuid}_app-data-pr-42'")
            ->not->toContain('shared-data');
    });
});
