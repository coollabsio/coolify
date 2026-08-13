<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GitlabApp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;

beforeEach(function () {
    Model::encryptUsing(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
});

afterEach(function () {
    Model::encryptUsing(null);
});

test('connected gitlab oauth submodule credentials use per command git config', function () {
    $application = new Application;
    $application->forceFill([
        'uuid' => 'test-app-uuid',
        'git_repository' => 'group/private-app',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
    ]);

    $settings = new ApplicationSetting;
    $settings->is_git_shallow_clone_enabled = false;
    $settings->is_git_submodules_enabled = true;
    $settings->is_git_lfs_enabled = false;
    $application->setRelation('settings', $settings);

    $source = new GitlabApp;
    $source->forceFill([
        'html_url' => 'https://gitlab.example.test',
        'api_url' => 'https://gitlab.example.test/api/v4',
        'is_public' => false,
    ]);
    // A non-expired token short-circuits refreshGitlabToken(), so no HTTP call is made.
    $source->access_token = 'gl-token/with+sym';
    $source->refresh_token = 'gl-refresh-token';
    $source->expires_at = time() + 3600;
    $application->setRelation('source', $source);

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'test-deployment',
        exec_in_docker: false,
    );

    $expectedConfig = "git -c 'url.https://oauth2:gl-token%2Fwith%2Bsym@gitlab.example.test/.insteadOf=https://gitlab.example.test/' -c http.version=HTTP/1.1";

    expect($result['commands'])
        ->not->toContain('git config --global')
        ->toContain("{$expectedConfig} clone --recurse-submodules -b 'main'")
        ->toContain("{$expectedConfig} submodule sync")
        ->toContain("{$expectedConfig} submodule update --init --recursive");
});
