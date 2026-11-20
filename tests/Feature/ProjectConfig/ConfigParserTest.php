<?php

use App\Services\ProjectConfig\ConfigDocument;
use App\Services\ProjectConfig\ConfigException;
use App\Services\ProjectConfig\ConfigParser;

/**
 * @param  array<string, mixed>  $overrides
 */
function parserTestFile(array $overrides = []): string
{
    $base = [
        'version' => 1,
        'resources' => ['api' => ['type' => 'application', 'server' => 'prod-1']],
        'environments' => ['production' => ['branch' => 'main']],
    ];

    return json_encode(array_replace($base, $overrides));
}

/**
 * @return list<array{path: string, message: string}>
 */
function parserTestErrors(string $raw, ?string $linkedProject = null): array
{
    try {
        app(ConfigParser::class)->parse($raw, $linkedProject);
    } catch (ConfigException $e) {
        return array_map(fn ($error) => ['path' => $error->path, 'message' => $error->message], $e->errors());
    }

    return [];
}

function parserTestPaths(string $raw): array
{
    return array_column(parserTestErrors($raw), 'path');
}

beforeEach(function () {
    config(['constants.coolify.version' => '4.7.0']);
});

it('parses a valid file into a document', function () {
    $raw = parserTestFile(['project' => 'acme', 'moved' => [['from' => 'web', 'to' => 'api']], 'unmanage' => ['old']]);
    $doc = app(ConfigParser::class)->parse($raw, 'acme');

    expect($doc)->toBeInstanceOf(ConfigDocument::class)
        ->and($doc->version)->toBe(1)
        ->and($doc->project)->toBe('acme')
        ->and(array_keys($doc->resources))->toBe(['api'])
        ->and($doc->moved)->toBe([['from' => 'web', 'to' => 'api']])
        ->and($doc->unmanage)->toBe(['old'])
        ->and($doc->fileHash)->toBe(hash('sha256', $raw));
});

it('reports every unknown key with its path', function () {
    $raw = parserTestFile(['extra' => 1, 'environments' => ['production' => ['branch' => 'main', 'nope' => true]]]);

    expect(parserTestErrors($raw))->toContain(['path' => 'extra', 'message' => 'Unknown key.'])
        ->toContain(['path' => 'environments.production.nope', 'message' => 'Unknown key.']);
});

it('ignores x- keys at every level', function () {
    $raw = parserTestFile([
        'x-note' => 'top',
        'resources' => ['api' => ['type' => 'application', 'x-why' => 'x', 'env' => ['A' => ['value' => 'b', 'x-c' => 1]]]],
        'environments' => ['production' => ['branch' => 'main', 'x-d' => []]],
    ]);

    expect(parserTestErrors($raw))->toBe([]);
});

it('strips a UTF-8 BOM and accepts CRLF line ends', function () {
    $raw = "\xEF\xBB\xBF".str_replace("\n", "\r\n", json_encode(json_decode(parserTestFile()), JSON_PRETTY_PRINT));

    expect(app(ConfigParser::class)->parse($raw)->version)->toBe(1);
});

it('rejects invalid env var names with the JSON path', function (string $name) {
    $raw = parserTestFile(['resources' => ['api' => ['type' => 'application', 'env' => [$name => ['value' => 'x']]]]]);

    expect(parserTestPaths($raw))->toContain("resources.api.env.{$name}");
})->with(['my-key', '1ABC', '']);

it('rejects a resource key with a trailing newline or capitals', function (string $key) {
    $raw = parserTestFile(['resources' => [$key => ['type' => 'application']]]);

    expect(parserTestPaths($raw))->toContain("resources.{$key}");
})->with(["api\n", 'Api', '-api', str_repeat('a', 64)]);

it('requires version 1', function (mixed $version) {
    $raw = parserTestFile(['version' => $version]);

    expect(parserTestPaths($raw))->toContain('version');
})->with([2, '1', null]);

it('requires a version key', function () {
    $data = json_decode(parserTestFile(), true);
    unset($data['version']);

    expect(parserTestPaths(json_encode($data)))->toContain('version');
});

it('fails when minimum_coolify_version is newer than this instance', function () {
    expect(parserTestPaths(parserTestFile(['minimum_coolify_version' => '4.8.0'])))->toContain('minimum_coolify_version')
        ->and(parserTestErrors(parserTestFile(['minimum_coolify_version' => '4.7.0'])))->toBe([]);
});

it('fails when project differs from the linked project', function () {
    expect(parserTestPaths(parserTestFile(['project' => 'other'])))->toBe([])
        ->and(parserTestErrors(parserTestFile(['project' => 'other']), 'acme'))->toBe([['path' => 'project', 'message' => 'This file is for project "other", but it is linked to project "acme".']]);
});

