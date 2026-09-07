<?php

it('uses yellow focus borders for normal v5 controls and purple focus borders for coolify buttons', function () {
    $v5Css = file_get_contents(resource_path('css/v5/app.css'));
    $buttonComponent = file_get_contents(resource_path('js/v5/components/ui/button.tsx'));
    $inputComponent = file_get_contents(resource_path('js/v5/components/ui/input.tsx'));
    $selectComponent = file_get_contents(resource_path('js/v5/components/ui/select.tsx'));
    $textareaComponent = file_get_contents(resource_path('js/v5/components/ui/textarea.tsx'));

    expect($v5Css)
        ->toContain(':focus-visible')
        ->toContain('ring-2 ring-ring ring-offset-2')
        ->toContain('--tw-ring-offset-color: var(--background)')
        ->and($buttonComponent)
        ->toContain('focus-visible:border-ring')
        ->toContain('focus-visible:border-coollabs-100')
        ->toContain('dark:focus-visible:border-coollabs-100')
        ->not->toContain('dark:focus-visible:border-coollabs-50')
        ->not->toContain('focus-visible:ring-')
        ->not->toContain('focus-visible:border-destructive')
        ->and($inputComponent)
        ->toContain('focus:border-ring')
        ->toContain('focus:ring-0')
        ->not->toContain('focus:ring-1')
        ->and($selectComponent)
        ->toContain('focus-visible:border-ring')
        ->not->toContain('focus-visible:ring-')
        ->and($textareaComponent)
        ->toContain('focus:border-ring')
        ->toContain('focus:ring-0')
        ->not->toContain('focus:ring-1');
});
