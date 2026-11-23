<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return array{0: string, 1: string, 2: string} the folder, the reviewed commit, and the newer pull request head
 */
function makeRepositoryWithForkPullRequest(): array
{
    $dir = sys_get_temp_dir().'/coolify-pr-checkout-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $script = <<<'SH'
        set -e
        git init -q -b main src && cd src
        git -c user.name=t -c user.email=t@t commit -q --allow-empty -m base
        git checkout -q -b fork
        git -c user.name=t -c user.email=t@t commit -q --allow-empty -m reviewed
        git rev-parse HEAD
        git -c user.name=t -c user.email=t@t commit -q --allow-empty -m newer
        git rev-parse HEAD
        git update-ref refs/pull/1/head HEAD
        git checkout -q main && git branch -q -D fork
        SH;
    $process = Process::fromShellCommandline($script, $dir);
    $process->mustRun();
    [$reviewed, $newer] = explode("\n", trim($process->getOutput()));

    return [$dir, $reviewed, $newer];
}

it('checks out the approved pull request commit when the pull request has newer commits', function (bool $shallow) {
    [$dir, $reviewed, $newer] = makeRepositoryWithForkPullRequest();

    $application = Mockery::mock(Application::class)->makePartial();
    $application->forceFill(['git_branch' => 'main', 'git_repository' => "file://{$dir}/src"]);
    $settings = new ApplicationSetting;
    $settings->is_git_shallow_clone_enabled = $shallow;
    $application->setRelation('settings', $settings);
    $application->shouldReceive('deploymentType')->andReturn('other');
    $application->shouldReceive('customRepository')->andReturn(['repository' => "file://{$dir}/src", 'port' => 22]);

    $result = $application->generateGitImportCommands('test-deployment', pull_request_id: 1, git_type: 'github', exec_in_docker: false, custom_base_dir: "{$dir}/clone", commit: $reviewed);

    $process = Process::fromShellCommandline($result['commands'], $dir, ['GIT_ALLOW_PROTOCOL' => 'file']);
    $process->run();
    $head = trim((string) Process::fromShellCommandline('git rev-parse HEAD', "{$dir}/clone")->mustRun()->getOutput());
    File::deleteDirectory($dir);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($head)->toBe($reviewed)->not->toBe($newer);
})->with(['full clone' => false, 'shallow clone' => true]);
