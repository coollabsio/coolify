<?php

use Illuminate\Support\Facades\Blade;

it('aggregates preview container and health check status', function () {
    $html = Blade::render('<x-status-summary status="running:unknown" title="Preview status" />');

    expect($html)
        ->toContain('Preview status')
        ->toContain('Container')
        ->toContain('Running (no healthcheck)')
        ->toContain('Healthcheck')
        ->toContain('Not configured')
        ->toContain('aria-label="About unconfigured healthchecks"')
        ->toContain('class="relative inline-flex align-middle"')
        ->toContain('Traffic can still be routed to the container')
        ->toContain('aria-haspopup="menu"')
        ->toContain('right-auto! left-0!')
        ->toContain('w-[min(16rem,calc(100vw-1.5rem))]!');
});

it('shows degraded aggregate service status as a warning', function () {
    $html = Blade::render('<x-status-summary status="degraded:unhealthy" title="Service status" container-name="Containers" />');
    $summaryButton = str($html)->between('<button', '</button>')->toString();

    expect($summaryButton)
        ->toContain('Degraded')
        ->toContain('bg-warning')
        ->not->toContain('bg-error');
});

it('uses the aggregated preview status in the previews list', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/previews.blade.php'));

    expect($view)
        ->toContain('<x-status-summary :status="data_get($preview, \'status\')" title="Preview status" />')
        ->not->toContain('<x-status.running :status="data_get($preview, \'status\')" />');
});

it('uses the aggregated application status beside the top breadcrumbs', function () {
    $status = file_get_contents(resource_path('views/livewire/project/application/status.blade.php'));

    expect($status)
        ->toContain('<x-status-summary :status="$application->status" />')
        ->not->toContain('$statusLabel');
});

it('uses the aggregated status badge for databases and services', function () {
    $databaseStatus = file_get_contents(resource_path('views/livewire/project/database/status.blade.php'));
    $serviceStatus = file_get_contents(resource_path('views/livewire/project/service/status.blade.php'));

    expect($databaseStatus)
        ->toContain('<x-status-summary :status="$database->status" title="Database status" />')
        ->and($serviceStatus)
        ->toContain('<x-status-summary :status="$displayStatus" :title="$selectedResource ? \'Resource status\' : \'Service status\'"');
});

it('groups preview deployment actions in a dropdown', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/previews.blade.php'));

    expect($view)
        ->toContain('title="Preview actions"')
        ->toContain('preview-stop-trigger-{{ data_get($preview, \'pull_request_id\') }}')
        ->toContain('preview-delete-trigger-{{ data_get($preview, \'pull_request_id\') }}')
        ->not->toContain('<x-slot:customButton>');
});

it('marks external preview links and groups logs in a dropdown', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/previews.blade.php'));

    expect($view)
        ->toContain('title="Open preview in a new tab"')
        ->toContain('title="Open pull request in a new tab"')
        ->toContain('<x-reicon name="external-link"')
        ->toContain('title="Preview logs"')
        ->toContain('Deployment logs')
        ->toContain('Runtime logs')
        ->not->toContain('Application logs');
});

it('places links and logs dropdowns beside preview actions', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/previews.blade.php'));
    $controls = str($view)->after('id="preview-header-controls')->before('<div class="hidden" aria-hidden="true">')->toString();

    expect($controls)
        ->toContain('title="Preview links"')
        ->toContain('title="Preview logs"')
        ->toContain('title="Preview actions"');
});

it('uses the shared domain list treatment for preview domains', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/previews.blade.php'));
    $domainsView = file_get_contents(resource_path('views/livewire/project/application/preview-domains.blade.php'));

    expect($view)
        ->toContain('<livewire:project.application.preview-domains')
        ->and($domainsView)
        ->toContain('Recheck DNS')
        ->toContain('Add domain')
        ->toContain('data-table-header')
        ->toContain('domains-table-grid-service')
        ->toContain('class="env-table-item"')
        ->toContain('No domains configured')
        ->toContain('class="data-table-row')
        ->toContain('DNS OK')
        ->toContain('Edit domain')
        ->toContain('Remove domain');
});
