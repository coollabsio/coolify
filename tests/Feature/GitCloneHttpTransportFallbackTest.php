<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GithubApp;

it('wraps https clone commands with an HTTP/1.1 retry after cleanup', function () {
    $command = "git clone --depth=1 -b 'main' 'https://github.com/coollabsio/coolify' '/artifacts/test-uuid'";

    $result = wrapGitCloneCommandWithHttpTransportFallback(
        $command,
        '/artifacts/test-uuid',
        'https://github.com/coollabsio/coolify'
    );

    expect($result)
        ->toContain("(git clone --depth=1 -b 'main' 'https://github.com/coollabsio/coolify' '/artifacts/test-uuid')")
        ->toContain("rm -rf '/artifacts/test-uuid'")
        ->toContain('Primary git clone failed, retrying with HTTP/1.1')
        ->toContain("git -c http.version=HTTP/1.1 clone --depth=1 -b 'main' 'https://github.com/coollabsio/coolify' '/artifacts/test-uuid'");
});

it('leaves ssh clone commands unchanged', function () {
    $command = "GIT_SSH_COMMAND=\"ssh -i /root/.ssh/id_rsa\" git clone -b 'main' 'git@github.com:coollabsio/coolify.git' '/artifacts/test-uuid'";

    $result = wrapGitCloneCommandWithHttpTransportFallback(
        $command,
        '/artifacts/test-uuid',
        'git@github.com:coollabsio/coolify.git'
    );

    expect($result)->toBe($command);
});

it('skips cleanup fallback when cloning into the current directory', function () {
    $command = "git clone --no-checkout -b 'main' 'https://github.com/coollabsio/coolify' .";

    $result = wrapGitCloneCommandWithHttpTransportFallback(
        $command,
        '.',
        'https://github.com/coollabsio/coolify'
    );

    expect($result)->toBe($command);
});

it('adds the http fallback to github app source clone commands', function () {
    $application = new Application;
    $application->forceFill([
        'git_branch' => 'main',
        'git_repository' => 'coollabsio/coolify',
        'git_commit_sha' => 'HEAD',
    ]);

    $settings = new ApplicationSetting;
    $settings->is_git_shallow_clone_enabled = true;
    $settings->is_git_submodules_enabled = false;
    $settings->is_git_lfs_enabled = false;
    $application->setRelation('settings', $settings);

    $source = new GithubApp;
    $source->html_url = 'https://github.com';
    $source->is_public = true;
    $application->setRelation('source', $source);

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'test-uuid',
        exec_in_docker: false
    );

    expect($result['commands'])
        ->toContain("git -c http.version=HTTP/1.1 clone --depth=1 -b 'main' 'https://github.com/coollabsio/coolify' '/artifacts/test-uuid'")
        ->toContain("rm -rf '/artifacts/test-uuid'");
});
