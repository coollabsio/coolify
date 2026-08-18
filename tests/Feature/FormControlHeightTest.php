<?php

test('default form buttons and inputs share the same control height', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    expect($utilities)
        ->toContain('@utility input-select {')
        ->toContain('@utility button {');

    preg_match('/@utility input-select \{[^}]*\}/s', $utilities, $inputSelect);
    preg_match('/@utility button \{[^}]*\}/s', $utilities, $button);

    expect($inputSelect[0] ?? '')
        ->toContain('h-9')
        ->and($button[0] ?? '')->toContain('h-9')
        ->and($button[0] ?? '')->toContain('min-h-9')
        ->and($button[0] ?? '')->toContain('whitespace-nowrap')
        ->and($button[0] ?? '')->toContain('shrink-0')
        ->and($button[0] ?? '')->not->toContain('h-8');
});

test('settings form surfaces keep input and button heights equal', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    preg_match(
        '/\.application-settings-workspace \.input,[\s\S]*?\.application-settings-form \.select \{[\s\S]*?\}/',
        $css,
        $inputs
    );
    preg_match(
        '/\.application-settings-workspace \.button,[\s\S]*?\.application-settings-form \.button \{[\s\S]*?\}/',
        $css,
        $buttons
    );

    expect($inputs[0] ?? '')
        ->toContain('height: 2rem;')
        ->and($buttons[0] ?? '')->toContain('height: 2rem;')
        ->and($buttons[0] ?? '')->toContain('min-height: 2rem;')
        ->and($buttons[0] ?? '')->toContain('white-space: nowrap;');
});

test('directory storage actions wrap on narrow viewports instead of stacking uneven heights', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/file-storage.blade.php'));

    expect($view)
        ->toContain('flex flex-wrap items-center gap-2')
        ->toContain('Convert to file')
        ->toContain('Configure Backup');
});
