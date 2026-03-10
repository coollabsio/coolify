<?php

test('extractComposeIncludePaths returns empty array when no include section', function () {
    $yaml = [
        'services' => [
            'web' => ['image' => 'nginx'],
        ],
    ];

    expect(extractComposeIncludePaths($yaml, ''))->toBe([]);
});

test('extractComposeIncludePaths extracts simple include paths', function () {
    $yaml = [
        'include' => [
            './backend/docker-compose.yml',
            './frontend/docker-compose.yml',
        ],
        'services' => [
            'nginx' => ['image' => 'nginx:alpine'],
        ],
    ];

    $paths = extractComposeIncludePaths($yaml, '');

    expect($paths)->toContain('/backend/docker-compose.yml')
        ->and($paths)->toContain('/frontend/docker-compose.yml')
        ->and($paths)->toHaveCount(2);
});

test('extractComposeIncludePaths resolves relative paths from compose directory', function () {
    $yaml = [
        'include' => [
            './backend/docker-compose.yml',
        ],
        'services' => [],
    ];

    $paths = extractComposeIncludePaths($yaml, '/app');

    expect($paths)->toBe(['/app/backend/docker-compose.yml']);
});

test('extractComposeIncludePaths skips remote URLs', function () {
    $yaml = [
        'include' => [
            './backend/docker-compose.yml',
            'oci://docker.io/username/compose:latest',
            'https://example.com/compose.yaml',
        ],
        'services' => [],
    ];

    $paths = extractComposeIncludePaths($yaml, '');

    expect($paths)->toBe(['/backend/docker-compose.yml']);
});

test('extractComposeIncludePaths handles path object format with overrides', function () {
    $yaml = [
        'include' => [
            [
                'path' => [
                    'third-party/compose.yaml',
                    'override.yaml',
                ],
            ],
        ],
        'services' => [],
    ];

    $paths = extractComposeIncludePaths($yaml, '/app');

    expect($paths)->toContain('/app/third-party/compose.yaml')
        ->and($paths)->toContain('/app/override.yaml');
});

test('resolveComposeIncludePath resolves relative paths', function () {
    expect(resolveComposeIncludePath('./backend/docker-compose.yml', ''))
        ->toBe('/backend/docker-compose.yml');

    expect(resolveComposeIncludePath('./backend/docker-compose.yml', '/app'))
        ->toBe('/app/backend/docker-compose.yml');
});

test('resolveComposeIncludePath normalizes dot segments', function () {
    expect(resolveComposeIncludePath('../shared/docker-compose.yml', '/apps/backend'))
        ->toBe('/apps/shared/docker-compose.yml');

    expect(resolveComposeIncludePath('./services/../worker/docker-compose.yml', '/apps/backend'))
        ->toBe('/apps/backend/worker/docker-compose.yml');
});

test('resolveComposeIncludePath returns null for remote URLs', function () {
    expect(resolveComposeIncludePath('oci://docker.io/compose:latest', ''))->toBeNull();
    expect(resolveComposeIncludePath('https://example.com/compose.yaml', ''))->toBeNull();
    expect(resolveComposeIncludePath('http://example.com/compose.yaml', ''))->toBeNull();
});

test('resolveComposeIncludePath returns null for empty or whitespace paths', function () {
    expect(resolveComposeIncludePath('', ''))->toBeNull();
    expect(resolveComposeIncludePath('   ', ''))->toBeNull();
});

test('resolveComposeIncludePath normalizes Windows backslashes to forward slashes', function () {
    expect(resolveComposeIncludePath('.\\backend\\docker-compose.yml', '/app'))
        ->toBe('/app/backend/docker-compose.yml');
});

test('extractComposeIncludePaths handles include as single string', function () {
    $yaml = [
        'include' => './single/docker-compose.yml',
        'services' => [],
    ];

    $paths = extractComposeIncludePaths($yaml, '/app');

    expect($paths)->toBe(['/app/single/docker-compose.yml']);
});

test('parseComposeIncludeFiles parses marker output without leading command output', function () {
    $includedContent = <<<'TEXT'
===INCLUDE:/backend/docker-compose.yml===
services:
  api:
    image: php:8.2-fpm
===INCLUDE:/frontend/docker-compose.yml===
services:
  website:
    image: node:20-alpine
TEXT;

    expect(parseComposeIncludeFiles($includedContent))->toBe([
        '/backend/docker-compose.yml' => "services:\n  api:\n    image: php:8.2-fpm",
        '/frontend/docker-compose.yml' => "services:\n  website:\n    image: node:20-alpine",
    ]);
});

test('parseComposeIncludeFiles ignores non-marker output before includes', function () {
    $includedContent = <<<'TEXT'
Cloning into '.'...
===INCLUDE:/backend/docker-compose.yml===
services:
  api:
    image: php:8.2-fpm
TEXT;

    expect(parseComposeIncludeFiles($includedContent))->toBe([
        '/backend/docker-compose.yml' => "services:\n  api:\n    image: php:8.2-fpm",
    ]);
});

