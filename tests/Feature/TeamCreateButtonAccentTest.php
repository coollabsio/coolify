<?php

/**
 * Create team primary action must use the theme-aware purple/yellow accent,
 * not the legacy solid coollabs purple fill.
 */
test('create team button uses theme-aware primary accent classes', function () {
    $view = file_get_contents(resource_path('views/livewire/team/create.blade.php'));

    expect($view)
        ->toContain('Create team')
        ->toContain('bg-coollabs/10!')
        ->toContain('text-coollabs!')
        ->toContain('dark:bg-warning/15!')
        ->toContain('dark:text-warning!')
        ->not->toContain('bg-coollabs! text-white!')
        ->not->toContain('hover:bg-coollabs-100!');
});
