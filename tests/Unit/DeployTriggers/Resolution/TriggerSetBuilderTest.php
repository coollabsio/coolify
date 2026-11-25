<?php

use App\Services\DeployTriggers\CompiledTriggerSet;
use App\Services\DeployTriggers\Contract\EvaluateApplicationInput;
use App\Services\DeployTriggers\Resolution\RepositorySnapshot;
use App\Services\DeployTriggers\Resolution\TriggerSetBuilder;
use App\Services\DeployTriggers\TriggerSetDecider;

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
        ->and(affects($built, 'LICENSE'))->toBeFalse()
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
        'deploy/docker-compose.yml' => "services:\n  api:\n    build: ./services/api\n    volumes:\n      - ./deploy/nginx:/etc/nginx\n  db:\n    image: postgres\n",
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

it('follows a symlink in the closure and ignores one that leaves the repository', function () {
    $snapshot = monorepo(['apps/web/shared' => '../../packages/other', 'apps/web/outside' => '../../../etc']);
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), RepositorySnapshot::fromFiles(
        array_merge(array_combine($snapshot->paths(), array_map(fn ($p) => $snapshot->read($p), $snapshot->paths()))),
        ['apps/web/shared' => '120000', 'apps/web/outside' => '120000'],
    ))->toArray();

    expect(affects($built, 'packages/other/index.ts'))->toBeTrue()
        ->and(affects($built, 'apps/api/src/main.ts'))->toBeFalse()
        ->and($built['widened'])->toHaveCount(1);
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

it('reads the Dockerfile relative to the base directory', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['baseDirectory' => '/apps/web', 'dockerfileLocation' => '/Dockerfile']), monorepo([
        'Dockerfile' => "FROM node\nCOPY root.txt /",
        'apps/web/Dockerfile' => "FROM node\nCOPY src src",
    ]))->toArray();

    expect(affects($built, 'apps/web/src/a.ts'))->toBeTrue()
        ->and(affects($built, 'apps/web/Dockerfile'))->toBeTrue()
        ->and(affects($built, 'apps/web/README.md'))->toBeFalse()
        ->and(CompiledTriggerSet::fromArray($built['input_patterns'])->matches('apps/web/Dockerfile'))->toBeTrue();
});

it('resolves compose paths from the base directory, as docker compose --project-directory does', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['buildPack' => 'dockercompose', 'baseDirectory' => '/apps/x', 'dockerfileLocation' => null, 'dockerComposeLocation' => '/deploy/compose.yaml']), RepositorySnapshot::fromFiles([
        'apps/x/deploy/compose.yaml' => "services:\n  api:\n    build: ../shared\n  web:\n    build: .\n    volumes:\n      - type: bind\n        source: nginx.conf\n        target: /etc/nginx.conf\n",
        'apps/shared/main.go' => 'x', 'apps/x/src/main.go' => 'x', 'apps/x/nginx.conf' => 'x', 'apps/other/main.go' => 'x',
    ]))->toArray();

    expect(affects($built, 'apps/shared/main.go'))->toBeTrue()
        ->and(affects($built, 'apps/x/src/main.go'))->toBeTrue()
        ->and(affects($built, 'apps/x/nginx.conf'))->toBeTrue()
        ->and(affects($built, 'apps/x/deploy/compose.yaml'))->toBeTrue()
        ->and(affects($built, 'apps/other/main.go'))->toBeFalse();
});

it('deploys every package when a build command selects several or all of them', function (string $command) {
    $built = (new TriggerSetBuilder)->build(builderApp(['buildPack' => 'railpack', 'dockerfileLocation' => null, 'commands' => ['build' => $command]]), monorepo())->toArray();

    expect(affects($built, 'packages/other/index.ts'))->toBeTrue();
})->with([
    'two filters' => 'turbo run build --filter=@acme/web --filter=@acme/other',
    'dependents' => 'pnpm --filter ...@acme/ui build',
    'recursive' => 'pnpm -r build',
    'unfiltered turbo' => 'turbo run build',
    'run-many' => 'nx run-many -t build',
]);

it('ignores build commands that the build pack does not run', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile', 'commands' => ['build' => 'pnpm --filter @acme/other build']]), monorepo())->toArray();

    expect($built['anchor']['package'])->toBe('@acme/web')
        ->and(affects($built, 'packages/other/index.ts'))->toBeFalse();
});

it('anchors on the package that the Dockerfile builds, and widens on a Dockerfile that builds them all', function () {
    $selected = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/Dockerfile']), monorepo([
        'Dockerfile' => "FROM node\nCOPY . .\nRUN pnpm install && pnpm turbo prune @acme/api --docker",
    ]))->toArray();
    $all = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), monorepo([
        'apps/web/Dockerfile' => "FROM node\nCOPY . .\nRUN pnpm install && pnpm turbo run build",
    ]))->toArray();

    expect($selected['anchor']['package'])->toBe('@acme/api')
        ->and(affects($all, 'packages/other/index.ts'))->toBeTrue();
});

