<?php

it('renders application and database statuses as shared status badges', function (string $status, string $expectedText) {
    $resource = new class($status)
    {
        public function __construct(public string $status) {}
    };

    $html = view('components.status.index', [
        'resource' => $resource,
        'showRefreshButton' => false,
    ])->render();

    expect($html)
        ->toContain('inline-flex h-6 max-w-full items-center gap-1.5 whitespace-nowrap rounded-full border')
        ->toContain($expectedText)
        ->not->toContain('badge-success')
        ->not->toContain('badge-warning')
        ->not->toContain('badge-error');
})->with([
    'running healthy' => ['running:healthy', 'Running (healthy)'],
    'starting unknown' => ['starting:unknown', 'Starting (unknown)'],
    'degraded unhealthy' => ['degraded:unhealthy', 'Degraded (unhealthy)'],
    'exited unhealthy' => ['exited:unhealthy', 'Stopped'],
]);

it('renders service container statuses as shared status badges', function () {
    $html = view('components.status.services', [
        'complexStatus' => 'running:healthy',
        'showRefreshButton' => false,
    ])->render();

    expect($html)
        ->toContain('inline-flex h-6 max-w-full items-center gap-1.5 whitespace-nowrap rounded-full border')
        ->toContain('Running (healthy)')
        ->not->toContain('badge-success');
});

it('uses bordered status badges in the top breadcrumb', function () {
    $breadcrumb = file_get_contents(resource_path('views/components/top-breadcrumb.blade.php'));
    $applicationStatus = file_get_contents(resource_path('views/livewire/project/application/status.blade.php'));
    $borderedBadgeClasses = 'rounded-full border border-neutral-200 bg-neutral-100';

    expect(substr_count($breadcrumb.$applicationStatus, $borderedBadgeClasses))->toBe(3)
        ->and($applicationStatus)->toContain('<x-status-summary')
        ->and(substr_count($breadcrumb, 'rounded-full bg-neutral-100'))->toBe(0);
});

it('renders resource statuses through reactive livewire components', function () {
    $breadcrumb = file_get_contents(resource_path('views/components/top-breadcrumb.blade.php'));

    expect($breadcrumb)
        ->toContain('<livewire:project.application.status')
        ->toContain('<livewire:project.database.status')
        ->toContain('<livewire:project.service.status')
        ->not->toContain('$applicationStatus = str($currentApplication->status');
});

it('uses a shared refresh badge for resource status refresh actions', function () {
    $statusIndex = file_get_contents(resource_path('views/components/status/index.blade.php'));
    $serviceStatus = file_get_contents(resource_path('views/components/status/services.blade.php'));

    expect($statusIndex)
        ->toContain('<x-status-badge as="button"')
        ->toContain("wire:click='manualCheckStatus'")
        ->toContain('wire:loading.attr="disabled"')
        ->not->toContain('status="Refreshing..."')
        ->toContain('wire:loading.attr="disabled"')
        ->not->toContain('status="Refreshing..."')
        ->not->toContain('<svg');

    expect(file_get_contents(resource_path('views/livewire/project/service/resource-card.blade.php')))
        ->toContain('<x-status-badge')
        ->toContain('formatContainerStatus($resource->status)');

    expect($serviceStatus)
        ->toContain('<x-status-badge as="button"')
        ->toContain("wire:click='manualCheckStatus'")
        ->not->toContain('<svg');
});

it('renders health warning helpers without increasing the badge row height', function (string $status, string $expectedText) {
    $html = view('components.status.running', [
        'status' => $status,
    ])->render();
    $runningStatus = file_get_contents(resource_path('views/components/status/running.blade.php'));

    expect($html)
        ->toContain($expectedText)
        ->toContain('class="flex items-center gap-1 leading-none"')
        ->toContain('inline-flex h-6 max-w-full items-center gap-1.5 whitespace-nowrap rounded-full border')
        ->not->toContain('<svg');

    expect($runningStatus)
        ->toContain('<x-status-badge')
        ->toContain('class="flex items-center gap-1 leading-none"')
        ->not->toContain('class="px-2"')
        ->not->toContain('viewBox="0 0 256 256"');
})->with([
    'unknown health' => ['running:unknown', 'No health check'],
    'unhealthy' => ['running:unhealthy', 'Unhealthy'],
]);

it('renders restart counts as warning badges', function () {
    $resource = new class
    {
        public string $status = 'running:unknown';

        public int $restart_count = 9;

        public ?int $max_restart_count = null;

        public ?string $last_restart_type = null;

        public $last_restart_at;

        public function __construct()
        {
            $this->last_restart_at = now();
        }
    };

    $html = view('components.status.index', [
        'resource' => $resource,
        'showRefreshButton' => false,
    ])->render();
    $statusIndex = file_get_contents(resource_path('views/components/status/index.blade.php'));

    expect($html)
        ->toContain('9x restarts')
        ->toContain('bg-warning')
        ->not->toContain('(9x restarts)');

    expect($statusIndex)
        ->toContain('<x-status-badge')
        ->toContain('restart_count')
        ->not->toContain('class="text-xs dark:text-warning"');
});
