<?php

function dockerPsContainer(string $name, string $createdAt, string $status, string $state = 'running'): array
{
    return [
        'Names' => $name,
        'CreatedAt' => $createdAt,
        'State' => $state,
        'Status' => $status,
    ];
}

it('returns the only running container', function () {
    $containers = collect([
        dockerPsContainer('app-old', '2026-09-23 16:00:00 +0000 UTC', 'Up 2 hours (healthy)'),
    ]);

    expect(selectServingContainer($containers)['Names'])->toBe('app-old');
});

it('keeps the healthy container while the new one is still starting', function () {
    $containers = collect([
        dockerPsContainer('app-new', '2026-09-23 16:18:53 +0000 UTC', 'Up 8 seconds (health: starting)'),
        dockerPsContainer('app-old', '2026-09-23 16:00:00 +0000 UTC', 'Up 18 minutes (healthy)'),
    ]);

    expect(selectServingContainer($containers)['Names'])->toBe('app-old');
});

it('picks the newest container once both are healthy', function () {
    $containers = collect([
        dockerPsContainer('app-old', '2026-09-23 16:00:00 +0000 UTC', 'Up 19 minutes (healthy)'),
        dockerPsContainer('app-new', '2026-09-23 16:18:53 +0000 UTC', 'Up 20 seconds (healthy)'),
    ]);

    expect(selectServingContainer($containers)['Names'])->toBe('app-new');
});

it('treats a container without a healthcheck as ready', function () {
    $containers = collect([
        dockerPsContainer('app-old', '2026-09-23 16:00:00 +0000 UTC', 'Up 19 minutes'),
        dockerPsContainer('app-new', '2026-09-23 16:18:53 +0000 UTC', 'Up 8 seconds (health: starting)'),
    ]);

    expect(selectServingContainer($containers)['Names'])->toBe('app-old');
});

it('orders by creation instant when the offset changes between containers', function () {
    $containers = collect([
        dockerPsContainer('app-old', '2026-10-25 02:30:00 +0200 CEST', 'Up 20 minutes (healthy)'),
        dockerPsContainer('app-new', '2026-10-25 02:10:00 +0100 CET', 'Up 20 seconds (healthy)'),
    ]);

    expect(selectServingContainer($containers)['Names'])->toBe('app-new');
});

it('prefers a healthy container over a newer unhealthy one', function () {
    $containers = collect([
        dockerPsContainer('app-new', '2026-09-23 16:18:53 +0000 UTC', 'Up 2 minutes (unhealthy)'),
        dockerPsContainer('app-old', '2026-09-23 16:00:00 +0000 UTC', 'Up 20 minutes (healthy)'),
    ]);

    expect(selectServingContainer($containers)['Names'])->toBe('app-old');
});

it('ignores containers that are not running', function () {
    $containers = collect([
        dockerPsContainer('app-exited', '2026-09-23 16:18:53 +0000 UTC', 'Exited (1) 5 seconds ago', 'exited'),
        dockerPsContainer('app-old', '2026-09-23 16:00:00 +0000 UTC', 'Up 20 minutes (healthy)'),
    ]);

    expect(selectServingContainer($containers)['Names'])->toBe('app-old');
});

it('falls back to the newest running container when none is ready', function () {
    $containers = collect([
        dockerPsContainer('app-old', '2026-09-23 16:00:00 +0000 UTC', 'Up 20 minutes (unhealthy)'),
        dockerPsContainer('app-new', '2026-09-23 16:18:53 +0000 UTC', 'Up 8 seconds (health: starting)'),
    ]);

    expect(selectServingContainer($containers)['Names'])->toBe('app-new');
});

it('returns null when no container is running', function () {
    $containers = collect([
        dockerPsContainer('app-exited', '2026-09-23 16:00:00 +0000 UTC', 'Exited (0) 1 minute ago', 'exited'),
    ]);

    expect(selectServingContainer($containers))->toBeNull();
});
