<?php

it('uses one shared visual treatment for all danger zone actions', function () {
    $component = file_get_contents(resource_path('views/components/danger-zone.blade.php'));

    expect($component)
        ->toContain("@props(['title'])")
        ->toContain('sm:flex-row sm:items-center sm:justify-between')
        ->toContain('text-red-700 dark:text-red-300')
        ->toContain('@isset($action)')
        ->not->toContain('bg-red-50');

    foreach ([
        'livewire/destination/show.blade.php',
        'livewire/project/database/backup-edit/danger.blade.php',
        'livewire/project/shared/danger.blade.php',
        'livewire/project/shared/storages/volume-backups/danger.blade.php',
        'livewire/server/delete.blade.php',
        'livewire/source/github/change.blade.php',
        'livewire/storage/show.blade.php',
        'livewire/team/danger-zone.blade.php',
    ] as $view) {
        expect(file_get_contents(resource_path('views/'.$view)))
            ->toContain('<x-danger-zone')
            ->not->toContain('rounded-lg border border-red-300 bg-red-50');
    }
});
