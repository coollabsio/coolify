<?php

use App\Services\ComposeBindPathResolver;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

test('short bind syntax keeps Compose expressions intact', function (string $source) {
    $parsed = parseDockerVolumeString("{$source}:/data:ro");

    expect($parsed['source']->value())->toBe($source)
        ->and($parsed['target']->value())->toBe('/data')
        ->and($parsed['mode']->value())->toBe('ro');
})->with([
    '$DATA', '${DATA}', '${DATA}/suffix', '${DATA:-./default}',
    '${DATA:-${FALLBACK:-./nested}}/suffix', '${DATA:+./replacement}',
    '${DATA:?required}', '${DATA?required}', './$$DATA',
]);

test('short bind syntax preserves combined mount options', function () {
    $parsed = parseDockerVolumeString('./data:/data:ro,z');

    expect($parsed['source']->value())->toBe('./data')
        ->and($parsed['target']->value())->toBe('/data')
        ->and($parsed['mode']->value())->toBe('ro,z');
});

test('normalized Compose bind source is selected by service and target', function () {
    $config = ['services' => [
        'app' => ['volumes' => [['type' => 'bind', 'source' => '/srv/data with spaces', 'target' => '/data']]],
        'other' => ['volumes' => [['type' => 'bind', 'source' => '/srv/other', 'target' => '/data']]],
    ]];

    expect(ComposeBindPathResolver::pathFromConfig($config, 'app', '/data'))->toBe('/srv/data with spaces');
    expect(fn () => ComposeBindPathResolver::pathFromConfig($config, null, '/data'))->toThrow(RuntimeException::class);
});

test('normalized Compose source must be one safe non-root bind', function (string $type, string $source) {
    $config = ['services' => ['app' => ['volumes' => [['type' => $type, 'source' => $source, 'target' => '/data']]]]];

    expect(fn () => ComposeBindPathResolver::pathFromConfig($config, 'app', '/data'))->toThrow(Exception::class);
})->with([
    ['volume', '/srv/data'], ['bind', '/'], ['bind', ''], ['bind', 'relative'],
    ['bind', '/tmp/$(id)'], ['bind', '/tmp/x;id'],
]);

test('Docker Compose resolves bind expressions with the supplied environment', function () {
    $version = new Process(['docker', 'compose', 'version']);
    $version->run();
    if (! $version->isSuccessful()) {
        test()->markTestSkipped('Docker Compose is not available.');
    }

    $yaml = <<<'YAML'
services:
  app:
    image: alpine
    volumes:
      - "${COOLIFY_BIND_TEST_ROOT_987:-./short}/file:/short:ro"
      - type: bind
        source: "${COOLIFY_BIND_TEST_ROOT_987:-${COOLIFY_BIND_TEST_FALLBACK_987:-./nested}}/file"
        target: /long
      - type: bind
        source: "/tmp/$$literal"
        target: /literal
YAML;

    $config = function (array $environment) use ($yaml): array {
        $process = new Process(
            ['docker', 'compose', '--env-file', '/dev/null', '--project-directory', '/tmp', '-f', '-', 'config', '--format', 'json'],
            env: $environment,
            input: $yaml,
        );
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    };

    $unset = $config(['COOLIFY_BIND_TEST_ROOT_987' => false, 'COOLIFY_BIND_TEST_FALLBACK_987' => false]);
    expect(ComposeBindPathResolver::pathFromConfig($unset, 'app', '/short'))->toBe('/tmp/short/file')
        ->and(ComposeBindPathResolver::pathFromConfig($unset, 'app', '/long'))->toBe('/tmp/nested/file')
        ->and(ComposeBindPathResolver::pathFromConfig($unset, 'app', '/literal'))->toBe('/tmp/$$literal');

    $defined = $config(['COOLIFY_BIND_TEST_ROOT_987' => '/srv/defined']);
    expect(ComposeBindPathResolver::pathFromConfig($defined, 'app', '/short'))->toBe('/srv/defined/file')
        ->and(ComposeBindPathResolver::pathFromConfig($defined, 'app', '/long'))->toBe('/srv/defined/file');

    $operators = <<<'YAML'
services:
  app:
    image: alpine
    volumes:
      - type: bind
        source: "${COOLIFY_BIND_TEST_REQUIRED_987:?required}/file"
        target: /required
      - type: bind
        source: "${COOLIFY_BIND_TEST_ALTERNATIVE_987:+/srv/replacement}/file"
        target: /alternative
YAML;
    $missing = new Process(
        ['docker', 'compose', '--env-file', '/dev/null', '--project-directory', '/tmp', '-f', '-', 'config', '--format', 'json'],
        env: ['COOLIFY_BIND_TEST_REQUIRED_987' => false],
        input: $operators,
    );
    $missing->run();
    expect($missing->isSuccessful())->toBeFalse();

    $present = new Process(
        ['docker', 'compose', '--env-file', '/dev/null', '--project-directory', '/tmp', '-f', '-', 'config', '--format', 'json'],
        env: ['COOLIFY_BIND_TEST_REQUIRED_987' => '/srv/required', 'COOLIFY_BIND_TEST_ALTERNATIVE_987' => 'yes'],
        input: $operators,
    );
    $present->run();
    expect($present->isSuccessful())->toBeTrue($present->getErrorOutput());
    $presentConfig = json_decode($present->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect(ComposeBindPathResolver::pathFromConfig($presentConfig, 'app', '/required'))->toBe('/srv/required/file')
        ->and(ComposeBindPathResolver::pathFromConfig($presentConfig, 'app', '/alternative'))->toBe('/srv/replacement/file');

    $temporaryProject = new Process(
        ['docker', 'compose', '--env-file', '/dev/null', '--project-directory', '/artifacts/coolify-bind-test', '-f', '-', 'config', '--format', 'json', '--no-env-resolution'],
        input: "services:\n  app:\n    image: alpine\n    volumes:\n      - ./data:/data\n",
    );
    $temporaryProject->run();
    expect($temporaryProject->isSuccessful())->toBeTrue($temporaryProject->getErrorOutput());
    $temporaryConfig = json_decode($temporaryProject->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect(ComposeBindPathResolver::pathFromConfig($temporaryConfig, 'app', '/data'))
        ->toBe('/artifacts/coolify-bind-test/data');
});

test('both volume forms reject unsafe source text', function (string $source) {
    expect(fn () => parseDockerVolumeString("{$source}:/data"))->toThrow(Exception::class);

    $yaml = Yaml::dump(['services' => ['app' => ['volumes' => [[
        'type' => 'bind', 'source' => $source, 'target' => '/data',
    ]]]]], 5);
    expect(fn () => validateDockerComposeForInjection($yaml))->toThrow(Exception::class);
})->with([
    '/tmp/$(id)', '/tmp/`id`', '/tmp/x;id', '/tmp/x|id',
    '${DATA:-/tmp/$(id)}', '${DATA:-/tmp/x;id}', '${DATA:-bad',
]);
