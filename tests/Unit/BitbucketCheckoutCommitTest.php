<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use Tests\TestCase;

uses(TestCase::class);

it('resolves the Bitbucket PR checkout commit', function (?string $configured, ?string $commit, string $expected) {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->forceFill(['git_branch' => 'main', 'git_commit_sha' => $configured]);
    $application->setRelation('settings', new ApplicationSetting);
    $application->shouldReceive('deploymentType')->andReturn('other');
    $application->shouldReceive('customRepository')->andReturn(['repository' => 'https://bitbucket.org/example/repo.git', 'port' => 22]);

    $result = $application->generateGitImportCommands('test-deployment', pull_request_id: 1, git_type: 'bitbucket', exec_in_docker: false, commit: $commit);

    expect($result['commands'])->toContain("git checkout '{$expected}'");
})->with([
    'configured commit' => ['abc1234', null, 'abc1234'],
    'HEAD fallback' => [null, null, 'HEAD'],
    'empty fallback' => ['', '', 'HEAD'],
    'explicit commit' => ['abc1234', 'def5678', 'def5678'],
]);
