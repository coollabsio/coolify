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

it('links the healthcheck row to the healthcheck page and keeps the helper outside the link', function () {
    $html = Blade::render('<x-status-summary status="running:unknown" healthcheck-url="https://coolify.test/healthcheck" />');
    $link = str($html)->after('href="https://coolify.test/healthcheck"')->before('</a>')->toString();

    expect($html)->toContain('href="https://coolify.test/healthcheck"')
        ->and($link)
        ->toContain('Healthcheck')
        ->toContain('Not configured')
        ->not->toContain('About unconfigured healthchecks')
        ->and(str($html)->after('</a>')->toString())
        ->toContain('aria-label="About unconfigured healthchecks"');
});

it('does not link the healthcheck row without a healthcheck url', function () {
    $html = Blade::render('<x-status-summary status="running:healthy" />');

    expect($html)->not->toContain('role="menuitem"');
});
