<?php

test('database restore output opens in the centered process dialog', function () {
    $view = file_get_contents(resource_path('views/livewire/project/database/import-form.blade.php'));

    expect($view)
        ->toContain('<x-process-dialog @databaserestore.window="processDialogOpen = true" closeWithX size="xl">')
        ->toContain('<livewire:activity-monitor wire:key="database-restore-{{ $resourceUuid }}" header="Logs" fullHeight />')
        ->not->toContain('<x-slide-over @databaserestore.window="slideOverOpen = true"');
});
