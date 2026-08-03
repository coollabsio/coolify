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
