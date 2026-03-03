<?php

use App\Jobs\CleanupGithubRunnerArtifactsJob;

it('builds cleanup commands for cached runner tarballs and template directories', function () {
    $commands = CleanupGithubRunnerArtifactsJob::buildCleanupCommands('/opt/github-runners');

    expect($commands)->toHaveCount(3);
    expect($commands[0])->toContain('.cache/actions-runner-linux-${arch}-*.tar.gz');
    expect($commands[1])->toContain('.templates/runner-${arch}-*');
    expect($commands[2])->toContain('.template/runner-${arch}-*');
    expect($commands[0])->toContain('tail -n +3');
    expect($commands[1])->toContain('tail -n +3');
    expect($commands[2])->toContain('tail -n +3');
});

it('schedules github runner artifact cleanup daily at 2am', function () {
    $kernelFile = file_get_contents(__DIR__.'/../../app/Console/Kernel.php');

    expect($kernelFile)->toContain('use App\\Jobs\\CleanupGithubRunnerArtifactsJob;');
    expect($kernelFile)->toContain("->job(new CleanupGithubRunnerArtifactsJob)->dailyAt('02:00')->onOneServer();");
});

it('updates runner cache and template timestamps during provisioning', function () {
    $provisionFile = file_get_contents(__DIR__.'/../../app/Jobs/ProvisionGithubRunnerJob.php');

    expect($provisionFile)->toContain('touch {$cacheDir}/{$tarball} {$templateDir}');
});
