<?php

use App\Models\Server;
use App\Services\DeployTriggers\AffectedRunnerException;
use App\Services\DeployTriggers\Contract\EvaluateApplicationInput;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\Git\GitEvaluator;
use App\Services\DeployTriggers\GitAccess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\DockerMirrorGit;
use Tests\Support\LocalMirrorGit;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/git-evaluator-'.bin2hex(random_bytes(6));
    $this->source = $this->root.'/source';
    File::makeDirectory($this->source, recursive: true);
    evalGit($this, 'init -q -b main');
    evalGit($this, 'config uploadpack.allowFilter true');
    evalGit($this, 'config uploadpack.allowAnySHA1InWant true');
    $image = getenv('DEPLOY_TRIGGERS_HELPER_IMAGE');
    $this->mirror = $image ? new DockerMirrorGit($this->root, $image) : new LocalMirrorGit($this->root);
    $this->evaluator = new GitEvaluator(fn () => $this->mirror);

    $this->base = evalCommit($this, [
        'package.json' => json_encode(['name' => 'root', 'private' => true]),
        'pnpm-workspace.yaml' => "packages:\n  - apps/*\n  - packages/*\n",
        'pnpm-lock.yaml' => 'lock',
        'README.md' => '# readme',
        'apps/web/package.json' => json_encode(['name' => '@acme/web', 'dependencies' => ['@acme/ui' => 'workspace:*']]),
        'apps/web/Dockerfile' => "FROM node\nCOPY . .\nRUN pnpm --filter @acme/web build",
        'apps/web/src/page.tsx' => 'export default 1',
        'apps/api/package.json' => json_encode(['name' => '@acme/api']),
        'apps/api/Dockerfile' => "FROM node\nCOPY . .\nRUN pnpm --filter @acme/api build",
        'apps/api/src/main.ts' => 'main',
        'packages/ui/package.json' => json_encode(['name' => '@acme/ui']),
        'packages/ui/src/Button.tsx' => 'button',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function evalGit($test, string $arguments): string
{
    $process = Process::path($test->source)->env(['GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@t', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@t'])
        ->run('git '.$arguments)->throw();

    return $process->output();
}

function evalCommit($test, array $files): string
{
    foreach ($files as $path => $content) {
        File::ensureDirectoryExists(dirname($test->source.'/'.$path));
        File::put($test->source.'/'.$path, $content);
    }
    evalGit($test, 'add -A');
    evalGit($test, 'commit -q -m change');

    return trim(evalGit($test, 'rev-parse HEAD'));
}

function gitEvaluatorInput(string $id, string $dockerfile, ?string $base, array $overrides = []): EvaluateApplicationInput
{
    $values = array_merge(['mergeBaseRef' => null, 'mode' => 'smart', 'watchPaths' => null, 'managedConfigPaths' => [], 'storedTriggerSet' => null], $overrides);

    return new EvaluateApplicationInput($id, $base, $values['mergeBaseRef'], $values['mode'], $values['watchPaths'], 'dockerfile', '/', $dockerfile, null,
        ['install' => null, 'build' => null, 'start' => null, 'compose_build' => null, 'compose_start' => null], $values['managedConfigPaths'], $values['storedTriggerSet']);
}

function gitEvaluatorRequest($test, ?string $after, array $inputs, array $refs = ['refs/heads/main'], ?GitAccess $access = null): EvaluateRequest
{
    $access ??= new GitAccess('file://'.$test->source);

    return new EvaluateRequest($access->repositoryUrl, 'mirror-key', $after, $refs, $inputs);
}

function evaluateWith($test, EvaluateRequest $request, ?GitAccess $access = null)
{
    return $test->evaluator->evaluate(new Server, $request, $access ?? new GitAccess($request->repositoryUrl));
}

it('deploys only the applications whose files changed', function () {
    $after = evalCommit($this, ['apps/api/src/main.ts' => 'changed']);

    $response = evaluateWith($this, gitEvaluatorRequest($this, $after, [
        gitEvaluatorInput('web', '/apps/web/Dockerfile', $this->base),
        gitEvaluatorInput('api', '/apps/api/Dockerfile', $this->base),
    ]));

    expect($response->after)->toBe($after)
        ->and($response->applications['web']->decision)->toBe('skip')
        ->and($response->applications['web']->reasonCode)->toBe('no_match')
        ->and($response->applications['api']->decision)->toBe('deploy')
        ->and($response->applications['api']->matches[0]['file'])->toBe('apps/api/src/main.ts')
        ->and($response->applications['web']->triggerSet['anchor']['package'])->toBe('@acme/web');

    $readme = trim(evalGit($this, "rev-parse {$after}:README.md"));
    $mirrorHasReadme = Process::env(['GIT_NO_LAZY_FETCH' => '1'])->run(['git', '-C', $this->root.'/mirrors/mirror-key.git', 'cat-file', '-e', $readme])->successful();
    expect($mirrorHasReadme)->toBeFalse();
});

it('deploys an application when a workspace dependency changes and names the chain', function () {
    $after = evalCommit($this, ['packages/ui/src/Button.tsx' => 'changed']);

    $decision = evaluateWith($this, gitEvaluatorRequest($this, $after, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $this->base)]))->applications['web'];

    expect($decision->decision)->toBe('deploy')
        ->and($decision->matches[0]['via'])->toBe(['@acme/web', '@acme/ui']);
});

it('resolves the trigger set when the push changes nothing, for evaluate now', function () {
    $decision = evaluateWith($this, gitEvaluatorRequest($this, $this->base, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $this->base)]))->applications['web'];

    expect($decision->reasonCode)->toBe('empty_diff')
        ->and($decision->triggerSet['anchor']['package'] ?? null)->toBe('@acme/web');
});

