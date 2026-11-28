<?php

use App\Services\DeployTriggers\Resolution\JsWorkspaces;
use App\Services\DeployTriggers\Resolution\RepositorySnapshot;
use App\Services\DeployTriggers\Resolution\WidenException;

function jsSnapshot(array $files): RepositorySnapshot
{
    return RepositorySnapshot::fromFiles($files);
}

it('reads pnpm workspace members, dependencies and lifecycle scripts', function () {
    $graph = JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['name' => 'root', 'private' => true]),
        'pnpm-workspace.yaml' => "packages:\n  - 'apps/*'\n  - 'packages/**'\n  - '!packages/legacy'\n",
        'apps/web/package.json' => json_encode(['name' => '@acme/web', 'dependencies' => ['@acme/ui' => 'workspace:*', 'react' => '^19']]),
        'apps/api/package.json' => json_encode(['name' => '@acme/api', 'devDependencies' => ['@acme/db' => '*']]),
        'packages/ui/package.json' => json_encode(['name' => '@acme/ui', 'dependencies' => ['@acme/tokens' => 'workspace:^']]),
        'packages/design/tokens/package.json' => json_encode(['name' => '@acme/tokens']),
        'packages/db/package.json' => json_encode(['name' => '@acme/db', 'scripts' => ['postinstall' => 'prisma generate']]),
        'packages/legacy/package.json' => json_encode(['name' => '@acme/legacy']),
        'apps/web/src/index.ts' => 'x',
    ]));

    expect($graph->packages)->toBe([
        '@acme/api' => 'apps/api', '@acme/web' => 'apps/web', '@acme/db' => 'packages/db',
        '@acme/tokens' => 'packages/design/tokens', '@acme/ui' => 'packages/ui',
    ])
        ->and($graph->closure(['@acme/web']))->toBe(['@acme/web', '@acme/ui', '@acme/tokens'])
        ->and($graph->lifecycleDirectories)->toBe(['packages/db'])
        ->and($graph->packageOwning('packages/ui/src/Button.tsx'))->toBe('@acme/ui')
        ->and($graph->packageOwning('packages/design/tokens/x.json'))->toBe('@acme/tokens')
        ->and($graph->packageOwning('scripts/x.sh'))->toBeNull();
});

it('reads npm and yarn workspaces from package.json', function (mixed $workspaces) {
    $graph = JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['name' => 'root', 'workspaces' => $workspaces]),
        'apps/web/package.json' => json_encode(['name' => 'web']),
    ]));

    expect($graph->packages)->toBe(['web' => 'apps/web']);
})->with([
    'array' => [['apps/*']],
    'object' => [['packages' => ['apps/*']]],
]);

it('is not a workspace without a workspace definition', function () {
    expect(JsWorkspaces::resolve(jsSnapshot(['package.json' => json_encode(['name' => 'single'])])))->toBeNull();
});

it('widens on manifests it cannot read', function () {
    JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['workspaces' => ['apps/*']]),
        'apps/web/package.json' => '{not json',
    ]));
})->throws(WidenException::class);

it('reads turbo global dependencies', function () {
    $graph = JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['workspaces' => ['apps/*']]),
        'apps/web/package.json' => json_encode(['name' => 'web']),
        'turbo.json' => json_encode(['globalDependencies' => ['.env', 'tsconfig.base.json']]),
    ]));

    expect($graph->globalPatterns)->toContain('.env', 'tsconfig.base.json', 'turbo.json', 'package.json');
});

it('finds the packages a build command selects', function (string $command, ?array $expected) {
    $graph = JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['workspaces' => ['apps/*']]),
        'apps/web/package.json' => json_encode(['name' => '@acme/web']),
        'apps/api/package.json' => json_encode(['name' => 'api']),
    ]));

    expect($graph->packagesFromCommand($command))->toBe($expected);
})->with([
    ['pnpm --filter @acme/web build', ['@acme/web']],
    ['pnpm -F api build', ['api']],
    ['pnpm --filter=@acme/web... build', ['@acme/web']],
    ['turbo run build --filter=api', ['api']],
    ['turbo run build --filter=api --filter=@acme/web', ['api', '@acme/web']],
    ['turbo prune api --docker', ['api']],
    ['npm run build -w apps/web', ['@acme/web']],
    ['npm run build --workspace=api', ['api']],
    ['yarn workspace @acme/web build', ['@acme/web']],
    ['bun --filter api run build', ['api']],
    ['npx nx build api', ['api']],
    ['npm run build', []],
    ['pnpm install --frozen-lockfile', []],
    ['pnpm --filter "./apps/**" build', null],
    ['pnpm --filter ...api build', null],
    ['pnpm --filter unknown build', null],
    ['pnpm -r build', null],
    ['turbo run build', null],
    ['npm run build --workspaces', null],
    ['nx run-many -t build', null],
]);

