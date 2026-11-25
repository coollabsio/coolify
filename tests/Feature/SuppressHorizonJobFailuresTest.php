<?php

use App\Exceptions\DeploymentException;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\TimeoutExceededException;
use Laravel\Horizon\Contracts\JobRepository;
use Mockery\MockInterface;

function fakeJob(string $uuid): Job
{
    $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('getJobId')->andReturn($uuid);

    return $job;
}

function fireJobFailed(Job $job, Throwable $exception): void
{
    event(new JobFailed('redis', $job, $exception));
}

beforeEach(function () {
    config(['constants.coolify.self_hosted' => false]);
});

test('scrubs Horizon failed entry for DeploymentException on cloud', function () {
    $uuid = 'uuid-deployment-1';

    $this->mock(JobRepository::class, function (MockInterface $mock) use ($uuid) {
        $mock->shouldReceive('deleteFailed')->once()->with($uuid);
    });

    fireJobFailed(fakeJob($uuid), new DeploymentException('build failed'));
});

test('scrubs Horizon failed entry for TimeoutExceededException on cloud', function () {
    $uuid = 'uuid-timeout-1';

    $this->mock(JobRepository::class, function (MockInterface $mock) use ($uuid) {
        $mock->shouldReceive('deleteFailed')->once()->with($uuid);
    });

    fireJobFailed(fakeJob($uuid), new TimeoutExceededException('worker timeout'));
});

test('does not scrub generic exceptions on cloud', function () {
    $this->mock(JobRepository::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('deleteFailed');
    });

    fireJobFailed(fakeJob('uuid-generic-1'), new RuntimeException('boom'));
});

test('does not scrub when self-hosted even for filtered exceptions', function () {
    config(['constants.coolify.self_hosted' => true]);

    $this->mock(JobRepository::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('deleteFailed');
    });

    fireJobFailed(fakeJob('uuid-deployment-2'), new DeploymentException('build failed'));
    fireJobFailed(fakeJob('uuid-timeout-2'), new TimeoutExceededException('worker timeout'));
});
