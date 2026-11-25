<?php

use App\Services\DeployTriggers\CompiledTriggerSet;
use App\Services\DeployTriggers\Contract\EvaluateApplicationInput;
use App\Services\DeployTriggers\Resolution\RepositorySnapshot;
use App\Services\DeployTriggers\Resolution\TriggerSetBuilder;

function builderApp(array $overrides = []): EvaluateApplicationInput
{
    $values = array_merge([
        'id' => 'web', 'mode' => 'smart', 'buildPack' => 'dockerfile', 'baseDirectory' => '/', 'dockerfileLocation' => '/Dockerfile',
        'dockerComposeLocation' => null, 'commands' => [], 'managedConfigPaths' => [],
    ], $overrides);

    return new EvaluateApplicationInput($values['id'], null, null, $values['mode'], null, $values['buildPack'], $values['baseDirectory'],
        $values['dockerfileLocation'], $values['dockerComposeLocation'],
        array_merge(['install' => null, 'build' => null, 'start' => null, 'compose_build' => null, 'compose_start' => null], $values['commands']),
        $values['managedConfigPaths']);
}

function monorepo(array $extra = []): RepositorySnapshot
{
    return RepositorySnapshot::fromFiles(array_merge([
        'package.json' => json_encode(['name' => 'root', 'private' => true]),
        'pnpm-workspace.yaml' => "packages:\n  - apps/*\n  - packages/*\n",
        'pnpm-lock.yaml' => 'lock',
        'README.md' => '# readme',
        '.github/workflows/ci.yml' => 'ci',
        'scripts/build.sh' => 'echo',
        'apps/web/package.json' => json_encode(['name' => '@acme/web', 'dependencies' => ['@acme/ui' => 'workspace:*']]),
        'apps/web/Dockerfile' => "FROM node\nCOPY . .\nRUN pnpm --filter @acme/web build",
        'apps/web/src/page.tsx' => 'x',
        'apps/api/package.json' => json_encode(['name' => '@acme/api']),
        'apps/api/src/main.ts' => 'x',
        'packages/ui/package.json' => json_encode(['name' => '@acme/ui']),
        'packages/ui/src/Button.tsx' => 'x',
        'packages/db/package.json' => json_encode(['name' => '@acme/db', 'scripts' => ['postinstall' => 'prisma generate']]),
        'packages/db/schema.prisma' => 'x',
        'packages/other/package.json' => json_encode(['name' => '@acme/other']),
        'packages/other/index.ts' => 'x',
    ], $extra));
}

function affects(array $built, string $path): bool
{
    return CompiledTriggerSet::fromArray($built['compiled'])->matches($path);
}

it('narrows a root-context workspace app to its closure, globals and lifecycle members', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), monorepo())->toArray();

    expect(affects($built, 'apps/web/src/page.tsx'))->toBeTrue()
        ->and(affects($built, 'packages/ui/src/Button.tsx'))->toBeTrue()
        ->and(affects($built, 'pnpm-lock.yaml'))->toBeTrue()
        ->and(affects($built, 'scripts/build.sh'))->toBeTrue()
        ->and(affects($built, 'packages/db/schema.prisma'))->toBeTrue()
        ->and(affects($built, 'apps/api/package.json'))->toBeTrue()
        ->and(affects($built, 'apps/api/src/main.ts'))->toBeFalse()
        ->and(affects($built, 'packages/other/index.ts'))->toBeFalse()
        ->and(affects($built, 'README.md'))->toBeFalse()
        ->and(affects($built, '.github/workflows/ci.yml'))->toBeFalse()
        ->and($built['limits'])->toBe(['build_context', 'workspace'])
        ->and($built['resolvers'])->toBe(['pnpm'])
        ->and($built['anchor'])->toBe(['resolver' => 'pnpm', 'package' => '@acme/web', 'dir' => 'apps/web'])
        ->and($built['widened'][0]['scope'])->toBe('dockerfile');
});

it('limits a selective Dockerfile to what it copies', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), monorepo([
        'apps/web/Dockerfile' => "FROM node\nCOPY package.json pnpm-lock.yaml ./\nCOPY apps/web apps/web\nCOPY packages packages\n",
    ]))->toArray();

    expect(affects($built, 'apps/web/src/page.tsx'))->toBeTrue()
        ->and(affects($built, 'packages/ui/src/Button.tsx'))->toBeTrue()
        ->and(affects($built, 'scripts/build.sh'))->toBeFalse()
        ->and(affects($built, 'apps/web/Dockerfile'))->toBeTrue()
        ->and($built['limits'])->toBe(['build_context', 'dockerfile', 'workspace']);
});

