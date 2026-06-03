<?php

use Illuminate\Support\Str;

function runPostgresUpgradeScriptCommand(string $script): array
{
    $root = base_path();
    $workingDirectory = sys_get_temp_dir().'/coolify-postgres-upgrade-test-'.Str::random(12);
    $binDirectory = $workingDirectory.'/bin';

    mkdir($binDirectory, 0777, true);

    file_put_contents($binDirectory.'/docker', <<<'BASH'
#!/bin/bash
if [ "$1" = "inspect" ]; then
    printf '%s\n' "${MOCK_DOCKER_IMAGE}"
    exit 0
fi

if [ "$1" = "compose" ]; then
    printf '%s\n' "${LATEST_IMAGE}" > "${MOCK_LATEST_IMAGE_OUTPUT}"
    exit 0
fi

exit 1
BASH);
    chmod($binDirectory.'/docker', 0755);

    $outputFile = $workingDirectory.'/latest-image.txt';
    $command = sprintf(
        'PATH=%s:$PATH MOCK_LATEST_IMAGE_OUTPUT=%s COOLIFY_POSTGRES_UPGRADE_SOURCE_ONLY=true bash -c %s 2>&1',
        escapeshellarg($binDirectory),
        escapeshellarg($outputFile),
        escapeshellarg('cd '.escapeshellarg($root).'; '.$script),
    );

    exec($command, $output, $exitCode);

    return [$exitCode, implode("\n", $output), is_file($outputFile) ? trim(file_get_contents($outputFile)) : null];
}

it('detects the current Coolify image tag from the running container image', function (string $scriptPath, string $image, string $expectedTag) {
    [$exitCode, $output] = runPostgresUpgradeScriptCommand(sprintf(
        'MOCK_DOCKER_IMAGE=%s; export MOCK_DOCKER_IMAGE; source %s; current_coolify_image_tag',
        escapeshellarg($image),
        escapeshellarg($scriptPath),
    ));

    expect($exitCode)->toBe(0)
        ->and($output)->toBe($expectedTag);
})->with([
    'nightly standard registry image' => ['other/nightly/upgrade-postgres.sh', 'ghcr.io/coollabsio/coolify:4.0.0-beta.420', '4.0.0-beta.420'],
    'nightly registry with port' => ['other/nightly/upgrade-postgres.sh', 'registry.example.com:5000/coollabsio/coolify:4.0.1', '4.0.1'],
    'nightly digest suffix' => ['other/nightly/upgrade-postgres.sh', 'ghcr.io/coollabsio/coolify:4.0.2@sha256:abcdef', '4.0.2'],
    'scripts standard registry image' => ['scripts/upgrade-postgres.sh', 'ghcr.io/coollabsio/coolify:4.0.0-beta.420', '4.0.0-beta.420'],
    'scripts registry with port' => ['scripts/upgrade-postgres.sh', 'registry.example.com:5000/coollabsio/coolify:4.0.1', '4.0.1'],
    'scripts digest suffix' => ['scripts/upgrade-postgres.sh', 'ghcr.io/coollabsio/coolify:4.0.2@sha256:abcdef', '4.0.2'],
]);

it('passes the preserved Coolify image tag to docker compose when starting the stack', function (string $scriptPath) {
    [$exitCode, $output, $latestImage] = runPostgresUpgradeScriptCommand(
        sprintf('source %s; start_stack 4.0.0-beta.420', escapeshellarg($scriptPath)),
    );

    expect($exitCode)->toBe(0)
        ->and($output)->toBe('')
        ->and($latestImage)->toBe('4.0.0-beta.420');
})->with([
    'nightly script' => 'other/nightly/upgrade-postgres.sh',
    'release script' => 'scripts/upgrade-postgres.sh',
]);
