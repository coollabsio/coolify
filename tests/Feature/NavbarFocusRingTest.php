<?php

it('keeps horizontal space around sidebar items for focus rings', function () {
    $navbar = file_get_contents(resource_path('views/components/navbar.blade.php'));

    expect($navbar)
        ->toContain('class="-mx-1 flex min-h-0 flex-1 flex-col gap-y-0.5 overflow-y-auto px-1 pb-2 scrollbar"');
});
