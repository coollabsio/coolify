<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;

function setDeploymentJobProperty(object $job, string $property, mixed $value): void
{
    $reflectionProperty = new ReflectionProperty(ApplicationDeploymentJob::class, $property);
    $reflectionProperty->setValue($job, $value);
}

function getDeploymentJobProperty(object $job, string $property): mixed
{
    $reflectionProperty = new ReflectionProperty(ApplicationDeploymentJob::class, $property);

    return $reflectionProperty->getValue($job);
}

function invokeCheckGitIfBuildNeeded(object $job): void
{
    $method = new ReflectionMethod(ApplicationDeploymentJob::class, 'check_git_if_build_needed');
    $method->invoke($job);
}

function makeDeploymentJobForCommitCheck(string $pinnedSha, string $branchHeadSha): object
{
    $job = new class extends ApplicationDeploymentJob
    {
        public function __construct() {}

        public function execute_remote_command(...$commands) {}
    };

    $application = new class extends Application
    {
        public function generateGitImportCommands(string $deployment_uuid, int $pull_request_id = 0, ?string $git_type = null, bool $exec_in_docker = true, bool $only_checkout = false, ?string $custom_base_dir = null, ?string $commit = null)
        {
            return [
                'commands' => collect([]),
                'branch' => 'main',
                'fullRepoUrl' => 'https://github.com/coollabsio/coolify.git',
            ];
        }
    };
    $application->forceFill([
        'uuid' => 'application-uuid',
        'git_branch' => 'main',
    ]);
    $application->setRelation('settings', (object) [
        'include_source_commit_in_build' => false,
        'use_build_secrets' => false,
    ]);
    $application->setRelation('private_key', null);

    $deploymentQueue = new class extends ApplicationDeploymentQueue
    {
        public bool $saved = false;

        public function save(array $options = []): bool
        {
            $this->saved = true;

            return true;
        }
    };
    $deploymentQueue->commit = $pinnedSha;

    setDeploymentJobProperty($job, 'application', $application);
    setDeploymentJobProperty($job, 'application_deployment_queue', $deploymentQueue);
    setDeploymentJobProperty($job, 'deployment_uuid', 'deployment-uuid');
    setDeploymentJobProperty($job, 'pull_request_id', 0);
    setDeploymentJobProperty($job, 'commit', $pinnedSha);
    setDeploymentJobProperty($job, 'rollback', false);
    setDeploymentJobProperty($job, 'git_type', 'github');
    setDeploymentJobProperty($job, 'saved_outputs', collect([
        'git_commit_sha' => str("{$branchHeadSha}\trefs/heads/main"),
    ]));

    return $job;
}

function shouldResolveBranchHeadForCommit(?string $commit): bool
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();

    $commitProperty = new ReflectionProperty($job, 'commit');
    $commitProperty->setValue($job, $commit ?? '');

    $method = new ReflectionMethod($job, 'shouldResolveBranchHeadCommit');

    return $method->invoke($job);
}

describe('ApplicationDeploymentJob commit resolution', function () {
    test('resolves branch head for HEAD deployments', function () {
        expect(shouldResolveBranchHeadForCommit('HEAD'))->toBeTrue();
    });

    test('resolves branch head for blank deployments', function () {
        expect(shouldResolveBranchHeadForCommit(''))->toBeTrue();
    });

    test('keeps pinned deployment commits instead of replacing them with branch head', function () {
        expect(shouldResolveBranchHeadForCommit('abc123def456abc123def456abc123def456abc1'))->toBeFalse();
    });

    test('check git does not overwrite pinned deployment commit with branch head', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $branchHeadSha = '111222333444555666777888999000aaabbbccc1';
        $job = makeDeploymentJobForCommitCheck($pinnedSha, $branchHeadSha);

        invokeCheckGitIfBuildNeeded($job);

        $deploymentQueue = getDeploymentJobProperty($job, 'application_deployment_queue');

        expect(getDeploymentJobProperty($job, 'commit'))->toBe($pinnedSha)
            ->and($deploymentQueue->commit)->toBe($pinnedSha)
            ->and($deploymentQueue->saved)->toBeFalse();
    });
});
