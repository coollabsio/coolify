<?php

it('renders the dashed empty-state card with title description and icon', function () {
    $html = $this->blade(
        '<x-empty title="No tags yet" description="Add a tag to group deployments." icon-name="tags" />'
    );

    $html->assertSee('No tags yet')
        ->assertSee('Add a tag to group deployments.')
        ->assertSee('empty-state', false)
        ->assertSee('border-dashed', false)
        ->assertSee('min-h-80', false);
});

it('renders empty states flush with no outer inset inside settings sections', function () {
    $html = $this->blade(
        <<<'BLADE'
        <x-application.settings-section title="Scheduled tasks" flush>
            <x-empty title="No scheduled tasks"
                description="Create a task to run maintenance commands."
                icon-name="browser-terminal" />
        </x-application.settings-section>
        BLADE
    );

    $html->assertSee('is-flush', false)
        ->assertSee('empty-state', false)
        ->assertSee('No scheduled tasks');

    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.application-settings-section-body.is-flush .empty-state')
        ->toContain('.application-settings-section-body:has(> .empty-state:only-child)')
        ->toContain('.application-settings-section > .empty-state')
        ->toMatch('/\.application-settings-section-body\.is-flush \.empty-state[\s\S]*?margin:\s*0/');
});

it('insets empty states nested inside a flush settings section wrapper', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('.application-settings-section-body.is-flush > div:has(> .empty-state:only-child)');
});

it('insets the backup executions empty state from the table header', function () {
    $view = file_get_contents(resource_path('views/livewire/project/database/backup-executions.blade.php'));

    expect($view)->toMatch('/@empty\s*<div class="p-4">\s*<x-empty size="sm" title="No backup executions"/');
});

it('keeps backup execution actions compact', function () {
    $css = file_get_contents(resource_path('css/app.css'));
    $view = file_get_contents(resource_path('views/livewire/project/database/backup-executions.blade.php'));

    expect($css)
        ->toContain('grid-template-columns: 6.5rem minmax(7rem, 0.8fr) minmax(14rem, 1.5fr) 7rem 5rem 4rem minmax(8rem, 1fr) 5rem;')
        ->and($view)
        ->toContain('title="Download backup" aria-label="Download backup"')
        ->toContain('<x-reicon name="upload" class="size-3.5 rotate-180" />')
        ->toContain('title="Delete backup" aria-label="Delete backup"')
        ->toContain('<x-reicon name="trash" class="size-3.5" />');
});

it('uses the compact resource table styling for backup executions', function () {
    $view = file_get_contents(resource_path('views/livewire/project/database/backup-executions.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('class="data-table deployment-table-scroll"')
        ->toContain('data-table-header backup-executions-table-grid h-auto rounded-none px-4 py-2.5 text-[11px]')
        ->toContain('data-table-row backup-executions-table-grid min-h-14 px-4 py-2.5')
        ->toContain('flex min-h-11 items-center justify-between border-t')
        ->and($css)
        ->toMatch('/\.backup-executions-table-grid\s*\{[^}]*gap:\s*0\.75rem;[^}]*min-width:\s*66rem;/');
});

it('renders compact size without the full-page min height class', function () {
    $html = $this->blade(
        '<x-empty title="No cloud tokens" description="Add a provider token." icon-name="keys" size="sm" />'
    );

    $html->assertSee('No cloud tokens')
        ->assertSee('min-h-44', false)
        ->assertDontSee('min-h-80', false);
});

it('renders action content from both contents and actions slots', function () {
    $contents = $this->blade(
        '<x-empty title="Empty" icon-name="keys"><x-slot:contents><button type="button">From contents</button></x-slot:contents></x-empty>'
    );
    $contents->assertSee('From contents');

    $actions = $this->blade(
        '<x-empty title="Empty" icon-name="keys"><x-slot:actions><button type="button">From actions</button></x-slot:actions></x-empty>'
    );
    $actions->assertSee('From actions');
});
