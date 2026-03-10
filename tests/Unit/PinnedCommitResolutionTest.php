<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;

function setJobProperty(object $job, string $property, mixed $value): void
{
    $reflection = new ReflectionClass($job);
    $prop = $reflection->getProperty($property);
    $prop->setAccessible(true);
    $prop->setValue($job, $value);
}

function getJobProperty(object $job, string $property): mixed
{
    $reflection = new ReflectionClass($job);
    $prop = $reflection->getProperty($property);
    $prop->setAccessible(true);

    return $prop->getValue($job);
}

it('keeps pinned commit when resolving git commit sha', function () {
    $pinnedCommit = str_repeat('a', 40);
    $remoteCommit = str_repeat('b', 40);
    $lsRemoteOutput = $remoteCommit."\trefs/heads/main";

    $settings = new ApplicationSetting([
        'include_source_commit_in_build' => false,
        'use_build_secrets' => false,
        'is_git_submodules_enabled' => false,
        'is_git_lfs_enabled' => false,
        'is_git_shallow_clone_enabled' => false,
    ]);
    $application = Application::factory()->make([
        'git_repository' => 'https://example.com/acme/repo.git',
        'git_branch' => 'main',
        'git_commit_sha' => $pinnedCommit,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
    ]);
    $application->setRelation('settings', $settings);

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->commit = $pinnedCommit;
    $queue->shouldReceive('save')->never();

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldReceive('execute_remote_command')->andReturnNull();

    setJobProperty($job, 'application', $application);
    setJobProperty($job, 'application_deployment_queue', $queue);
    setJobProperty($job, 'deployment_uuid', 'deployment-uuid');
    setJobProperty($job, 'pull_request_id', 0);
    setJobProperty($job, 'commit', 'HEAD');
    setJobProperty($job, 'rollback', false);
    setJobProperty($job, 'git_type', null);
    setJobProperty($job, 'saved_outputs', collect([
        'git_commit_sha' => str($lsRemoteOutput),
    ]));
    setJobProperty($job, 'source', 'other');

    $method = new ReflectionMethod(ApplicationDeploymentJob::class, 'check_git_if_build_needed');
    $method->setAccessible(true);
    $method->invoke($job);

    expect(getJobProperty($job, 'commit'))->toBe($pinnedCommit);
});

it('updates commit from ls-remote when no pinned commit is set', function () {
    $remoteCommit = str_repeat('c', 40);
    $lsRemoteOutput = $remoteCommit."\trefs/heads/main";

    $settings = new ApplicationSetting([
        'include_source_commit_in_build' => false,
        'use_build_secrets' => false,
        'is_git_submodules_enabled' => false,
        'is_git_lfs_enabled' => false,
        'is_git_shallow_clone_enabled' => false,
    ]);
    $application = Application::factory()->make([
        'git_repository' => 'https://example.com/acme/repo.git',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
    ]);
    $application->setRelation('settings', $settings);

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->commit = 'HEAD';
    $queue->shouldReceive('save')->once();

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldReceive('execute_remote_command')->andReturnNull();

    setJobProperty($job, 'application', $application);
    setJobProperty($job, 'application_deployment_queue', $queue);
    setJobProperty($job, 'deployment_uuid', 'deployment-uuid');
    setJobProperty($job, 'pull_request_id', 0);
    setJobProperty($job, 'commit', 'HEAD');
    setJobProperty($job, 'rollback', false);
    setJobProperty($job, 'git_type', null);
    setJobProperty($job, 'saved_outputs', collect([
        'git_commit_sha' => str($lsRemoteOutput),
    ]));
    setJobProperty($job, 'source', 'other');

    $method = new ReflectionMethod(ApplicationDeploymentJob::class, 'check_git_if_build_needed');
    $method->setAccessible(true);
    $method->invoke($job);

    expect(getJobProperty($job, 'commit'))->toBe($remoteCommit);
    expect($queue->commit)->toBe($remoteCommit);
});
