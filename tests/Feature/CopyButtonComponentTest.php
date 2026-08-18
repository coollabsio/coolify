<?php

it('renders a reusable compact copy button', function () {
    $html = $this->blade('<x-copy-button value="backup/path.sql" label="Copy backup path" />');

    $html->assertSee('Copy backup path')
        ->assertSee('backup\/path.sql', false)
        ->assertSee('window.copyToClipboard', false)
        ->assertSee('size-6', false);
});

it('uses the reusable copy button for database backup paths', function () {
    $view = file_get_contents(resource_path('views/livewire/project/database/backup-executions.blade.php'));

    expect($view)->toContain('<x-copy-button :value="data_get($execution, \'filename\', \'\')" label="Copy backup path" />');
});
