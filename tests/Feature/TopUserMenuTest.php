<?php

it('renders the same compact profile trigger on all breakpoints', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($menu)
        ->toContain('{{ $userInitial }}')
        ->toContain('aria-label="Account menu for {{ $userName }}"')
        ->not->toContain('sm:hidden')
        ->not->toContain('hidden sm:block max-w-[9rem]')
        ->not->toContain('sm:px-3');
});

it('still shows the user name and email inside the dropdown panel', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($menu)
        ->toContain('truncate text-[13px] font-semibold text-black dark:text-fg">{{ $userName }}</div>')
        ->toContain('{{ $userEmail }}');
});

it('changes appearance from a submenu instead of navigating to a separate page', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($menu)
        ->toContain('appearanceOpen: false')
        ->toContain('@click.outside="open = false; appearanceOpen = false"')
        ->toContain("theme: localStorage.getItem('theme') || 'dark'")
        ->toContain('setTheme(type)')
        ->toContain("['value' => 'light', 'label' => 'Light'")
        ->toContain("['value' => 'system', 'label' => 'System'")
        ->toContain("['value' => 'dark', 'label' => 'Dark'")
        ->toContain('hover:bg-neutral-200 hover:text-neutral-950')
        ->not->toContain("route('profile.appearance')");
});
