<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GithubApp;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The helper image installs the Git LFS smudge filter system-wide, so a clone downloads every
 * LFS object unless GIT_LFS_SKIP_SMUDGE is set for the git process. These tests run the
 * generated commands in bash with a fake `git` that records the value each call sees. The fake
 * `docker exec` drops the caller's environment, like a real container.
 *
 * @return list<string>
 */
function gitCallsWithLfsSkipSmudge(bool $isGitLfsEnabled, int $pullRequestId = 0, bool $onlyCheckout = false, string $commit = 'HEAD'): array
{
    $application = new Application;
    $application->forceFill([
        'uuid' => 'test-app-uuid',
        'git_repository' => 'duilio/bad-lfs-example',
        'git_branch' => 'main',
        'git_commit_sha' => $commit,
    ]);

    $settings = new ApplicationSetting;
    $settings->is_git_shallow_clone_enabled = false;
    $settings->is_git_submodules_enabled = false;
    $settings->is_git_lfs_enabled = $isGitLfsEnabled;
    $application->setRelation('settings', $settings);

    $source = new GithubApp;
    $source->forceFill(['html_url' => 'https://github.com', 'is_public' => true]);
    $application->setRelation('source', $source);

    $bin = sys_get_temp_dir().'/coolify-lfs-test-'.uniqid();
    mkdir("{$bin}/repo", recursive: true);

    ['commands' => $commands] = $application->generateGitImportCommands(
        deployment_uuid: 'test-deployment',
        pull_request_id: $pullRequestId,
        only_checkout: $onlyCheckout,
        custom_base_dir: "{$bin}/repo",
    );

    file_put_contents("{$bin}/git", "#!/bin/bash\necho \"\${GIT_LFS_SKIP_SMUDGE:-unset} \$*\" >> \"\$GIT_CALL_LOG\"\n");
    file_put_contents("{$bin}/docker", "#!/bin/bash\nshift 4\nexec env -u GIT_LFS_SKIP_SMUDGE bash -c \"\$1\"\n");
    chmod("{$bin}/git", 0755);
    chmod("{$bin}/docker", 0755);

    try {
        Process::env(['PATH' => "{$bin}:".getenv('PATH'), 'GIT_CALL_LOG' => "{$bin}/calls.log"])
            ->run(['bash', '-ec', $commands])
            ->throw();

        return str_replace($bin, '<tmp>', file("{$bin}/calls.log", FILE_IGNORE_NEW_LINES));
    } finally {
        File::deleteDirectory($bin);
    }
}

it('skips the lfs smudge filter for every git call when git lfs is disabled', function () {
    $calls = gitCallsWithLfsSkipSmudge(isGitLfsEnabled: false, pullRequestId: 7);

    expect($calls)->toBe([
        '1 clone -b main https://github.com/duilio/bad-lfs-example <tmp>/repo',
        '1 fetch origin pull/7/head:pr-7-coolify',
        '1 checkout pr-7-coolify',
    ]);
});

it('keeps the lfs smudge filter and pulls lfs objects when git lfs is enabled', function () {
    $calls = gitCallsWithLfsSkipSmudge(isGitLfsEnabled: true);

    expect($calls)->toBe([
        'unset clone -b main https://github.com/duilio/bad-lfs-example <tmp>/repo',
        'unset lfs ls-files -n',
    ]);
});

it('skips the lfs smudge filter for the compose file checkout of a pinned commit when git lfs is enabled', function () {
    $calls = gitCallsWithLfsSkipSmudge(isGitLfsEnabled: true, onlyCheckout: true, commit: 'abc123def456abc123def456abc123def456abc1');

    expect($calls)->toBe([
        '1 clone --no-checkout -b main https://github.com/duilio/bad-lfs-example <tmp>/repo',
        '1 -c advice.detachedHead=false checkout abc123def456abc123def456abc123def456abc1',
    ]);
});
