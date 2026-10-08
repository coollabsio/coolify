<?php

use App\Actions\Shared\MigrateResourceToDestination;
use App\Jobs\ServerFilesFromServerJob;
use App\Jobs\ServerStorageSaveJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\ComposeBindPathResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $team = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

function regressionComposeApplication(string $compose, array $attributes = []): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
        ...$attributes,
    ]);
}

function regressionComposeService(string $compose): array
{
    $service = Service::factory()->create([
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'docker_compose_raw' => $compose,
    ]);
    $serviceApplication = ServiceApplication::create(['name' => 'app', 'service_id' => $service->id]);

    return [$service, $serviceApplication];
}

function fakeComposeConfig(array $volumes): void
{
    Process::fake(function ($process) use ($volumes) {
        if (str_contains($process->command, 'config --format json')) {
            return Process::result(output: json_encode(['services' => $volumes]));
        }

        return Process::result(output: 'NOK');
    });
}

test('Compose expression sources are host paths only when they contain a slash', function (string $source, bool $isLocal) {
    expect(sourceIsLocal(str($source)))->toBe($isLocal);
})->with([
    ['${DATA:-./data}', true],
    ['${DATA}/suffix', true],
    ['${DB_VOLUME:-dbdata}', false],
    ['${COOLIFY_VOLUME_APP}', false],
    ['$DATA', false],
    ['./data', true],
    ['named', false],
]);

test('relative defaults inside Compose expressions point to the configuration directory', function (string $source, string $expected) {
    expect(replaceLocalSource(str($source), str('/base'))->value())->toBe($expected);
})->with([
    ['${DATA:-./data}', '${DATA:-/base/data}'],
    ['${DATA:-${FALLBACK:-./nested}}/file', '${DATA:-${FALLBACK:-/base/nested}}/file'],
    ['${DATA-~/data}', '${DATA-/base/data}'],
    ['${DATA:+./data}/file', '${DATA:+/base/data}/file'],
    ['${DATA}/file', '${DATA}/file'],
    ['${DATA:?./required}/file', '${DATA:?./required}/file'],
    ['${DATA:-/srv/data}', '${DATA:-/srv/data}'],
    ['./data/', '/base/data'],
]);

test('named volume sources keep their old names', function (string $source, string $expected) {
    expect(composeNamedVolumeSource(str($source))->value())->toBe($expected);
})->with([
    ['${VOLUME_DB_PATH:-db}', 'db'],
    ['${VOLUME_PATH:-}', '${VOLUME_PATH}'],
    ['${COOLIFY_VOLUME_APP}', '${COOLIFY_VOLUME_APP}'],
    ['plain', 'plain'],
]);

test('short volume syntax accepts Compose mount options', function () {
    expect(parseDockerVolumeString('vol:/data:nocopy')['mode']->value())->toBe('nocopy')
        ->and(parseDockerVolumeString('./data:/data:ro,z')['mode']->value())->toBe('ro,z')
        ->and(fn () => parseDockerVolumeString('vol:/data:ro;id'))->toThrow(Exception::class);
});

test('application parser keeps named volumes and default bind locations', function () {
    $application = regressionComposeApplication(<<<'YAML'
services:
  app:
    image: alpine
    volumes:
      - ${DB_VOLUME:-dbdata}:/var/lib/db
      - ${DATA:-./data}:/data
YAML);

    applicationParser($application);

    $volumes = Yaml::parse($application->fresh()->docker_compose)['services']['app']['volumes'];
    $workdir = '/data/coolify/applications/'.$application->uuid;
    expect($volumes[0])->toBe($application->uuid.'_dbdata:/var/lib/db')
        ->and($application->persistentStorages()->where('name', $application->uuid.'_dbdata')->exists())->toBeTrue()
        ->and($application->fileStorages()->count())->toBe(1)
        ->and($application->fileStorages()->first()->fs_path)->toBe('${DATA:-'.$workdir.'/data}');
});

test('service parser keeps a variable-only source as a named volume', function () {
    [$service, $serviceApplication] = regressionComposeService(<<<'YAML'
services:
  app:
    image: ghcr.io/denoland/denokv:latest
    volumes:
      - '${COOLIFY_VOLUME_APP}:/data'
YAML);

    serviceParser($service);

    $volumes = Yaml::parse($service->fresh()->docker_compose)['services']['app']['volumes'];
    expect($volumes[0])->toBe($service->uuid.'_coolify-volume-app:/data')
        ->and($serviceApplication->fileStorages()->count())->toBe(0);
});