it('deploys when the base commit cannot be found', function () {
    $after = evalCommit($this, ['apps/api/src/main.ts' => 'changed']);

    $decision = evaluateWith($this, gitEvaluatorRequest($this, $after, [gitEvaluatorInput('web', '/apps/web/Dockerfile', str_repeat('e', 40))]))->applications['web'];

    expect($decision->decision)->toBe('deploy')->and($decision->reasonCode)->toBe('base_unreachable');
});

it('reuses a stored trigger set when no input of resolution changed', function () {
    $first = evalCommit($this, ['apps/api/src/main.ts' => 'one']);
    $set = evaluateWith($this, gitEvaluatorRequest($this, $first, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $this->base)]))->applications['web']->triggerSet;
    $this->mirror->scripts = [];
    $second = evalCommit($this, ['apps/api/src/main.ts' => 'two']);

    $decision = evaluateWith($this, gitEvaluatorRequest($this, $second, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $first, ['storedTriggerSet' => $set])]))->applications['web'];

    expect($decision->decision)->toBe('skip')->and($this->mirror->scripts)->toHaveCount(1)
        ->and($set['references_checked'])->toBeTrue();
});

it('re-resolves when a manifest changes and picks up a new dependency', function () {
    $after = evalCommit($this, [
        'apps/web/package.json' => json_encode(['name' => '@acme/web', 'dependencies' => ['@acme/ui' => 'workspace:*', '@acme/api' => 'workspace:*']]),
        'apps/api/src/main.ts' => 'changed',
    ]);
    $stale = evaluateWith($this, gitEvaluatorRequest($this, $this->base, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $this->base)]))->applications['web']->triggerSet;

    $decision = evaluateWith($this, gitEvaluatorRequest($this, $after, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $this->base, ['storedTriggerSet' => $stale])]))->applications['web'];

    expect($decision->decision)->toBe('deploy')->and($decision->triggerSet['packages'])->toHaveKey('@acme/api');
});

it('deploys when the application references a changed package it does not declare', function () {
    evalCommit($this, ['apps/web/src/page.tsx' => "import { main } from '@acme/api/src/main'"]);
    $base = trim(evalGit($this, 'rev-parse HEAD'));
    $after = evalCommit($this, ['apps/api/src/main.ts' => 'changed']);

    $decision = evaluateWith($this, gitEvaluatorRequest($this, $after, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $base)]))->applications['web'];

    expect($decision->decision)->toBe('deploy')
        ->and($decision->matches[0]['file'])->toBe('apps/api/src/main.ts')
        ->and($decision->triggerSet['excluded'])->not->toHaveKey('@acme/api');
});

it('checks references again when a deployment changes the application', function () {
    $first = evalCommit($this, ['apps/api/src/main.ts' => 'one']);
    $checked = evaluateWith($this, gitEvaluatorRequest($this, $first, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $this->base)]))->applications['web']->triggerSet;
    $second = evalCommit($this, ['apps/web/src/page.tsx' => "import '@acme/api'"]);

    $deployed = evaluateWith($this, gitEvaluatorRequest($this, $second, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $first, ['storedTriggerSet' => $checked])]))->applications['web'];
    $third = evalCommit($this, ['apps/api/src/main.ts' => 'two']);
    $next = evaluateWith($this, gitEvaluatorRequest($this, $third, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $second, ['storedTriggerSet' => $deployed->triggerSet])]))->applications['web'];

    expect($checked['references_checked'])->toBeTrue()
        ->and($deployed->decision)->toBe('deploy')
        ->and($deployed->triggerSet['excluded'])->not->toHaveKey('@acme/api')
        ->and($next->decision)->toBe('deploy');
});

