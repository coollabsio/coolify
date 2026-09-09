<?php

it('keeps editable standalone database storage actions in the table action column', function () {
    $component = file_get_contents(app_path('Livewire/Project/Shared/Storages/All.php'));
    $view = file_get_contents(resource_path('views/livewire/project/shared/storages/all.blade.php'));

    expect($component)
        ->toContain('$this->showActionsColumn = $this->canUpdate;')
        ->toContain('$this->showBackupAction = $this->resource instanceof Application')
        ->toContain('if (! $this->showBackupAction)')
        ->and($view)
        ->toContain('@if ($showActionsColumn)')
        ->toContain('@if ($showBackupAction)')
        ->toContain('title="New Scheduled Backup"')
        ->toContain('<livewire:project.database.create-scheduled-backup :database="$resource"')
        ->toContain('aria-label="Configure backup"')
        ->toContain('<x-reicon name="database" class="size-4" />');
});
