<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use Symfony\Component\Process\Process;

/**
 * Runs the generated import command in bash with a stub git that records its calls.
 * The stub prints $lfsFiles for `git lfs ls-files -n`.
 *
 * @return array{0: int, 1: list<string>, 2: list<string>}
 */
function runImportCommandWithStubGit(string $lfsFiles, bool $hasSubmodules = false): array
{
    $dir = sys_get_temp_dir().'/coolify-import-'.bin2hex(random_bytes(4));
    mkdir("{$dir}/bin", 0755, true);
    file_put_contents("{$dir}/bin/git", <<<SH
        #!/bin/sh
        echo "\$*" >> {$dir}/git.log
        if [ "\$*" = "lfs ls-files -n" ]; then printf '%s' '{$lfsFiles}'; fi
        SH);
    chmod("{$dir}/bin/git", 0755);
    if ($hasSubmodules) {
        touch("{$dir}/.gitmodules");
    }

    $application = new Application;
    $application->forceFill(['uuid' => 'test-app-uuid', 'git_commit_sha' => 'abc123']);
    $settings = new ApplicationSetting;
    $settings->is_git_shallow_clone_enabled = true;
    $settings->is_git_submodules_enabled = true;
    $settings->is_git_lfs_enabled = true;
    $application->setRelation('settings', $settings);

    $command = $application->setGitImportSettings(
        deployment_uuid: 'test-uuid',
        git_clone_command: 'true',
        gitSshCommand: 'ssh -o ConnectTimeout=30 -i /root/.ssh/id_rsa',
        baseDir: $dir,
    );

    $process = Process::fromShellCommandline($command, env: ['PATH' => "{$dir}/bin:".getenv('PATH')]);
    $process->run();
    $calls = file_exists("{$dir}/git.log") ? file("{$dir}/git.log", FILE_IGNORE_NEW_LINES) : [];

    exec('rm -rf '.escapeshellarg($dir));

    return [$process->getExitCode(), $calls, explode("\n", trim($process->getOutput()))];
}

test('git lfs pull is skipped when the checkout has no LFS files', function () {
    [$exitCode, $calls, $output] = runImportCommandWithStubGit('');

    expect($exitCode)->toBe(0)
        ->and($calls)->toBe([
            'fetch --depth=1 origin abc123',
            '-c advice.detachedHead=false checkout abc123',
            'lfs ls-files -n',
        ])
        ->and($output)->toBe(['Fetching the commit.']);
});

test('each network step after the clone prints a log line before it runs', function () {
    [$exitCode, $calls, $output] = runImportCommandWithStubGit('assets/logo.psd', hasSubmodules: true);

    expect($exitCode)->toBe(0)
        ->and($calls)->toBe([
            'fetch --depth=1 origin abc123',
            '-c advice.detachedHead=false checkout abc123',
            'submodule sync',
            'submodule update --init --recursive --depth=1',
            'lfs ls-files -n',
            'lfs pull',
        ])
        ->and($output)->toBe(['Fetching the commit.', 'Updating submodules.', 'Downloading Git LFS objects.']);
});

test('git ssh command stops a stalled connection instead of waiting for the deployment timeout', function () {
    expect(gitSshCommand(2222, '/root/.ssh/key'))->toBe(
        'ssh -o ConnectTimeout=30 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -p 2222 -o Port=2222 -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/key -o IdentitiesOnly=yes'
    )
        ->and(gitSshCommand(22))->not->toContain(' -i ')
        ->and(gitSshCommand(22, '/root/.ssh/id_rsa', identitiesOnly: false))->toEndWith('-i /root/.ssh/id_rsa');
});
