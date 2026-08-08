<?php

use App\Models\Application;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Collection;

beforeEach(function () {
    Model::encryptUsing(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
});

afterEach(function () {
    Model::encryptUsing(null);
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

it('applies OAuth git config to GitLab merge-request fetch and submodule checkout', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $gitlabSource = new GitlabApp([
        'html_url' => 'https://gitlab.example.test',
        'access_token' => 'gitlab-access-token',
        'refresh_token' => 'gitlab-refresh-token',
        'expires_at' => time() + 3600,
    ]);

    $settings = (object) [
        'is_git_shallow_clone_enabled' => false,
        'is_git_submodules_enabled' => true,
    ];

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => 'root/qa-private-app',
        'port' => 22,
    ]);
    $application->shouldReceive('getAttribute')->with('source')->andReturn($gitlabSource);
    $application->shouldReceive('getAttribute')->with('settings')->andReturn($settings);
    $application->source = $gitlabSource;

    $result = $application->generateGitImportCommands(
        deployment_uuid: $deploymentUuid,
        pull_request_id: 2,
        exec_in_docker: false,
        only_checkout: true,
        custom_base_dir: '/artifacts/test',
    );

    $commands = $result['commands'];
    // The MR-ref fetch and submodule update must run through the OAuth-rewritten git, or private same-host submodules fail.
    expect($commands)
        ->toContain('oauth2:gitlab-access-token@gitlab.example.test')
        ->toContain("http.version=HTTP/1.1 fetch origin 'merge-requests/2/head:pr-2-coolify'")
        ->toContain('http.version=HTTP/1.1 submodule update --init --recursive');
});
