<?php

test('parseDockerVolumeString correctly handles Windows paths with drive letters', function () {
    $windowsVolume = 'C:\\host\\path:/container';

    $result = parseDockerVolumeString($windowsVolume);

    expect((string) $result['source'])->toBe('C:\\host\\path');
    expect((string) $result['target'])->toBe('/container');
});

test('validateVolumeStringForInjection correctly handles Windows paths via parseDockerVolumeString', function () {
    $windowsVolume = 'C:\\Users\\Data:/app/data';

    // Should not throw an exception
    validateVolumeStringForInjection($windowsVolume);

    // If we get here, the test passed
    expect(true)->toBeTrue();
});

test('validateVolumeStringForInjection rejects malicious Windows-like paths', function () {
    $maliciousVolume = 'C:\\host\\`whoami`:/container';

    expect(fn () => validateVolumeStringForInjection($maliciousVolume))
        ->toThrow(\Exception::class);
});

test('validateDockerComposeForInjection handles Windows paths in compose files', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  web:
    image: nginx
    volumes:
      - C:\Users\Data:/app/data
YAML;

    // Should not throw an exception
    validateDockerComposeForInjection($dockerComposeYaml);

    expect(true)->toBeTrue();
});

test('validateDockerComposeForInjection rejects Windows paths with injection', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  web:
    image: nginx
    volumes:
      - C:\Users\$(whoami):/app/data
YAML;

    expect(fn () => validateDockerComposeForInjection($dockerComposeYaml))
        ->toThrow(\Exception::class);
});

test('Windows paths with complex paths and spaces are handled correctly', function () {
    $windowsVolume = 'C:\\Program Files\\MyApp:/app';

    $result = parseDockerVolumeString($windowsVolume);

    expect((string) $result['source'])->toBe('C:\\Program Files\\MyApp');
    expect((string) $result['target'])->toBe('/app');
});
