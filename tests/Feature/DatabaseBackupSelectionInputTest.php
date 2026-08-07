<?php

it('uses a database list editor instead of a comma-separated text field', function () {
    $view = file_get_contents(resource_path('views/livewire/project/database/backup-edit/general.blade.php'));

    expect($view)
        ->toContain('<div class="grid w-full gap-4">')
        ->toContain("['value' => true, 'label' => 'All databases']")
        ->toContain("['value' => false, 'label' => 'Specific databases']")
        ->toContain("value: @entangle('databasesToBackup').live")
        ->toContain('class="chip-input"')
        ->toContain('class="chip font-mono"')
        ->toContain('class="chip-remove"')
        ->toContain('addDatabase()')
        ->toContain('removeDatabase(index)')
        ->toContain('Type a database and press Enter');

    expect(substr_count($view, '<x-forms.input label="Databases to back up"'))->toBe(1);
});
