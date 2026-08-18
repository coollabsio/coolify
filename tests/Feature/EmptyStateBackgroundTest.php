<?php

use Illuminate\Support\Facades\Blade;

it('uses the Coolify base background for all empty states', function () {
    $html = Blade::render('<x-empty title="Nothing found" />');
    $css = file_get_contents(resource_path('css/app.css'));

    expect($html)
        ->toContain('empty-state')
        ->not->toContain('bg-neutral-50')
        ->not->toContain('dark:bg-white/[0.02]')
        ->and($css)
        ->toContain('.application-settings-section-body:has(> .empty-state:only-child)')
        ->toContain('.application-settings-section-body.is-flush:has(> .empty-state:only-child)')
        ->toContain('padding: 1rem;')
        ->toContain('.empty-state')
        ->toContain('background: var(--coollabs-base);')
        ->toContain('.loading-state-card')
        ->toContain('border: 1px dashed var(--coollabs-line);');
});