test('an existing named volume from an older parse stays mounted', function () {
    $application = regressionComposeApplication(<<<'YAML'
services:
  app:
    image: alpine
    volumes:
      - ${DATA}/files:/files
YAML);
    $oldName = $application->uuid.'_'.Str::slug('${DATA}/files', '-');
    LocalPersistentVolume::create([
        'name' => $oldName,
        'mount_path' => '/files',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);

    applicationParser($application);

    $volumes = Yaml::parse($application->fresh()->docker_compose)['services']['app']['volumes'];
    expect($volumes[0])->toBe($oldName.':/files')
        ->and($application->fileStorages()->count())->toBe(0);
});

test('the parser queues one filesystem sync per resource', function () {
    $application = regressionComposeApplication(<<<'YAML'
services:
  app:
    image: alpine
    volumes:
      - ./one:/one
      - ./two:/two
      - named:/named
YAML);

    applicationParser($application);

    Bus::assertDispatchedTimes(ServerFilesFromServerJob::class, 1);
});

test('one bind source mounted by several services resolves once', function () {
    $config = ['services' => [
        'web' => ['volumes' => [['type' => 'bind', 'source' => '/etc/localtime', 'target' => '/etc/localtime', 'read_only' => true]]],
        'worker' => ['volumes' => [['type' => 'bind', 'source' => '/etc/localtime', 'target' => '/etc/localtime']]],
    ]];

    expect(ComposeBindPathResolver::pathFromConfig($config, null, '/etc/localtime'))->toBe('/etc/localtime');

    $config['services']['worker']['volumes'][0]['source'] = '/srv/other';
    expect(fn () => ComposeBindPathResolver::pathFromConfig($config, null, '/etc/localtime'))->toThrow(RuntimeException::class);
});

test('the resolver supports custom start commands and applications without a finished deployment', function () {
    $application = regressionComposeApplication("services:\n  app:\n    image: alpine\n", [
        'docker_compose_custom_start_command' => 'docker compose up -d',
    ]);
    $volume = LocalFileVolume::create([
        'fs_path' => '${DATA:-/data/coolify/applications/'.$application->uuid.'/data}',
        'mount_path' => '/data',
        'is_directory' => true,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    fakeComposeConfig(['app' => ['volumes' => [['type' => 'bind', 'source' => '/srv/data', 'target' => '/data']]]]);

    expect(ComposeBindPathResolver::resolve($volume))->toBe('/srv/data');
    $workdir = escapeshellarg($application->workdir());
    Process::assertRan(fn ($process) => str_contains($process->command, "--project-directory {$workdir}") && ! str_contains($process->command, '--no-env-resolution'));
});

test('a storage without a variable source keeps its stored host path without docker compose config', function () {
    $application = regressionComposeApplication("services:\n  app:\n    image: alpine\n    volumes:\n      - ./data:/data\n");
    $volume = LocalFileVolume::create([
        'fs_path' => $application->workdir().'/data',
        'mount_path' => '/data',
        'is_directory' => true,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    Process::fake(fn () => Process::result(output: 'NOK'));

    expect($volume->fresh()->initializeOnServer())->toBeNull();

    Process::assertRan(fn ($process) => str_contains($process->command, 'mkdir -p '.escapeshellarg($application->workdir().'/data')));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'config --format json'));
});

test('failed initialization returns the error and keeps the storage pending', function () {
    $application = regressionComposeApplication("services:\n  app:\n    image: alpine\n");
    $volume = LocalFileVolume::create([
        'fs_path' => '${DATA_DIR:-./data}',
        'mount_path' => '/data',
        'is_directory' => true,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    fakeComposeConfig(['app' => ['volumes' => []]]);

    expect($volume->fresh()->pending_initialization)->toBeTrue()
        ->and($volume->fresh()->initializeOnServer())->toContain('does not identify one Compose bind mount')
        ->and($volume->fresh()->pending_initialization)->toBeTrue();
});

test('moving Compose resources marks their storages pending instead of queueing a save', function () {
    $application = regressionComposeApplication("services:\n  app:\n    image: alpine\n");
    [$service, $serviceApplication] = regressionComposeService("services:\n  app:\n    image: alpine\n");
    $storages = collect([$application, $serviceApplication])->map(function ($resource) {
        $storage = LocalFileVolume::create([
            'fs_path' => './data',
            'mount_path' => '/data',
            'is_directory' => true,
            'resource_id' => $resource->id,
            'resource_type' => $resource->getMorphClass(),
        ]);
        $storage->forceFill(['pending_initialization' => false])->saveQuietly();

        return $storage;
    });
    $action = new MigrateResourceToDestination;
    $resave = new ReflectionMethod($action, 'resaveFileStorages');

    $resave->invoke($action, $application);
    $resave->invoke($action, $service);

    Bus::assertNotDispatched(ServerStorageSaveJob::class);
    expect($storages->every(fn (LocalFileVolume $storage) => $storage->fresh()->pending_initialization))->toBeTrue();
});

test('directory backups use the resolved Compose bind source', function () {
    $application = regressionComposeApplication("services:\n  app:\n    image: alpine\n");
    $volume = LocalFileVolume::create([
        'fs_path' => '${DATA:-/data/coolify/applications/'.$application->uuid.'/data}',
        'mount_path' => '/data',
        'is_directory' => true,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    fakeComposeConfig(['app' => ['volumes' => [['type' => 'bind', 'source' => '/srv/data', 'target' => '/data']]]]);
    $backup = new ScheduledVolumeBackup;
    $backup->setRelation('backupable', $volume);

    expect($backup->sourcePath())->toBe('/srv/data');
});

test('the confinement check runs through sudo on non-root servers', function () {
    $this->server->update(['user' => 'ubuntu']);
    $base = '/data/coolify/applications/app';
    Process::fake(fn ($process) => Process::result(
        output: str_contains($process->command, 'sudo bash -c') && str_contains($process->command, 'readlink -f') ? 'OK' : ''
    ));

    LocalFileVolume::assertRemotePathIsConfined($base, "{$base}/data", $this->server->fresh());

    Process::fake(fn () => Process::result(output: 'NOK'));
    expect(fn () => LocalFileVolume::assertRemotePathIsConfined($base, "{$base}/link", $this->server->fresh()))
        ->toThrow(RuntimeException::class);
});

test('a named volume from a variable default is not shown as stale', function () {
    $application = regressionComposeApplication(<<<'YAML'
services:
  app:
    image: alpine
    volumes:
      - ${DB_VOLUME:-dbdata}:/var/lib/db
YAML);

    applicationParser($application);

    expect($application->persistentStorages()->sole()->isDeclaredInCompose())->toBeTrue();
});

test('a new storage adopts an existing host file instead of failing', function () {
    $application = regressionComposeApplication("services:\n  app:\n    image: alpine\n");
    $volume = LocalFileVolume::create([
        'fs_path' => '/etc/localtime',
        'mount_path' => '/etc/localtime',
        'is_directory' => true,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    Process::fake(function ($process) {
        return match (true) {
            str_contains($process->command, 'config --format json') => Process::result(output: json_encode(['services' => ['app' => ['volumes' => [
                ['type' => 'bind', 'source' => '/etc/localtime', 'target' => '/etc/localtime'],
            ]]]])),
            str_contains($process->command, 'test -f') => Process::result(output: 'OK'),
            str_contains($process->command, 'stat -c%s') => Process::result(output: '100'),
            str_contains($process->command, 'head -c') => Process::result(output: "TZif2\0\0binary"),
            default => Process::result(output: 'NOK'),
        };
    });

    expect($volume->fresh()->initializeOnServer())->toBeNull();

    $volume = $volume->fresh();
    expect($volume->pending_initialization)->toBeFalse()
        ->and($volume->is_directory)->toBeFalse()
        ->and($volume->content)->toBe(LocalFileVolume::BINARY_PLACEHOLDER);
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'tee') || str_contains($process->command, 'chmod'));
});

test('placeholder content is never written over a server file', function (string $placeholder) {
    $application = regressionComposeApplication("services:\n  app:\n    image: alpine\n");
    $volume = LocalFileVolume::create([
        'fs_path' => './large.bin',
        'mount_path' => '/large.bin',
        'content' => $placeholder,
        'is_directory' => false,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    Process::fake(function ($process) {
        return match (true) {
            str_contains($process->command, 'config --format json') => Process::result(output: json_encode(['services' => ['app' => ['volumes' => [
                ['type' => 'bind', 'source' => '/srv/large.bin', 'target' => '/large.bin'],
            ]]]])),
            str_contains($process->command, 'test -f') => Process::result(output: 'OK'),
            default => Process::result(output: 'NOK'),
        };
    });

    expect($volume->fresh()->initializeOnServer())->toBeNull();
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'tee'));
})->with([LocalFileVolume::BINARY_PLACEHOLDER, LocalFileVolume::TOO_LARGE_PLACEHOLDER]);
