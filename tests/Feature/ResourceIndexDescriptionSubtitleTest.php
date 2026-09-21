<?php

test('resource table subtitle shows description only and never falls back to uuid', function () {
    $view = file_get_contents(resource_path('views/livewire/project/resource/index.blade.php'));

    expect($view)
        ->toContain('x-show="item.description"')
        ->toContain('x-text="item.description"')
        ->not->toContain('item.description || item.fqdn || item.uuid')
        ->not->toContain('item.fqdn || item.uuid');
});

test('resource grid does not show a placeholder when a domain is missing', function () {
    $view = file_get_contents(resource_path('views/livewire/project/resource/index.blade.php'));
    $gridView = explode('x-show="viewMode === \'grid\'"', $view, 2)[1];

    expect($gridView)
        ->not->toContain('x-show="!item.fqdn"')
        ->not->toContain('>-</span>');
});
