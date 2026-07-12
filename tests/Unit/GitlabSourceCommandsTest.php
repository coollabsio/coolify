<?php

use App\Models\Application;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use Illuminate\Support\Collection;

afterEach(function () {
    Mockery::close();
});

function gitlabCommandStrings(array|Collection|string $commands): Collection
{
    if (is_string($commands)) {
        return collect([$commands]);
    }

    return collect($commands)->map(fn ($command) => data_get($command, 'command') ?? $command[0] ?? $command);
}

function expectGitlabCommandListToContain(array|Collection|string $commands, string $expected): void
{
    expect(gitlabCommandStrings($commands)->implode(' && '))->toContain($expected);
}

function expectGitlabCommandListNotToContain(array|Collection|string $commands, string $expected): void
{
    expect(gitlabCommandStrings($commands)->implode(' && '))->not->toContain($expected);
}

function expectGitlabPrivateKeyMaterializationCommandsSkipLogging(array|Collection|string $commands): void
{
    if (is_string($commands)) {
        $commands = [$commands];
    }

    $keyCommands = collect($commands)->filter(fn ($command) => str(data_get($command, 'command') ?? $command[0] ?? $command)->contains('base64 -d | tee /root/.ssh/id_rsa_coolify_'));

    expect($keyCommands)->not->toBeEmpty();
    $keyCommands->each(function ($command): void {
        expect(data_get($command, 'skip_command_log'))->toBeTrue();
    });
}

it('generates ls-remote commands for GitLab source with private key', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');

    $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('html_url')->andReturn('https://gitlab.com');
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
    expectGitlabCommandListToContain($result['commands'], 'git ls-remote');
    expectGitlabCommandListToContain($result['commands'], 'id_rsa');
    expectGitlabCommandListToContain($result['commands'], 'mkdir -p /root/.ssh');
    expectGitlabPrivateKeyMaterializationCommandsSkipLogging($result['commands']);
});

it('generates ls-remote commands for GitLab source without private key', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('html_url')->andReturn('https://gitlab.com');
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
    expectGitlabCommandListToContain($result['commands'], 'git ls-remote');
    expectGitlabCommandListToContain($result['commands'], 'https://gitlab.com/user/repo.git');
    // Should NOT contain SSH key setup
    expectGitlabCommandListNotToContain($result['commands'], 'id_rsa');
});

it('does not return null for GitLab source type', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('html_url')->andReturn('https://gitlab.com');
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
