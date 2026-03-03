<?php

use App\Models\GithubRunnerConfig;

it('matches when all requested labels are present', function () {
    $config = new GithubRunnerConfig;
    $config->labels = ['self-hosted', 'linux', 'x64', 'coolify'];

    expect($config->matchesLabels(['self-hosted', 'linux']))->toBeTrue();
    expect($config->matchesLabels(['self-hosted', 'coolify']))->toBeTrue();
    expect($config->matchesLabels(['self-hosted']))->toBeTrue();
});

it('does not match when a requested label is missing', function () {
    $config = new GithubRunnerConfig;
    $config->labels = ['self-hosted', 'linux', 'x64'];

    expect($config->matchesLabels(['self-hosted', 'gpu']))->toBeFalse();
    expect($config->matchesLabels(['self-hosted', 'arm64']))->toBeFalse();
});

it('matches labels case-insensitively', function () {
    $config = new GithubRunnerConfig;
    $config->labels = ['self-hosted', 'Linux', 'X64'];

    expect($config->matchesLabels(['Self-Hosted', 'linux']))->toBeTrue();
    expect($config->matchesLabels(['SELF-HOSTED', 'x64']))->toBeTrue();
});

it('matches when requesting empty labels', function () {
    $config = new GithubRunnerConfig;
    $config->labels = ['self-hosted'];

    expect($config->matchesLabels([]))->toBeTrue();
});
