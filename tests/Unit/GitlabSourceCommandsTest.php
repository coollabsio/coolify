<?php

use App\Models\Application;
use App\Models\GitlabApp;
use App\Models\PrivateKey;

afterEach(function () {
    Mockery::close();
});

it('generates ls-remote commands for GitLab source with private key', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');

    $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(\App\Models\GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('privateKey')->andReturn($privateKey);
    $gitlabSource->shouldReceive('getAttribute')->with('private_key_id')->andReturn(1);
    $gitlabSource->shouldReceive('getAttribute')->with('custom_port')->andReturn(22);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => 'git@gitlab.com:user/repo.git',
        'port' => 22,
    ]);
    $application->shouldReceive('getAttribute')->with('source')->andReturn($gitlabSource);
    $application->source = $gitlabSource;

    $result = $application->generateGitLsRemoteCommands($deploymentUuid, false);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('commands');
    expect($result['commands'])->toContain('git ls-remote');
    expect($result['commands'])->toContain('id_rsa');
    expect($result['commands'])->toContain('mkdir -p /root/.ssh');
});

it('generates ls-remote commands for GitLab source without private key', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(\App\Models\GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('privateKey')->andReturn(null);
    $gitlabSource->shouldReceive('getAttribute')->with('private_key_id')->andReturn(null);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => 'https://gitlab.com/user/repo.git',
        'port' => 22,
    ]);
    $application->shouldReceive('getAttribute')->with('source')->andReturn($gitlabSource);
    $application->source = $gitlabSource;

    $result = $application->generateGitLsRemoteCommands($deploymentUuid, false);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('commands');
    expect($result['commands'])->toContain('git ls-remote');
    expect($result['commands'])->toContain('https://gitlab.com/user/repo.git');
    // Should NOT contain SSH key setup
    expect($result['commands'])->not->toContain('id_rsa');
});

it('does not return null for GitLab source type', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(\App\Models\GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('privateKey')->andReturn(null);
    $gitlabSource->shouldReceive('getAttribute')->with('private_key_id')->andReturn(null);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => 'https://gitlab.com/user/repo.git',
        'port' => 22,
    ]);
    $application->shouldReceive('getAttribute')->with('source')->andReturn($gitlabSource);
    $application->source = $gitlabSource;

    $lsRemoteResult = $application->generateGitLsRemoteCommands($deploymentUuid, false);
    expect($lsRemoteResult)->not->toBeNull();
    expect($lsRemoteResult)->toHaveKeys(['commands', 'branch', 'fullRepoUrl']);
});
