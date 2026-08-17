<?php

use Illuminate\Support\Facades\File;

it('uses one aligned sticky rule for all settings sidebars', function () {
    $appCss = file_get_contents(resource_path('css/app.css'));
    preg_match('/\.application-settings-navigation\s*\{([^}]+)\}/', $appCss, $matches);
    $navigationCss = $matches[1] ?? '';

    expect($navigationCss)
        ->toContain('align-self: start')
        ->toContain('position: sticky')
        ->toContain('top: calc(3rem + 1.75rem)')
        ->toContain('max-height: calc(100dvh - 5.5rem)')
        ->toContain('overflow-y: auto');
});

it('aligns every settings sidebar with its content without per-view top offsets', function () {
    $navigationViews = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), 'application-settings-navigation'));

    expect($navigationViews)->not->toBeEmpty();

    foreach ($navigationViews as $file) {
        expect($file->getContents())
            ->not->toContain('xl:sticky')
            ->not->toContain('xl:top-26');
    }
});

it('keeps settings card headers the same height with or without actions', function () {
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($appCss)->toMatch('/\.application-settings-section > :is\(header, \.application-settings-section-header\)\s*\{[^}]*min-height:\s*3rem/s');
});
