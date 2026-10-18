<?php

it('gates standalone database storage actions behind the update permission', function () {
    $component = file_get_contents(app_path('Livewire/Project/Shared/Storages/All.php'));
    $view = file_get_contents(resource_path('views/livewire/project/shared/storages/all.blade.php'));

    expect($component)
        ->toContain('$this->showActionsColumn = $this->canUpdate;')
        ->and($view)
        ->toContain('@if ($showActionsColumn)')
        ->toContain('canGate="update"');
});
