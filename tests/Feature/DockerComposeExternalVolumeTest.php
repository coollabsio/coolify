<?php

/**
 * Feature tests for external volumes in Docker Compose files.
 *
 * Volumes declared with `external: true` are created and owned outside of Coolify
 * (`docker volume create`, volume plugins like rclone, or another stack).
 * The parser must keep their name untouched and must not register them as
 * persistent storage, otherwise Coolify creates an empty volume of its own and
 * deletes it together with the resource.
 *
 * Covers GitHub issue #3303.
 */

use App\Models\Application;
use App\Models\Environment;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use phpseclib3\Crypt\EC;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The parser dispatches ServerFilesFromServerJob, which would try to reach the server over SSH.
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $privateKey = PrivateKey::create([
        'name' => 'test-key',
        'private_key' => EC::createKey('Ed25519')->toString('OpenSSH'),
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->uuid(),
    ]);

    $this->makeApplication = function (string $dockerCompose): Application {
        return Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => $dockerCompose,
        ]);
    };
});

test('applicationParser keeps external volumes untouched', function () {
    $application = ($this->makeApplication)(<<<'YAML'
services:
  filebrowser:
    image: filebrowser/filebrowser:latest
    volumes:
      - 'rclone-http:/srv/httptesting'
volumes:
  rclone-http:
    external: true
YAML);

    applicationParser($application);

    $compose = Yaml::parse($application->refresh()->docker_compose);

    expect(data_get($compose, 'services.filebrowser.volumes'))->toContain('rclone-http:/srv/httptesting');
    expect(data_get($compose, 'volumes.rclone-http.external'))->toBeTrue();
    expect(array_keys(data_get($compose, 'volumes', [])))->not->toContain("{$application->uuid}_rclone-http");
    expect(LocalPersistentVolume::count())->toBe(0);
});

test('applicationParser keeps external volumes declared with the long syntax untouched', function () {
    $application = ($this->makeApplication)(<<<'YAML'
services:
  filebrowser:
    image: filebrowser/filebrowser:latest
    volumes:
      - type: volume
        source: shared-data
        target: /srv/data
volumes:
  shared-data:
    external: true
    name: my-existing-volume
YAML);

    applicationParser($application);

    $compose = Yaml::parse($application->refresh()->docker_compose);

    expect(data_get($compose, 'services.filebrowser.volumes.0.source'))->toBe('shared-data');
    expect(data_get($compose, 'volumes.shared-data.name'))->toBe('my-existing-volume');
    expect(LocalPersistentVolume::count())->toBe(0);
});

test('applicationParser keeps external volumes that also define driver options', function () {
    $application = ($this->makeApplication)(<<<'YAML'
services:
  filebrowser:
    image: filebrowser/filebrowser:latest
    volumes:
      - 'media:/srv/media'
      - 'app-data:/srv/data'
volumes:
  media:
    external: true
    driver_opts:
      type: nfs
      o: addr=10.0.0.1,rw
      device: ':/exports/media'
  app-data:
YAML);

    applicationParser($application);

    $compose = Yaml::parse($application->refresh()->docker_compose);

    expect(data_get($compose, 'services.filebrowser.volumes'))->toContain('media:/srv/media');
    expect(data_get($compose, 'services.filebrowser.volumes'))->toContain("{$application->uuid}_app-data:/srv/data");
    expect(data_get($compose, 'volumes.media.external'))->toBeTrue();
});

test('applicationParser still prefixes regular named volumes', function () {
    $application = ($this->makeApplication)(<<<'YAML'
services:
  filebrowser:
    image: filebrowser/filebrowser:latest
    volumes:
      - 'app-data:/srv/data'
volumes:
  app-data:
YAML);

    applicationParser($application);

    $compose = Yaml::parse($application->refresh()->docker_compose);
    $expectedName = "{$application->uuid}_app-data";

    expect(data_get($compose, 'services.filebrowser.volumes'))->toContain("{$expectedName}:/srv/data");
    expect(data_get($compose, "volumes.{$expectedName}.name"))->toBe($expectedName);
    expect(LocalPersistentVolume::where('name', $expectedName)->count())->toBe(1);
});

test('serviceParser keeps external volumes untouched', function () {
    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'docker_compose_raw' => <<<'YAML'
services:
  filebrowser:
    image: filebrowser/filebrowser:latest
    volumes:
      - 'rclone-http:/srv/httptesting'
volumes:
  rclone-http:
    external: true
YAML,
    ]);

    serviceParser($service);

    $compose = Yaml::parse($service->refresh()->docker_compose);

    expect(data_get($compose, 'services.filebrowser.volumes'))->toContain('rclone-http:/srv/httptesting');
    expect(data_get($compose, 'volumes.rclone-http.external'))->toBeTrue();
    expect(array_keys(data_get($compose, 'volumes', [])))->not->toContain("{$service->uuid}_rclone-http");
    expect(LocalPersistentVolume::count())->toBe(0);
});

test('a storage record left over from an external volume is no longer reported as compose managed', function () {
    $application = ($this->makeApplication)(<<<'YAML'
services:
  filebrowser:
    image: filebrowser/filebrowser:latest
    volumes:
      - 'rclone-http:/srv/httptesting'
      - 'app-data:/srv/data'
volumes:
  rclone-http:
    external: true
  app-data:
YAML);

    $externalLeftover = LocalPersistentVolume::create([
        'name' => "{$application->uuid}_rclone-http",
        'mount_path' => '/srv/httptesting',
        'resource_id' => $application->id,
        'resource_type' => Application::class,
    ]);

    $managed = LocalPersistentVolume::create([
        'name' => "{$application->uuid}_app-data",
        'mount_path' => '/srv/data',
        'resource_id' => $application->id,
        'resource_type' => Application::class,
    ]);

    expect($externalLeftover->isDeclaredInCompose())->toBeFalse();
    expect($managed->isDeclaredInCompose())->toBeTrue();
});

test('isExternalVolume recognises the supported compose formats', function () {
    expect(isExternalVolume(['external' => true]))->toBeTrue();
    expect(isExternalVolume(['external' => 'true']))->toBeTrue();
    expect(isExternalVolume(['external' => ['name' => 'my-volume']]))->toBeTrue();

    expect(isExternalVolume(['external' => false]))->toBeFalse();
    expect(isExternalVolume(['external' => 'false']))->toBeFalse();
    expect(isExternalVolume(['external' => []]))->toBeFalse();
    expect(isExternalVolume(['driver' => 'local']))->toBeFalse();
    expect(isExternalVolume(null))->toBeFalse();
});
