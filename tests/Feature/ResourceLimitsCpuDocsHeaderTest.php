<?php

/**
 * Docker CPU constraints docs link belongs in the CPU section header as a button.
 */
test('resource limits cpu docs link is a header action button', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/resource-limits.blade.php'));

    expect($view)
        ->toContain('<x-slot:actions>')
        ->toContain('Docker CPU constraints')
        ->toContain('https://docs.docker.com/engine/containers/resource_constraints/#cpu')
        ->toContain('class="button"')
        ->toContain('name="external-link"')
        ->not->toContain('mt-4 inline-flex items-center gap-1 text-xs text-neutral-500');
});