it('keeps root files that the Dockerfile copies explicitly', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), monorepo([
        'apps/web/Dockerfile' => "FROM node\nCOPY README.md ./\nCOPY apps/web apps/web\n",
    ]))->toArray();

    expect(affects($built, 'README.md'))->toBeTrue();
});

it('widens a Dockerfile source with brackets', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), monorepo([
        'apps/web/Dockerfile' => "FROM node\nCOPY apps/web/[ab].txt ./\n",
        'apps/web/a.txt' => 'x',
    ]))->toArray();

    expect(affects($built, 'apps/web/a.txt'))->toBeTrue();
});

it('follows a symlink that a Dockerfile copies', function () {
    $files = ['Dockerfile' => "FROM alpine\nCOPY lib /lib\n", 'lib' => 'vendor/lib', 'vendor/lib/a.c' => 'x', 'other/b.c' => 'x'];
    $built = (new TriggerSetBuilder)->build(builderApp(), RepositorySnapshot::fromFiles($files, ['lib' => '120000']))->toArray();

    expect(affects($built, 'vendor/lib/a.c'))->toBeTrue()
        ->and(affects($built, 'other/b.c'))->toBeFalse();
});

it('records the tsconfig path aliases of packages outside the closure for the reference check', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), monorepo([
        'tsconfig.base.json' => "{\n  // aliases\n  \"compilerOptions\": { \"baseUrl\": \".\", \"paths\": { \"@other/*\": [\"packages/other/src/*\"], \"@ui\": [\"packages/ui/index.ts\"], }, },\n}",
    ]))->toArray();

    expect($built['reference_needles']['@acme/other'])->toContain('@other/', '@acme/other', 'packages/other', '../other')
        ->and($built['reference_needles'])->not->toHaveKey('@acme/ui')
        ->and(CompiledTriggerSet::fromArray($built['input_patterns'])->matches('tsconfig.base.json'))->toBeTrue();
});

it('includes the whole repository for a Compose service that builds or mounts the repository root', function (string $compose) {
    $snapshot = RepositorySnapshot::fromFiles(['docker-compose.yaml' => $compose, 'Dockerfile' => "FROM node\nCOPY . .\n", 'src/index.js' => 'x']);

    $built = (new TriggerSetBuilder)->build(builderApp(['buildPack' => 'dockercompose', 'dockerComposeLocation' => '/docker-compose.yaml']), $snapshot)->toArray();

    expect(affects($built, 'src/index.js'))->toBeTrue();
})->with([
    'build .' => "services:\n  app:\n    build: .\n",
    'bind mount .' => "services:\n  app:\n    image: node\n    volumes:\n      - .:/app\n",
]);

it('widens the workspace when one Dockerfile step names a package and another builds every package', function (string $dockerfile) {
    $built = (new TriggerSetBuilder)->build(builderApp(), monorepo(['Dockerfile' => $dockerfile]))->toArray();

    expect(affects($built, 'apps/web/src/page.tsx'))->toBeTrue()
        ->and($built['limits'])->not->toContain('workspace');
})->with([
    'separate steps' => "FROM node\nCOPY . .\nRUN pnpm --filter @acme/api prisma generate\nRUN pnpm -r build\n",
    'one step' => "FROM node\nCOPY . .\nRUN pnpm --filter @acme/api prisma generate && pnpm -r build\n",
    'exec form' => "FROM node\nCOPY . .\nRUN pnpm --filter @acme/api prisma generate\nCMD [\"pnpm\", \"-r\", \"--parallel\", \"start\"]\n",
]);

it('keeps a filtered recursive command narrow', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(), monorepo([
        'Dockerfile' => "FROM node\nCOPY . .\nRUN pnpm -r --filter @acme/web build\n",
    ]))->toArray();

    expect($built['anchor']['package'])->toBe('@acme/web')
        ->and(affects($built, 'apps/api/src/main.ts'))->toBeFalse();
});

it('drops the Compose limit when the Compose commands name other Compose or env files', function () {
    $snapshot = RepositorySnapshot::fromFiles([
        'docker-compose.yaml' => "services:\n  app:\n    image: nginx\n",
        'docker-compose.prod.yaml' => "services:\n  app:\n    build: ./app\n",
        'app/index.html' => 'x',
    ]);
    $command = 'docker compose -f docker-compose.yaml -f docker-compose.prod.yaml';

    $built = (new TriggerSetBuilder)->build(builderApp(['buildPack' => 'dockercompose', 'dockerComposeLocation' => '/docker-compose.yaml',
        'commands' => ['compose_build' => "{$command} build", 'compose_start' => "{$command} up -d"]]), $snapshot)->toArray();

    expect(affects($built, 'app/index.html'))->toBeTrue()
        ->and(affects($built, 'docker-compose.prod.yaml'))->toBeTrue()
        ->and($built['limits'])->not->toContain('compose');
});

