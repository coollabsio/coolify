<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests to verify that empty top-level sections (volumes, configs, secrets)
 * are removed from generated Docker Compose files.
 *
 * Empty sections like "volumes: {  }" are not valid/clean YAML and should be omitted
 * when they contain no actual content.
 */
it('ensures parsers.php filters empty top-level sections', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that filtering logic exists
    expect($parsersFile)
        ->toContain('Remove empty top-level sections')
        ->toContain('->filter(function ($value, $key)');
});

it('verifies YAML dump produces empty objects for empty arrays', function () {
    // Demonstrate the problem: empty arrays serialize as empty objects
    $data = [
        'services' => ['web' => ['image' => 'nginx']],
        'volumes' => [],
        'configs' => [],
        'secrets' => [],
    ];

    $yaml = Yaml::dump($data, 10, 2);

    // Empty arrays become empty objects in YAML
    expect($yaml)->toContain('volumes: {  }');
    expect($yaml)->toContain('configs: {  }');
    expect($yaml)->toContain('secrets: {  }');
});

it('verifies YAML dump omits keys that are not present', function () {
    // Demonstrate the solution: omit empty keys entirely
    $data = [
        'services' => ['web' => ['image' => 'nginx']],
        // Don't include volumes, configs, secrets at all
    ];

    $yaml = Yaml::dump($data, 10, 2);

    // Keys that don't exist are not in the output
    expect($yaml)->not->toContain('volumes:');
    expect($yaml)->not->toContain('configs:');
    expect($yaml)->not->toContain('secrets:');
    expect($yaml)->toContain('services:');
});

it('verifies collection filter removes empty items', function () {
    // Test Laravel Collection filter behavior
    $collection = collect([
        'services' => collect(['web' => ['image' => 'nginx']]),
        'volumes' => collect([]),
        'networks' => collect(['coolify' => ['external' => true]]),
        'configs' => collect([]),
        'secrets' => collect([]),
    ]);

    $filtered = $collection->filter(function ($value, $key) {
        // Always keep services
        if ($key === 'services') {
            return true;
        }

        // Keep only non-empty collections
        return $value->isNotEmpty();
    });

    // Should have services and networks (non-empty)
    expect($filtered)->toHaveKey('services');
    expect($filtered)->toHaveKey('networks');

    // Should NOT have volumes, configs, secrets (empty)
    expect($filtered)->not->toHaveKey('volumes');
    expect($filtered)->not->toHaveKey('configs');
    expect($filtered)->not->toHaveKey('secrets');
});

it('verifies filtered collections serialize cleanly to YAML', function () {
    // Full test: filter then serialize
    $collection = collect([
        'services' => collect(['web' => ['image' => 'nginx']]),
        'volumes' => collect([]),
        'networks' => collect(['coolify' => ['external' => true]]),
        'configs' => collect([]),
        'secrets' => collect([]),
    ]);

    $filtered = $collection->filter(function ($value, $key) {
        if ($key === 'services') {
            return true;
        }

        return $value->isNotEmpty();
    });

    $yaml = Yaml::dump($filtered->toArray(), 10, 2);

    // Should have services and networks
    expect($yaml)->toContain('services:');
    expect($yaml)->toContain('networks:');

    // Should NOT have empty sections
    expect($yaml)->not->toContain('volumes:');
    expect($yaml)->not->toContain('configs:');
    expect($yaml)->not->toContain('secrets:');
});

it('ensures services section is always kept even if empty', function () {
    // Services should never be filtered out
    $collection = collect([
        'services' => collect([]),
        'volumes' => collect([]),
    ]);

    $filtered = $collection->filter(function ($value, $key) {
        if ($key === 'services') {
            return true; // Always keep
        }

        return $value->isNotEmpty();
    });

    // Services should be present
    expect($filtered)->toHaveKey('services');

    // Volumes should be removed
    expect($filtered)->not->toHaveKey('volumes');
});

it('verifies non-empty sections are preserved', function () {
    // Non-empty sections should remain
    $collection = collect([
        'services' => collect(['web' => ['image' => 'nginx']]),
        'volumes' => collect(['data' => ['driver' => 'local']]),
        'networks' => collect(['coolify' => ['external' => true]]),
        'configs' => collect(['app_config' => ['file' => './config']]),
        'secrets' => collect(['db_password' => ['file' => './secret']]),
    ]);

    $filtered = $collection->filter(function ($value, $key) {
        if ($key === 'services') {
            return true;
        }

        return $value->isNotEmpty();
    });

    // All sections should be present (none are empty)
    expect($filtered)->toHaveKey('services');
    expect($filtered)->toHaveKey('volumes');
    expect($filtered)->toHaveKey('networks');
    expect($filtered)->toHaveKey('configs');
    expect($filtered)->toHaveKey('secrets');

    // Count should be 5 (all original keys)
    expect($filtered->count())->toBe(5);
});

it('verifies mixed empty and non-empty sections', function () {
    // Mixed scenario: some empty, some not
    $collection = collect([
        'services' => collect(['web' => ['image' => 'nginx']]),
        'volumes' => collect([]), // Empty
        'networks' => collect(['coolify' => ['external' => true]]), // Not empty
        'configs' => collect([]), // Empty
        'secrets' => collect(['db_password' => ['file' => './secret']]), // Not empty
    ]);

    $filtered = $collection->filter(function ($value, $key) {
        if ($key === 'services') {
            return true;
        }

        return $value->isNotEmpty();
    });

    // Should have: services, networks, secrets
    expect($filtered)->toHaveKey('services');
    expect($filtered)->toHaveKey('networks');
    expect($filtered)->toHaveKey('secrets');

    // Should NOT have: volumes, configs
    expect($filtered)->not->toHaveKey('volumes');
    expect($filtered)->not->toHaveKey('configs');

    // Count should be 3
    expect($filtered->count())->toBe(3);
});
