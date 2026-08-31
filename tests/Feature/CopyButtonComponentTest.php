<?php

it('renders a self-contained clipboard button for backend-provided values', function () {
    $html = $this->blade('<x-copy-button value="backup/path.sql" label="Copy backup path" />');

    $html->assertSee('Copy backup path')
        ->assertSee('backup\/path.sql', false)
        ->assertSee('x-data="copyButton"', false)
        ->assertDontSee('window.copyToClipboard', false);
});

it('disables the button when no backend value is available', function () {
    $html = $this->blade('<x-copy-button :value="null" />');

    $html->assertSee('disabled', false);
});

it('evaluates a resolve expression at click time instead of a static value', function () {
    $html = $this->blade('<x-copy-button resolve="$wire.copyValue()" />');

    $html->assertSee('await ($wire.copyValue())', false)
        ->assertDontSee('disabled', false);
});

it('is the single clipboard implementation shared by its call sites', function () {
    expect(file_get_contents(resource_path('js/copy-button.js')))
        ->toContain("window.Alpine.data('copyButton'");

    expect(file_get_contents(resource_path('js/app.js')))
        ->toContain('initializeCopyButtonComponent');

    $modalConfirmation = file_get_contents(resource_path('views/components/modal-confirmation.blade.php'));
    $backupExecutions = file_get_contents(resource_path('views/livewire/project/database/backup-executions.blade.php'));

    expect($modalConfirmation)
        ->toContain('<x-copy-button resolve="decodedText"')
        ->not->toContain('navigator.clipboard');

    expect($backupExecutions)
        ->toContain('<x-copy-button :value="data_get($execution, \'filename\', \'\')" label="Copy backup path"')
        ->not->toContain('navigator.clipboard');
});
