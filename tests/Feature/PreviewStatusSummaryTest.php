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
