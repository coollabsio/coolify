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

it('animates the dropdown panel when the user menu opens', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));
    $stylesheet = file_get_contents(resource_path('css/app.css'));

    expect($menu)
        ->toContain('animate-in fade-in zoom-in-95 duration-150')
        ->toContain("'origin-bottom-left' => \$sidebar")
        ->toContain("'origin-top-right' => ! \$sidebar");

    expect($stylesheet)->toContain('@import "tw-animate-css";');
});

it('changes appearance from a submenu instead of navigating to a separate page', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($menu)
        ->toContain('appearanceOpen: false')
        ->toContain('@click.outside="open = false; appearanceOpen = false"')
        ->toContain("theme: localStorage.getItem('theme') === 'purple' ? 'custom' : (localStorage.getItem('theme') || 'dark')")
        ->toContain('setTheme(type, closeMenu = true)')
        ->toContain("this.setTheme('custom', false)")
        ->not->toContain('@change="appearanceOpen = false; open = false"')
        ->toContain('this.appearanceOpen = false;')
        ->toContain('this.open = false;')
        ->toContain('<div x-show="open" x-cloak @class([')
        ->not->toContain('<template x-if="open">')
        ->not->toContain('x-show.important="open"')
        ->not->toContain('<div x-show="open" x-cloak x-transition.opacity.duration.120ms')
        ->toContain("['value' => 'light', 'label' => 'Light'")
        ->toContain("['value' => 'system', 'label' => 'System'")
        ->toContain("['value' => 'dark', 'label' => 'Dark'")
        ->toContain('hover:bg-neutral-200 hover:text-neutral-950')
        ->not->toContain("route('profile.appearance')");
});
