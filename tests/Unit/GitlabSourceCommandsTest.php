<?php

use App\Models\Application;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;

beforeEach(function () {
    Model::encryptUsing(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
});

afterEach(function () {
    Model::encryptUsing(null);
    Mockery::close();
});

it('generates ls-remote commands for GitLab source with private key', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');

    $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('privateKey')->andReturn($privateKey);
    $gitlabSource->shouldReceive('getAttribute')->with('private_key_id')->andReturn(1);
    $gitlabSource->shouldReceive('getAttribute')->with('custom_port')->andReturn(22);
    $gitlabSource->shouldReceive('getAttribute')->with('access_token')->andReturn(null);
    $gitlabSource->shouldReceive('getAttribute')->with('refresh_token')->andReturn(null);
    $gitlabSource->shouldReceive('isConnected')->andReturn(false);

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
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('privateKey')->andReturn(null);
    $gitlabSource->shouldReceive('getAttribute')->with('private_key_id')->andReturn(null);
    $gitlabSource->shouldReceive('getAttribute')->with('access_token')->andReturn(null);
    $gitlabSource->shouldReceive('getAttribute')->with('refresh_token')->andReturn(null);
    $gitlabSource->shouldReceive('isConnected')->andReturn(false);

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
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('privateKey')->andReturn(null);
    $gitlabSource->shouldReceive('getAttribute')->with('private_key_id')->andReturn(null);
    $gitlabSource->shouldReceive('getAttribute')->with('access_token')->andReturn(null);
    $gitlabSource->shouldReceive('getAttribute')->with('refresh_token')->andReturn(null);
    $gitlabSource->shouldReceive('isConnected')->andReturn(false);

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

it('preserves custom GitLab http port for connected OAuth sources', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $gitlabSource = new GitlabApp([
        'html_url' => 'http://gitlab.example.test:8081',
        'access_token' => 'gitlab-access-token',
        'refresh_token' => 'gitlab-refresh-token',
        'expires_at' => time() + 3600,
    ]);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => 'root/qa-private-app',
        'port' => 22,
    ]);
    $application->shouldReceive('getAttribute')->with('source')->andReturn($gitlabSource);
    $application->source = $gitlabSource;

    $result = $application->generateGitLsRemoteCommands($deploymentUuid, false);

    expect($result['fullRepoUrl'])
        ->toContain('gitlab.example.test:8081')
        ->toBe('http://oauth2:gitlab-access-token@gitlab.example.test:8081/root/qa-private-app.git');
    expect($result['commands'])->toContain('gitlab.example.test:8081/root/qa-private-app.git');
});
