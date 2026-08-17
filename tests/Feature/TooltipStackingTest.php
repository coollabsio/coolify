<?php

test('shared tooltips render above toasts and notification banners', function () {
    $iconTooltip = file_get_contents(resource_path('views/components/icon-tooltip.blade.php'));
    $helper = file_get_contents(resource_path('views/components/helper.blade.php'));
    $navbar = file_get_contents(resource_path('views/components/navbar.blade.php'));
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    expect($iconTooltip)->toContain('z-[10000]')
        ->and($helper)->toContain('z-[10000]')
        ->and($navbar)->toContain('z-[10000]')
        ->and($utilities)->toContain('@utility auth-tooltip')
        ->and($utilities)->toContain('@apply fixed z-[10000]');
});
