<?php

test('disabled application metrics use the standard empty state', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/metrics.blade.php'));

    expect($view)
        ->toContain('<x-empty size="sm" title="Metrics are not enabled"')
        ->toContain('description="Enable Sentinel and metrics for this server before collecting application usage data."')
        ->toContain('icon-name="dashboard"')
        ->not->toContain('<x-callout type="info" title="Metrics are not enabled">');
});
