<?php

use App\Jobs\ServerStorageSaveJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\LocalFileVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

const TWO_FILE_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - ./wg_config.conf:/app/ps/wg0.conf
      - ./override_trays.json:/app/ps/override_tray.json
YAML;

const DATA_DIR_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - ./data:/app/data
YAML;

beforeEach(function () {
    Bus::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->destination = $destination;
    $this->environment = $environment;
});

function makeComposeApplication(string $dockerComposeRaw): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $dockerComposeRaw,
    ]);
}

/**
 * @return array{0: Service, 1: ServiceApplication}
 */
function makeComposeService(string $dockerComposeRaw): array
{
    $service = Service::factory()->create([
        'environment_id' => test()->environment->id,
        'server_id' => test()->destination->server_id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'docker_compose_raw' => $dockerComposeRaw,
    ]);

    $serviceApplication = ServiceApplication::create([
        'name' => 'app',
        'service_id' => $service->id,
    ]);

    return [$service, $serviceApplication];
}

function seedFileVolume($resource, string $baseDir, string $fileName, string $mountPath, string $content): void
{
    LocalFileVolume::create([
        'fs_path' => "{$baseDir}/{$fileName}",
        'mount_path' => $mountPath,
        'content' => $content,
        'is_directory' => false,
        'resource_id' => $resource->id,
        'resource_type' => $resource->getMorphClass(),
    ]);
}

it('preserves comments in a service source Compose when parsing', function () {
    $source = "# Service notes\nservices:\n  app:\n    # Keep this image note\n    image: nginx:latest # pinned by operator\n";
    [$service] = makeComposeService($source);

    serviceParser($service);

    expect($service->fresh()->docker_compose_raw)->toBe($source)
        ->and($service->fresh()->docker_compose)->toContain('services:');
});

it('preserves comments in an application source Compose when parsing', function () {
    $source = "# Application notes\nservices:\n  app:\n    # Keep this image note\n    image: nginx:latest # pinned by operator\n";
    $application = makeComposeApplication($source);

    applicationParser($application);

    expect($application->fresh()->docker_compose_raw)->toBe($source)
        ->and($application->fresh()->docker_compose)->toContain('services:');
});

it('removes one-time volume fields without losing service source comments', function () {
    $source = "# Service note\nservices:\n  app:\n    image: nginx:latest # Image note\n    command: |\n      volumes:\n        - type: bind\n          content: keep-this-command\n    volumes:\n      # Volume note\n      - type: bind\n        source: ./config.txt\n        target: /app/config.txt\n        content: |\n          first line\n          second line\n        isDirectory: false\n        # After content\n";
    [$service] = makeComposeService($source);

    serviceParser($service);

    expect($service->fresh()->docker_compose_raw)->toBe("# Service note\nservices:\n  app:\n    image: nginx:latest # Image note\n    command: |\n      volumes:\n        - type: bind\n          content: keep-this-command\n    volumes:\n      # Volume note\n      - type: bind\n        source: ./config.txt\n        target: /app/config.txt\n        # After content\n");
});

it('removes one-time volume fields without losing application source comments', function () {
    $source = "# Application note\nservices:\n  app:\n    image: nginx:latest # Image note\n    volumes:\n      - type: bind\n        source: ./config.txt\n        target: /app/config.txt\n        content: initial\n        is_directory: false\n        # After content\n";
    $application = makeComposeApplication($source);

    applicationParser($application);

    expect($application->fresh()->docker_compose_raw)->toBe("# Application note\nservices:\n  app:\n    image: nginx:latest # Image note\n    volumes:\n      - type: bind\n        source: ./config.txt\n        target: /app/config.txt\n        # After content\n");
});

it('keeps valid Compose when one-time fields use flow syntax', function () {
    $source = "# Flow-style volume\nservices:\n  app:\n    image: nginx:latest\n    volumes: [{type: bind, source: ./config.txt, target: /app/config.txt, content: initial}]\n";
    $cleanedYaml = Yaml::parse($source);
    unset($cleanedYaml['services']['app']['volumes'][0]['content']);

    $cleanedSource = removeComposeVolumeFieldsPreservingComments($source, $cleanedYaml, ['content']);

    expect(Yaml::parse($cleanedSource))->toBe($cleanedYaml)
        ->and($cleanedSource)->not->toContain('content: initial');
});

