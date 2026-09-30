<?php

use App\Actions\Service\DeployServiceApplication;
use App\Actions\Service\StartService;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\DeleteResourceJob;
use App\Livewire\Project\Service\StackForm;
use App\Livewire\Project\Service\Storage as StoragePage;
use App\Livewire\Project\Shared\Storages\All;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\RemoteProcessCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

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

const EXTERNAL_VOLUME_DATABASE_COMPOSE = <<<'YAML'
services:
  db:
    image: postgres:16-alpine
    volumes:
      - 'shared-data:/var/lib/postgresql/data'
volumes:
  shared-data:
    external: true
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
 * The storage entry that the parsers made for an external volume before they used it as written.
 */
function legacyExternalVolumeRow(Model $owner, string $name, string $mountPath = '/data'): LocalPersistentVolume
{
    return $owner->persistentStorages()->create(['name' => $name, 'mount_path' => $mountPath]);
}

function legacyExternalVolumeServiceResource(Service $service, string $name, string $compose = EXTERNAL_VOLUME_SHORT_COMPOSE): ServiceApplication|ServiceDatabase
{
    $image = data_get(Yaml::parse($compose), "services.{$name}.image");

    return isDatabaseImage($image)
        ? ServiceDatabase::create(['name' => $name, 'service_id' => $service->id, 'image' => $image])
        : ServiceApplication::create(['name' => $name, 'service_id' => $service->id, 'image' => $image]);
}

function legacyExternalVolumeWarning(string $oldName, ?string $dockerVolume = null): string
{
    $external = $dockerVolume === null ? 'external' : "external (Docker volume '{$dockerVolume}')";

    return "Volume 'shared-data' is declared as {$external}, but Coolify still uses '{$oldName}' because this resource used it before. To use the external volume, copy your data into it and delete the storage entry '{$oldName}', then redeploy.";
}

function legacyParserExternalVolumeWarning(string $keptName, ?string $dockerVolume = null): string
{
    $external = $dockerVolume === null ? 'external' : "external (Docker volume '{$dockerVolume}')";

    return "Volume 'shared-data' is declared as {$external}, but this application uses an old Compose parser, so Coolify uses '{$keptName}'. To use the external volume, copy your data into it, then clone this application or create it again.";
}

/**
 * The "External volumes" card of the storage page, or an empty string when the page does not show it.
 */
function externalVolumesSection(string $html, string $resourceUuid): string
{
    $pattern = '/<section[^>]*id="external-volumes-'.preg_quote($resourceUuid, '/').'".*?<\/section>/s';

    return preg_match($pattern, $html, $matches) === 1 ? $matches[0] : '';
}

/**
 * Runs the Compose parse step of a deployment job.
 *
 * @return array{0: Collection<array-key, mixed>, 1: list<array{0: string, 1: string}>}
 */
function parseExternalVolumeDeployment(Application $application, int $pullRequestId = 0, ?ApplicationPreview $preview = null): array
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $logEntries = new ArrayObject;
    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message, string $type = 'stdout') use ($logEntries): void {
        $logEntries[] = [$message, $type];
    });

    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'pull_request_id' => $pullRequestId,
        'preview' => $preview,
        'commit' => 'HEAD',
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    $composeFile = (new ReflectionMethod(ApplicationDeploymentJob::class, 'parseComposeFileForDeployment'))->invoke($job);

    return [$composeFile, $logEntries->getArrayCopy()];
}

