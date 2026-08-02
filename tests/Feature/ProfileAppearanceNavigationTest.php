<?php

it('adds profile navigation with an appearance tab and route', function () {
    $routes = file_get_contents(base_path('routes/web.php'));
    $profileNavbar = file_get_contents(resource_path('views/components/profile/navbar.blade.php'));
    $profileView = file_get_contents(resource_path('views/livewire/profile/index.blade.php'));

    expect($routes)
        ->toContain("Route::get('/profile/appearance', ProfileAppearance::class)->name('profile.appearance')")
        ->and($profileNavbar)
        ->toContain('route(\'profile\')')
        ->toContain('route(\'profile.appearance\')')
        ->toContain('General')
        ->toContain('Appearance')
        ->and($profileView)
        ->toContain('<x-profile.navbar />')
        ->not->toContain('<h1>Profile</h1>\n    <div class="subtitle -mt-2">');
});

it('keeps color theme preferences on the profile appearance view without page width or density controls', function () {
    $appearanceView = file_get_contents(resource_path('views/livewire/profile/appearance.blade.php'));

    expect($appearanceView)
        ->toContain('<x-profile.navbar />')
        ->toContain('Color theme')
        ->toContain("setTheme('{{ \$option['value'] }}')")
        ->toContain("['value' => 'light'")
        ->toContain("['value' => 'system'")
        ->toContain("['value' => 'dark'")
        ->not->toContain('Page width')
        ->not->toContain('Interface density')
        ->not->toContain('setWidth(')
        ->not->toContain('setZoom(')
        ->not->toContain('pageWidth')
        ->not->toContain("localStorage.getItem('zoom')")
        ->not->toContain("localStorage.setItem('pageWidth'")
        ->not->toContain("localStorage.setItem('zoom'");
});
