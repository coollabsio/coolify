<?php

test('database restore output opens in the centered process dialog', function () {
    $view = file_get_contents(resource_path('views/livewire/project/database/import-form.blade.php'));

    expect($view)
        ->toContain('<x-process-dialog @databaserestore.window="processDialogOpen = true" closeWithX size="xl">')
        ->toContain('<livewire:activity-monitor wire:key="database-restore-{{ $resourceUuid }}" header="Logs" fullHeight />')
        ->not->toContain('<x-slide-over @databaserestore.window="slideOverOpen = true"');
});

test('postgresql dump all restore warns that administrator passwords are overwritten', function () {
    $view = file_get_contents(resource_path('views/livewire/project/database/import-form.blade.php'));

    expect($view)
        ->toContain('@if ($dumpAll)')
        ->toContain('Full restore overwrites administrator passwords')
        ->toContain('The backup replaces PostgreSQL administrator role passwords, including the destination administrator password.')
        ->toContain("If the administrator password changes, update it in Coolify's database configuration after the restore.");
});
