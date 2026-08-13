<?php

it('collapses resource header actions only when the top bar cannot fit them', function () {
    $overflow = file_get_contents(resource_path('views/components/resource-heading-overflow.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($overflow)
        ->toContain('data-resource-heading-overflow')
        ->toContain('measureInlineWidth')
        ->toContain('availableWidth')
        ->toContain('reserved += 200')
        ->toContain('hudSiblings')
        ->toContain('Actions')
        ->toContain('listbox-panel top-full! right-0! left-auto!')
        ->toContain("\$dispatch('resource-actions-toggled', { open })");

    expect($css)
        ->toContain('.resource-heading-overflow-items')
        ->toContain('.resource-heading-overflow-items.is-measuring')
        ->toContain('.resource-heading-overflow.is-collapsed');
});

it('uses the overflow group for application, service, database, and server desktop actions', function () {
    $files = [
        resource_path('views/livewire/project/application/heading.blade.php') => 'application-desktop-actions',
        resource_path('views/livewire/project/service/heading.blade.php') => 'service-desktop-actions',
        resource_path('views/livewire/project/database/heading.blade.php') => 'database-desktop-actions',
        resource_path('views/livewire/server/navbar.blade.php') => 'server-desktop-actions',
    ];

    foreach ($files as $path => $id) {
        expect(file_get_contents($path))
            ->toContain('<x-resource-heading-overflow')
            ->toContain($id);
    }
});