it('finds a reference through a tsconfig path alias', function () {
    evalCommit($this, [
        'tsconfig.base.json' => "{\n  // aliases\n  \"compilerOptions\": { \"paths\": { \"@api/*\": [\"apps/api/src/*\"] } }\n}",
        'apps/web/src/page.tsx' => "import { main } from '@api/main'",
    ]);
    $base = trim(evalGit($this, 'rev-parse HEAD'));
    $after = evalCommit($this, ['apps/api/src/main.ts' => 'changed']);

    $decision = evaluateWith($this, gitEvaluatorRequest($this, $after, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $base)]))->applications['web'];

    expect($decision->decision)->toBe('deploy');
});

it('re-resolves after a push adds a symlink, so its target counts', function () {
    $first = evalCommit($this, ['apps/api/src/main.ts' => 'one']);
    $set = evaluateWith($this, gitEvaluatorRequest($this, $first, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $this->base)]))->applications['web']->triggerSet;
    symlink('../api', $this->source.'/apps/web/api-link');
    $second = evalCommit($this, []);
    $deployed = evaluateWith($this, gitEvaluatorRequest($this, $second, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $first, ['storedTriggerSet' => $set])]))->applications['web'];
    $third = evalCommit($this, ['apps/api/src/main.ts' => 'two']);

    $next = evaluateWith($this, gitEvaluatorRequest($this, $third, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $second, ['storedTriggerSet' => $deployed->triggerSet])]))->applications['web'];

    expect($deployed->decision)->toBe('deploy')->and($next->decision)->toBe('deploy');
});

it('stops fetching a repository that is larger than the limit for a day', function () {
    $after = evalCommit($this, ['apps/api/src/main.ts' => 'changed']);
    $request = new EvaluateRequest('file://'.$this->source, 'mirror-key', $after, ['refs/heads/main'], [gitEvaluatorInput('web', '/apps/web/Dockerfile', $this->base)], ['max_changed_files' => 50000, 'max_mirror_bytes' => 1024]);

    expect(fn () => evaluateWith($this, $request))->toThrow(AffectedRunnerException::class, 'larger than the limit');
    expect(fn () => evaluateWith($this, $request))->toThrow(AffectedRunnerException::class, 'larger than the limit');
    expect($this->mirror->scripts)->toHaveCount(1);
});

it('compares a new pull request with its merge base', function () {
    evalGit($this, 'checkout -q -b feature');
    $after = evalCommit($this, ['packages/ui/src/Button.tsx' => 'feature']);
    evalGit($this, 'checkout -q main');
    evalCommit($this, ['apps/web/src/page.tsx' => 'main moved on']);

    $decisions = evaluateWith($this, gitEvaluatorRequest($this, $after, [
        gitEvaluatorInput('web:pr-1', '/apps/web/Dockerfile', null, ['mergeBaseRef' => 'refs/heads/main']),
        gitEvaluatorInput('api:pr-1', '/apps/api/Dockerfile', null, ['mergeBaseRef' => 'refs/heads/main']),
    ], ['refs/heads/feature', 'refs/heads/main']))->applications;

    expect($decisions['web:pr-1']->decision)->toBe('deploy')
        ->and($decisions['web:pr-1']->base)->toBe($this->base)
        ->and($decisions['api:pr-1']->decision)->toBe('skip');
});

it('applies watch paths to the exact diff', function () {
    $after = evalCommit($this, ['apps/api/src/main.ts' => 'changed']);

    $decision = evaluateWith($this, gitEvaluatorRequest($this, $after, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $this->base, ['mode' => 'watch_paths', 'watchPaths' => 'apps/web/**'])]))->applications['web'];

    expect($decision->decision)->toBe('skip')->and($decision->reasonCode)->toBe('watch_paths_no_match');
});

it('deploys when the coolify.json of the application changes', function () {
    $after = evalCommit($this, ['coolify.json' => '{}']);

    $decision = evaluateWith($this, gitEvaluatorRequest($this, $after, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $this->base, ['managedConfigPaths' => ['coolify.json']])]))->applications['web'];

    expect($decision->decision)->toBe('deploy')->and($decision->reasonCode)->toBe('config_changed');
});

