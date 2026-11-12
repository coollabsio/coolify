<?php

use Tests\TestCase;

uses(TestCase::class);

test('known hosts lines accept host names with an underscore', function () {
    expect(gitKnownHostsLines('[git_host.internal]:2222 ssh-ed25519 AAAA'))->toBe(['[git_host.internal]:2222 ssh-ed25519 AAAA']);
});

test('known hosts lines drop other keys for pinned hosts', function (string $line) {
    expect(gitKnownHostsLines($line))->toBe([]);
})->with([
    'pinned host' => 'github.com ssh-ed25519 AAAA',
    'pinned host with port' => '[bitbucket.org]:2222 ssh-ed25519 AAAA',
    'pinned host in a list' => 'git.example.com,bitbucket.org ssh-ed25519 AAAA',
    'pinned port 443 host' => '[ssh.github.com]:443 ssh-ed25519 AAAA',
]);

test('the ssh setup pins the port 443 endpoints of the large git hosts', function () {
    $script = gitSshSetupCommand('test-uuid');
    preg_match_all("/echo '([^']+)'/", $script, $matches);
    [$knownHosts, $config] = array_map(base64_decode(...), $matches[1]);

    preg_match('/\AHost (.+)$/m', $config, $strictHosts);

    expect(explode(' ', $strictHosts[1]))->toEqualCanonicalizing(['github.com', 'gitlab.com', 'bitbucket.org', 'ssh.github.com', 'altssh.gitlab.com', 'altssh.bitbucket.org'])
        ->and($knownHosts)->toContain('[ssh.github.com]:443 ssh-ed25519 ')
        ->and($knownHosts)->toContain('[altssh.gitlab.com]:443 ssh-ed25519 ')
        ->and($knownHosts)->toContain('[altssh.bitbucket.org]:443 ssh-ed25519 ');
});

test('accepting fetched keys replaces every saved line of the same host and port', function () {
    $saved = implode("\n", [
        'git.example.com,1.2.3.4 ssh-ed25519 OLD',
        '[git.example.com]:22 ssh-rsa OLDRSA',
        '[git.example.com]:2222 ssh-ed25519 OTHERPORT',
        'keep.example.com ssh-ed25519 KEEP',
    ]);

    expect(gitKnownHostsReplaceHostKeys($saved, ['git.example.com ssh-ed25519 NEW']))->toBe([
        '[git.example.com]:2222 ssh-ed25519 OTHERPORT',
        'keep.example.com ssh-ed25519 KEEP',
        'git.example.com ssh-ed25519 NEW',
    ]);
});

test('the ssh setup checks pinned hosts strictly and trusts other hosts on first use', function () {
    $script = gitSshSetupCommand('test-uuid', 'git.example.com ssh-ed25519 SAVED');
    preg_match_all("/echo '([^']+)'/", $script, $matches);
    [$knownHosts, $config] = array_map(base64_decode(...), $matches[1]);

    expect($config)->toMatch('/\AHost github\.com[^\n]*\n    StrictHostKeyChecking yes\nHost \*\n    StrictHostKeyChecking accept-new\n    UserKnownHostsFile \/root\/\.ssh\/coolify_git_test-uuid\.known_hosts\n/')
        ->and($knownHosts)->toContain("\ngit.example.com ssh-ed25519 SAVED\n")
        ->and($script)->toContain('tee /root/.ssh/coolify_git_test-uuid.config');
});

test('github keys are pinned for ssh.github.com on port 22, where a port 443 redirect in the ssh config can end', function () {
    $githubEd25519 = collect(GIT_PINNED_HOST_KEYS)->first(fn (string $line) => str_starts_with($line, 'github.com') && str_contains($line, 'ssh-ed25519'));

    expect(explode(',', strtok($githubEd25519, ' ')))->toContain('ssh.github.com', '[ssh.github.com]:443');
});

test('the ssh setup in the helper container does not probe the ssh version', function () {
    expect(gitSshSetupCommand('test-uuid'))->not->toContain('ssh -F /dev/null -G')
        ->and(gitSshSetupCommand('test-uuid'))->not->toContain(' if ');
});
