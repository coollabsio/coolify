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
            ->toContain("git -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=https://github.com/' -c http.version=HTTP/1.1 clone --recurse-submodules -b 'main'")
            ->toContain("git -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=https://github.com/' -c http.version=HTTP/1.1 submodule sync")
            ->toContain("git -c 'url.https://x-access-token:review%20token%2Fwith%2Bsymbols@github.com/.insteadOf=https://github.com/' -c http.version=HTTP/1.1 submodule update --init --recursive");
    });
}
