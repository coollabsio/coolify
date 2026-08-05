<?php

it('shows a loading overlay while resource selection steps load', function () {
    $view = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($view)
        ->toContain('wire:loading.class="pointer-events-none opacity-40 blur-[2px]"')
        ->toContain('wire:loading.flex')
        ->toContain('selectServer,selectDestination,selectProject,selectEnvironment')
        ->toContain('Loading selection…')
        ->toContain('isPaletteTransitioning')
        ->toContain('runPaletteTransition')
        ->toContain('Loading…');
});