test('mergeComposeWithIncludes merges services from included files', function () {
    $mainYaml = [
        'include' => ['./backend/docker-compose.yml', './frontend/docker-compose.yml'],
        'services' => [
            'nginx' => [
                'image' => 'nginx:alpine',
                'depends_on' => ['api', 'website'],
            ],
        ],
        'networks' => [
            'bridge' => ['driver' => 'bridge'],
        ],
    ];

    $backendCompose = <<<'YAML'
services:
  api:
    image: php:8.2-fpm
    build:
      context: ./backend
YAML;

    $frontendCompose = <<<'YAML'
services:
  website:
    image: node:20-alpine
    build:
      context: ./frontend
YAML;

    $includedFiles = [
        '/backend/docker-compose.yml' => $backendCompose,
        '/frontend/docker-compose.yml' => $frontendCompose,
    ];

    $merged = mergeComposeWithIncludes($mainYaml, $includedFiles);

    expect($merged)->not->toHaveKey('include')
        ->and($merged['services'])->toHaveKeys(['nginx', 'api', 'website'])
        ->and($merged['services']['nginx']['depends_on'])->toBe(['api', 'website'])
        ->and($merged['services']['api']['image'])->toBe('php:8.2-fpm')
        ->and($merged['services']['website']['image'])->toBe('node:20-alpine');
});

test('mergeComposeWithIncludes main file services take precedence', function () {
    $mainYaml = [
        'include' => ['./backend/docker-compose.yml'],
        'services' => [
            'api' => [
                'image' => 'custom-api:latest',
            ],
        ],
    ];

    $backendCompose = <<<'YAML'
services:
  api:
    image: php:8.2-fpm
YAML;

    $merged = mergeComposeWithIncludes($mainYaml, ['/backend/docker-compose.yml' => $backendCompose]);

    expect($merged['services']['api']['image'])->toBe('custom-api:latest');
});

test('mergeComposeWithIncludes merges volumes and networks', function () {
    $mainYaml = [
        'include' => ['./backend/docker-compose.yml'],
        'services' => [
            'web' => ['image' => 'nginx'],
        ],
        'volumes' => [
            'main-data' => null,
        ],
    ];

    $backendCompose = <<<'YAML'
services:
  api:
    image: php:8.2
volumes:
  backend-data: null
networks:
  backend-net:
    driver: bridge
YAML;

    $merged = mergeComposeWithIncludes($mainYaml, ['/backend/docker-compose.yml' => $backendCompose]);

    expect($merged['volumes'])->toHaveKeys(['main-data', 'backend-data'])
        ->and($merged['networks'])->toHaveKey('backend-net');
});

test('mergeComposeWithIncludes handles empty included files', function () {
    $mainYaml = [
        'include' => ['./backend/docker-compose.yml'],
        'services' => [
            'web' => ['image' => 'nginx'],
        ],
    ];

    $merged = mergeComposeWithIncludes($mainYaml, []);

    expect($merged)->not->toHaveKey('include')
        ->and($merged['services'])->toHaveKey('web')
        ->and($merged['services']['web']['image'])->toBe('nginx');
});

test('mergeComposeWithIncludes handles invalid YAML in included file', function () {
    $mainYaml = [
        'include' => ['./backend/docker-compose.yml'],
        'services' => [
            'web' => ['image' => 'nginx'],
        ],
    ];

    $includedFiles = [
        '/backend/docker-compose.yml' => 'invalid: yaml: content: [',
    ];

    $merged = mergeComposeWithIncludes($mainYaml, $includedFiles);

    expect($merged['services'])->toHaveKey('web');
});

test('mergeComposeWithIncludes merges configs and secrets', function () {
    $mainYaml = [
        'include' => ['./backend/docker-compose.yml'],
        'services' => [
            'web' => ['image' => 'nginx'],
        ],
        'configs' => [
            'main-config' => ['file' => './main.conf'],
        ],
    ];

    $backendCompose = <<<'YAML'
services:
  api:
    image: php:8.2
configs:
  backend-config:
    file: ./backend.conf
secrets:
  backend-secret:
    file: ./secret.txt
YAML;

    $merged = mergeComposeWithIncludes($mainYaml, ['/backend/docker-compose.yml' => $backendCompose]);

    expect($merged['configs'])->toHaveKeys(['main-config', 'backend-config'])
        ->and($merged['secrets'])->toHaveKey('backend-secret');
});

test('extractComposeIncludePaths deduplicates paths', function () {
    $yaml = [
        'include' => [
            './backend/docker-compose.yml',
            './backend/docker-compose.yml',
        ],
        'services' => [],
    ];

    $paths = extractComposeIncludePaths($yaml, '/app');

    expect($paths)->toBe(['/app/backend/docker-compose.yml']);
});

test('mergeComposeWithIncludes filters empty top-level sections', function () {
    $mainYaml = [
        'include' => ['./backend/docker-compose.yml'],
        'services' => [
            'nginx' => ['image' => 'nginx:alpine'],
        ],
    ];

    $backendCompose = <<<'YAML'
services:
  api:
    image: php:8.2-fpm
YAML;

    $merged = mergeComposeWithIncludes($mainYaml, ['/backend/docker-compose.yml' => $backendCompose]);

    expect($merged)->toHaveKeys(['services'])
        ->and($merged)->not->toHaveKey('volumes')
        ->and($merged)->not->toHaveKey('networks')
        ->and($merged)->not->toHaveKey('configs')
        ->and($merged)->not->toHaveKey('secrets')
        ->and($merged['services'])->toHaveKeys(['nginx', 'api']);
});

test('applicationParser calls resolveComposeIncludes when parsing compose with include', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    expect($parsersFile)
        ->toContain('resolveComposeIncludes')
        ->toContain('Resolve include directives so deployable compose has all services from included files');
});