it('preserves existing application file volume content when reparsing compose bind mounts', function () {
    $application = makeComposeApplication(TWO_FILE_COMPOSE);
    $baseDir = application_configuration_dir()."/{$application->uuid}";

    seedFileVolume($application, $baseDir, 'wg_config.conf', '/app/ps/wg0.conf', 'test-conf');
    seedFileVolume($application, $baseDir, 'override_trays.json', '/app/ps/override_tray.json', '0');

    applicationParser($application);

    $fileVolume = $application->fileStorages()->where('mount_path', '/app/ps/override_tray.json')->first();

    expect($fileVolume->content)->toBe('0')
        ->and($fileVolume->is_directory)->toBeFalse();
});

it('keeps existing application file volumes as files when content is empty', function () {
    $application = makeComposeApplication(TWO_FILE_COMPOSE);
    $baseDir = application_configuration_dir()."/{$application->uuid}";

    seedFileVolume($application, $baseDir, 'wg_config.conf', '/app/ps/wg0.conf', 'test-conf');
    seedFileVolume($application, $baseDir, 'override_trays.json', '/app/ps/override_tray.json', '');

    applicationParser($application);

    $fileVolume = $application->fileStorages()->where('mount_path', '/app/ps/override_tray.json')->first();

    expect($fileVolume->content)->toBe('')
        ->and($fileVolume->is_directory)->toBeFalse();
});

it('defaults new application bind mounts to directories', function () {
    $application = makeComposeApplication(DATA_DIR_COMPOSE);

    applicationParser($application);

    $fileVolume = $application->fileStorages()->where('mount_path', '/app/data')->first();

    expect($fileVolume->content)->toBeNull()
        ->and($fileVolume->is_directory)->toBeTrue();
});

it('preserves existing service file volume content when reparsing compose bind mounts', function () {
    [$service, $serviceApplication] = makeComposeService(TWO_FILE_COMPOSE);
    $baseDir = service_configuration_dir()."/{$service->uuid}";

    seedFileVolume($serviceApplication, $baseDir, 'wg_config.conf', '/app/ps/wg0.conf', 'test-conf');
    seedFileVolume($serviceApplication, $baseDir, 'override_trays.json', '/app/ps/override_tray.json', '0');

    serviceParser($service);

    $fileVolume = $serviceApplication->fileStorages()->where('mount_path', '/app/ps/override_tray.json')->first();

    expect($fileVolume->content)->toBe('0')
        ->and($fileVolume->is_directory)->toBeFalse();
});

it('keeps existing service file volumes as files when content is empty', function () {
    [$service, $serviceApplication] = makeComposeService(TWO_FILE_COMPOSE);
    $baseDir = service_configuration_dir()."/{$service->uuid}";

    seedFileVolume($serviceApplication, $baseDir, 'wg_config.conf', '/app/ps/wg0.conf', 'test-conf');
    seedFileVolume($serviceApplication, $baseDir, 'override_trays.json', '/app/ps/override_tray.json', '');

    serviceParser($service);

    $fileVolume = $serviceApplication->fileStorages()->where('mount_path', '/app/ps/override_tray.json')->first();

    expect($fileVolume->content)->toBe('')
        ->and($fileVolume->is_directory)->toBeFalse();
});

it('defaults new service bind mounts to directories', function () {
    [$service, $serviceApplication] = makeComposeService(DATA_DIR_COMPOSE);

    serviceParser($service);

    $fileVolume = $serviceApplication->fileStorages()->where('mount_path', '/app/data')->first();

    expect($fileVolume->content)->toBeNull()
        ->and($fileVolume->is_directory)->toBeTrue();
});

it('queues a new application mount once without loading its service relation', function () {
    $application = makeComposeApplication(DATA_DIR_COMPOSE);

    applicationParser($application);
    applicationParser($application);

    Bus::assertDispatchedTimes(ServerStorageSaveJob::class, 1);
    Bus::assertDispatched(ServerStorageSaveJob::class, function (ServerStorageSaveJob $job): bool {
        return ! $job->localFileVolume->relationLoaded('service') && $job->afterCommit === true;
    });
});

it('queues a new service mount once without loading its service relation', function () {
    [$service] = makeComposeService(DATA_DIR_COMPOSE);

    serviceParser($service);
    serviceParser($service);

    Bus::assertDispatchedTimes(ServerStorageSaveJob::class, 1);
    Bus::assertDispatched(ServerStorageSaveJob::class, function (ServerStorageSaveJob $job): bool {
        return ! $job->localFileVolume->relationLoaded('service') && $job->afterCommit === true;
    });
});
