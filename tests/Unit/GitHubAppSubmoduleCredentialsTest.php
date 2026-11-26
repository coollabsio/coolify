<?php

namespace App\Models {
    function generateGithubInstallationToken(GithubApp $source): string
    {
        return 'review token/with+symbols';
    }
}

namespace {
    use App\Jobs\ApplicationDeploymentJob;
    use App\Models\Application;
    use App\Models\ApplicationPreview;
    use App\Models\ApplicationSetting;
    use App\Models\GithubApp;
    use Symfony\Component\Process\Process;

    function privateGithubAppApplication(): Application
    {
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
        $settings->is_git_lfs_enabled = true;
        $application->setRelation('settings', $settings);

        $source = new GithubApp;
        $source->forceFill([
            'html_url' => 'https://github.com',
            'api_url' => 'https://api.github.com',
            'is_public' => false,
        ]);
        $application->setRelation('source', $source);

        return $application;
    }

    function gitOutput(string $command): string
    {
        $process = Process::fromShellCommandline($command);
        $process->run();

        return trim($process->getOutput());
    }

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

    test('a fork preview sends the github app token only to its repository and its LFS endpoint', function () {
        $result = privateGithubAppApplication()->generateGitImportCommands(
            deployment_uuid: 'test-deployment',
            pull_request_id: 7,
            exec_in_docker: false,
            untrusted_source: true,
        );
        $git = $result['git'];
        $header = 'Authorization: Basic '.base64_encode('x-access-token:review token/with+symbols');
        $headerFor = fn (string $url): string => gitOutput("{$git} config --get-urlmatch http.extraHeader ".escapeshellarg($url));

        expect($result['commands'])
            ->not->toContain('x-access-token:review')
            ->not->toContain('insteadOf')
            ->not->toContain('clone --recurse-submodules')
            ->toContain("clone -b 'main' -- 'https://github.com/coollabsio/private-app.git'")
            ->toContain('submodule update --init --recursive')
            ->and($headerFor('https://github.com/coollabsio/private-app.git'))->toBe($header)
            ->and($headerFor('https://github.com/coollabsio/private-app.git/info/lfs/objects/batch'))->toBe($header)
            ->and($headerFor('https://github.com/coollabsio/private-app'))->toBe($header)
            ->and($headerFor('https://github.com/coollabsio/other-private.git'))->toBe('')
            ->and($headerFor('https://github.com/coollabsio/private-app-secrets.git'))->toBe('')
            ->and($headerFor('https://github.com/coollabsio/private-app.git/../other-private.git'))->toBe('');

        foreach (['https://github.com/coollabsio/other-private.git', 'git@github.com:coollabsio/other-private.git'] as $submoduleUrl) {
            expect(gitOutput("{$git} ls-remote --get-url ".escapeshellarg($submoduleUrl)))->toBe($submoduleUrl);
        }
    });

    test('a fork preview uses a deploy key only to fetch its repository', function () {
        $application = privateGithubAppApplication();
        $application->setRelation('source', null);
        $application->forceFill(['git_repository' => 'git@github.com:coollabsio/private-app.git', 'private_key_id' => 5]);
        $application->setRelation('private_key', (object) ['private_key' => 'test-key']);

        $commands = collect($application->generateGitImportCommands(
            deployment_uuid: 'test-deployment',
            pull_request_id: 7,
            git_type: 'github',
            exec_in_docker: false,
            untrusted_source: true,
        )['commands'])->map(fn ($command) => data_get($command, 'command'))->implode("\n");

        expect($commands)
            ->toContain('-i /root/.ssh/id_rsa_coolify_test-deployment -o IdentitiesOnly=yes" git fetch origin pull/7/head:pr-7-coolify')
            ->not->toContain('clone --recurse-submodules')
            ->and(str($commands)->after('git fetch origin pull/7/head')->value())
            ->not->toContain('-i /root/.ssh/id_rsa_coolify_test-deployment')
            ->toContain('-o IdentityFile=none -o IdentitiesOnly=yes" git submodule update --init --recursive');
    });

    test('the deployment job treats a preview as untrusted unless its branch is in the repository', function (?bool $isSameRepository, bool $untrusted) {
        $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionObject($job);
        $preview = $isSameRepository === null ? null : (new ApplicationPreview)->forceFill(['is_same_repository' => $isSameRepository]);
        foreach (['application' => privateGithubAppApplication(), 'deployment_uuid' => 'test-deployment', 'pull_request_id' => 7, 'git_type' => 'github', 'commit' => 'HEAD', 'preview' => $preview] as $property => $value) {
            $reflection->getProperty($property)->setValue($job, $value);
        }

        $commands = $reflection->getMethod('generate_git_import_commands')->invoke($job);

        expect(str_contains($commands, 'x-access-token:review'))->toBe(! $untrusted);
    })->with([
        'fork' => [false, true],
        'unknown preview' => [null, true],
        'same repository' => [true, false],
    ]);
}