it('fails when two environments use one branch', function () {
    $raw = parserTestFile(['environments' => ['production' => ['branch' => 'main'], 'staging' => ['branch' => 'main']]]);

    expect(parserTestPaths($raw))->toContain('environments.staging.branch');
});

it('requires at least one environment with a branch', function () {
    expect(parserTestPaths(parserTestFile(['environments' => []])))->toContain('environments')
        ->and(parserTestPaths(parserTestFile(['environments' => ['production' => []]])))->toContain('environments.production.branch');
});

it('requires exactly one env form', function (array $entry) {
    $raw = parserTestFile(['resources' => ['api' => ['type' => 'application', 'env' => ['A' => $entry]]]]);

    expect(parserTestPaths($raw))->toContain('resources.api.env.A');
})->with([
    'none' => [[]],
    'two' => [['value' => 'x', 'required' => true]],
    'required false' => [['required' => false]],
    'value not text' => [['value' => 5]],
]);

it('checks generate kinds, length, and rotation', function () {
    $env = fn (array $entry) => parserTestFile(['resources' => ['api' => ['type' => 'application', 'env' => ['A' => $entry]]]]);

    expect(parserTestErrors($env(['generate' => 'password', 'length' => 32, 'rotation' => 2])))->toBe([])
        ->and(parserTestErrors($env(['generate' => 'uuid'])))->toBe([])
        ->and(parserTestPaths($env(['generate' => 'hex'])))->toContain('resources.api.env.A.generate')
        ->and(parserTestPaths($env(['generate' => 'password', 'length' => 4])))->toContain('resources.api.env.A.length')
        ->and(parserTestPaths($env(['generate' => 'uuid', 'length' => 40])))->toContain('resources.api.env.A.length')
        ->and(parserTestPaths($env(['generate' => 'password', 'rotation' => -1])))->toContain('resources.api.env.A.rotation')
        ->and(parserTestPaths($env(['value' => 'x', 'rotation' => 1])))->toContain('resources.api.env.A.rotation');
});

it('checks ref namespaces', function (string $ref, bool $valid) {
    $raw = parserTestFile(['resources' => ['api' => ['type' => 'application', 'env' => ['A' => ['ref' => $ref]]]]]);

    expect(parserTestErrors($raw) === [])->toBe($valid);
})->with([
    ['team.SENDGRID_KEY', true],
    ['project.X', true],
    ['environment.X', true],
    ['server.X', true],
    ['vault.X', true],
    ['resource.db.internal_url', true],
    ['resource.db.port', true],
    ['resource.db.password', false],
    ['other.X', false],
    ['team.', false],
]);

it('allows null env entries only in environment overrides', function () {
    $base = parserTestFile(['resources' => ['api' => ['type' => 'application', 'env' => ['A' => null]]]]);
    $override = parserTestFile(['environments' => ['production' => ['branch' => 'main', 'resources' => ['api' => ['env' => ['A' => null]]]]]]);

    expect(parserTestPaths($base))->toContain('resources.api.env.A')
        ->and(parserTestErrors($override))->toBe([]);
});

it('rejects an unknown resource type', function () {
    expect(parserTestPaths(parserTestFile(['resources' => ['api' => ['type' => 'lambda']]])))->toContain('resources.api.type')
        ->and(parserTestPaths(parserTestFile(['resources' => ['api' => ['server' => 'x']]])))->toContain('resources.api.type');
});

it('checks moved, unmanage, and import entries', function () {
    expect(parserTestPaths(parserTestFile(['moved' => [['from' => 'api', 'to' => 'web']]])))->toContain('moved.0.from')
        ->and(parserTestPaths(parserTestFile(['moved' => [['from' => 'web']]])))->toContain('moved.0.to')
        ->and(parserTestPaths(parserTestFile(['unmanage' => ['api']])))->toContain('unmanage.0')
        ->and(parserTestPaths(parserTestFile(['environments' => ['production' => ['branch' => 'main', 'import' => [['to' => 'api']]]]])))->toContain('environments.production.import.0.uuid')
        ->and(parserTestErrors(parserTestFile(['environments' => ['production' => ['branch' => 'main', 'import' => [['to' => 'api', 'uuid' => 'k8s0abc']]]]])))->toBe([]);
});

it('rejects a file larger than 1 MiB', function () {
    $raw = parserTestFile(['x-pad' => str_repeat('a', 1048576)]);

    expect(parserTestErrors($raw))->toBe([['path' => '', 'message' => 'The file is larger than 1 MiB.']]);
});

it('rejects invalid JSON and a non-object root', function () {
    expect(parserTestPaths('{"version": 1,'))->toBe([''])
        ->and(parserTestPaths('[1,2]'))->toBe(['']);
});