it('keeps tokens out of errors and out of the files it leaves behind', function () {
    $token = 'tok-'.bin2hex(random_bytes(8));
    $access = new GitAccess('file://'.$this->root.'/missing', config: ["url.file://x-access-token:{$token}@{$this->root}/.insteadOf=file://{$this->root}/"]);
    $request = gitEvaluatorRequest($this, str_repeat('a', 40), [gitEvaluatorInput('web', '/apps/web/Dockerfile', $this->base)], access: $access);

    expect(fn () => evaluateWith($this, $request, $access))->toThrow(AffectedRunnerException::class);
    try {
        evaluateWith($this, $request, $access);
    } catch (AffectedRunnerException $exception) {
        expect($exception->getMessage())->not->toContain($token);
    }
    expect(Process::run(['grep', '-rl', $token, $this->root.'/mirrors'])->output())->toBe('');
});

it('deploys a Compose application that builds from the repository root when its source changes', function () {
    $base = evalCommit($this, ['docker-compose.yaml' => "services:\n  app:\n    build: .\n", 'Dockerfile' => "FROM node\nCOPY . .\n", 'src/index.js' => '1']);
    $after = evalCommit($this, ['src/index.js' => '2']);
    $input = new EvaluateApplicationInput('app', $base, null, 'smart', null, 'dockercompose', '/', null, '/docker-compose.yaml',
        ['install' => null, 'build' => null, 'start' => null, 'compose_build' => null, 'compose_start' => null], []);

    expect(evaluateWith($this, gitEvaluatorRequest($this, $after, [$input]))->applications['app']->decision)->toBe('deploy');
});

it('follows the workspace dependencies of a package that the application references without declaring it', function () {
    $base = evalCommit($this, [
        'apps/web/src/page.tsx' => "import { Button } from '@acme/editor'",
        'packages/editor/package.json' => json_encode(['name' => '@acme/editor', 'dependencies' => ['@acme/core' => 'workspace:*']]),
        'packages/editor/src/index.ts' => 'export const editor = 1',
        'packages/core/package.json' => json_encode(['name' => '@acme/core']),
        'packages/core/src/index.ts' => 'export const x = 1',
    ]);
    $after = evalCommit($this, ['packages/core/src/index.ts' => 'export const x = 2']);

    $decision = evaluateWith($this, gitEvaluatorRequest($this, $after, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $base)]))->applications['web'];

    expect($decision->decision)->toBe('deploy')
        ->and($decision->triggerSet['packages'])->toHaveKeys(['@acme/editor', '@acme/core']);
});

it('searches the packages that a reference brings in for further references', function () {
    $base = evalCommit($this, [
        'apps/web/src/page.tsx' => "import { Editor } from '@acme/editor'",
        'packages/editor/package.json' => json_encode(['name' => '@acme/editor']),
        'packages/editor/src/index.ts' => "export * from '@acme/core'",
        'packages/core/package.json' => json_encode(['name' => '@acme/core']),
        'packages/core/src/index.ts' => 'export const x = 1',
    ]);
    $after = evalCommit($this, ['packages/core/src/index.ts' => 'export const x = 2']);

    $decision = evaluateWith($this, gitEvaluatorRequest($this, $after, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $base)]))->applications['web'];

    expect($decision->decision)->toBe('deploy')
        ->and($decision->triggerSet['references_checked'])->toBeTrue();
});

it('keeps checking references on later pushes after a reference brought in a package', function () {
    $base = evalCommit($this, [
        'apps/web/src/page.tsx' => "import { Editor } from '@acme/editor'",
        'packages/editor/package.json' => json_encode(['name' => '@acme/editor']),
        'packages/editor/src/index.ts' => "export * from '@acme/core'",
        'packages/core/package.json' => json_encode(['name' => '@acme/core']),
        'packages/core/src/index.ts' => 'export const x = 1',
    ]);
    $middle = evalCommit($this, ['packages/editor/src/index.ts' => "export * from '@acme/core' // 2"]);
    $first = evaluateWith($this, gitEvaluatorRequest($this, $middle, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $base)]))->applications['web'];
    $after = evalCommit($this, ['packages/core/src/index.ts' => 'export const x = 2']);

    $next = evaluateWith($this, gitEvaluatorRequest($this, $after, [gitEvaluatorInput('web', '/apps/web/Dockerfile', $middle, ['storedTriggerSet' => $first->triggerSet])]))->applications['web'];

    expect($first->decision)->toBe('deploy')
        ->and($next->decision)->toBe('deploy');
});
