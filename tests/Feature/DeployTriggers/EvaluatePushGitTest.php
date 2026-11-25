<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\EvaluatePush;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Services\DeployTriggers\AffectedRunner;
use App\Services\DeployTriggers\EvaluationSlots;
use App\Services\DeployTriggers\Git\GitEvaluator;
use App\Services\DeployTriggers\PushContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\LocalMirrorGit;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([ApplicationDeploymentJob::class]);
    app()->instance(EvaluationSlots::class, new class extends EvaluationSlots
    {
        public function run(Closure $callback, Closure $whenBusy): void
        {
            $callback();
        }
    });

    $this->root = sys_get_temp_dir().'/evaluate-push-git-'.bin2hex(random_bytes(6));
    $this->source = $this->root.'/source';
    File::makeDirectory($this->source, recursive: true);
    pushGit($this, 'init -q -b main');
    pushGit($this, 'config uploadpack.allowFilter true');
    pushGit($this, 'config uploadpack.allowAnySHA1InWant true');
    $this->mirror = new LocalMirrorGit($this->root);
    app()->instance(AffectedRunner::class, new GitEvaluator(fn () => $this->mirror));

    $this->base = pushCommit($this, [
        'package.json' => json_encode(['name' => 'root', 'private' => true]),
        'pnpm-workspace.yaml' => "packages:\n  - apps/*\n",
        'apps/web/package.json' => json_encode(['name' => '@acme/web']),
        'apps/web/Dockerfile' => "FROM node\nCOPY . .\nRUN pnpm --filter @acme/web build",
        'apps/web/index.ts' => 'web',
        'apps/api/package.json' => json_encode(['name' => '@acme/api']),
        'apps/api/Dockerfile' => "FROM node\nCOPY . .\nRUN pnpm --filter @acme/api build",
        'apps/api/index.ts' => 'api',
    ]);

    $this->fixture = deployTriggerFixture();
    $this->web = pushApplication($this, 'web');
    $this->api = pushApplication($this, 'api');
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function pushGit($test, string $arguments): string
{
    return Process::path($test->source)->env(['GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@t', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@t'])
        ->run('git '.$arguments)->throw()->output();
}

function pushCommit($test, array $files): string
{
    foreach ($files as $path => $content) {
        File::ensureDirectoryExists(dirname($test->source.'/'.$path));
        File::put($test->source.'/'.$path, $content);
    }
    pushGit($test, 'add -A');
    pushGit($test, 'commit -q -m change');

    return trim(pushGit($test, 'rev-parse HEAD'));
}

function pushApplication($test, string $name): Application
{
    $application = deployTriggerApplication($test->fixture, [
        'name' => $name, 'git_repository' => 'file://'.$test->source, 'build_pack' => 'dockerfile', 'base_directory' => '/',
        'dockerfile_location' => "/apps/{$name}/Dockerfile",
    ]);
    $application->forceFill(['trigger_base_commit' => $test->base])->save();

    return $application->refresh();
}

function pushTo($test, string $after): void
{
    $context = new PushContext(provider: 'gitlab', teamId: $test->fixture['team']->id, repositoryUrl: 'file://'.$test->source, ref: 'refs/heads/main', after: $after, commitMessage: 'change');
    (new EvaluatePush($context, [$test->web->id, $test->api->id]))->handle();
}

it('evaluates a push with git and skips the applications it does not affect', function () {
    $after = pushCommit($this, ['apps/api/index.ts' => 'changed']);

    pushTo($this, $after);

    $web = ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->sole();
    $api = ApplicationDeploymentQueue::query()->where('application_id', $this->api->id)->sole();
    expect($web->status)->toBe(ApplicationDeploymentStatus::SKIPPED->value)
        ->and($web->trigger_reason['reason_code'])->toBe('no_match')
        ->and($api->status)->not->toBe(ApplicationDeploymentStatus::SKIPPED->value)
        ->and($api->commit)->toBe($after)
        ->and($api->trigger_reason['matches'][0]['file'])->toBe('apps/api/index.ts')
        ->and($this->web->refresh()->trigger_base_commit)->toBe($after)
        ->and($this->web->trigger_set['anchor']['package'])->toBe('@acme/web')
        ->and($this->web->trigger_set['evaluated_commit'])->toBe($after);
});

it('decides the next push from the stored sets without reading the tree again', function () {
    pushTo($this, pushCommit($this, ['apps/api/index.ts' => 'one']));
    $this->mirror->scripts = [];

    pushTo($this, pushCommit($this, ['apps/web/index.ts' => 'two']));

    expect(collect($this->mirror->scripts)->filter(fn (string $script) => str_contains($script, '--full-tree')))->toBeEmpty()
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $this->web->id)->where('status', '!=', ApplicationDeploymentStatus::SKIPPED->value)->count())->toBe(1)
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $this->api->id)->where('status', ApplicationDeploymentStatus::SKIPPED->value)->count())->toBe(1);
});