function loginExternalVolumeOwner(): void
{
    test()->withoutVite();
    $team = test()->environment->project->team;
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    test()->actingAs($user);
    session(['currentTeam' => $team]);
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

    it('does not show a warning when the resource has no old storage entry', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);

        applicationParser($application);

        expect($application->composeVolumeWarnings())->toBe([]);
    });

    it('keeps the old prefixed volume and shows a warning when the resource has its old storage entry', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $application->uuid;
        $oldVolume = legacyExternalVolumeRow($application, "{$uuid}_shared-data");

        $compose = applicationParser($application)->toArray();

        expect($compose['services']['web']['volumes'])->toContain("{$uuid}_shared-data:/data:ro")
            ->not->toContain('shared-data:/data:ro')
            ->and($compose['volumes']["{$uuid}_shared-data"])->toBe(['name' => "{$uuid}_shared-data"])
            ->and(LocalPersistentVolume::find($oldVolume->id)?->name)->toBe("{$uuid}_shared-data")
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data", "{$uuid}_shared-data"])
            ->and($application->composeVolumeWarnings())->toBe([legacyExternalVolumeWarning("{$uuid}_shared-data")]);
    });

    it('keeps the old prefixed volume in long syntax and names the external Docker volume in the warning', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_LONG_COMPOSE);
        $uuid = $application->uuid;
        legacyExternalVolumeRow($application, "{$uuid}_shared-data");

        $compose = applicationParser($application)->toArray();
        $sources = collect($compose['services']['web']['volumes'])->pluck('source')->all();

        expect($sources)->toBe(["{$uuid}_shared-data", "{$uuid}_app-data"])
            ->and($compose['volumes']["{$uuid}_shared-data"])->toBe(['name' => "{$uuid}_shared-data"])
            ->and($application->composeVolumeWarnings())->toBe([legacyExternalVolumeWarning("{$uuid}_shared-data", 'existing-shared-volume')]);
    });

    it('keeps the old prefixed preview volume when the resource has its old storage entry', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $application->uuid;
        $preview = externalVolumePreview($application);
        legacyExternalVolumeRow($application, "{$uuid}_shared-data-pr-42");

        $compose = applicationParser($application, 42, $preview->id)->toArray();

        expect($compose['services']['web-pr-42']['volumes'])->toContain("{$uuid}_shared-data-pr-42:/data:ro")
            ->and($compose['volumes']["{$uuid}_shared-data-pr-42"])->toBe(['name' => "{$uuid}_shared-data-pr-42"])
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data-pr-42", "{$uuid}_shared-data-pr-42"])
            ->and($application->composeVolumeWarnings())->toBe([legacyExternalVolumeWarning("{$uuid}_shared-data-pr-42")]);
    });

    it('uses the external volume in a preview when only the production volume has an old storage entry', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $application->uuid;
        $preview = externalVolumePreview($application);
        legacyExternalVolumeRow($application, "{$uuid}_shared-data");

        $compose = applicationParser($application, 42, $preview->id)->toArray();

        expect($compose['services']['web-pr-42']['volumes'])->toContain('shared-data:/data:ro')
            ->and($application->composeVolumeWarnings())->toBe([]);
    });

    it('uses the external volume after the old storage entry is deleted', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $application->uuid;
        $oldVolume = legacyExternalVolumeRow($application, "{$uuid}_shared-data");
        applicationParser($application);

        $oldVolume->delete();
        $compose = applicationParser($application)->toArray();

        expect($compose['services']['web']['volumes'])->toContain('shared-data:/data:ro')
            ->and($compose['volumes'])->not->toHaveKey("{$uuid}_shared-data")
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data"])
            ->and($application->composeVolumeWarnings())->toBe([]);
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

    it('keeps the old prefixed volume and shows a warning when the service has its old storage entry', function (string $compose, string $serviceName, string $mountPath, string $mode) {
        $service = externalVolumeService($compose);
        $uuid = $service->uuid;
        $owner = legacyExternalVolumeServiceResource($service, $serviceName, $compose);
        $oldVolume = legacyExternalVolumeRow($owner, "{$uuid}_shared-data", $mountPath);

        $parsed = serviceParser($service)->toArray();

        expect($parsed['services'][$serviceName]['volumes'])->toContain("{$uuid}_shared-data:{$mountPath}{$mode}")
            ->and($parsed['volumes']["{$uuid}_shared-data"])->toBe(['name' => "{$uuid}_shared-data"])
            ->and(LocalPersistentVolume::find($oldVolume->id)?->name)->toBe("{$uuid}_shared-data")
            ->and(LocalPersistentVolume::query()->where('name', "{$uuid}_shared-data")->count())->toBe(1)
            ->and($service->composeVolumeWarnings())->toBe([legacyExternalVolumeWarning("{$uuid}_shared-data")]);
    })->with([
        'service application' => [EXTERNAL_VOLUME_SHORT_COMPOSE, 'web', '/data', ':ro'],
        'service database' => [EXTERNAL_VOLUME_DATABASE_COMPOSE, 'db', '/var/lib/postgresql/data', ''],
    ]);

    it('uses the external volume after the old storage entry of the service is deleted', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $service->uuid;
        $oldVolume = legacyExternalVolumeRow(legacyExternalVolumeServiceResource($service, 'web'), "{$uuid}_shared-data");
        serviceParser($service);

        $oldVolume->delete();
        $parsed = serviceParser($service)->toArray();

        expect($parsed['services']['web']['volumes'])->toContain('shared-data:/data:ro')
            ->and($parsed['volumes'])->not->toHaveKey("{$uuid}_shared-data")
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data"])
            ->and($service->composeVolumeWarnings())->toBe([]);
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

    it('keeps the old prefixed volume of a legacy service that has its old storage entry', function (string $compose, string $expectedVolume) {
        $service = externalVolumeService($compose, '2');
        $uuid = $service->uuid;
        $oldVolume = legacyExternalVolumeRow(legacyExternalVolumeServiceResource($service, 'web'), "{$uuid}_shared-data");

        $parsed = parseDockerComposeFile($service)->toArray();
        $volumes = collect($parsed['services']['web']['volumes'])
            ->map(fn (mixed $volume): string => is_array($volume) ? $volume['source'] : $volume)
            ->all();

        expect($volumes)->toContain(str_replace('{uuid}', $uuid, $expectedVolume))
            ->and($parsed['volumes']["{$uuid}_shared-data"])->toBe(['name' => "{$uuid}_shared-data"])
            ->and(LocalPersistentVolume::find($oldVolume->id)?->name)->toBe("{$uuid}_shared-data")
            ->and(LocalPersistentVolume::query()->where('name', "{$uuid}_shared-data")->count())->toBe(1)
            ->and($service->composeVolumeWarnings())->toHaveCount(1)
            ->and($service->composeVolumeWarnings()[0])->toStartWith("Volume 'shared-data' is declared as external")
            ->toContain("Coolify still uses '{$uuid}_shared-data'");
    })->with([
        'short syntax' => [EXTERNAL_VOLUME_SHORT_COMPOSE, '{uuid}_shared-data:/data'],
        'long syntax' => [EXTERNAL_VOLUME_LONG_COMPOSE, '{uuid}_shared-data'],
    ]);

    it('uses the external volume after the old storage entry of a legacy service is deleted', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE, '2');
        $uuid = $service->uuid;
        $oldVolume = legacyExternalVolumeRow(legacyExternalVolumeServiceResource($service, 'web'), "{$uuid}_shared-data");
        parseDockerComposeFile($service);

        $oldVolume->delete();
        $parsed = parseDockerComposeFile($service)->toArray();

        expect($parsed['services']['web']['volumes'])->toContain('shared-data:/data:ro')
            ->and($parsed['volumes'])->not->toHaveKey("{$uuid}_shared-data")
            ->and(persistentVolumeNames())->toBe(["{$uuid}_app-data"])
            ->and($service->composeVolumeWarnings())->toBe([]);
    });

    it('keeps the old volume names of a legacy application and shows a warning when the name is not the external name', function (string $parsingVersion, int $pullRequestId, string $compose, string $expectedVolume, ?string $dockerVolume) {
        // Legacy Compose applications (parser versions 1 and 2) never stored their volumes, so Coolify
        // cannot tell which external volume already holds data: they always keep the old names.
        $application = externalVolumeApplication($compose, $parsingVersion);
        $uuid = $application->uuid;
        $previewId = $pullRequestId === 0 ? null : externalVolumePreview($application, $pullRequestId)->id;

        $parsed = parseDockerComposeFile($application, pull_request_id: $pullRequestId, preview_id: $previewId)->toArray();
        $serviceName = $pullRequestId === 0 ? 'web' : "web-pr-{$pullRequestId}";
        $expectedVolume = str_replace('{uuid}', $uuid, $expectedVolume);
        $expectedName = explode(':', $expectedVolume)[0];

        expect($parsed['services'][$serviceName]['volumes'])->toContain($expectedVolume)
            ->and(array_keys($parsed['volumes'] ?? []))->toContain($expectedName)
            ->and($application->composeVolumeWarnings())->toBe(
                // Parser version 1 keeps the name of a production volume, so it uses the external volume.
                $expectedName === 'shared-data' ? [] : [legacyParserExternalVolumeWarning($expectedName, $dockerVolume)]
            );
    })->with([
        'v1 short' => ['1', 0, EXTERNAL_VOLUME_SHORT_COMPOSE, 'shared-data:/data:ro', null],
        'v1 short preview' => ['1', 42, EXTERNAL_VOLUME_SHORT_COMPOSE, 'shared-data-pr-42:/data:ro', null],
        'v1 long preview' => ['1', 42, EXTERNAL_VOLUME_LONG_COMPOSE, 'shared-data-pr-42:/data', 'existing-shared-volume'],
        'v2 short' => ['2', 0, EXTERNAL_VOLUME_SHORT_COMPOSE, '{uuid}-shared-data:/data:ro', null],
        'v2 short preview' => ['2', 42, EXTERNAL_VOLUME_SHORT_COMPOSE, '{uuid}-shared-data-pr-42:/data:ro', null],
        'v2 long' => ['2', 0, EXTERNAL_VOLUME_LONG_COMPOSE, '{uuid}-shared-data:/data', 'existing-shared-volume'],
        'v2 long preview' => ['2', 42, EXTERNAL_VOLUME_LONG_COMPOSE, '{uuid}-shared-data-pr-42:/data', 'existing-shared-volume'],
    ]);

    it('writes the warning of a legacy application to the deployment log', function (string $parsingVersion, int $pullRequestId, string $keptName) {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE, $parsingVersion);
        $keptName = str_replace('{uuid}', $application->uuid, $keptName);
        $preview = $pullRequestId === 0 ? null : externalVolumePreview($application, $pullRequestId);

        [$composeFile, $logEntries] = parseExternalVolumeDeployment($application, $pullRequestId, $preview);

        $serviceName = $pullRequestId === 0 ? 'web' : "web-pr-{$pullRequestId}";
        expect(data_get($composeFile, "services.{$serviceName}.volumes"))->toContain("{$keptName}:/data:ro")
            ->and($logEntries)->toBe([['Warning: '.legacyParserExternalVolumeWarning($keptName), 'stderr']]);
    })->with([
        'v2 production' => ['2', 0, '{uuid}-shared-data'],
        'v2 preview' => ['2', 42, '{uuid}-shared-data-pr-42'],
        'v1 preview' => ['1', 42, 'shared-data-pr-42'],
    ]);

    it('writes no warning to the deployment log for a production deployment of a parser version 1 application', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE, '1');

        [$composeFile, $logEntries] = parseExternalVolumeDeployment($application);

        expect(data_get($composeFile, 'services.web.volumes'))->toContain('shared-data:/data:ro')
            ->and($logEntries)->toBe([]);
    });

    it('does not reject a variable in the external volume name of a legacy application', function () {
        $compose = "services:\n  web:\n    image: nginx\n    volumes:\n      - 'shared-data:/data'\nvolumes:\n  shared-data:\n    external: true\n    name: \${SHARED_VOLUME}\n";
        $application = externalVolumeApplication($compose, '2');
        $uuid = $application->uuid;

        $parsed = parseDockerComposeFile($application)->toArray();

        // The variable is not validated, so the warning does not show it.
        expect($parsed['services']['web']['volumes'])->toContain("{$uuid}-shared-data:/data")
            ->and($application->composeVolumeWarnings())->toBe([legacyParserExternalVolumeWarning("{$uuid}-shared-data")]);
    });

    it('uses the external volume as written after a legacy application is cloned', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE, '2');
        loginExternalVolumeOwner();

        $clone = clone_application($application, test()->destination, ['environment_id' => test()->environment->id])->fresh();

        $parsed = $clone->parse()->toArray();
        expect($clone->compose_parsing_version)->toBe('5')
            ->and($parsed['services']['web']['volumes'])->toContain('shared-data:/data:ro')
            ->and($clone->composeVolumeWarnings())->toBe([]);
    });
});

