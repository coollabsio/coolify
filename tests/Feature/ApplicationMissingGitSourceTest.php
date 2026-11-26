<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GithubApp;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function applicationWithSource(?string $sourceType, ?int $sourceId): Application
{
    $application = (new Application)->forceFill([
        'git_repository' => 'organization/repo',
        'git_branch' => 'main',
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'private_key_id' => null,
    ]);
    $application->setRelation('settings', new ApplicationSetting);

    return $application;
}

it('stops the clone with a clear error when the git source of the application does not exist', function (string $method) {
    $application = applicationWithSource(GithubApp::class, 999);

    expect(fn () => $application->{$method}('deployment-uuid'))
        ->toThrow(RuntimeException::class, 'The Git source of this application (GitHub App with ID 999) does not exist');
})->with(['generateGitImportCommands', 'generateGitLsRemoteCommands']);

it('still clones a repository URL directly when the application has no git source', function () {
    $application = applicationWithSource(null, null);
    $application->git_repository = 'https://example.com/organization/repo.git';

    $result = $application->generateGitImportCommands('deployment-uuid', exec_in_docker: false);

    expect($result['fullRepoUrl'])->toBe('https://example.com/organization/repo.git');
});
