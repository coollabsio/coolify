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

it('preserves existing application file volume content when reparsing compose bind mounts', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - ./wg_config.conf:/app/ps/wg0.conf
      - ./override_trays.json:/app/ps/override_tray.json
YAML,
    ]);

    LocalFileVolume::create([
        'fs_path' => application_configuration_dir()."/{$application->uuid}/wg_config.conf",
        'mount_path' => '/app/ps/wg0.conf',
        'content' => 'test-conf',
        'is_directory' => false,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);

    LocalFileVolume::create([
        'fs_path' => application_configuration_dir()."/{$application->uuid}/override_trays.json",
        'mount_path' => '/app/ps/override_tray.json',
        'content' => '0',
        'is_directory' => false,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);

    applicationParser($application);

    $fileVolume = $application->fileStorages()->where('mount_path', '/app/ps/override_tray.json')->first();

    expect($fileVolume->content)->toBe('0')
        ->and($fileVolume->is_directory)->toBeFalse();
});

it('keeps existing application file volumes as files when content is empty', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - ./wg_config.conf:/app/ps/wg0.conf
      - ./override_trays.json:/app/ps/override_tray.json
YAML,
    ]);

    LocalFileVolume::create([
        'fs_path' => application_configuration_dir()."/{$application->uuid}/wg_config.conf",
        'mount_path' => '/app/ps/wg0.conf',
        'content' => 'test-conf',
        'is_directory' => false,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);

    LocalFileVolume::create([
        'fs_path' => application_configuration_dir()."/{$application->uuid}/override_trays.json",
        'mount_path' => '/app/ps/override_tray.json',
        'content' => '',
        'is_directory' => false,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);

    applicationParser($application);

    $fileVolume = $application->fileStorages()->where('mount_path', '/app/ps/override_tray.json')->first();

    expect($fileVolume->content)->toBe('')
        ->and($fileVolume->is_directory)->toBeFalse();
});

it('defaults new application bind mounts to directories', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - ./data:/app/data
YAML,
    ]);

    applicationParser($application);

    $fileVolume = $application->fileStorages()->where('mount_path', '/app/data')->first();

    expect($fileVolume->content)->toBeNull()
        ->and($fileVolume->is_directory)->toBeTrue();
});

it('preserves existing service file volume content when reparsing compose bind mounts', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->destination->server_id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - ./wg_config.conf:/app/ps/wg0.conf
      - ./override_trays.json:/app/ps/override_tray.json
YAML,
    ]);

    $serviceApplication = ServiceApplication::create([
        'name' => 'app',
        'service_id' => $service->id,
    ]);

    LocalFileVolume::create([
        'fs_path' => service_configuration_dir()."/{$service->uuid}/wg_config.conf",
        'mount_path' => '/app/ps/wg0.conf',
        'content' => 'test-conf',
        'is_directory' => false,
        'resource_id' => $serviceApplication->id,
        'resource_type' => $serviceApplication->getMorphClass(),
    ]);

    LocalFileVolume::create([
        'fs_path' => service_configuration_dir()."/{$service->uuid}/override_trays.json",
        'mount_path' => '/app/ps/override_tray.json',
        'content' => '0',
        'is_directory' => false,
        'resource_id' => $serviceApplication->id,
        'resource_type' => $serviceApplication->getMorphClass(),
    ]);

    serviceParser($service);

    $fileVolume = $serviceApplication->fileStorages()->where('mount_path', '/app/ps/override_tray.json')->first();

    expect($fileVolume->content)->toBe('0')
        ->and($fileVolume->is_directory)->toBeFalse();
});

it('keeps existing service file volumes as files when content is empty', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->destination->server_id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - ./wg_config.conf:/app/ps/wg0.conf
      - ./override_trays.json:/app/ps/override_tray.json
YAML,
    ]);

    $serviceApplication = ServiceApplication::create([
        'name' => 'app',
        'service_id' => $service->id,
    ]);

    LocalFileVolume::create([
        'fs_path' => service_configuration_dir()."/{$service->uuid}/wg_config.conf",
        'mount_path' => '/app/ps/wg0.conf',
        'content' => 'test-conf',
        'is_directory' => false,
        'resource_id' => $serviceApplication->id,
        'resource_type' => $serviceApplication->getMorphClass(),
    ]);

    LocalFileVolume::create([
        'fs_path' => service_configuration_dir()."/{$service->uuid}/override_trays.json",
        'mount_path' => '/app/ps/override_tray.json',
        'content' => '',
        'is_directory' => false,
        'resource_id' => $serviceApplication->id,
        'resource_type' => $serviceApplication->getMorphClass(),
    ]);

    serviceParser($service);

    $fileVolume = $serviceApplication->fileStorages()->where('mount_path', '/app/ps/override_tray.json')->first();

    expect($fileVolume->content)->toBe('')
        ->and($fileVolume->is_directory)->toBeFalse();
});

it('defaults new service bind mounts to directories', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->destination->server_id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - ./data:/app/data
YAML,
    ]);

    serviceParser($service);

    $serviceApplication = ServiceApplication::where('name', 'app')
        ->where('service_id', $service->id)
        ->first();
    $fileVolume = $serviceApplication->fileStorages()->where('mount_path', '/app/data')->first();

    expect($fileVolume->content)->toBeNull()
        ->and($fileVolume->is_directory)->toBeTrue();
});