function variableExternalVolumeCompose(string $name, bool $oldSyntax = false): string
{
    $declaration = $oldSyntax ? "    external:\n      name: '{$name}'\n" : "    external: true\n    name: '{$name}'\n";

    return "services:\n  web:\n    image: nginx\n    volumes:\n      - 'shared-data:/data'\nvolumes:\n  shared-data:\n{$declaration}";
}

dataset('external volume names with a variable', [
    'variable' => ['${SHARED_VOLUME}', false],
    'variable with a default' => ['${SHARED_VOLUME:-shared}', false],
    'variable with text around it' => ['stack-${STACK}_data', false],
    'two variables' => ['${PROJECT}-${ENVIRONMENT}', false],
    'old syntax' => ['$SHARED_VOLUME', true],
]);

describe('validation', function () {
    it('accepts an external volume name with a variable and leaves it to Docker Compose', function (string $name, bool $oldSyntax) {
        $compose = variableExternalVolumeCompose($name, $oldSyntax);
        $declaration = $oldSyntax ? ['external' => ['name' => $name]] : ['external' => true, 'name' => $name];
        validateDockerComposeForInjection($compose);

        $application = applicationParser(externalVolumeApplication($compose))->toArray();
        $service = serviceParser(externalVolumeService($compose))->toArray();

        expect($application['services']['web']['volumes'])->toBe(['shared-data:/data'])
            ->and($application['volumes'])->toBe(['shared-data' => $declaration])
            ->and($service['services']['web']['volumes'])->toBe(['shared-data:/data'])
            ->and($service['volumes'])->toBe(['shared-data' => $declaration])
            ->and(persistentVolumeNames())->toBe([]);
    })->with('external volume names with a variable');

    it('keeps the old volume of an existing application whose external volume name has a variable', function (string $name, bool $oldSyntax) {
        $application = externalVolumeApplication(variableExternalVolumeCompose($name, $oldSyntax));
        $uuid = $application->uuid;
        legacyExternalVolumeRow($application, "{$uuid}_shared-data");

        $parsed = applicationParser($application)->toArray();

        expect($parsed['services']['web']['volumes'])->toBe(["{$uuid}_shared-data:/data"])
            ->and($parsed['volumes']["{$uuid}_shared-data"])->toBe(['name' => "{$uuid}_shared-data"])
            ->and($application->composeVolumeWarnings())->toBe([legacyExternalVolumeWarning("{$uuid}_shared-data", $name)]);
    })->with('external volume names with a variable');

    it('keeps the old volume of an existing service whose external volume name has a variable', function (string $name, bool $oldSyntax) {
        $compose = variableExternalVolumeCompose($name, $oldSyntax);
        $service = externalVolumeService($compose);
        $uuid = $service->uuid;
        legacyExternalVolumeRow(legacyExternalVolumeServiceResource($service, 'web', $compose), "{$uuid}_shared-data");

        $parsed = serviceParser($service)->toArray();

        expect($parsed['services']['web']['volumes'])->toBe(["{$uuid}_shared-data:/data"])
            ->and($parsed['volumes']["{$uuid}_shared-data"])->toBe(['name' => "{$uuid}_shared-data"])
            ->and($service->composeVolumeWarnings())->toBe([legacyExternalVolumeWarning("{$uuid}_shared-data", $name)]);
    })->with('external volume names with a variable');

    it('saves a service Compose file whose external volume name has a variable', function () {
        $compose = variableExternalVolumeCompose('${SHARED_VOLUME}');
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $owner = User::factory()->create();
        $team = $service->environment->project->team;
        $team->members()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner);
        session(['currentTeam' => $team]);

        Livewire::test(StackForm::class, ['service' => $service])
            ->set('dockerComposeRaw', $compose)
            ->call('submit')
            ->assertDispatched('success');

        expect($service->fresh()->docker_compose_raw)->toBe($compose);
    });

    it('keeps the old behavior of a legacy Compose application whose external volume name has a variable', function (string $name, bool $oldSyntax) {
        $application = externalVolumeApplication(variableExternalVolumeCompose($name, $oldSyntax), '2');

        $parsed = parseDockerComposeFile($application)->toArray();

        expect($parsed['services']['web']['volumes'])->toBe(["{$application->uuid}-shared-data:/data"]);
    })->with('external volume names with a variable');

    it('rejects an external volume name with an unsafe or unsupported variable', function (string $name) {
        $compose = variableExternalVolumeCompose($name);
        $application = externalVolumeApplication($compose);

        expect(fn () => validateDockerComposeForInjection($compose))
            ->toThrow(Exception::class, 'Invalid external Docker Compose volume shared-data')
            ->and(fn () => applicationParser($application))
            ->toThrow(Exception::class, 'Invalid external Docker Compose volume shared-data');
    })->with([
        'command after a variable' => ['${SHARED_VOLUME}; rm -rf /'],
        'command substitution' => ['$(id)'],
        'space' => ['shared ${VOLUME}'],
        'dollar sign alone' => ['shared-$'],
        'escaped dollar sign' => ['$$SHARED_VOLUME'],
        'required variable syntax' => ['${SHARED_VOLUME:?required}'],
        'default with a space' => ['${SHARED_VOLUME:-a b}'],
    ]);

    it('rejects a variable in the key of an external volume', function () {
        $compose = "services:\n  web:\n    image: nginx\nvolumes:\n  \${SHARED_VOLUME}:\n    external: true\n";

        expect(fn () => validateDockerComposeForInjection($compose))
            ->toThrow(Exception::class, 'Invalid external Docker Compose volume. Volume names must start with an alphanumeric character');
    });

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

describe('docker volume names', function () {
    it('lists the Docker volumes that a compose file declares as external', function () {
        expect(composeExternalVolumeDockerNames(EXTERNAL_VOLUME_SHORT_COMPOSE))->toBe(['shared-data'])
            ->and(composeExternalVolumeDockerNames(EXTERNAL_VOLUME_LONG_COMPOSE))->toBe(['existing-shared-volume'])
            ->and(composeExternalVolumeDockerNames(EXTERNAL_VOLUME_OLD_SYNTAX_COMPOSE))->toBe(['legacy-shared-volume'])
            ->and(composeExternalVolumeDockerNames(EXTERNAL_VOLUME_NFS_COMPOSE))->toBe([])
            ->and(composeExternalVolumeDockerNames("services:\n  web: [\n"))->toBe([])
            ->and(composeExternalVolumeDockerNames(null))->toBe([]);
    });
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
        // --project-directory, not cd: a non-root SSH user cannot enter the directory on the Coolify host.
        expect($all)->toContain("docker compose --project-directory {$application->dirOnServer()} down -v")
            ->not->toContain("cd {$application->dirOnServer()}")
            ->not->toContain('docker volume rm')
            ->and($application->docker_compose)->toContain("shared-data:\n    external: true");
    });

    it('removes only the old prefixed volume when a service with an old storage entry is deleted with its volumes', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $service->uuid;
        legacyExternalVolumeRow(legacyExternalVolumeServiceResource($service, 'web'), "{$uuid}_shared-data");
        serviceParser($service);
        $service->delete();
        $commands = [];
        fakeExternalVolumeServer($commands);

        (new DeleteResourceJob($service))->handle();

        $all = implode("\n", $commands);
        expect($all)->toContain("docker volume rm -f '{$uuid}_shared-data'")
            ->toContain("docker volume rm -f '{$uuid}_app-data'")
            ->not->toContain("'shared-data'");
    });

    it('lets the storage list delete the old storage entry without removing the external volume', function (bool $deleteDockerVolume) {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $application->uuid;
        $oldVolume = legacyExternalVolumeRow($application, "{$uuid}_shared-data");
        applicationParser($application);
        loginExternalVolumeOwner();
        $commands = [];
        fakeExternalVolumeServer($commands);

        Livewire::test(All::class, ['resource' => $application->fresh()])
            ->assertSee('Replaces the external volume')
            ->call('delete', $oldVolume->id, 'password', $deleteDockerVolume ? ['deleteDockerVolume'] : [])
            ->assertHasNoErrors()
            ->assertReturned(true);

        $volumeCommands = collect($commands)->filter(fn (string $command): bool => str_contains($command, 'docker volume'))->values();
        expect(LocalPersistentVolume::find($oldVolume->id))->toBeNull()
            ->and($volumeCommands)->toHaveCount($deleteDockerVolume ? 1 : 0)
            ->and($volumeCommands->implode("\n"))->not->toContain("'shared-data'");
        if ($deleteDockerVolume) {
            expect($volumeCommands->first())->toContain("docker volume rm -f '{$uuid}_shared-data'");
        }

        $compose = applicationParser($application)->toArray();
        expect($compose['services']['web']['volumes'])->toContain('shared-data:/data:ro')
            ->and($application->composeVolumeWarnings())->toBe([]);
    })->with([
        'keep the Docker volume' => [false],
        'delete the old Docker volume' => [true],
    ]);

    it('does not let the storage list delete a storage entry of a volume that is not external', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        applicationParser($application);
        $volume = LocalPersistentVolume::query()->where('name', "{$application->uuid}_app-data")->firstOrFail();
        loginExternalVolumeOwner();

        Livewire::test(All::class, ['resource' => $application->fresh()])
            ->assertDontSee('Replaces the external volume')
            ->call('delete', $volume->id, 'password', [])
            ->assertHasNoErrors()
            ->assertReturned(false);

        expect(LocalPersistentVolume::find($volume->id))->not->toBeNull();
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

    it('removes the old prefixed preview volume, but not the external volume, when a preview is deleted', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $application->uuid;
        $preview = externalVolumePreview($application);
        legacyExternalVolumeRow($application, "{$uuid}_shared-data-pr-42");
        $commands = [];
        fakeExternalVolumeServer($commands);

        $preview->forceDelete();

        $volumeCommands = collect($commands)->filter(fn (string $command): bool => str_contains($command, 'docker volume'))->implode("\n");
        expect($volumeCommands)->toContain("docker volume rm -f '{$uuid}_shared-data-pr-42'")
            ->not->toContain("'shared-data'");
    });
});

