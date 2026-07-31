<?php

use App\Mcp\Concerns\BuildsResponse;

/**
 * Expose protected action helpers for unit testing without booting MCP tools.
 */
class BuildsResponseActionsHarness
{
    use BuildsResponse;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function databaseActions(string $uuid, ?string $status = null): array
    {
        return $this->actionsForDatabase($uuid, $status);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function serviceActions(string $uuid, ?string $status = null): array
    {
        return $this->actionsForService($uuid, $status);
    }
}

function controlActionFrom(array $actions): ?string
{
    $control = collect($actions)->firstWhere('tool', 'control');

    return is_array($control) ? ($control['args']['action'] ?? null) : null;
}

function toolsFrom(array $actions): array
{
    return collect($actions)->pluck('tool')->all();
}

test('healthy running database suggests logs and restart', function () {
    $actions = (new BuildsResponseActionsHarness)->databaseActions('db-uuid', 'running:healthy');

    expect(toolsFrom($actions))->toContain('get_logs')
        ->and(controlActionFrom($actions))->toBe('restart');
});

test('unhealthy running database suggests logs and restart not start', function () {
    $actions = (new BuildsResponseActionsHarness)->databaseActions('db-uuid', 'running:unhealthy');

    expect(toolsFrom($actions))->toContain('get_logs')
        ->and(controlActionFrom($actions))->toBe('restart')
        ->and(collect($actions)->pluck('args.action')->filter()->all())->not->toContain('start');
});

test('stopped database suggests start not restart', function () {
    $actions = (new BuildsResponseActionsHarness)->databaseActions('db-uuid', 'exited');

    expect(controlActionFrom($actions))->toBe('start')
        ->and(toolsFrom($actions))->not->toContain('get_logs');
});

test('healthy running service suggests logs and restart', function () {
    $actions = (new BuildsResponseActionsHarness)->serviceActions('svc-uuid', 'running:healthy');

    expect(toolsFrom($actions))->toContain('get_logs')
        ->and(controlActionFrom($actions))->toBe('restart');
});

test('unhealthy running service suggests logs and restart not start', function () {
    $actions = (new BuildsResponseActionsHarness)->serviceActions('svc-uuid', 'running:unhealthy');

    expect(toolsFrom($actions))->toContain('get_logs')
        ->and(controlActionFrom($actions))->toBe('restart')
        ->and(collect($actions)->pluck('args.action')->filter()->all())->not->toContain('start');
});

test('stopped service suggests start not restart', function () {
    $actions = (new BuildsResponseActionsHarness)->serviceActions('svc-uuid', 'exited:unhealthy');

    expect(controlActionFrom($actions))->toBe('start')
        ->and(toolsFrom($actions))->not->toContain('get_logs');
});
