<?php

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
