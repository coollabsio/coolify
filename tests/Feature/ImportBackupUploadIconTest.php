<?php

use Illuminate\Support\Facades\Blade;

it('registers the upload reicon used by import backup navigation', function () {
    $path = resource_path('views/components/reicon.blade.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain("'upload' => ");

    $html = Blade::render('<x-reicon name="upload" class="menu-item-icon" />');

    expect($html)
        ->toContain('viewBox="0 0 24 24"')
        ->toContain('fill="currentColor"')
        ->toContain('menu-item-icon')
        ->not->toBe('<svg class="menu-item-icon size-4" viewBox="0 0 24 24" fill="none"
    xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    
</svg>');
});

it('uses the upload reicon for import backup in database configuration nav', function () {
    $contents = file_get_contents(resource_path('views/livewire/project/database/configuration.blade.php'));

    expect($contents)
        ->toContain("'label' => 'Import Backup'")
        ->toContain("'icon' => 'upload'");
});

it('uses the upload reicon for import backup in service navigation', function () {
    $contents = file_get_contents(resource_path('views/components/service/configuration-sidebar.blade.php'));

    expect($contents)
        ->toContain("'label' => 'Import Backup'")
        ->toContain("'icon' => 'upload'")
        ->toContain("'route' => 'project.service.import-backup'");
});
