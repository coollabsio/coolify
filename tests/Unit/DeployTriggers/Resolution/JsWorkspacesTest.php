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

it('finds the package a build command selects', function (string $command, ?string $expected) {
    $graph = JsWorkspaces::resolve(jsSnapshot([
        'package.json' => json_encode(['workspaces' => ['apps/*']]),
        'apps/web/package.json' => json_encode(['name' => '@acme/web']),
        'apps/api/package.json' => json_encode(['name' => 'api']),
    ]));

    expect($graph->packageFromCommand($command))->toBe($expected);
})->with([
    ['pnpm --filter @acme/web build', '@acme/web'],
    ['pnpm -F api build', 'api'],
    ['pnpm --filter=@acme/web... build', '@acme/web'],
    ['turbo run build --filter=api', 'api'],
    ['npm run build -w apps/web', '@acme/web'],
    ['npm run build --workspace=api', 'api'],
    ['yarn workspace @acme/web build', '@acme/web'],
    ['bun --filter api run build', 'api'],
    ['npx nx build api', 'api'],
    ['npm run build', null],
    ['pnpm --filter "./apps/**" build', null],
]);
