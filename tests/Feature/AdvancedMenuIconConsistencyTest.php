<?php

/**
 * Advanced navigation and action menus must share the same reicon ("grid").
 */
test('advanced sidebar and configuration menus use the grid icon', function () {
    $files = [
        resource_path('views/components/settings/layout.blade.php'),
        resource_path('views/components/server/sidebar.blade.php'),
        resource_path('views/components/service-database/sidebar.blade.php'),
        resource_path('views/livewire/project/service/index.blade.php'),
        resource_path('views/livewire/project/application/configuration.blade.php'),
    ];

    foreach ($files as $path) {
        $contents = file_get_contents($path);

        expect($contents)->toContain('Advanced');

        // PHP menu arrays: label Advanced paired with icon grid
        if (str_contains($contents, "'label' => 'Advanced'") || str_contains($contents, "'Advanced' =>")) {
            expect($contents)
                ->toMatch("/'label'\\s*=>\\s*'Advanced'[\\s\\S]{0,160}?'icon'\\s*=>\\s*'grid'|'Advanced'\\s*=>\\s*'grid'/")
                ->not->toMatch("/'Advanced'\\s*=>\\s*'(?!grid)[^']+'/");
        }

        // Inline blade markup: reicon grid near Advanced label
        if (str_contains($contents, 'menu-item-label">Advanced')) {
            expect($contents)
                ->toMatch('/name="grid"[\\s\\S]{0,120}menu-item-label">Advanced/')
                ->not->toMatch('/name="(?!grid)[^"]+"[\\s\\S]{0,120}menu-item-label">Advanced/');
        }
    }
});

test('advanced action dropdown menus use the grid icon', function () {
    $files = [
        resource_path('views/components/applications/advanced.blade.php'),
        resource_path('views/components/services/advanced.blade.php'),
        resource_path('views/components/server/advanced.blade.php'),
    ];

    foreach ($files as $path) {
        $contents = file_get_contents($path);

        expect($contents)
            ->toContain('Advanced')
            ->toContain('name="grid"')
            ->not->toContain('name="sliders"')
            ->not->toContain("name='sliders'");
    }
});
