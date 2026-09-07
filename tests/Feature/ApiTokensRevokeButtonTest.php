<?php

test('api tokens revoke action is text-only without a trash icon', function () {
    $blade = file_get_contents(resource_path('views/livewire/security/api-tokens.blade.php'));

    expect($blade)
        ->toContain('Revoke')
        ->not->toMatch('/name="trash"[\s\S]{0,80}Revoke|Revoke[\s\S]{0,80}name="trash"/');
});