it('widens the workspace when a Compose service builds from a directory that holds several packages', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['buildPack' => 'dockercompose', 'dockerComposeLocation' => '/docker-compose.yaml']), monorepo([
        'docker-compose.yaml' => "services:\n  web:\n    build: ./apps/web\n  worker:\n    build:\n      context: .\n      dockerfile: apps/api/Dockerfile\n",
        'apps/api/Dockerfile' => "FROM node\nCOPY . .\nRUN cd apps/api && pnpm build\n",
    ]))->toArray();

    expect(affects($built, 'apps/api/src/main.ts'))->toBeTrue();
});

it('keeps a Compose app narrow when it also mounts a directory outside the packages', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['buildPack' => 'dockercompose', 'dockerComposeLocation' => '/docker-compose.yaml']), monorepo([
        'docker-compose.yaml' => "services:\n  web:\n    build: ./apps/web\n    volumes:\n      - ./nginx:/etc/nginx\n",
    ]))->toArray();

    expect(affects($built, 'apps/web/src/page.tsx'))->toBeTrue()
        ->and(affects($built, 'apps/api/src/main.ts'))->toBeFalse();
});

it('includes a package that the Dockerfile runs by its directory', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(), monorepo([
        'Dockerfile' => "FROM node\nCOPY . .\nRUN node packages/other/index.ts\nRUN pnpm --filter @acme/web build\n",
    ]))->toArray();

    expect(affects($built, 'packages/other/index.ts'))->toBeTrue()
        ->and(affects($built, 'apps/api/src/main.ts'))->toBeFalse()
        ->and($built['anchor']['package'])->toBe('@acme/web');
});

it('does not narrow to a package that the Dockerfile only runs by its directory', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(), monorepo([
        'Dockerfile' => "FROM node\nCOPY . .\nRUN node packages/other/index.ts\n",
    ]))->toArray();

    expect(affects($built, 'apps/api/src/main.ts'))->toBeTrue();
});

it('keeps root files that a build can read, such as the README and the CHANGELOG', function (string $file) {
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), monorepo())->toArray();

    expect(affects($built, $file))->toBeTrue();
})->with(['README.md', 'CHANGELOG.md', '.editorconfig']);

it('deploys when a submodule changes that holds files of the set', function (array $application, array $files) {
    $built = (new TriggerSetBuilder)->build(builderApp($application), RepositorySnapshot::fromFiles([...$files, 'vendor/lib' => 'gitlink'], ['vendor/lib' => '160000']))->toArray();

    expect(TriggerSetDecider::decide('web', $built, ['vendor/lib'], null)['decision']?->deploys())->toBeTrue()
        ->and(TriggerSetDecider::decide('web', $built, ['vendor/other'], null)['decision']?->deploys())->toBeFalse();
})->with([
    'copied below it' => [[], ['Dockerfile' => "FROM node\nCOPY vendor/lib/src ./src\n"]],
    'base directory below it' => [['buildPack' => 'railpack', 'baseDirectory' => '/vendor/lib/app', 'dockerfileLocation' => null], []],
]);

it('shows root files as files and leaves out the paths that do not exist yet', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), monorepo())->toArray();

    expect($built['display'])->toContain('workspace: package.json', 'workspace: pnpm-lock.yaml', 'workspace: apps/web/**', 'workspace: packages/ui/**')
        ->not->toContain('workspace: package.json/**', 'workspace: yarn.lock/**', 'workspace: yarn.lock')
        // The rules still cover a lockfile that a later push adds.
        ->and(affects($built, 'yarn.lock'))->toBeTrue();
});

it('lists each glob once, hides globs under missing folders, and folds the package manifests into one line', function () {
    $built = (new TriggerSetBuilder)->build(builderApp(['dockerfileLocation' => '/apps/web/Dockerfile']), monorepo())->toArray();
    $workspace = array_values(array_filter($built['display'], fn (string $line) => str_starts_with($line, 'workspace: ')));

    expect($workspace)->toContain('workspace: tsconfig*.json', 'workspace: **/package.json')
        ->not->toContain('workspace: tsconfig*.json/**', 'workspace: .yarn/**', 'workspace: apps/api/package.json')
        ->and(affects($built, '.yarn/releases/yarn.cjs'))->toBeTrue()
        ->and(affects($built, 'apps/api/package.json'))->toBeTrue();
});