describe('warnings', function () {
    it('writes the warning to the deployment log after the parse', function (int $pullRequestId, string $oldName, string $expectedVolume) {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $application->uuid;
        $preview = $pullRequestId === 0 ? null : externalVolumePreview($application, $pullRequestId);
        legacyExternalVolumeRow($application, str_replace('{uuid}', $uuid, $oldName));

        [$composeFile, $logEntries] = parseExternalVolumeDeployment($application, $pullRequestId, $preview);

        $serviceName = $pullRequestId === 0 ? 'web' : "web-pr-{$pullRequestId}";
        expect(data_get($composeFile, "services.{$serviceName}.volumes"))->toContain(str_replace('{uuid}', $uuid, $expectedVolume))
            ->and($logEntries)->toBe([['Warning: '.legacyExternalVolumeWarning(str_replace('{uuid}', $uuid, $oldName)), 'stderr']]);
    })->with([
        'production' => [0, '{uuid}_shared-data', '{uuid}_shared-data:/data:ro'],
        'preview' => [42, '{uuid}_shared-data-pr-42', '{uuid}_shared-data-pr-42:/data:ro'],
    ]);

    it('writes no warning to the deployment log when the external volume is used as written', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);

        [$composeFile, $logEntries] = parseExternalVolumeDeployment($application);

        expect(data_get($composeFile, 'services.web.volumes'))->toContain('shared-data:/data:ro')
            ->and($logEntries)->toBe([]);
    });

    it('shows the warning before docker compose up when a service starts', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $service->uuid;
        legacyExternalVolumeRow(legacyExternalVolumeServiceResource($service, 'web'), "{$uuid}_shared-data");

        $command = RemoteProcessCommand::read(StartService::run($service->fresh()));

        $echo = 'echo '.escapeshellarg('Warning: '.legacyExternalVolumeWarning("{$uuid}_shared-data"));
        expect($command)->toContain($echo)
            ->and(strpos($command, $echo))->toBeLessThan(strpos($command, ' up -d'));
    });

    it('shows the warning before docker compose up when a service application is deployed', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $uuid = $service->uuid;
        $serviceApplication = legacyExternalVolumeServiceResource($service, 'web');
        legacyExternalVolumeRow($serviceApplication, "{$uuid}_shared-data");

        $command = RemoteProcessCommand::read(DeployServiceApplication::run($serviceApplication->fresh()));

        $echo = 'echo '.escapeshellarg('Warning: '.legacyExternalVolumeWarning("{$uuid}_shared-data"));
        expect($command)->toContain($echo)
            ->and(strpos($command, $echo))->toBeLessThan(strpos($command, ' up -d'));
    });

    it('keeps the warning line unchanged for a server with a non-root user', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_LONG_COMPOSE);
        $uuid = $service->uuid;
        legacyExternalVolumeRow(legacyExternalVolumeServiceResource($service, 'web'), "{$uuid}_shared-data");
        serviceParser($service);
        $service->server->update(['user' => 'ubuntu']);

        $commands = StartService::composeVolumeWarningCommands($service);

        expect($commands)->toBe(['echo '.escapeshellarg('Warning: '.legacyExternalVolumeWarning("{$uuid}_shared-data", 'existing-shared-volume'))])
            ->and(parseCommandsByLineForSudo(collect($commands), $service->server->fresh()))->toBe($commands);
    });

    it('shows no warning when a service starts with the external volume as written', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE);

        $command = RemoteProcessCommand::read(StartService::run($service->fresh()));

        expect($command)->not->toContain('declared as external');
    });
});

