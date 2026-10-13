<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GithubApp;

function applicationWithGitSettings(bool $shallow = true): Application
{
    $application = new Application;
    $application->forceFill([
        'uuid' => 'test-app-uuid',
        'git_repository' => 'coollabsio/private-app',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
    ]);

    $settings = new ApplicationSetting;
    $settings->is_git_shallow_clone_enabled = $shallow;
    $settings->is_git_submodules_enabled = false;
    $settings->is_git_lfs_enabled = false;
    $application->setRelation('settings', $settings);

    return $application;
}

it('rewrites generic ssh submodule remotes to https for public clones', function () {
    $application = applicationWithGitSettings(shallow: false);
    $application->settings->is_git_submodules_enabled = true;

    $source = new GithubApp;
    $source->forceFill([
        'html_url' => 'https://github.com',
        'api_url' => 'https://api.github.com',
        'is_public' => true,
    ]);
    $application->setRelation('source', $source);

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'test-deployment',
        exec_in_docker: false,
    );

    expect($result['commands'])
        ->toContain('sed -i "s#[A-Za-z0-9._-]*@\(.*\):#https://\\1/#g"')
        ->not->toContain('s#git@\(.*\):#https://\\1/#g');
});
