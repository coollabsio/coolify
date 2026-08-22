<?php

/**
 * Family H1s hide when the desktop shell starts (lg+ fixed tabs + sidebar).
 * Collection indexes always keep their title; resource in-flow titles only
 * show below md (when the fixed resource tab bar is hidden).
 */
test('dashboard navbar hides family titles at lg to match the desktop shell', function () {
    expect(file_get_contents(resource_path('views/components/dashboard/navbar.blade.php')))
        ->toContain("'titleOnDesktop' => false")
        ->toContain("'lg:hidden' => ! \$titleOnDesktop")
        ->toContain('lg:h-12')
        ->not->toContain('lg:h-10');
});

test('fixed layer-2 spacers match the fixed bar height', function () {
    $paths = [
        resource_path('views/components/dashboard/navbar.blade.php'),
        resource_path('views/livewire/server/navbar.blade.php'),
        resource_path('views/livewire/project/application/heading.blade.php'),
        resource_path('views/livewire/project/database/heading.blade.php'),
        resource_path('views/livewire/project/service/heading.blade.php'),
    ];

    foreach ($paths as $path) {
        $blade = file_get_contents($path);
        expect($blade)->toContain('lg:h-12');
        expect($blade)->not->toContain('lg:h-10');
        expect($blade)->not->toContain('lg:block lg:h-5');
    }
});

test('resource in-flow titles show until the desktop topbar takes over at lg', function () {
    $paths = [
        resource_path('views/livewire/server/navbar.blade.php'),
        resource_path('views/livewire/project/application/heading.blade.php'),
        resource_path('views/livewire/project/database/heading.blade.php'),
        resource_path('views/livewire/project/service/heading.blade.php'),
    ];

    foreach ($paths as $path) {
        $blade = file_get_contents($path);

        expect($blade)
            ->toContain('w-full lg:hidden')
            ->toContain('w-full md:hidden')
            ->not->toContain('w-full xl:hidden');
    }
});

test('slide-over shells do not reserve layout space', function () {
    expect(file_get_contents(resource_path('views/components/slide-over.blade.php')))
        ->toContain("'class' => 'contents'")
        ->not->toContain('relative w-auto h-auto');
});

test('collection index headers stack so title and actions never overlap', function () {
    $paths = [
        resource_path('views/livewire/server/index.blade.php'),
        resource_path('views/livewire/project/index.blade.php'),
        resource_path('views/source/all.blade.php'),
        resource_path('views/livewire/destination/index.blade.php'),
        resource_path('views/livewire/storage/index.blade.php'),
    ];

    foreach ($paths as $path) {
        expect(file_get_contents($path))
            ->toContain('flex-col gap-3 sm:flex-row')
            ->toContain('text-[24px]!');
    }
});
