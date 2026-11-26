<?php

use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\Server;
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

it('finds the include section when the file uses Compose merge tags', function () {
    $compose = "include:\n  - shared/compose.yml\nservices:\n  web:\n    image: nginx\n    ports: !reset []\n";

    expect(ComposeIncludeFlattener::hasIncludes($compose))->toBeTrue();
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

it('resolves extends files against the project directory', function () {
    $compose = ['services' => [
        'api' => ['extends' => ['file' => '../shared/base.yml', 'service' => 'base']],
        'worker' => ['extends' => ['file' => '../shared/base.yml', 'service' => 'worker']],
        'web' => ['extends' => 'api'],
        'db' => ['image' => 'postgres'],
    ]];

    expect(ComposeIncludeFlattener::extendsFiles($compose, 'app'))->toBe(['shared/base.yml']);
});

it('rejects extends files that Coolify cannot read safely', function (string $file, string $message) {
    expect(fn () => ComposeIncludeFlattener::extendsFiles(['services' => ['api' => ['extends' => ['file' => $file, 'service' => 'api']]]], 'app'))
        ->toThrow(DeploymentException::class, $message);
})->with([
    'absolute path' => ['/etc/compose.yml', 'must be a relative path'],
    'parent directory above the repository' => ['../../compose.yml', 'must stay inside the repository'],
    'variable' => ['${BASE}/compose.yml', 'cannot use variables'],
]);

it('stages the nested includes of a shared file for each project directory that reaches it', function () {
    $repository = [
        'compose.yml' => "include:\n  - path: [a/base.yml, shared/override.yml]\n  - path: [b/base.yml, shared/override.yml]\n",
        'a/base.yml' => "services: {}\n",
        'b/base.yml' => "services: {}\n",
        'shared/override.yml' => "include:\n  - extra.yml\n",
        'a/extra.yml' => "services: {}\n",
        'b/extra.yml' => "services: {}\n",
    ];
    $flattener = new class(Mockery::mock(Server::class), '/checkout', '/flat', $repository) extends ComposeIncludeFlattener
    {
        public array $copied = [];

        public function __construct(Server $server, string $checkoutDirectory, string $flatDirectory, private array $repository)
        {
            parent::__construct($server, $checkoutDirectory, $flatDirectory);
        }

        protected function ensureComposeVersion(): void {}

        protected function copyFile(string $file): string
        {
            $this->copied[] = $file;

            return $this->repository[$file];
        }

        protected function runComposeConfig(string $composeFile, string $projectDirectory, array $envFiles): string
        {
            return "services: {}\n";
        }
    };

    $flattener->flatten('compose.yml', '');

    expect($flattener->copied)->toBe(['compose.yml', 'a/base.yml', 'shared/override.yml', 'b/base.yml', 'a/extra.yml', 'b/extra.yml']);
});

it('rejects a flattened file that grows above the size limit when Coolify saves it', function () {
    // Coolify adds `./` to each bind source and writes each inline volume as an indented block.
    $output = "services:\n  api:\n    image: nginx\n    volumes:\n".str_repeat("      - {type: bind, source: d, target: /d}\n", 95000);
    expect(strlen($output))->toBeLessThan(Application::MAX_DOCKER_COMPOSE_SIZE_BYTES);

    $flattener = new class(Mockery::mock(Server::class), '/checkout', '/flat', $output) extends ComposeIncludeFlattener
    {
        public function __construct(Server $server, string $checkoutDirectory, string $flatDirectory, private string $output)
        {
            parent::__construct($server, $checkoutDirectory, $flatDirectory);
        }

        protected function ensureComposeVersion(): void {}

        protected function copyFile(string $file): string
        {
            return "include:\n  - a.yml\n";
        }

        protected function runComposeConfig(string $composeFile, string $projectDirectory, array $envFiles): string
        {
            return $this->output;
        }
    };

    expect(fn () => $flattener->flatten('compose.yml', ''))
        ->toThrow(DeploymentException::class, 'exceeds the 5 MiB size limit');
});
