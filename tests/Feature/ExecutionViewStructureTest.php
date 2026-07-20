<?php

it('uses the simplified database backup execution heading and spacing', function () {
    $view = file_get_contents(resource_path('views/livewire/project/database/backup-executions.blade.php'));

    expect($view)
        ->toContain('<div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">')
        ->toContain('<h2 class="py-0">Executions</h2>')
        ->not->toContain('Executions <span')
        ->toContain('class="flex flex-col gap-4 pt-2"');
});

it('renders scheduled task executions as selectable status cards with expandable logs', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/scheduled-task/executions.blade.php'));

    expect($view)
        ->toContain('<div class="flex flex-col gap-2" wire:poll.5000ms="refreshExecutions"')
        ->toContain('<a wire:click="selectTask({{ data_get($execution, \'id\') }})"')
        ->toContain('border-l-2 transition-colors p-4 cursor-pointer')
        ->toContain("'success' => 'Success'")
        ->toContain("'running' => 'In Progress'")
        ->toContain("'failed' => 'Failed'")
        ->toContain("data_get(\$execution, 'id') == \$selectedKey")
        ->toContain('max-h-[600px] overflow-y-auto')
        ->toContain('No executions found.');
});
