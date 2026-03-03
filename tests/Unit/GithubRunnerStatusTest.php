<?php

use App\Enums\GithubRunnerStatus;

it('identifies active statuses correctly', function () {
    expect(GithubRunnerStatus::Queued->isActive())->toBeTrue();
    expect(GithubRunnerStatus::Provisioning->isActive())->toBeTrue();
    expect(GithubRunnerStatus::Running->isActive())->toBeTrue();
    expect(GithubRunnerStatus::Cleaning->isActive())->toBeTrue();
});

it('identifies inactive statuses correctly', function () {
    expect(GithubRunnerStatus::Completed->isActive())->toBeFalse();
    expect(GithubRunnerStatus::Failed->isActive())->toBeFalse();
    expect(GithubRunnerStatus::TimedOut->isActive())->toBeFalse();
});

it('creates from string values', function () {
    expect(GithubRunnerStatus::from('queued'))->toBe(GithubRunnerStatus::Queued);
    expect(GithubRunnerStatus::from('running'))->toBe(GithubRunnerStatus::Running);
    expect(GithubRunnerStatus::from('completed'))->toBe(GithubRunnerStatus::Completed);
    expect(GithubRunnerStatus::from('timed_out'))->toBe(GithubRunnerStatus::TimedOut);
});
