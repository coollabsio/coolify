<?php

it('prefers the net push comparison over transient per-commit files', function () {
    $payload = collect([
        'commits' => [
            [
                'modified' => [
                    'packages/payments/config.yml',
                    'packages/payments/README.md',
                ],
                'added' => [],
                'removed' => [],
            ],
        ],
    ]);

    $changedFiles = getGithubPushChangedFiles($payload, [
        'apps/dashboard/page.tsx',
    ]);

    expect($changedFiles->all())->toBe([
        'apps/dashboard/page.tsx',
    ]);
});

it('treats a successful empty comparison as authoritative', function () {
    $payload = collect([
        'commits' => [
            [
                'modified' => ['packages/payments/README.md'],
                'added' => [],
                'removed' => [],
            ],
        ],
    ]);

    expect(getGithubPushChangedFiles($payload, [])->all())->toBe([]);
});

it('falls back to per-commit files when a comparison is unavailable', function () {
    $payload = collect([
        'commits' => [
            [
                'modified' => ['packages/payments/README.md'],
                'added' => [],
                'removed' => [],
            ],
        ],
    ]);

    expect(getGithubPushChangedFiles($payload)->all())->toBe([
        'packages/payments/README.md',
    ]);
});

it('preserves webhook files when the comparison reaches the GitHub file limit', function () {
    $payload = collect([
        'commits' => [
            [
                'modified' => ['apps/worker/job.php'],
                'added' => [],
                'removed' => [],
            ],
        ],
    ]);
    $comparedFiles = collect(range(1, 300))
        ->map(fn (int $index) => "packages/generated/{$index}.php")
        ->all();

    expect(getGithubPushChangedFiles($payload, $comparedFiles)->all())
        ->toHaveCount(301)
        ->toContain('apps/worker/job.php');
});
