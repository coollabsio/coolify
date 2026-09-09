<?php

it('strips leftover x-cloak after wire:navigate to prevent blank page', function () {
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)
        ->toContain("document.addEventListener('livewire:navigated'")
        ->toContain("querySelectorAll('[x-cloak]')")
        ->toContain("removeAttribute('x-cloak')");
});

it('keeps the initial-load x-cloak guard on the app wrapper', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->toContain('x-cloak');
});
