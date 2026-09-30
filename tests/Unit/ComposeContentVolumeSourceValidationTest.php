<?php

use App\Models\Application;
use App\Models\Service;
use Symfony\Component\Yaml\Yaml;

/**
 * Coolify writes the `content:` of a Compose bind volume to the host. The validator makes sure
 * that the file is inside the resource directory, for Services and Git-based Compose applications.
 */
const CONTENT_VOLUME_RESOURCE_DIRECTORY = '/data/coolify/services/content-test-uuid';

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

/**
 * @return array<string, array{string}>
 */
function contentVolumeSourcesOutsideTheResourceDirectory(): array
{
    return [
        'absolute host file' => ['/root/.ssh/authorized_keys'],
        'absolute cron file' => ['/etc/cron.d/coolify'],
        'absolute path in another resource directory' => ['/data/coolify/services/other-uuid/app.conf'],
        'home directory' => ['~/app.conf'],
        'home directory of another user' => ['~root/.ssh/authorized_keys'],
        'parent directory' => ['../app.conf'],
        'traversal after ./' => ['./../../../root/.ssh/authorized_keys'],
        'traversal inside the path' => ['./config/../../outside.conf'],
        'dot segment in the path' => ['./config/../app.conf'],
        'braced variable' => ['${HOME}/.ssh/authorized_keys'],
        'variable with default' => ['${DATA:-/etc/cron.d/coolify}'],
        'plain variable' => ['$HOME/.ssh/authorized_keys'],
        'variable after ./' => ['./$HOME/app.conf'],
        'resource directory itself' => ['./'],
        'current directory' => ['.'],
        'hidden sibling' => ['.ssh/authorized_keys'],
        'bare relative path' => ['config/app.conf'],
        'backslash' => ['./config\\app.conf'],
    ];
}

it('rejects content volumes outside the resource directory', function (string $source) {
    expect(fn () => validateDockerComposeForInjection(contentVolumeCompose($source)))
        ->toThrow(Exception::class, 'with content must be inside the resource directory. Use a relative path such as ./config/app.conf.');
})->with(contentVolumeSourcesOutsideTheResourceDirectory());

it('rejects content volumes outside the resource directory of an existing resource', function (string $source) {
    expect(fn () => validateDockerComposeForInjection(contentVolumeCompose($source), CONTENT_VOLUME_RESOURCE_DIRECTORY))
        ->toThrow(Exception::class, 'with content must be inside the resource directory.');
})->with(contentVolumeSourcesOutsideTheResourceDirectory());

it('shows the source in the error message', function () {
    expect(fn () => validateDockerComposeForInjection(contentVolumeCompose('/root/.ssh/authorized_keys')))
        ->toThrow(Exception::class, 'Volume source /root/.ssh/authorized_keys with content must be inside the resource directory. Use a relative path such as ./config/app.conf.');
});

it('rejects an outside content volume that is also marked as a directory', function (string $flag) {
    $compose = contentVolumeCompose('/etc/cron.d', ['content' => '', $flag => true]);

    expect(fn () => validateDockerComposeForInjection($compose))
        ->toThrow(Exception::class, 'with content must be inside the resource directory.');
})->with(['is_directory', 'isDirectory']);

it('rejects an empty or missing content value on an outside source', function (mixed $content) {
    expect(fn () => validateDockerComposeForInjection(contentVolumeCompose('/etc/nginx', ['content' => $content])))
        ->toThrow(Exception::class, 'with content must be inside the resource directory.');
})->with(['empty string' => '', 'null' => null]);

it('rejects a content volume without a source', function () {
    $compose = "services:\n  app:\n    image: nginx\n    volumes:\n      - type: bind\n        target: /etc/app.conf\n        content: x\n";

    expect(fn () => validateDockerComposeForInjection($compose))
        ->toThrow(Exception::class, 'Volume source (empty) with content must be inside the resource directory.');
});

it('accepts relative content volumes inside the resource directory', function (string $source) {
    validateDockerComposeForInjection(contentVolumeCompose($source));
    validateDockerComposeForInjection(contentVolumeCompose($source), CONTENT_VOLUME_RESOURCE_DIRECTORY);

    expect(true)->toBeTrue();
})->with(['./app.conf', './config/app.conf', './config/nested/app.conf', './config/', './.env.local']);

it('accepts an absolute content source only inside the directory of an existing resource', function () {
    $inside = CONTENT_VOLUME_RESOURCE_DIRECTORY.'/config/app.conf';

    validateDockerComposeForInjection(contentVolumeCompose($inside), CONTENT_VOLUME_RESOURCE_DIRECTORY);

    expect(fn () => validateDockerComposeForInjection(contentVolumeCompose($inside)))
        ->toThrow(Exception::class, 'with content must be inside the resource directory.')
        ->and(fn () => validateDockerComposeForInjection(contentVolumeCompose(CONTENT_VOLUME_RESOURCE_DIRECTORY), CONTENT_VOLUME_RESOURCE_DIRECTORY))
        ->toThrow(Exception::class, 'with content must be inside the resource directory.')
        ->and(fn () => validateDockerComposeForInjection(contentVolumeCompose(CONTENT_VOLUME_RESOURCE_DIRECTORY.'-sibling/app.conf'), CONTENT_VOLUME_RESOURCE_DIRECTORY))
        ->toThrow(Exception::class, 'with content must be inside the resource directory.');
});

it('does not change bind volumes without content', function (string $compose) {
    validateDockerComposeForInjection($compose);
    validateDockerComposeForInjection($compose, CONTENT_VOLUME_RESOURCE_DIRECTORY);

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

it('still accepts every service template with content volumes', function (string $file) {
    $compose = file_get_contents($file);
    validateDockerComposeForInjection($compose);
    validateDockerComposeForInjection($compose, CONTENT_VOLUME_RESOURCE_DIRECTORY);

    $contentVolumes = 0;
    foreach (Yaml::parse($compose)['services'] as $service) {
        foreach ($service['volumes'] ?? [] as $volume) {
            if (! is_array($volume) || ! array_key_exists('content', $volume) || ($volume['type'] ?? null) !== 'bind') {
                continue;
            }
            $contentVolumes++;
            $resolved = replaceLocalSource(str($volume['source']), str(CONTENT_VOLUME_RESOURCE_DIRECTORY))->value();

            expect(confinePathToBase(CONTENT_VOLUME_RESOURCE_DIRECTORY, $resolved))->toStartWith(CONTENT_VOLUME_RESOURCE_DIRECTORY.'/');
        }
    }

    expect($contentVolumes)->toBeGreaterThan(0);
})->with(composeTemplatesWithContentVolumes());

it('checks the complete content volume template corpus', function () {
    $contentVolumes = 0;
    foreach (composeTemplatesWithContentVolumes() as [$file]) {
        foreach (Yaml::parse(file_get_contents($file))['services'] as $service) {
            foreach ($service['volumes'] ?? [] as $volume) {
                if (is_array($volume) && array_key_exists('content', $volume)) {
                    $contentVolumes++;
                }
            }
        }
    }

    expect($contentVolumes)->toBeGreaterThanOrEqual(91);
});
