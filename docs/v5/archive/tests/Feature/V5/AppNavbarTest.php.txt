<?php

it('renders the Coolify dashboard icon smaller than the nav height', function () {
    $navbar = file_get_contents(resource_path('js/v5/components/app-navbar.tsx'));

    expect($navbar)->toContain('<img src="/coolify-logo.svg" alt="Coolify" className="size-6" />')
        ->and($navbar)->not->toContain('<img src="/coolify-logo.svg" alt="Coolify" className="size-8" />');
});

it('shows keyboard focus on ghost select dropdown triggers', function () {
    $selectComponent = file_get_contents(resource_path('js/v5/components/ui/select.tsx'));

    expect($selectComponent)
        ->toContain("variant === 'ghost'")
        ->toContain('focus-visible:border-ring')
        ->not->toContain('focus-visible:border-transparent');
});
