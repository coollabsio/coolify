<?php

use App\Exceptions\DeploymentException;
use App\Services\ComposeIncludeFlattener;

it('resolves include entries like Docker Compose does', function (array $include, string $projectDirectory, array $expected) {
    expect(ComposeIncludeFlattener::includeEntries(['include' => $include], $projectDirectory))->toBe($expected);
})->with([
    'short syntax against the project directory' => [
        ['./backend/compose.yml', 'db.yml'],
        'app',
        [
            ['files' => ['app/backend/compose.yml'], 'projectDirectory' => 'app/backend', 'envFiles' => []],
            ['files' => ['app/db.yml'], 'projectDirectory' => 'app', 'envFiles' => []],
        ],
    ],
    'long syntax with a path list, project directory and env file' => [
        [['path' => ['a/compose.yml', 'a/override.yml'], 'project_directory' => 'shared', 'env_file' => ['a/.env', 'b.env']]],
        '',
        [['files' => ['a/compose.yml', 'a/override.yml'], 'projectDirectory' => 'shared', 'envFiles' => ['a/.env', 'b.env']]],
    ],
    'parent directory that stays inside the repository' => [
        ['../shared/compose.yml'],
        'app',
        [['files' => ['shared/compose.yml'], 'projectDirectory' => 'shared', 'envFiles' => []]],
    ],
]);

it('returns no entries when the file has no include section', function () {
    expect(ComposeIncludeFlattener::includeEntries(['services' => ['web' => ['image' => 'nginx']]], ''))->toBe([]);
});

it('rejects include paths that Coolify cannot read safely', function (mixed $include, string $message) {
    expect(fn () => ComposeIncludeFlattener::includeEntries(['include' => $include], 'app'))
        ->toThrow(DeploymentException::class, $message);
})->with([
    'parent directory above the repository' => [['../../etc/compose.yml'], 'must stay inside the repository'],
    'absolute path' => [['/etc/compose.yml'], 'must be a relative path'],
    'home directory' => [['~/compose.yml'], 'must be a relative path'],
    'https remote' => [['https://example.com/compose.yml'], 'Remote includes are not supported'],
    'git ssh remote' => [['git@github.com:acme/stack.git'], 'Remote includes are not supported'],
    'oci remote' => [['oci://docker.io/acme/stack:latest'], 'Remote includes are not supported'],
    'variable' => [['${STACK}/compose.yml'], 'cannot use variables'],
    'project directory above the repository' => [[['path' => 'a.yml', 'project_directory' => '../..']], 'must stay inside the repository'],
    'env file above the repository' => [[['path' => 'a.yml', 'env_file' => '/root/.env']], 'must be a relative path'],
    'missing path' => [[['project_directory' => 'a']], 'needs a path'],
    'include is not a list' => ['compose.yml', 'must be a list'],
]);