describe('storage page', function () {
    it('lists the external volumes of a Compose application as read-only', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_LONG_COMPOSE);
        applicationParser($application);
        loginExternalVolumeOwner();

        $html = Livewire::test(StoragePage::class, ['resource' => $application->fresh()])->html();
        $section = externalVolumesSection($html, $application->uuid);

        expect($section)->toContain('External volumes')
            ->toContain('Managed outside Coolify. Coolify never removes these volumes.')
            ->toContain('shared-data')
            ->toContain('existing-shared-volume')
            ->toContain('/data')
            ->toContain('web')
            ->not->toContain('app-data')
            ->not->toContain('delete(')
            ->not->toContain('Delete')
            ->not->toContain('Backup')
            ->not->toContain('wire:submit')
            ->not->toContain('<input');
    });

    it('lists the external volumes of a service resource as read-only', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE);
        serviceParser($service);
        $serviceApplication = $service->applications()->where('name', 'web')->firstOrFail();
        loginExternalVolumeOwner();

        $html = Livewire::test(StoragePage::class, ['resource' => $serviceApplication])->html();
        $section = externalVolumesSection($html, $serviceApplication->uuid);

        expect($section)->toContain('External volumes')
            ->toContain('shared-data')
            ->toContain('/data')
            ->toContain('web')
            ->not->toContain('app-data')
            ->not->toContain('delete(')
            ->not->toContain('Backup');
    });

    it('shows each mount path of an external volume', function () {
        $compose = "services:\n  web:\n    image: nginx\n    volumes:\n      - 'shared-data:/data'\n      - type: volume\n        source: shared-data\n        target: /backup\n        read_only: true\nvolumes:\n  shared-data:\n    external: true\n";
        $application = externalVolumeApplication($compose);
        loginExternalVolumeOwner();

        $component = Livewire::test(StoragePage::class, ['resource' => $application]);

        expect($component->get('externalVolumes'))->toBe([[
            'key' => 'shared-data',
            'dockerName' => 'shared-data',
            'service' => 'web',
            'mountPaths' => ['/data', '/backup'],
        ]]);
    });

    it('does not list an external volume while the old storage entry is kept', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        legacyExternalVolumeRow($application, "{$application->uuid}_shared-data");
        loginExternalVolumeOwner();

        $html = Livewire::test(StoragePage::class, ['resource' => $application->fresh()])->html();

        expect(externalVolumesSection($html, $application->uuid))->toBe('');
    });

    it('does not list an external volume of a service resource while its old storage entry is kept', function () {
        $service = externalVolumeService(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $serviceApplication = legacyExternalVolumeServiceResource($service, 'web');
        legacyExternalVolumeRow($serviceApplication, "{$service->uuid}_shared-data");
        loginExternalVolumeOwner();

        $html = Livewire::test(StoragePage::class, ['resource' => $serviceApplication->fresh()])->html();

        expect(externalVolumesSection($html, $serviceApplication->uuid))->toBe('');
    });

    it('lists the external volume after the old storage entry is deleted', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $oldVolume = legacyExternalVolumeRow($application, "{$application->uuid}_shared-data");
        loginExternalVolumeOwner();
        $component = Livewire::test(StoragePage::class, ['resource' => $application->fresh()]);
        expect($component->get('externalVolumes'))->toBe([]);

        $oldVolume->delete();
        $component->dispatch('storageCountsChanged');

        expect(collect($component->get('externalVolumes'))->pluck('key')->all())->toBe(['shared-data']);
    });

    it('does not list external volumes of a legacy Compose application', function (string $parsingVersion) {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE, $parsingVersion);
        loginExternalVolumeOwner();

        $html = Livewire::test(StoragePage::class, ['resource' => $application])->html();

        expect(externalVolumesSection($html, $application->uuid))->toBe('');
    })->with(['1', '2']);

    it('lets a team member see the external volumes without actions', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $team = test()->environment->project->team;
        $member = User::factory()->create();
        $team->members()->attach($member->id, ['role' => 'member']);
        test()->withoutVite()->actingAs($member);
        session(['currentTeam' => $team]);

        $section = externalVolumesSection(Livewire::test(StoragePage::class, ['resource' => $application])->html(), $application->uuid);

        expect($section)->toContain('shared-data')
            ->not->toContain('delete(')
            ->not->toContain('Backup');
    });

    it('does not show external volumes to a user of another team', function () {
        $application = externalVolumeApplication(EXTERNAL_VOLUME_SHORT_COMPOSE);
        $otherTeam = Team::factory()->create();
        $otherUser = User::factory()->create();
        $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
        test()->withoutVite()->actingAs($otherUser);
        session(['currentTeam' => $otherTeam]);

        $component = Livewire::test(StoragePage::class, ['resource' => $application]);

        expect($component->get('externalVolumes'))->toBe([])
            ->and(externalVolumesSection($component->html(), $application->uuid))->toBe('');

        test()->get(route('project.application.persistent-storage', [
            'project_uuid' => test()->environment->project->uuid,
            'environment_uuid' => test()->environment->uuid,
            'application_uuid' => $application->uuid,
        ]))->assertNotFound();
    });
});
