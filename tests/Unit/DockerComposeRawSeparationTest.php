<?php

use App\Models\Application;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * Integration test to verify docker_compose_raw remains clean after parsing
 */
it('verifies docker_compose_raw does not contain Coolify labels after parsing', function () {
    // This test requires database, so skip if not available
    if (! DB::connection()->getDatabaseName()) {
        $this->markTestSkipped('Database not available');
    }

    // Create a simple compose file with volumes containing content
    $originalCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - type: bind
        source: ./config
        target: /etc/nginx/conf.d
        content: |
          server {
            listen 80;
          }
    labels:
      - "my.custom.label=value"
YAML;

    // Create application with mocked data
    $app = new Application;
    $app->docker_compose_raw = $originalCompose;
    $app->uuid = 'test-uuid-123';
    $app->name = 'test-app';
    $app->compose_parsing_version = 3;

    // Mock the destination and server relationships
    $app->setRelation('destination', (object) [
        'server' => (object) [
            'proxyType' => fn () => 'traefik',
            'settings' => (object) [
                'generate_exact_labels' => true,
            ],
        ],
        'network' => 'coolify',
    ]);

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
    foreach ($volumes as $volume) {
        if (is_array($volume)) {
            expect($volume)->not->toHaveKey('content', 'content field should be removed from volumes');
        }
    }
});