it('follows the package scripts a command runs', function (string $command, ?array $expected) {
    $graph = JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['workspaces' => ['apps/*'], 'scripts' => [
            'build' => 'turbo run build', 'build:web' => 'pnpm --filter @acme/web build', 'deploy' => 'npm run build:web',
            'lint' => 'eslint .', 'prelint' => 'pnpm -r codegen', 'a' => 'npm run b', 'b' => 'npm run a',
        ]]),
        'apps/web/package.json' => json_encode(['name' => '@acme/web', 'scripts' => ['build' => 'next build']]),
        'apps/api/package.json' => json_encode(['name' => 'api', 'scripts' => ['start' => 'node dist/main.js']]),
    ]));

    expect($graph->packagesFromCommand($command))->toBe($expected);
})->with([
    'root script that builds every package' => ['npm run build', null],
    'pnpm shorthand' => ['pnpm build', null],
    'root script that names a package' => ['yarn run build:web', ['@acme/web']],
    'nested root scripts' => ['npm run deploy', ['@acme/web']],
    'pre script' => ['npm run lint', null],
    'filtered to a package script' => ['pnpm --filter @acme/web build', ['@acme/web']],
    'cycle' => ['npm run a', []],
    'no such script' => ['npm run test', []],
]);

it('adds the packages that root lifecycle scripts run', function () {
    $graph = JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['workspaces' => ['packages/*'], 'scripts' => ['postinstall' => 'node packages/codegen/generate.js', 'prepare' => 'husky']]),
        'packages/codegen/package.json' => json_encode(['name' => 'codegen']),
        'packages/codegen/generate.js' => 'x',
        'packages/web/package.json' => json_encode(['name' => 'web']),
    ]));

    expect($graph->lifecycleDirectories)->toBe(['packages/codegen']);
});

it('widens on root lifecycle scripts it cannot bound', function (string $script) {
    JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['workspaces' => ['packages/*'], 'scripts' => ['postinstall' => $script]]),
        'packages/web/package.json' => json_encode(['name' => 'web']),
        'scripts/generate.js' => 'x',
    ]));
})->throws(WidenException::class)->with([
    'every package' => 'turbo run generate',
    'a repository file' => 'node scripts/generate.js',
]);

it('includes the workspace dependencies of lifecycle members', function () {
    $graph = JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['workspaces' => ['packages/*']]),
        'packages/codegen/package.json' => json_encode(['name' => 'codegen', 'scripts' => ['postinstall' => 'node generate.js'], 'dependencies' => ['schema' => '*']]),
        'packages/schema/package.json' => json_encode(['name' => 'schema', 'dependencies' => ['types' => '*']]),
        'packages/types/package.json' => json_encode(['name' => 'types']),
        'packages/web/package.json' => json_encode(['name' => 'web']),
    ]));

    expect($graph->lifecycleDirectories)->toBe(['packages/codegen', 'packages/schema', 'packages/types']);
});

it('includes the literal folder of a turbo global dependency that uses brace or bracket globs', function () {
    $graph = JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['workspaces' => ['apps/*']]),
        'apps/web/package.json' => json_encode(['name' => 'web']),
        'turbo.json' => json_encode(['globalDependencies' => ['packages/config/*.{json,yaml}', 'env/[ab].env', '.env']]),
    ]));

    expect($graph->globalPatterns)->toContain('packages/config', 'env', '.env')
        ->not->toContain('packages/config/*.{json,yaml}');
});

it('widens on a turbo global dependency with brace globs at the root', function () {
    JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['workspaces' => ['apps/*']]),
        'apps/web/package.json' => json_encode(['name' => 'web']),
        'turbo.json' => json_encode(['globalDependencies' => ['**/*.{json,yaml}']]),
    ]));
})->throws(WidenException::class);

it('reads a turbo.json with comments and trailing commas', function () {
    $graph = JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['workspaces' => ['apps/*']]),
        'apps/web/package.json' => json_encode(['name' => 'web']),
        'turbo.json' => "{\n  // shared inputs\n  \"globalDependencies\": [\".env\",],\n}",
    ]));

    expect($graph->globalPatterns)->toContain('.env');
});
