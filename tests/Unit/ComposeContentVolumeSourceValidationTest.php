<?php

use App\Models\Application;
use App\Models\Service;
use Symfony\Component\Yaml\Yaml;

/**
 * Coolify writes the `content:` of a Compose bind volume to the host. Only administrators can edit
 * a Compose file, and they can mount any host path, so the source can be any host path. It must
 * still be safe to use in a shell command.
 */
function contentVolumeCompose(string $source, array $extra = ['content' => "key=value\n"]): string
{
    return Yaml::dump([
        'services' => [
            'app' => [
                'image' => 'nginx:alpine',
                'volumes' => [
                    array_merge(['type' => 'bind', 'source' => $source, 'target' => '/etc/app.conf'], $extra),
                ],
            ],
        ],
    ], 10, 2);
}

it('accepts content volumes with any host path, like Coolify v4.3.23', function (string $source) {
    validateDockerComposeForInjection(contentVolumeCompose($source));

    expect(true)->toBeTrue();
})->with([
    'absolute host file' => ['/etc/myapp/app.conf'],
    'home directory' => ['~/x/app.conf'],
    'bare relative path' => ['app.conf'],
    'parent directory after ./' => ['./../shared/app.conf'],
    'parent directory' => ['../shared/app.conf'],
    'relative path' => ['./config/app.conf'],
    'braced variable with a path' => ['${DATA_DIR}/app.conf'],
    'single quote' => ["/etc/my'app/app.conf"],
]);

it('rejects shell injection in a content volume source', function (string $source) {
    expect(fn () => validateDockerComposeForInjection(contentVolumeCompose($source)))
        ->toThrow(Exception::class, 'Invalid Docker volume definition');
})->with([
    'command substitution' => ['/etc/$(id)/app.conf'],
    'backtick' => ['/etc/`id`/app.conf'],
    'command separator' => ['/etc/app.conf;id'],
    'pipe' => ['/etc/app.conf|id'],
    'background operator' => ['/etc/app.conf&id'],
    'redirect' => ['/etc/app.conf>/root/x'],
    'newline' => ["/etc/app.conf\nid"],
    'control character' => ["/etc/app\x01.conf"],
    'command substitution in a variable default' => ['${DATA:-/etc/$(id)}'],
]);

it('rejects a content volume without a source', function () {
    $compose = "services:\n  app:\n    image: nginx\n    volumes:\n      - type: bind\n        target: /etc/app.conf\n        content: x\n";

    expect(fn () => validateDockerComposeForInjection($compose))
        ->toThrow(Exception::class, 'A bind volume with content needs a source path.');
});

it('does not change bind volumes without content', function (string $compose) {
    validateDockerComposeForInjection($compose);

    expect(true)->toBeTrue();
})->with([
    'docker socket short syntax' => ["services:\n  app:\n    image: nginx\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n"],
    'docker socket long syntax' => ["services:\n  app:\n    image: nginx\n    volumes:\n      - type: bind\n        source: /var/run/docker.sock\n        target: /var/run/docker.sock\n"],
    'absolute host directory' => ["services:\n  app:\n    image: nginx\n    volumes:\n      - type: bind\n        source: /srv/shared\n        target: /shared\n        is_directory: true\n"],
    'home directory' => ["services:\n  app:\n    image: nginx\n    volumes:\n      - type: bind\n        source: ~/booklore\n        target: /bookdrop\n        is_directory: true\n"],
    'variable with default' => ["services:\n  app:\n    image: nginx\n    volumes:\n      - type: bind\n        source: \${FOUNDRY_DATA:-/data/foundryvtt}\n        target: /data\n        is_directory: true\n"],
    'parent directory short syntax' => ["services:\n  app:\n    image: nginx\n    volumes:\n      - ../shared:/shared\n"],
]);

it('ignores content on named volumes because Coolify does not write it', function () {
    validateDockerComposeForInjection(contentVolumeCompose('app-data', ['type' => 'volume', 'content' => 'x']));

    expect(true)->toBeTrue();
});

it('resolves the directory the parsers use for each resource', function () {
    $service = new Service;
    $service->uuid = 'service-uuid';
    $service->compose_parsing_version = '5';
    $legacyService = new Service;
    $legacyService->uuid = 'legacy-uuid';
    $legacyService->compose_parsing_version = '3';
    $application = new Application;
    $application->uuid = 'application-uuid';

    expect(composeResourceDirectory($service))->toBe('/data/coolify/services/service-uuid')
        ->and(composeResourceDirectory($legacyService))->toBe('/data/coolify/applications/legacy-uuid')
        ->and(composeResourceDirectory($application))->toBe('/data/coolify/applications/application-uuid');
});

/**
 * @return array<string, array{string}>
 */
function composeTemplatesWithContentVolumes(): array
{
    $templates = [];
    foreach (glob(dirname(__DIR__, 2).'/templates/compose/*.{yaml,yml}', GLOB_BRACE) as $file) {
        if (preg_match('/^\s+content:/m', file_get_contents($file))) {
            $templates[basename($file)] = [$file];
        }
    }

    return $templates;
}

it('accepts every service template with content volumes', function (string $file) {
    validateDockerComposeForInjection(file_get_contents($file));

    expect(true)->toBeTrue();
})->with(composeTemplatesWithContentVolumes());
