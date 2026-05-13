<?php

use App\Models\Application;
use App\Models\ApplicationSetting;

function applicationWithGitSettings(): Application
{
    $application = new Application;
    $application->git_repository = 'https://github.com/example/repo';
    $application->git_branch = 'main';

    $settings = new ApplicationSetting;
    $settings->is_git_submodules_enabled = false;
    $settings->is_git_shallow_clone_enabled = false;
    $application->setRelation('settings', $settings);

    return $application;
}

it('uses quiet clone output when only checking out a compose file', function () {
    $application = applicationWithGitSettings();

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'test-deployment',
        exec_in_docker: false,
        only_checkout: true,
        custom_base_dir: '.',
    );

    expect($result['commands'])
        ->toContain('git clone --quiet --no-checkout -b')
        ->toContain("'https://github.com/example/repo' '.'");
});

it('keeps normal deployment clone output unchanged', function () {
    $application = applicationWithGitSettings();

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'test-deployment',
        exec_in_docker: false,
        only_checkout: false,
    );

    expect($result['commands'])
        ->toContain('git clone -b')
        ->not->toContain('git clone --quiet');
});
