<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use phpseclib3\Crypt\EC;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Integration test to verify docker_compose_raw remains clean after parsing
 */
it('verifies docker_compose_raw does not contain Coolify labels after parsing', function () {
    // Keeps the ServerStorageSaveJob of the file volume from writing to the (fake) server.
    Bus::fake();

    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });

    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $privateKey = PrivateKey::create([
        'name' => 'test-key',
        'private_key' => EC::createKey('Ed25519')->toString('OpenSSH'),
        'team_id' => $team->id,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'network' => 'test-network-'.fake()->uuid(),
    ]);

    // Create a simple compose file with volumes containing content
    $originalCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - type: bind
        source: ./config/default.conf
        target: /etc/nginx/conf.d/default.conf
        content: |
          server {
            listen 80;
          }
    labels:
      - "my.custom.label=value"
YAML;

    $app = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $originalCompose,
        'fqdn' => null,
        'docker_compose_domains' => null,
    ]);

    applicationParser($app);
    $app->refresh();

    // Parse the YAML after running through the parser logic
    $yamlAfterParsing = Yaml::parse($app->docker_compose_raw);

    // Check that docker_compose_raw does NOT contain Coolify labels
    $labels = data_get($yamlAfterParsing, 'services.web.labels', []);
    $hasTraefikLabels = false;
    $hasCoolifyManagedLabel = false;

    foreach ($labels as $label) {
        if (is_string($label)) {
            if (str_contains($label, 'traefik.')) {
                $hasTraefikLabels = true;
            }
            if (str_contains($label, 'coolify.managed')) {
                $hasCoolifyManagedLabel = true;
            }
        }
    }

    // docker_compose_raw should NOT have Coolify additions
    expect($hasTraefikLabels)->toBeFalse('docker_compose_raw should not contain Traefik labels');
    expect($hasCoolifyManagedLabel)->toBeFalse('docker_compose_raw should not contain coolify.managed label');

    // But it SHOULD still have the original custom label
    $hasCustomLabel = false;
    foreach ($labels as $label) {
        if (str_contains($label, 'my.custom.label')) {
            $hasCustomLabel = true;
        }
    }
    expect($hasCustomLabel)->toBeTrue('docker_compose_raw should contain original user labels');

    // Check that content field is removed
    $volumes = data_get($yamlAfterParsing, 'services.web.volumes', []);
    expect($volumes)->not->toBeEmpty();
    foreach ($volumes as $volume) {
        if (is_array($volume)) {
            expect($volume)->not->toHaveKey('content', 'content field should be removed from volumes');
        }
    }

    // The processed compose file keeps the Coolify additions
    expect($app->docker_compose)->toContain('coolify.managed');
});
