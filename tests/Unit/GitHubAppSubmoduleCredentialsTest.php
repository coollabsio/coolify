<?php

namespace App\Models {
    function generateGithubInstallationToken(GithubApp $source): string
    {
        return 'review token/with+symbols';
    }
}

namespace {
    use App\Models\Application;
    use App\Models\ApplicationSetting;
    use App\Models\GithubApp;
    use Symfony\Component\Process\Process;

    test('private github app submodule credentials use per command git config', function () {
        $application = new Application;
        $application->forceFill([
            'uuid' => 'test-app-uuid',
            'git_repository' => 'coollabsio/private-app',
            'git_branch' => 'main',
            'git_commit_sha' => 'HEAD',
        ]);

        $settings = new ApplicationSetting;
        $settings->is_git_shallow_clone_enabled = false;
        $settings->is_git_submodules_enabled = true;
        $settings->is_git_lfs_enabled = false;
        $application->setRelation('settings', $settings);

        $source = new GithubApp;
        $source->forceFill([
            'html_url' => 'https://github.com',
            'api_url' => 'https://api.github.com',
            'is_public' => false,
        ]);
        $application->setRelation('source', $source);

        $result = $application->generateGitImportCommands(
            deployment_uuid: 'test-deployment',
            exec_in_docker: false,
        );

        expect($result['commands'])
            ->not->toContain('git config --global')
            ->toContain("git -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=https://github.com/' -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=git@github.com:' -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=ssh://git@github.com/' clone --recurse-submodules -b 'main'")
            ->toContain("git -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=https://github.com/' -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=git@github.com:' -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=ssh://git@github.com/' submodule sync")
            ->toContain("git -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=https://github.com/' -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=git@github.com:' -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=ssh://git@github.com/' submodule update --init --recursive");
    });

    test('private github app credentials also reach submodules with ssh urls', function () {
        $application = new Application;
        $application->forceFill([
            'uuid' => 'test-app-uuid',
            'git_repository' => 'coollabsio/private-app',
            'git_branch' => 'main',
            'git_commit_sha' => 'HEAD',
        ]);

        $settings = new ApplicationSetting;
        $settings->is_git_shallow_clone_enabled = false;
        $settings->is_git_submodules_enabled = true;
        $settings->is_git_lfs_enabled = false;
        $application->setRelation('settings', $settings);

        $source = new GithubApp;
        $source->forceFill([
            'html_url' => 'https://github.com',
            'api_url' => 'https://api.github.com',
            'is_public' => false,
        ]);
        $application->setRelation('source', $source);

        $git = $application->generateGitImportCommands(deployment_uuid: 'test-deployment', exec_in_docker: false)['git'];
        $tokenUrl = 'https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/coollabsio/private-submodule.git';

        // `ls-remote --get-url` prints the URL that git uses after the insteadOf rewrites, without a connection.
        foreach ([
            'https://github.com/coollabsio/private-submodule.git',
            'git@github.com:coollabsio/private-submodule.git',
            'ssh://git@github.com/coollabsio/private-submodule.git',
        ] as $submoduleUrl) {
            $process = Process::fromShellCommandline("{$git} ls-remote --get-url ".escapeshellarg($submoduleUrl));
            $process->mustRun();

            expect(trim($process->getOutput()))->toBe($tokenUrl);
        }

        $otherHost = Process::fromShellCommandline("{$git} ls-remote --get-url git@gitlab.com:coollabsio/other.git");
        $otherHost->mustRun();
        expect(trim($otherHost->getOutput()))->toBe('git@gitlab.com:coollabsio/other.git');
    });

    test('the compose file submodule step uses the github app credentials of the clone', function () {
        $application = new Application;
        $application->forceFill([
            'uuid' => 'test-app-uuid',
            'git_repository' => 'coollabsio/private-app',
            'git_branch' => 'main',
            'git_commit_sha' => 'HEAD',
            'base_directory' => '/',
            'docker_compose_location' => '/infra/docker-compose.yml',
        ]);

        $settings = new ApplicationSetting;
        $settings->is_git_shallow_clone_enabled = true;
        $settings->is_git_submodules_enabled = true;
        $settings->is_git_lfs_enabled = false;
        $application->setRelation('settings', $settings);

        $source = new GithubApp;
        $source->forceFill([
            'html_url' => 'https://github.com',
            'api_url' => 'https://api.github.com',
            'is_public' => false,
        ]);
        $application->setRelation('source', $source);

        $commands = (new ReflectionMethod(Application::class, 'composeFileReadCommands'))->invoke($application, 'test-uuid', '2.47.0');

        expect($commands->implode("\n"))
            ->toContain('--no-checkout')
            // The step is quoted for `sh -c`, so compare without the outer quotes.
            ->toContain(substr(escapeshellarg("git -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=https://github.com/' -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=git@github.com:' -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=ssh://git@github.com/' submodule update --init --recursive --depth=1"), 1, -1));
    });
}
