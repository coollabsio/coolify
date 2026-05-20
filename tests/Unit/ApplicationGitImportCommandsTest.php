<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GithubApp;

it('uses quiet clone output when loading only checkout files', function () {
    $source = Mockery::mock(GithubApp::class)->makePartial();
    $source->html_url = 'https://github.com';
    $source->is_public = true;
    $source->shouldReceive('getMorphClass')->andReturn(GithubApp::class);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->source = $source;
    $application->settings = new ApplicationSetting([
        'is_git_shallow_clone_enabled' => false,
        'is_git_submodules_enabled' => false,
    ]);
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => 'coollabsio/coolify',
        'port' => 22,
    ]);

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'compose-load-test',
        exec_in_docker: false,
        only_checkout: true,
        custom_base_dir: '.'
    );

    expect($result['commands'])
        ->toContain('git clone --quiet --no-checkout')
        ->not->toContain("git clone --no-checkout");
});

it('loads compose files from a dedicated checkout directory before catting compose content', function () {
    $source = file_get_contents(app_path('Models/Application.php'));

    expect($source)
        ->toContain("custom_base_dir: 'checkout'")
        ->toContain("'cd checkout'")
        ->toContain('"cat .$workdir$composeFile"');
});