it('limits an app with a base directory to its context minus its dockerignore', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['baseDirectory' => '/apps/web']), monorepo([
        'apps/web/Dockerfile' => "FROM node\nCOPY . .",
        'apps/web/.dockerignore' => "*.md\ncoverage",
        'apps/web/README.md' => 'x',
        'apps/web/coverage/index.html' => 'x',
    ]))->toArray();

    expect(affects($built, 'apps/web/src/page.tsx'))->toBeTrue()
        ->and(affects($built, 'apps/web/README.md'))->toBeFalse()
        ->and(affects($built, 'apps/web/coverage/index.html'))->toBeFalse()
        ->and(affects($built, 'apps/web/.dockerignore'))->toBeTrue()
        ->and(affects($built, 'packages/ui/src/Button.tsx'))->toBeFalse()
        ->and(affects($built, 'pnpm-lock.yaml'))->toBeFalse();
});

it('limits a compose app to its build contexts, mounts and files', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['buildPack' => 'dockercompose', 'dockerfileLocation' => null, 'dockerComposeLocation' => '/deploy/docker-compose.yml']), RepositorySnapshot::fromFiles([
        'deploy/docker-compose.yml' => "services:\n  api:\n    build: ../services/api\n    volumes:\n      - ./nginx:/etc/nginx\n  db:\n    image: postgres\n",
        'services/api/main.go' => 'x', 'services/worker/main.go' => 'x', 'deploy/nginx/default.conf' => 'x', 'README.md' => 'x',
    ]))->toArray();

    expect(affects($built, 'services/api/main.go'))->toBeTrue()
        ->and(affects($built, 'deploy/nginx/default.conf'))->toBeTrue()
        ->and(affects($built, 'deploy/docker-compose.yml'))->toBeTrue()
        ->and(affects($built, 'services/worker/main.go'))->toBeFalse()
        ->and(affects($built, 'README.md'))->toBeFalse();
});

it('finds the package from the build command for a root railpack app', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['buildPack' => 'railpack', 'dockerfileLocation' => null, 'commands' => ['build' => 'pnpm turbo run build --filter=@acme/api']]), monorepo())->toArray();

    expect($built['anchor']['package'])->toBe('@acme/api')
        ->and(affects($built, 'apps/api/src/main.ts'))->toBeTrue()
        ->and(affects($built, 'apps/web/src/page.tsx'))->toBeFalse();
});

it('deploys everything it cannot attribute to a package', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['buildPack' => 'railpack', 'dockerfileLocation' => null]), monorepo())->toArray();

    expect(affects($built, 'apps/api/src/main.ts'))->toBeTrue()
        ->and(affects($built, 'README.md'))->toBeTrue()
        ->and($built['widened'])->toContain(['scope' => 'workspace', 'reason' => "Couldn't tell which package this application builds."]);
});

it('adds referenced packages to the closure', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), monorepo(), ['@acme/other'])->toArray();

    expect(affects($built, 'packages/other/index.ts'))->toBeTrue();
});

it('follows a symlink in the closure and widens on one that leaves the repository', function () {
    $snapshot = monorepo(['apps/web/shared' => '../../packages/other', 'apps/web/outside' => '../../../etc']);
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), RepositorySnapshot::fromFiles(
        array_merge(array_combine($snapshot->paths(), array_map(fn ($p) => $snapshot->read($p), $snapshot->paths()))),
        ['apps/web/shared' => '120000', 'apps/web/outside' => '120000'],
    ))->toArray();

    expect(affects($built, 'apps/api/src/main.ts'))->toBeTrue()
        ->and(collect($built['widened'])->pluck('scope'))->toContain('workspace');
});

it('lists the paths whose change can alter the set', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile', 'managedConfigPaths' => ['coolify.json']]), monorepo())->toArray();
    $inputs = CompiledTriggerSet::fromArray($built['input_patterns']);

    expect($inputs->matches('apps/new/package.json'))->toBeTrue()
        ->and($inputs->matches('pnpm-workspace.yaml'))->toBeTrue()
        ->and($inputs->matches('apps/web/Dockerfile'))->toBeTrue()
        ->and($inputs->matches('apps/web/.dockerignore'))->toBeTrue()
        ->and($inputs->matches('coolify.json'))->toBeTrue()
        ->and($inputs->matches('apps/web/src/page.tsx'))->toBeFalse();
});

it('returns the directories of the packages outside the closure', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), monorepo());

    expect($built->excludedPackages())->toBe(['@acme/api' => 'apps/api', '@acme/other' => 'packages/other']);
});
